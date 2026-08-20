export default function request() {
    return {
        request: {
            url: document.location.href,
            useragent: navigator.userAgent,
            referrer: document.referrer,
            readyState: document.readyState,
        },
    };
}
