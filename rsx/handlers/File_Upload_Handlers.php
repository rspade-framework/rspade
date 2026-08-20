<?php

namespace Rsx\Handlers;

use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;

/**
 * File_Upload_Handlers
 *
 * Event handlers for file upload system.
 * Demonstrates how to use the event system to add custom logic to file uploads.
 */
class File_Upload_Handlers
{
    /**
     * Require authentication for file uploads.
     *
     * THIS HANDLER IS MANDATORY. /_upload refuses to accept a file when NO
     * file.upload.authorize handler is registered (it throws), because an unregistered gate
     * would be an anonymous upload endpoint. Every application needs one of these; what it
     * checks is the application's decision.
     *
     * A STAFF session (internal app) OR a PORTAL session (client portal) may upload.
     * Portal users upload their request submissions through /_upload, so a logged-in
     * portal user is allowed here too; the per-feature authorize step (e.g. the portal
     * Requests controller's submit()) then validates the upload before attaching it.
     *
     * The gate also carries the file itself, so a stricter policy can inspect it before a
     * single byte is persisted:
     *     $data['filename']   // client original name
     *     $data['size']       // bytes
     *     $data['mime_type']  // sniffed mime
     *     $data['extension']  // lowercased client extension
     *     $data['tmp_path']   // temp upload path - read the real bytes to reject on content
     *
     * @param array $data Event data: request, user, params, file, filename, size, mime_type,
     *                    extension, tmp_path
     * @return true|\Illuminate\Http\JsonResponse
     */
    #[OnEvent('file.upload.authorize', priority: 10)]
    public static function require_authentication($data)
    {
        if (Session::is_logged_in() || Portal_Session::is_logged_in()) {
            return true;
        }

        return response()->json([
            'success' => false,
            'error' => 'Authentication required to upload files',
        ], 403);
    }
}
