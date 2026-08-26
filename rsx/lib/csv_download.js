/**
 * CSV download helper.
 *
 * A datagrid export endpoint returns the CSV as a STRING - an Ajax response cannot be a
 * file download, because the transport is XHR and every response is HTTP 200 JSON. The
 * browser side turns that string into a file the only way it can: a Blob behind a
 * transient <a download> that is clicked and thrown away.
 *
 * One implementation, because every grid's export does exactly this.
 */

/**
 * Hand the user a file built from a string.
 *
 * @param {string} content - File contents
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
