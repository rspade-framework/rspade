'use strict';

/**
 * Render HTML pages to PDF, PNG or JPG with the shipped headless Chromium - the document
 * generator's helper (generate_documents.php). Reads a JSON list on stdin:
 *
 *     [{"html": "<!doctype html>...", "out": "/abs/path/file.pdf", "type": "pdf|png|jpg"}, ...]
 *
 * and writes each file. One browser for the whole list. Exits non-zero on the first failure.
 */

const { chromium } = require('/var/www/html/system/node_modules/playwright');

async function main() {
    const chunks = [];
    for await (const chunk of process.stdin) {
        chunks.push(chunk);
    }
    const jobs = JSON.parse(Buffer.concat(chunks).toString('utf8'));

    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({ viewport: { width: 1200, height: 800 } });
        for (const job of jobs) {
            // No timeout: the content is inline and local, so it takes as long as it takes.
            await page.setContent(job.html, { waitUntil: 'load', timeout: 0 });
            if (job.type === 'pdf') {
                await page.pdf({ path: job.out, format: 'Letter', printBackground: true, margin: { top: '0.6in', bottom: '0.6in', left: '0.7in', right: '0.7in' }, timeout: 0 });
            } else {
                await page.screenshot({ path: job.out, type: job.type === 'jpg' ? 'jpeg' : 'png', quality: job.type === 'jpg' ? 82 : undefined, timeout: 0 });
            }
            console.log('wrote ' + job.out);
        }
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err && err.stack ? err.stack : String(err));
    process.exit(1);
});
