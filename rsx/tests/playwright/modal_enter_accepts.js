#!/usr/bin/env node
/**
 * Enter accepts the open modal - it activates the dialog's default button.
 *
 * WHAT THIS PINS. Every dialog the Modal API builds declares `default: true` on exactly one
 * button - alert and danger acknowledge, confirm confirms, prompt and select answer, and
 * Modal.form()'s footer button submits. Enter activates THAT button, whatever it does, so
 * one rule covers every dialog and the modal never has to know whether a form is inside it.
 *
 * WHY IT MATTERS FOR FORMS. Modal.form() puts its submit button in the modal FOOTER, outside
 * the <form>, and its callback is the ONLY completion path - it awaits the hosted
 * <Rsx_Form>'s submit(), runs on_success, then closes with the result. Rsx_Form separately
 * binds the form element's own submit event so that Enter on a PAGE form submits instead of
 * navigating (framework fix, 2026-09-08). In a modal that binding would reach submit() while
 * bypassing the modal entirely: the record saves, the dialog stays open and on_success never
 * runs. The Enter handler here calls preventDefault(), so the browser's implicit submission
 * never fires and exactly ONE submission path runs - the one that closes the dialog.
 *
 * Four probes:
 *
 *   1. CONFIRM   - Enter resolves the dialog with the default button's value.
 *   2. FORM      - Enter drives the footer button's callback: the form's submit() runs, then
 *                  on_success, then the dialog closes resolving with the server result. This
 *                  is the probe that fails if Enter ever reaches the form directly again.
 *   3. TEXTAREA  - Enter inside a textarea is a NEWLINE and must not accept the dialog.
 *   4. NO BUTTONS- a dialog with no default button (Modal.unclosable) ignores Enter.
 *
 * The form probe stubs the hosted form's submit() with a recorder. The endpoint is not what
 * is under test - the WIRING is, and a stub makes the assertion exact.
 *
 * Runs against /dashboard, an ordinary authenticated application page, because Modal lives
 * in rsx/lib/modal and is application code - it is not in the framework's own bundle.
 *
 * Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js.
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/dashboard';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: Enter accepts the open modal - ' + msg);
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
    await page.evaluate(() => { if (typeof Rsx !== 'undefined') Rsx.validate_session = async () => true; });
}

/** Probe 1 - Modal.confirm resolves true on Enter. */
async function probe_confirm(page) {
    await page.evaluate(() => {
        window.__probe_confirm = 'pending';
        Modal.confirm('Probe question?').then((r) => { window.__probe_confirm = r; });
    });
    await page.waitForTimeout(600);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(600);
    const resolved = await page.evaluate(() => window.__probe_confirm);

    // Modals QUEUE - a dialog left open here would starve every probe after it, turning one
    // failure into four. Close it so each probe reports its own verdict.
    await page.evaluate(() => { if (Modal._current) Modal._current.close(false); });
    await page.waitForTimeout(400);

    return resolved;
}

/** Probe 2 - Modal.form: Enter runs submit(), then on_success, then closes with the result. */
async function probe_form(page) {
    await page.evaluate(() => {
        window.__probe_submits = 0;
        window.__probe_success = null;
        window.__probe_result = 'pending';

        class Rsx_Modal_Enter_Probe_Temp extends Component {
            on_render() {
                this.$.append('<form class="rsx_modal_enter_probe_form_temp"></form>');
                const $form = this.$.find('.rsx_modal_enter_probe_form_temp');
                $form.component('Rsx_Form', {
                    controller: 'Rsx_Modal_Enter_Probe_Controller_Temp',
                    method: 'never_called',
                });
                const form = $form.component();
                // The endpoint is not under test; the wiring is.
                form.submit = function () {
                    window.__probe_submits++;
                    return Promise.resolve({ saved: true });
                };
                $form.append('<input type="text" name="probe" value="x" />');
            }
        }
        jqhtml.register_component('Rsx_Modal_Enter_Probe_Temp', Rsx_Modal_Enter_Probe_Temp);

        Modal.form({
            title: 'Probe form',
            component: 'Rsx_Modal_Enter_Probe_Temp',
            on_success: (result) => { window.__probe_success = result; },
        }).then((r) => { window.__probe_result = r; });
    });

    await page.waitForTimeout(900);
    await page.focus('.rsx_modal_enter_probe_form_temp input[name="probe"]');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(900);

    return await page.evaluate(() => ({
        submits: window.__probe_submits,
        success: window.__probe_success,
        result: window.__probe_result,
        modal_open: $('.Rsx_Modal:visible').length > 0,
    }));
}

/** Probe 3 - Enter inside a textarea is a newline, not an accept. */
async function probe_textarea(page) {
    await page.evaluate(() => {
        window.__probe_textarea = 'pending';
        Modal.show({
            title: 'Probe textarea',
            body: $('<div><textarea id="rsx_modal_enter_probe_ta_temp"></textarea></div>'),
            buttons: [{ label: 'OK', value: true, class: 'btn-primary', default: true }],
        }).then((r) => { window.__probe_textarea = r; });
    });
    await page.waitForTimeout(700);
    await page.focus('#rsx_modal_enter_probe_ta_temp');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(500);

    const state = await page.evaluate(() => ({
        resolved: window.__probe_textarea,
        still_open: $('.Rsx_Modal:visible').length > 0,
    }));

    // Leave the page clean for the next probe.
    await page.evaluate(() => { if (Modal._current) Modal._current.close(false); });
    await page.waitForTimeout(400);
    return state;
}

/** Probe 4 - a dialog with no buttons ignores Enter. */
async function probe_no_buttons(page) {
    await page.evaluate(async () => { await Modal.unclosable('Probe loading'); });
    await page.waitForTimeout(600);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(400);
    const state = await page.evaluate(() => ({ still_open: $('.Rsx_Modal:visible').length > 0 }));
    await page.evaluate(() => { if (Modal._current) Modal._current.close(false); });
    await page.waitForTimeout(400);
    return state;
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

        const ready = await page.evaluate(() => typeof Modal !== 'undefined' && typeof jqhtml !== 'undefined');
        if (!ready) {
            fail('Modal is not present on the page - auth may have failed');
            await browser.close();
            return;
        }

        // --- 1. confirm ---
        const confirmed = await probe_confirm(page);
        if (confirmed !== true) {
            fail('Enter did not activate the default button of Modal.confirm (resolved: ' + JSON.stringify(confirmed) + ')');
        } else {
            console.log('PASS: Enter accepts a simple dialog');
        }

        // --- 2. the form dialog ---
        const form = await probe_form(page);
        if (form.submits !== 1) {
            fail('the hosted form submitted ' + form.submits + ' times on Enter - expected exactly 1');
        } else if (!form.success || form.success.saved !== true) {
            fail('on_success did not run with the server result - Enter reached the form directly '
                + 'instead of the modal\'s button callback (success: ' + JSON.stringify(form.success) + ')');
        } else if (form.modal_open) {
            fail('the dialog stayed OPEN after an Enter-driven submit - the modal never learned it happened');
        } else if (!form.result || form.result.saved !== true) {
            fail('Modal.form() did not resolve with the server result (got: ' + JSON.stringify(form.result) + ')');
        } else {
            console.log('PASS: Enter submits a modal form, runs on_success and closes with the result');
        }

        // --- 3. textarea ---
        const ta = await probe_textarea(page);
        if (ta.resolved !== 'pending' || !ta.still_open) {
            fail('Enter inside a textarea accepted the dialog - it must insert a newline');
        } else {
            console.log('PASS: Enter inside a textarea does not accept the dialog');
        }

        // --- 4. no default button ---
        const nb = await probe_no_buttons(page);
        if (!nb.still_open) {
            fail('a dialog with no buttons closed on Enter');
        } else {
            console.log('PASS: a dialog with no default button ignores Enter');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Enter accepts the open modal');
    } else {
        console.log('PASS: Enter accepts the open modal');
    }
}

run();
