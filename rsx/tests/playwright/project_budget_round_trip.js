#!/usr/bin/env node
/**
 * A project's budget - a money column - through the real edit form and view page.
 *
 *   1. SAVE AND PRINT. Typing "1,234.5" into the budget's Money_Input and saving stores
 *      1234.50; the view prints "$1,234.50"; the edit form loads it back as "1234.50".
 *   2. BLANK. Emptying the box and saving stores no budget (null, never zero).
 *   3. REFUSED. "12.345" is submitted as typed and comes back as the field error under the
 *      budget input ("An amount of money has at most two decimal places."), the form stays
 *      open, and nothing is saved.
 *
 * Runs against the developer database on project 1 and puts its budget back afterwards.
 * Exit 0 on pass, 1 on any failure.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const USER_ID = 1;
const PROJECT_ID = 1;
const EDIT_ROUTE = '/projects/edit/' + PROJECT_ID;
const VIEW_ROUTE = '/projects/view/' + PROJECT_ID;

// A bounded poll for an event that takes about two seconds on an idle box: 100x that, and
// expiry fails the run naming the condition it was waiting for.
const WAIT_MS = 180000;

function fail(msg) {
    console.log('FAIL: project budget round trip - ' + msg);
    process.exitCode = 1;
}

function pass(msg) {
    console.log('PASS: project budget round trip - ' + msg);
}

/**
 * Wait until the page's SPA has finished booting (Rsx '_debug_ready'). A bounded POLL, each
 * probe its own evaluate: the document the goto committed can still be replaced while it loads
 * ("Execution context was destroyed"), and a probe that dies with it, or runs before the
 * bundle defines Rsx, counts as "not yet". Rsx.on() calls back at once for an event already
 * triggered, so a probe answers immediately either way.
 */
async function await_spa_ready(page) {
    const deadline = Date.now() + WAIT_MS;
    let last_error = null;
    for (;;) {
        try {
            const ready = await page.evaluate(() => new Promise((resolve) => {
                Rsx.on('_debug_ready', () => resolve(true));
                resolve(false);
            }));
            if (ready) {
                return;
            }
            last_error = null;
        } catch (e) {
            last_error = e;
        }
        if (Date.now() >= deadline) {
            throw new Error('never reached: the page finished booting (Rsx _debug_ready)'
                + (last_error ? ' (last probe: ' + last_error.message.split('\n')[0] + ')' : ''));
        }
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
}

/** Open the edit page and wait until the record's budget has been applied to the box. */
async function open_edit(page) {
    await page.goto(BASE_URL + EDIT_ROUTE, { waitUntil: 'commit' });
    await await_spa_ready(page);
    await page.waitForSelector('.Rsx_Form .Money_Input input', { timeout: WAIT_MS });
}

/** Type into the budget box as a person would. */
async function type_budget(page, text) {
    await page.evaluate((typed) => {
        const input = $('.Rsx_Form .Money_Input').first().component();
        input.$sid('field').val(typed).trigger('input');
    }, text);
}

/** The stored budget, read through the model: its canonical decimal, or null. */
async function stored_budget(page) {
    return page.evaluate(async (id) => {
        const Project_Model = Manifest.get_class_by_name('Project_Model');
        const project = await Project_Model.fetch(id);
        return project.budget === null ? null : project.budget.to_decimal();
    }, PROJECT_ID);
}

/** Submit the form and wait for its redirect to the view page. */
async function save_and_land_on_view(page) {
    await page.evaluate(() => {
        // Not awaited: a successful save navigates away from this page.
        $('.Rsx_Form').first().component().submit();
    });
    await page.waitForFunction((route) => location.pathname === route, VIEW_ROUTE, { timeout: WAIT_MS });
}

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    let original = null;
    let page = null;

    try {
        const context = await browser.newContext({ ignoreHTTPSErrors: true });
        page = await context.newPage();

        // The dev-auth token is bound to the URL it authorises, and only the FIRST document
        // request needs one - the session cookie carries to the later navigations.
        const auth_headers = { ...dev_auth_headers(EDIT_ROUTE, USER_ID), 'X-Playwright-Test': '1' };
        let initial_doc_sent = false;

        await page.route('**/*', async (route, request) => {
            if (!request.url().startsWith(BASE_URL)) {
                await route.continue();
                return;
            }
            const headers = { ...request.headers() };
            if (!initial_doc_sent && request.resourceType() === 'document') {
                Object.assign(headers, auth_headers);
                initial_doc_sent = true;
            } else {
                headers['X-Playwright-Test'] = '1';
            }
            await route.continue({ headers });
        });

        await open_edit(page);

        const landed = await page.evaluate(() => location.pathname);
        if (landed !== EDIT_ROUTE) {
            fail(`expected to land on ${EDIT_ROUTE}, got ${landed} - dev auth did not take`);
            return;
        }

        original = await stored_budget(page);

        // --- 1. SAVE AND PRINT ---
        await type_budget(page, '1,234.5');
        await save_and_land_on_view(page);

        if ((await stored_budget(page)) === '1234.50') {
            pass('"1,234.5" is stored as 1234.50');
        } else {
            fail(`"1,234.5" stored as ${await stored_budget(page)}`);
        }

        await page.waitForFunction(
            () => $('.View_Field').filter((i, el) => $(el).text().includes('Budget')).text().includes('$1,234.50'),
            null,
            { timeout: WAIT_MS }
        ).then(() => pass('the view prints $1,234.50'), () => fail('the view never printed $1,234.50'));

        await open_edit(page);
        const loaded = await page.evaluate(() => $('.Rsx_Form .Money_Input input').val());
        if (loaded === '1234.50') {
            pass('the edit form loads the budget as 1234.50');
        } else {
            fail(`the edit form loaded "${loaded}"`);
        }

        // --- 2. BLANK ---
        await type_budget(page, '');
        await save_and_land_on_view(page);

        if ((await stored_budget(page)) === null) {
            pass('a blank budget is stored as none');
        } else {
            fail(`a blank budget stored as ${await stored_budget(page)}`);
        }

        // --- 3. REFUSED ---
        await open_edit(page);
        await type_budget(page, '12.345');
        await page.evaluate(() => $('.Rsx_Form').first().component().submit());

        const message = 'An amount of money has at most two decimal places.';
        await page.waitForFunction(
            (text) => $('.Rsx_Form .Money_Input').closest('.Form_Field').text().includes(text),
            message,
            { timeout: WAIT_MS }
        ).then(() => pass('"12.345" is a field error on the budget'), () => fail('"12.345" showed no field error on the budget'));

        const still = await page.evaluate(() => location.pathname);
        const kept = await page.evaluate(() => $('.Rsx_Form .Money_Input input').val());
        if (still === EDIT_ROUTE && kept === '12.345') {
            pass('the form stays open with the text as typed');
        } else {
            fail(`after the refusal: on ${still}, box "${kept}"`);
        }

        if ((await stored_budget(page)) === null) {
            pass('the refused amount was not saved');
        } else {
            fail(`the refused amount changed the budget to ${await stored_budget(page)}`);
        }
    } catch (e) {
        fail(e.message);
    } finally {
        // Put project 1's budget back as it was, through the same form.
        if (page !== null) {
            try {
                await open_edit(page);
                await type_budget(page, original === null ? '' : original);
                await save_and_land_on_view(page);
            } catch (e) {
                fail('restoring the original budget: ' + e.message);
            }
        }
        await browser.close();
    }
})();
