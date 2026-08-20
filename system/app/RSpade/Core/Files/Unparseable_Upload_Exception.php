<?php

namespace App\RSpade\Core\Files;

use RuntimeException;

/**
 * Thrown by File_Attachment_Model::process_file() when an uploaded image's BYTES cannot be
 * parsed by ImageMagick (a content-parse failure) AND the app has opted into strict rejection
 * via config('rsx.attachments.reject_unparseable_images') = true.
 *
 * This is distinct from a MISSING ImageMagick binary (which fatals loudly BEFORE the try in
 * process_file() and is never represented by this exception). The DEFAULT behavior on a parse
 * failure is accept-and-degrade (no throw); this exception is only raised in strict mode so the
 * upload endpoint can catch it, reject the upload with a 4xx, and clean up the orphaned row.
 *
 * NOTE FOR THE UPLOAD ENDPOINT: create_from_upload() persists the attachment row (and its blob)
 * BEFORE calling process_file(), and does NOT wrap the flow in a DB transaction, so a throw here
 * leaves a saved attachment row behind. The catching endpoint must delete the attachment
 * explicitly (which cascades blob cleanup via the model's deleted() hook).
 */
class Unparseable_Upload_Exception extends RuntimeException
{
}
