<?php

namespace App\RSpade\Core\Files;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\RSpade\Core\Files\File_Attachment_Model;

/**
 * Rsx_Attachment_Handler_Abstract - external byte-residency handler for attachments.
 *
 * An attachment may exist with no local blob (file_storage_id NULL) and instead carry a
 * handler_class + opaque handler_ref describing where its bytes live (OneDrive, S3, a URL,
 * a future integration). Whenever the framework needs bytes it lacks, it asks the attachment's
 * handler to materialize them via fetch(), stores the result in the blob store exactly as if
 * uploaded, links it, and proceeds normally.
 *
 * This is a RESIDENCY abstraction, not a storage abstraction: _file_storage is untouched -
 * same schema, same sha256 content addressing, same dedup, same immutability. sha256 is
 * computed at materialization time.
 *
 * SECURITY: the framework NEVER instantiates a handler class named in the database unless it
 * appears in config('rsx.attachments.handlers'). See File_Attachment_Model::__resolve_handler().
 *
 * Static-first per framework philosophy. A handler that only implements fetch() gets every
 * framework feature (download, inline, thumbnails, metadata extraction) working automatically.
 */
abstract class Rsx_Attachment_Handler_Abstract
{
    /**
     * Consulted before serving download/inline for attachments of this handler.
     * When true, the framework calls is_stale() before serving; if stale it evicts +
     * re-materializes first. Thumbnails never freshness-check.
     */
    public const CHECK_FRESHNESS_ON_SERVE = false;

    /**
     * REQUIRED. Produce the attachment's bytes. Download/copy to a temp file and return its
     * absolute path. The FRAMEWORK then does store_blob() + link + metadata extraction - the
     * handler only fetches. MUST throw on any failure (fail loud). Never return a path to a
     * file that might be mutated afterward (always a private temp copy).
     *
     * @param File_Attachment_Model $attachment
     * @return string Absolute path to a private temp file holding the bytes.
     */
    abstract public static function fetch(File_Attachment_Model $attachment): string;

    /**
     * Optional. Return true if the remote content has changed vs. the currently-linked blob
     * (e.g. eTag comparison using handler_ref). Only consulted when CHECK_FRESHNESS_ON_SERVE
     * is true. Must be CHEAP (one metadata round-trip max). Throw on hard failure.
     *
     * @param File_Attachment_Model $attachment
     * @return bool
     */
    #[Replaceable]
    public static function is_stale(File_Attachment_Model $attachment): bool
    {
        return false;
    }

    /**
     * Optional serving override. Return a Response to fully take over (e.g. stream from the
     * external service, or 302 to a short-lived pre-authorized URL), or null to let the
     * framework materialize-and-serve normally. Called AFTER authorization gates have passed -
     * handlers do no auth. $disposition semantics match the calling endpoint (attachment).
     *
     * @param File_Attachment_Model $attachment
     * @param Request $request
     * @return Response|null
     */
    public static function serve_download(File_Attachment_Model $attachment, Request $request): ?Response
    {
        return null;
    }

    /**
     * Optional serving override for inline display. Same contract as serve_download().
     *
     * @param File_Attachment_Model $attachment
     * @param Request $request
     * @return Response|null
     */
    public static function serve_inline(File_Attachment_Model $attachment, Request $request): ?Response
    {
        return null;
    }
}
