export default function cookie() {
    if (!document.cookie) {
        return {};
    }

    return {
        cookies: document.cookie.split('; ').reduce(
            (cookies, cookie) => {
                const [cookieName, cookieValue] = cookie.split(/=/);
                cookies[cookieName] = cookieValue;

                return cookies;
            },
            {} as { [key: string]: string }
        ),
    };
}
