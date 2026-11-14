#!/usr/bin/env node
/**
 * Declared TEXT column types, end to end on real application screens.
 *
 * The framework suite pins the contract against fixture types; this pins that the
 * application is actually WIRED to it, which is a different claim and the one that breaks
 * silently. Three things are asserted, each of which would otherwise fail invisibly:
 *
 *   1. PRINTING. Interpolating a Rich_Text mounts its PRINTER component and emits real
 *      markup; interpolating a Raw_Text escapes and breaks lines. If the printer were not
 *      registered the page would throw; if a template had quietly reverted to a hand-rolled
 *      escape it would still LOOK right, so the assertion is on the mounted component.
 *   2. EDITING THROUGH A DYNAMIC TAG. The edit form names no widget - it asks the model
 *      which component edits the column. A wrong answer yields an empty placeholder rather
 *      than an error, so "an editor is present and is the expected one" has to be asserted.
 *   3. A LOSSLESS ROUND TRIP. Load a record into the editor and save it back unchanged; the
 *      stored value must be byte-identical. This is the assertion that catches an editor
 *      library silently dropping markup it did not author.
 *
 * Exit 0 on pass, 1 on any failure.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const USER_ID = 1;
const EDIT_ROUTE = '/projects/edit/1';
const VIEW_ROUTE = '/projects/view/1';

// A bounded poll for an event that takes about two seconds on an idle box: 100x that, and
// expiry fails the run naming the condition it was waiting for.
const WAIT_MS = 180000;

function fail(msg) {
    console.log('FAIL: text type rendering - ' + msg);
    process.exitCode = 1;
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

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });

    try {
        const context = await browser.newContext({ ignoreHTTPSErrors: true });
        const page = await context.newPage();

        // The dev-auth token is bound to the URL it authorises, and only the FIRST document
        // request needs one - the session cookie carries to the later navigations.
        const auth_headers = { ...dev_auth_headers(EDIT_ROUTE, USER_ID), 'X-Playwright-Test': '1' };
        let initial_doc_sent = false;

        await page.route('**/*', async (route, request) => {
            const url = request.url();

            if (!url.startsWith(BASE_URL)) {
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

        // --- Seed a known value through the model, so the assertions name exact content ---
        let seeded = await page.goto(BASE_URL + EDIT_ROUTE, { waitUntil: 'commit' });

        if (!seeded || seeded.status() !== 200) {
            fail(`${EDIT_ROUTE} returned ${seeded ? seeded.status() : 'no response'}`);
            await browser.close();
            return;
        }

        await await_spa_ready(page);

        // A dev-auth failure lands on /login, where these assertions would fail confusingly
        // rather than saying why.
        const landed = await page.evaluate(() => location.pathname);
        if (landed !== EDIT_ROUTE) {
            fail(`expected to land on ${EDIT_ROUTE}, got ${landed} - dev auth did not take`);
            await browser.close();
            return;
        }

        const known_html = '<h2>Heading</h2><p>Body with <strong>bold</strong>.</p>'
            + '<ul><li>one</li><li>two</li></ul>';
        const known_notes = 'first line\nsecond line';

        await page.evaluate(async ([html, notes]) => {
            const Project_Model = Manifest.get_class_by_name('Project_Model');
            const Rich_Text = Manifest.get_class_by_name('Rich_Text');
            const Raw_Text = Manifest.get_class_by_name('Raw_Text');
            const Frontend_Projects_Controller = Manifest.get_class_by_name('Frontend_Projects_Controller');

            // An edit says what it was edited from and which fields it changes, as an
            // <Rsx_Form> does (Form_Edit writes only the fields named in _changed).
            const before = await Project_Model.fetch(1);
            await Frontend_Projects_Controller.save({
                _loaded_updated_at: before.updated_at,
                _changed: ['description', 'notes'],
                id: 1,
                name: before.name,
                client_id: before.client_id,
                status: before.status,
                priority: before.priority,
                description: Rich_Text.from_editor(html),
                notes: Raw_Text.from_editor(notes),
            });
        }, [known_html, known_notes]);

        // --- 1. PRINTING ---
        await page.goto(BASE_URL + VIEW_ROUTE, { waitUntil: 'commit' });
        await await_spa_ready(page);

        // The printers mount from an ASYNC action load, so the page being "ready" is not
        // the same as the value having rendered. Wait for the mount rather than sampling
        // a page that has not got there yet - a sleep here would make this test flaky in
        // exactly the direction that hides a real regression.
        await page.waitForSelector('.Rich_Text_Display', { timeout: 20000 });
        await page.waitForSelector('.Raw_Text_Display', { timeout: 20000 });

        const printed = await page.evaluate(() => {
            const rich = document.querySelector('.Rich_Text_Display');
            const raw = document.querySelector('.Raw_Text_Display');

            return {
                rich_mounted: !!rich,
                raw_mounted: !!raw,
                rich_has_real_markup: !!(rich && rich.querySelector('ul li') && rich.querySelector('strong')),
                rich_text: rich ? rich.textContent.replace(/\s+/g, ' ').trim() : null,
                raw_has_break: !!(raw && raw.querySelector('br')),
                raw_escaped_not_markup: !!(raw && raw.querySelector('*:not(br)') === null),
            };
        });

        if (!printed.rich_mounted) {
            fail('interpolating a Rich_Text did not mount Rich_Text_Display');
        }
        if (!printed.rich_has_real_markup) {
            fail(`Rich_Text rendered without its markup (text: ${JSON.stringify(printed.rich_text)})`);
        }
        if (!printed.raw_mounted) {
            fail('interpolating a Raw_Text did not mount Raw_Text_Display');
        }
        if (!printed.raw_has_break) {
            fail('Raw_Text rendered without converting its newline to a line break');
        }
        if (!printed.raw_escaped_not_markup) {
            fail('Raw_Text emitted markup - its content must be escaped');
        }

        // --- 2. EDITING THROUGH A DYNAMIC TAG, and 3. LOSSLESS ROUND TRIP ---
        await page.goto(BASE_URL + EDIT_ROUTE, { waitUntil: 'commit' });
        await await_spa_ready(page);

        // The form populates from an async fetch, and the WYSIWYG initialises its library
        // asynchronously on top of that. Reading val() before both have landed would
        // compare an EMPTY editor against the stored value and report a loss that is really
        // a race - so wait until the editor actually holds the record.
        await page.waitForFunction(() => {
            const form = $('.Rsx_Form').component();
            if (!form) {
                return false;
            }

            const input = form.input('description');
            const value = input ? input.val() : null;

            return !!value && !value.is_empty();
        }, { timeout: 25000 });

        const edited = await page.evaluate(async () => {
            const Project_Model = Manifest.get_class_by_name('Project_Model');
            const Rich_Text = Manifest.get_class_by_name('Rich_Text');
            const Frontend_Projects_Controller = Manifest.get_class_by_name('Frontend_Projects_Controller');

            const form = $('.Rsx_Form').component();
            const description_input = form.input('description');
            const notes_input = form.input('notes');

            const before = (await Project_Model.fetch(1)).description.to_storage();

            // Save the record back UNTOUCHED. Anything the editor cannot represent is lost
            // here, and lost silently - which is exactly the failure being guarded against.
            // Both text fields are named as changed, so the editors' values are written to
            // storage - an edit naming nothing would write nothing and prove nothing.
            const record = await Project_Model.fetch(1);
            await Frontend_Projects_Controller.save({
                _loaded_updated_at: record.updated_at,
                _changed: ['description', 'notes'],
                id: 1,
                name: record.name,
                client_id: record.client_id,
                status: record.status,
                priority: record.priority,
                description: description_input.val(),
                notes: notes_input.val(),
            });

            const after = (await Project_Model.fetch(1)).description.to_storage();

            return {
                expected_editor: Project_Model.editor_component_for('description'),
                actual_editor: description_input ? description_input.constructor.name : null,
                expected_notes_editor: Project_Model.editor_component_for('notes'),
                actual_notes_editor: notes_input ? notes_input.constructor.name : null,
                value_is_typed: description_input.val() instanceof Rich_Text,
                before: before,
                after: after,
            };
        });

        if (edited.actual_editor !== edited.expected_editor) {
            fail(`the dynamic tag mounted ${edited.actual_editor}, but the model says the editor is ${edited.expected_editor}`);
        }
        if (edited.actual_notes_editor !== edited.expected_notes_editor) {
            fail(`notes mounted ${edited.actual_notes_editor}, expected ${edited.expected_notes_editor}`);
        }
        if (!edited.value_is_typed) {
            fail('the editor returned something other than a Rich_Text value');
        }
        if (edited.before !== edited.after) {
            fail('loading a record into the editor and saving it back was LOSSY\n'
                + `  before: ${edited.before}\n  after:  ${edited.after}`);
        }

        if (!process.exitCode) {
            console.log('PASS: text type rendering - printers mount, dynamic editors resolve, round trip is lossless');
        }
    } catch (error) {
        fail(error.message);
    } finally {
        await browser.close();
    }
})();
