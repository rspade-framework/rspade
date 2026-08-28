/**
 * File download helpers.
 *
 * A datagrid export endpoint returns the file's CONTENT - an Ajax response cannot be a
 * file download, because the transport is XHR and every response is HTTP 200 JSON. The
 * browser side turns that content into a file the only way it can: a Blob behind a
 * transient <a download> that is clicked and thrown away.
 *
 * Text formats (CSV) travel as a plain string. A binary format (xlsx) travels base64-encoded
 * because JSON has no bytes, and base64_to_bytes() turns it back before the Blob is built.
 *
 * One implementation, because every grid's export does exactly this.
 */

/**
 * Hand the user a file.
 *
 * @param {string|Uint8Array} content - File contents; a string for text, bytes for binary
 * @param {string} filename - Name the browser saves it under
 * @param {string} [mime_type] - MIME type; defaults to CSV
 */
function trigger_file_download(content, filename, mime_type = 'text/csv;charset=utf-8;') {
    const blob = new Blob([content], { type: mime_type });
    const url = URL.createObjectURL(blob);

    const $link = $('<a>').attr('href', url).attr('download', filename);

    $('body').append($link);
    $link[0].click();
    $link.remove();

    URL.revokeObjectURL(url);
}

/**
 * Decode a base64 payload into the bytes it stands for.
 *
 * The step between "the endpoint sent me a binary file inside a JSON envelope" and a Blob.
 * atob() yields one character per byte; a Blob needs the byte values themselves.
 *
 * @param {string} base64 - Base64-encoded content
 * @returns {Uint8Array} The decoded bytes
 */
function base64_to_bytes(base64) {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}
