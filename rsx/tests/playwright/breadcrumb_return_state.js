#!/usr/bin/env node
/**
 * A breadcrumb returns to the list in the state the user left it in.
 *
 * WHAT THIS PINS. The framework journal (Spa_Journal, Spa.return_url(), rsx:man
 * breadcrumb_origins RETURNING TO THE STATE YOU LEFT) on the template's real screens: the
 * client list's grid keeps its state under a STABLE hash namespace (DataGrid_Abstract
 * $hash_ns), and every link back to the list - the crumb, Record_Page_Actions' Back, the edit
 * form's Cancel - carries the grid's fragment and the scroll position it was left at.
 *
 *   1. /clients: sort by name, filter by status, page 2, scroll down
 *   2. open a client -> Edit -> Save (back on the client)
 *   3. the Clients crumb, Back and Cancel link to the list's fragment + the scroll key
 *   4. following the crumb restores sort, filter, page and scroll, and the scroll key is
 *      gone from the address bar
 *   5. the same client opened directly (a fresh tab, no journey) crumbs to the bare /clients
 *
 * Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js.
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: breadcrumb return state - ' + msg);
    process.exitCode = 1;
}

function check(ok, pass_msg, detail) {
    if (ok) {
        console.log('PASS: ' + pass_msg);
    } else {
        fail(pass_msg + ' - got ' + JSON.stringify(detail));
    }
}

/**
 * A signed-in page on `route`, its SPA booted. A fresh context each time: a new tab with an
 * empty session storage.
 */
async function open_page(browser, route) {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1400, height: 600 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (err) => errors.push(err.message));
    page.on('console', (msg) => {
        if (msg.type() === 'error' && !msg.text().includes('Transition was aborted')) {
            errors.push(msg.text());
        }
    });

    const auth_headers = { ...dev_auth_headers(route, USER_ID), 'X-Playwright-Test': '1' };
    let initial_doc_sent = false;
    await page.route('**/*', async (route_, request) => {
        if (!request.url().startsWith(BASE_URL)) {
            await route_.continue();
            return;
        }
        const headers = { ...request.headers() };
        if (!initial_doc_sent && request.resourceType() === 'document') {
            Object.assign(headers, auth_headers);
            initial_doc_sent = true;
        } else {
            headers['X-Playwright-Test'] = '1';
        }
        await route_.continue({ headers });
    });

    await page.goto(BASE_URL + route, { waitUntil: 'commit' });
    await page.waitForFunction(() => {
        try {
            return typeof Spa !== 'undefined' && !!Spa.action();
        } catch (e) {
            return false;
        }
    }, null, { timeout: 0 });

    await page.evaluate(() => {
        window.__rs = {
            until(fn) {
                return new Promise((resolve) => {
                    const poll = () => {
                        let value = null;
                        try { value = fn(); } catch (e) { value = null; }
                        if (value) resolve(value); else requestAnimationFrame(poll);
                    };
                    poll();
                });
            },
            // Navigate (or click) and wait for the page of `cls` to be ready with its crumbs drawn.
            async landed(cls) {
                await window.__rs.until(() => Spa.action() && Spa.action().constructor.name === cls);
                await Spa.action().ready();
                await window.__rs.until(() => $('.Breadcrumb_Nav li').length >= 1 && !$('.Breadcrumb_Nav__placeholder').length);
            },
            crumbs: () => $('.Breadcrumb_Nav a').map((i, a) => a.getAttribute('href')).get(),
        };
    });

    return { context, page, errors };
}

async function run() {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });

    try {
        const { page, errors } = await open_page(browser, '/clients');

        // 1. The list, sorted, filtered, paged and scrolled.
        const list = await page.evaluate(async () => {
            const rs = window.__rs;
            await rs.landed('Clients_Index_Action');
            const grid = Spa.action().sid('grid');
            await rs.until(() => grid.state.total > 0);
            grid.sort_by('name');
            await rs.until(() => location.hash.includes('Clients_DataGrid_sort=name'));
            grid.set_custom_filter('status_id', String(Client_Model.STATUS_ACTIVE));
            await rs.until(() => location.hash.includes('Clients_DataGrid_f_status_id='));
            grid.load_page(2);
            await rs.until(() => location.hash.includes('Clients_DataGrid_page=2'));
            const scroller = Spa._page_scroller();
            scroller.scrollTo({ top: 200, behavior: 'instant' });
            await new Promise((resolve) => requestAnimationFrame(resolve));
            return { hash: location.hash, scroll: scroller.scrollTop, total_pages: grid.state.total_pages };
        });
        console.log('INFO: list left at ' + JSON.stringify(list));
        check(list.hash.includes('Clients_DataGrid_page=2') && list.scroll > 0,
            'the grid writes its state under its stable namespace (Clients_DataGrid_*)', list);

        // 2. A client, its Edit page, Save.
        const journey = await page.evaluate(async () => {
            const rs = window.__rs;
            const href = $('tr[data-href]').first().attr('data-href');
            await Rsx.navigate(href);
            await rs.landed('Clients_View_Action');
            const view = { crumbs: rs.crumbs(), back: Spa.layout.$sid('page_actions').find('a').first().attr('href') };

            await Rsx.navigate(Rsx.Route('Clients_Edit_Action', Spa.action().args.id));
            await rs.landed('Clients_Edit_Action');
            const edit = {
                crumbs: rs.crumbs(),
                cancel: Spa.action().$.find('a.btn-secondary').filter((i, a) => $(a).text().includes('Cancel')).attr('href'),
            };

            Spa.action().$.find('.Rsx_Form').component().submit();
            await rs.landed('Clients_View_Action');
            const saved = { crumbs: rs.crumbs(), back: Spa.layout.$sid('page_actions').find('a').first().attr('href') };

            return { view, edit, saved };
        });
        console.log('INFO: ' + JSON.stringify(journey));

        const expected = '/clients' + list.hash + '&_scroll=' + list.scroll;
        check(journey.view.crumbs[0] === expected && journey.view.back === expected,
            'on the client, the Clients crumb and Back link to the list as it was left', journey.view);
        check(journey.edit.crumbs[0] === expected && journey.edit.cancel === expected,
            'on Edit, the Clients crumb and Cancel link to the list as it was left', journey.edit);
        check(journey.saved.crumbs[0] === expected,
            'after Save, the Clients crumb still links to the list as it was left', journey.saved);

        // 4. Follow the crumb.
        const back = await page.evaluate(async () => {
            const rs = window.__rs;
            $('.Breadcrumb_Nav a').first()[0].click();
            await rs.landed('Clients_Index_Action');
            const grid = Spa.action().sid('grid');
            // The rows are in (the grid reports its total once its body has loaded); the
            // restoration has re-applied the position at every height change up to here.
            await rs.until(() => grid.state.total > 0 && Spa.action().$.find('tr[data-href]').length > 0);
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            return {
                address: location.pathname + location.hash,
                page: grid.state.page,
                sort: grid.state.sort,
                filter: grid.state.custom_filters.status_id,
                scroll: Spa._page_scroller().scrollTop,
            };
        });
        console.log('INFO: ' + JSON.stringify(back));
        check(back.page === 2 && back.sort === 'name' && String(back.filter) !== 'undefined',
            'the crumb restores the grid\'s sort, filter and page', back);
        check(back.scroll === list.scroll, 'the crumb restores the scroll position', back);
        check(!back.address.includes('_scroll'), 'the scroll key is gone from the address bar once applied', back);
        check(errors.length === 0, 'no console error along the way', errors);

        // 5. The same client in a fresh tab: no journey, so the bare route.
        const client_path = journey.saved.crumbs[1] || null;
        const fresh = await open_page(browser, '/clients/view/' + (await page.evaluate(() => {
            const href = $('tr[data-href]').first().attr('data-href');
            return href.split('/').pop();
        })));
        const fresh_crumbs = await fresh.page.evaluate(async () => {
            await window.__rs.landed('Clients_View_Action');
            return window.__rs.crumbs();
        });
        check(fresh_crumbs[0] === '/clients', 'a client opened in a new tab crumbs to the bare /clients', { fresh_crumbs, client_path });
    } finally {
        await browser.close();
    }
}

run().catch((err) => {
    fail(err.stack || String(err));
});
