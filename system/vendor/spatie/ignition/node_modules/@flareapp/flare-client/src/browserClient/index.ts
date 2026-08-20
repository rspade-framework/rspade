export default function catchWindowErrors() {
    const flare = window.flare;

    if (!window || !flare) {
        return;
    }

    const originalOnerrorHandler = window.onerror;
    const originalOnunhandledrejectionHandler = window.onunhandledrejection;

    window.onerror = (_1, _2, _3, _4, error) => {
        if (error) {
            flare.report(error);
        }

        if (typeof originalOnerrorHandler === 'function') {
            originalOnerrorHandler(_1, _2, _3, _4, error);
        }
    };

    window.onunhandledrejection = (error: PromiseRejectionEvent) => {
        if (error.reason instanceof Error) {
            flare.report(error.reason);
        }

        // TODO: Maybe also send errors without stacktrace for unhandled rejections without an Error as reason? (could be a string, …)

        if (typeof originalOnunhandledrejectionHandler === 'function') {
            // @ts-ignore
            originalOnunhandledrejectionHandler(error);
        }
    };
}
