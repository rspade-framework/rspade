/**
 * The hello companion: heartbeats, sets a status line, calls hello() every 30 seconds, honours
 * pause and resume, and exits cleanly on SIGTERM. The SDK sends the heartbeats by itself.
 *
 * Every companion has this shape: ready(), a loop of idempotent units of work, and a stop
 * handler that lets the current unit finish.
 */

const companion = require('rsx-companion-sdk');

const CALL_INTERVAL_MS = 30000;

let paused = false;
let calls = 0;
let wake = null;

/** A sleep that a stop can end early. */
function sleep(ms) {
    return new Promise((resolve) => {
        const timer = setTimeout(resolve, ms);
        wake = () => {
            clearTimeout(timer);
            resolve();
        };
    });
}

let stopping = false;

companion.on('pause', () => {
    paused = true;
    companion.status('Paused');
});

companion.on('resume', () => {
    paused = false;
    companion.status('Resumed');
});

companion.on('stop', () => {
    stopping = true;
    if (wake !== null) wake();
});

async function main() {
    await companion.ready();
    companion.status('Started');

    while (!stopping) {
        if (!paused) {
            const answer = await companion.client.hello({ calls });
            calls++;
            companion.status('Said ' + answer.greeting + ' ' + calls + ' time(s)');
            companion.state({ calls, site_id: answer.site_id });
            console.log('hello answered: ' + JSON.stringify(answer));
        }

        await sleep(CALL_INTERVAL_MS);
    }

    console.log('stopping cleanly after ' + calls + ' call(s)');
    process.exit(0);
}

main().catch((err) => {
    companion.error('hello_failed', err.message);
    console.error(err.stack);
    process.exit(1);
});
