export default function requestData() {
    if (!location.search) {
        return {};
    }

    const queryString: { [key: string]: string } = {};

    new URLSearchParams(location.search).forEach((value, key) => {
        queryString[key] = value;
    });

    return { request_data: { queryString } };
}
