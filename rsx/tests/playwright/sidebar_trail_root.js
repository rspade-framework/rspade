#!/usr/bin/env node
/**
 * The sidebar follows the breadcrumb trail's root on a page reached through an origin.
 *
 * WHAT THIS PINS. A page reached from somewhere other than its home shows THAT trail
 * (breadcrumb origins, rsx:man breadcrumb_origins): the resolver reports meta.grafted and
 * meta.root_url, and Frontend_Spa_Layout hands that meta to Sidebar_Nav.follow_trail(). The
 * section lit must be the one the TRAIL starts in, not the one the URL is under - and on the
 * same page without the origin, the URL's own section.
 *
 * The origin is declared at run time on Contacts_View_Action (no example code ships in the
 * template): it accepts 'client' and crumbs to that client, so a contact opened "from its
 * client" lights Clients instead of Contacts.
 *
 *   1. /contacts/view/1?_via=client.<its client>  -> the active nav item is Clients
 *   2. /contacts/view/1                           -> the active nav item is Contacts
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
    console.log('FAIL: sidebar follows the trail root - ' + msg);
    process.exitCode = 1;
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
        // Spa.action() throws until the SPA has booted, so the probe reads that as "not yet".
        await page.waitForFunction(() => {
            try {
                return typeof Spa !== 'undefined' && !!Spa.action();
            } catch (e) {
                return false;
            }
        }, null, { timeout: 0 });

        const result = await page.evaluate(async () => {
            Contacts_View_Action.breadcrumb_via = ['client'];
            Contacts_View_Action.prototype.breadcrumb_parent_via = async function (via) {
                await this.await_loaded();
                return String(this.data.contact.client_id) === via.id ? Rsx.Route('Clients_View_Action', via.id) : null;
            };

            const contact = await Contact_Model.fetch(1);

            // Visit, then wait until the layout has handed the finished trail to the sidebar.
            const visit = async (url, options = {}) => {
                const layout = Spa.layout;
                const done = new Promise((resolve) => {
                    const original = layout.on_breadcrumb_complete;
                    layout.on_breadcrumb_complete = function (chain, meta) {
                        original.call(layout, chain, meta);
                        layout.on_breadcrumb_complete = original;
                        resolve(meta);
                    };
                });
                await Spa.dispatch(url, options);
                const meta = await done;
                await layout.sid('sidebar_nav').follow_trail(meta);
                return {
                    meta: meta,
                    active: $('.Sidebar_Nav__link.active').map((i, el) => el.getAttribute('href')).get(),
                };
            };

            const grafted = await visit('/contacts/view/1?_via=client.' + contact.client_id);
            // {via: false}: from the grafted page the origin would otherwise be carried.
            const plain = await visit('/contacts/view/1', { via: false });

            return { client_id: contact.client_id, grafted: grafted, plain: plain };
        });

        const clients_href = '/clients';
        const contacts_href = '/contacts';

        if (!result.grafted.meta.grafted || JSON.stringify(result.grafted.active) !== JSON.stringify([clients_href])) {
            fail('a contact reached from its client should light Clients - got ' + JSON.stringify(result.grafted));
        } else {
            console.log('PASS: a grafted trail lights the section its root is in (Clients)');
        }

        if (result.plain.meta.grafted || JSON.stringify(result.plain.active) !== JSON.stringify([contacts_href])) {
            fail('the same contact without an origin should light Contacts - got ' + JSON.stringify(result.plain));
        } else {
            console.log('PASS: the plain trail lights the URL\'s own section (Contacts)');
        }
    } finally {
        await browser.close();
    }
}

run().catch((err) => {
    fail(err.stack || String(err));
});
