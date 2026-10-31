#!/usr/bin/env node
/**
 * Password_Confirmation_Modal - this application's "confirm it's you" dialog.
 *
 * WHAT THIS PINS, on the Password & Security screen (where the gated buttons live):
 *
 *   1. CANCEL      - require() resolves false and nothing is confirmed.
 *   2. THREE TRIES - three refused passwords close the dialog, the "Not Confirmed" notice is
 *                    shown, require() resolves false, and the user is still on the page,
 *                    signed in. The refusals render inside the dialog in between.
 *
 * The success path is the framework's (tests/password_confirmation, and the HTTP check in
 * tests/two_factor/http/two_factor_endpoint_gates.sh); this script owns the dialog, which is
 * application code in rsx/lib/password_confirmation/.
 *
 * Each refused password counts toward the sign-in rate limit for this account and address
 * (three is below it).
 *
 * Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js.
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/frontend/settings/password_security';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: Password confirmation dialog - ' + msg);
    process.exitCode = 1;
}

async function await_spa_ready(page) {
    await page.evaluate(() => new Promise((resolve) => {
        if (typeof Rsx !== 'undefined' && Rsx.on) {
            Rsx.on('_debug_ready', () => resolve());
            setTimeout(resolve, 15000);
        } else {
            setTimeout(resolve, 3000);
        }
    }));
}

/** Start require() without awaiting it; its answer lands in window.__confirm_result. */
async function open_dialog(page) {
    await page.evaluate(() => {
        window.__confirm_result = 'pending';
        Password_Confirmation_Modal.require().then((r) => { window.__confirm_result = r; });
    });
    await page.waitForSelector('.Password_Confirmation_Form .Password_Input input', { state: 'visible' });
}

async function probe_cancel(page) {
    await open_dialog(page);
    await page.click('.modal-footer button:has-text("Cancel")');
    await page.waitForFunction(() => window.__confirm_result !== 'pending');

    return page.evaluate(() => ({
        result: window.__confirm_result,
        confirmed: Rsx_Password_Confirmation.is_confirmed(),
    }));
}

async function probe_three_tries(page) {
    await open_dialog(page);

    const errors = [];
    for (let i = 1; i <= 3; i++) {
        await page.fill('.Password_Confirmation_Form .Password_Input input', 'not-the-password-' + i);
        await page.click('.modal-footer button:has-text("Confirm")');

        if (i < 3) {
            // The refusal renders in the dialog, which stays open.
            await page.waitForFunction(() => /not correct/i.test($('.Password_Confirmation_Form').text()));
            errors.push(await page.evaluate(() => $('.Password_Confirmation_Form').length));
        }
    }

    // The third refusal closes the dialog and the notice replaces it.
    await page.waitForFunction(() => /could not be confirmed/i.test($('.modal').text()));

    await page.click('.modal-footer button:has-text("OK")');
    await page.waitForFunction(() => window.__confirm_result !== 'pending');

    return page.evaluate((n) => ({
        result: window.__confirm_result,
        confirmed: Rsx_Password_Confirmation.is_confirmed(),
        refusals_rendered: n,
        still_signed_in: !!(window.rsxapp && window.rsxapp.user),
    }), errors.length);
}

async function run() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 1000 } });
    const page = await context.newPage();

    const auth_headers = { ...dev_auth_headers(ROUTE, USER_ID), 'X-Playwright-Test': '1' };
    let initial_doc_sent = false;
    await page.route('**/*', async (route, request) => {
        const url = request.url();
        if (url.startsWith(BASE_URL)) {
            const headers = { ...request.headers() };
            if (!initial_doc_sent && request.resourceType() === 'document') {
                Object.assign(headers, auth_headers);
                initial_doc_sent = true;
            } else {
                headers['X-Playwright-Test'] = '1';
            }
            await route.continue({ headers });
        } else {
            await route.continue();
        }
    });

    try {
        await page.goto(BASE_URL + ROUTE, { waitUntil: 'commit' });
        await await_spa_ready(page);

        const ready = await page.evaluate(() => typeof Password_Confirmation_Modal !== 'undefined');
        if (!ready) {
            fail('Password_Confirmation_Modal is not present on the page - auth may have failed');
            await browser.close();
            return;
        }

        const cancel = await probe_cancel(page);
        if (cancel.result !== false || cancel.confirmed) {
            fail('Cancel did not resolve false with nothing confirmed (got ' + JSON.stringify(cancel) + ')');
        } else {
            console.log('PASS: Cancel answers false');
        }

        const tries = await probe_three_tries(page);
        if (tries.refusals_rendered !== 2) {
            fail('the first two refusals were not rendered inside the open dialog');
        } else if (tries.result !== false || tries.confirmed) {
            fail('three refused passwords did not resolve false (got ' + JSON.stringify(tries) + ')');
        } else if (!tries.still_signed_in) {
            fail('the user was signed out by refused confirmations');
        } else {
            console.log('PASS: three refused passwords close the dialog and say so; the user stays signed in');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (!process.exitCode) {
        console.log('PASS: Password confirmation dialog');
    }
}

run();
