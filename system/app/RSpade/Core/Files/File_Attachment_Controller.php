<?php

namespace App\RSpade\Core\Files;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Icons;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Files\Unparseable_Upload_Exception;
use App\RSpade\Core\Files\Zip_Download_Request_Model;
use App\RSpade\Core\Files\Zip_Stream;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * File_Attachment_Controller
 *
 * Unified controller for file attachment operations: upload, download, viewing, and thumbnails.
 *
 * ================================================================================================
 * UPLOAD ENDPOINT
 * ================================================================================================
 *
 * POST /_upload
 * AUTH: Handled via the MANDATORY file.upload.authorize event hook (no handler registered =
 *       uploads fail loud with a RuntimeException; see SECURITY HOOKS below)
 *
 * REQUEST PARAMETERS:
 * - file (required) - The file to upload
 * - fileable_type (optional) - Type of parent entity (e.g., "post", "comment")
 * - fileable_id (optional) - ID of parent entity
 * - fileable_category (optional) - Category/purpose (e.g., "avatar", "attachment")
 * - fileable_type_meta (optional) - Additional type metadata
 * - fileable_order (optional) - Sort order for multiple attachments
 * - filename_override (optional) - Override the uploaded filename
 *
 * RESPONSE: JSON
 * - success: true
 * - attachment: {
 *     key: string (unique identifier for retrieving file)
 *     file_name: string
 *     file_type: string (enum label: "Image", "Video", "Document", etc.)
 *     file_extension: string
 *     url: string (URL to access file)
 *     download_url: string (URL to download file)
 *     size: int (bytes)
 *     width: int|null (for images)
 *     height: int|null (for images)
 *     is_animated: bool|null (for images)
 *     duration: int|null (for videos, in seconds)
 *     preview_unavailable: bool (true if an image's bytes were unparseable and it was degraded to a generic file)
 *   }
 *
 * ================================================================================================
 * DOWNLOAD/VIEW ENDPOINTS
 * ================================================================================================
 *
 * GET /_download/:key                               - Download file as attachment
 * GET /_download_zip/:key                           - Stream a multi-file ZIP for a minted download request
 * GET /_inline/:key                                 - View file inline (browser display)
 * GET /_thumbnail/dynamic/:key/:type/:width/:height? - Generate dynamic thumbnail (WebP)
 * GET /_icon_by_extension/:extension                - Get file type icon as PNG
 *
 * FILE RESPONSE PATTERN:
 *   Use Response facade methods for file responses from static route methods:
 *   - Response::download($path, $name, $headers)  - Forces download dialog
 *   - Response::file($path, $headers)             - Displays inline in browser
 *
 * THUMBNAIL TYPES:
 *   - cover: Fills the requested dimensions, cropping as needed
 *   - fit:   Maintains aspect ratio within dimensions, transparent background
 *
 * THUMBNAIL GENERATION:
 *   - Max dimensions: 256x256 (automatically constrained)
 *   - Height optional: if omitted, maintains aspect ratio
 *   - Output format: WebP for static thumbnails
 *   - Supports: JPG, PNG, GIF, WEBP, BMP (images); icon-based thumbnails for all other types
 *   - Future: PDF, DOCX, videos, animated files
 *
 * ================================================================================================
 * SECURITY HOOKS
 * ================================================================================================
 *
 * All event handlers should be public static methods with #[OnEvent] attribute.
 * Place handlers in /rsx/handlers/ directory.
 *
 * 1. file.upload.authorize (gate) - MANDATORY
 *    Purpose: Authorization check for uploads - first non-true response halts upload
 *    MANDATORY: unlike every other gate here, /_upload REFUSES to run when NO handler is
 *      registered for this event - it throws a RuntimeException (HTTP 5xx). An unregistered
 *      gate is an open anonymous upload endpoint, and who may upload is an application
 *      decision, so the framework treats "nobody is listening" as a misconfigured app rather
 *      than as permission. Registering one handler (even a bare login check) satisfies it.
 *    Data: [
 *      'request'   => Request,
 *      'user'      => User_Model|Portal_User_Model|null (realm-honest: the portal user on a
 *                     portal request, else the staff user),
 *      'params'    => array,
 *      'file'      => UploadedFile (present and validated before the gate fires),
 *      'filename'  => string  client original name,
 *      'size'      => int     bytes,
 *      'mime_type' => string  sniffed mime (pre-persist),
 *      'extension' => string  lowercased client extension,
 *      'tmp_path'  => string  temp upload path - read it to reject on actual CONTENT
 *    ]
 *    Return: true to allow, or JsonResponse/redirect to deny
 *    Example:
 *      #[OnEvent('file.upload.authorize', priority: 10)]
 *      public static function require_auth($data) {
 *          if (!Session::is_logged_in() && !Portal_Session::is_logged_in()) {
 *              return response()->json(['success' => false, 'error' => 'Auth required'], 403);
 *          }
 *          return true;
 *      }
 *
 * 2. file.upload.params (filter)
 *    Purpose: Modify upload parameters before file is saved
 *    Data: array of upload params (site_id, fileable_type, etc.)
 *    Return: modified params array
 *    Example:
 *      #[OnEvent('file.upload.params', priority: 10)]
 *      public static function add_metadata($params) {
 *          $params['fileable_type_meta'] = 'user_uploaded';
 *          return $params;
 *      }
 *
 * 3. file.upload.complete (action)
 *    Purpose: Post-upload processing (logging, notifications, etc.)
 *    Data: ['attachment' => File_Attachment_Model, 'request' => Request, 'params' => array]
 *    Return: void (return values ignored)
 *    Example:
 *      #[OnEvent('file.upload.complete', priority: 10)]
 *      public static function log_upload($data) {
 *          Log::info("File uploaded: " . $data['attachment']->file_name);
 *      }
 *
 * 4. file.upload.response (filter)
 *    Purpose: Modify response data before sending to client
 *    Data: array with 'success' and 'attachment' keys
 *    Return: modified response array
 *    Example:
 *      #[OnEvent('file.upload.response', priority: 10)]
 *      public static function add_cdn_url($response) {
 *          $response['attachment']['cdn_url'] = cdn_url($response['attachment']['key']);
 *          return $response;
 *      }
 *
 * 5. file.thumbnail.authorize (gate)
 *    Purpose: Authorization check for viewing thumbnails - first non-true response denies access
 *    Data: ['attachment' => File_Attachment_Model, 'user' => User|null, 'request' => Request]
 *    Return: true to allow, or JsonResponse/redirect to deny
 *    Example:
 *      #[OnEvent('file.thumbnail.authorize', priority: 10)]
 *      public static function check_thumbnail_access($data) {
 *          if ($data['attachment']->fileable_type === 'private_document') {
 *              if (!Session::is_logged_in()) {
 *                  return response()->json(['success' => false, 'error' => 'Auth required'], 403);
 *              }
 *          }
 *          return true;
 *      }
 *
 * 6. file.download.authorize (gate)
 *    Purpose: Authorization check for file downloads - first non-true response denies access
 *    Data: ['attachment' => File_Attachment_Model, 'user' => User|null, 'request' => Request]
 *    Return: true to allow, or JsonResponse/redirect to deny
 *    Example:
 *      #[OnEvent('file.download.authorize', priority: 10)]
 *      public static function check_download_access($data) {
 *          if ($data['attachment']->fileable_type === 'premium_content') {
 *              if (!user_has_premium($data['user'])) {
 *                  return response()->json(['success' => false, 'error' => 'Premium required'], 403);
 *              }
 *          }
 *          return true;
 *      }
 *
 * CASCADING SECURITY MODEL:
 *   - Thumbnails: Check file.thumbnail.authorize only
 *   - Inline/Download: Check BOTH file.thumbnail.authorize AND file.download.authorize
 *
 * This ensures that:
 *   - If you implement thumbnail security, it automatically applies to downloads too
 *   - You can add stricter download-only rules without affecting thumbnail viewing
 *   - If you forget to implement download security, thumbnail security still protects downloads
 *   - Download security rules do NOT restrict thumbnail viewing
 *
 * AUTHORIZATION: the class gate is 'public'; per-file authorization runs through the
 * file.*.authorize event hooks described above. Those hooks ARE the record layer -
 * they answer "may this caller touch THIS attachment", which no user-scoped gate can
 * express - so they stay exactly where they are. A blanket login gate here would be
 * wrong in both directions: it would lock out deliberately public files while adding
 * nothing to a private one.
 */
#[Auth('public')]
class File_Attachment_Controller extends Rsx_Controller_Abstract
{
    // ============================================================================================
    // UPLOAD
    // ============================================================================================

    /**
     * Handle file upload with event hooks
     *
     * ONE HANDLER, TWO CHANNELS: the #[Portal_Route] serves the same upload transport
     * under the portal's own base, so a portal upload is dispatched as a portal
     * request and its CSRF token verifies against the portal session. The site
     * resolution below already reads Portal_Session first, and the app's
     * file.upload.authorize gate sees the correct realm. See
     * Ajax_Endpoint_Controller::dispatch().
     *
     * @param Request $request
     * @param array $params
     * @return \Illuminate\Http\JsonResponse
     */
    #[Route('/_upload', methods: ['POST'])]
    #[Portal_Route('/_upload', methods: ['POST'])]
    public static function upload(Request $request, array $params = [])
    {
        // MANDATORY GATE. trigger_gate() defaults OPEN when nothing is listening - correct for
        // an optional gate, catastrophic for this one (an app that never wrote a handler would
        // be running an anonymous upload endpoint). Who may upload is an APPLICATION decision
        // the framework cannot guess, so an unregistered gate is a MISCONFIGURED APPLICATION,
        // not a bad request: fail loud (5xx), before the request is examined at all.
        if (!Event_Registry::has_handlers('file.upload.authorize')) {
            throw new \RuntimeException(
                'File uploads are disabled: no file.upload.authorize gate handler is registered. '
                . 'Uploading is an application authorization decision the framework will not make '
                . 'for you (at minimum, require a logged-in user), so /_upload refuses to accept a '
                . 'file until the application registers a #[OnEvent(\'file.upload.authorize\')] '
                . 'handler in /rsx/handlers/. See: php artisan rsx:man file_upload'
            );
        }

        // Validate the file BEFORE the gate: the gate payload carries the uploaded file itself
        // (and its temp path), so a handler can inspect the real bytes and reject on content.
        // That is only meaningful once the file is known to be present and successfully received.
        if (!$request->hasFile('file')) {
            // A body over PHP's post_max_size is DISCARDED before userland sees it: $_FILES
            // and $_POST arrive empty while Content-Length still says how big it was. Reported
            // as a bare "No file uploaded" that is indistinguishable from a form bug, and the
            // operator has no way to know the request was thrown away by the ini rather than
            // by us. Say which one it was.
            $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $post_max = ini_bytes(ini_get('post_max_size'));

            if ($post_max > 0 && $content_length > $post_max) {
                return response()->json([
                    'success' => false,
                    'error' => 'Upload rejected by PHP before it reached the application: the request body was '
                        . bytes_to_human($content_length) . ', over the post_max_size limit of '
                        . bytes_to_human($post_max) . '. Raise post_max_size and upload_max_filesize in the '
                        . 'php-fpm php.ini (they are separate from the CLI ini) to at least the configured '
                        . 'rsx.files.max_file_size.',
                ], 413);
            }

            return response()->json([
                'success' => false,
                'error' => 'No file uploaded',
            ], 400);
        }

        $file = $request->file('file');

        // Validate file uploaded successfully
        if (!$file->isValid()) {
            return response()->json([
                'success' => false,
                'error' => 'File upload failed: ' . $file->getErrorMessage(),
            ], 400);
        }

        // Size ceiling. Checked BEFORE the authorize gate: the gate is application code that
        // may read the real bytes, and there is no reason to run it over a file we have
        // already decided to refuse. 0 or null disables the framework ceiling entirely, which
        // leaves only PHP's ini limits.
        $max_file_size = (int) config('rsx.files.max_file_size', 0);
        if ($max_file_size > 0 && $file->getSize() > $max_file_size) {
            return response()->json([
                'success' => false,
                'error' => 'File is too large: ' . bytes_to_human((int) $file->getSize())
                    . ' exceeds the ' . bytes_to_human($max_file_size) . ' limit.',
            ], 413);
        }

        // Event: file.upload.authorize (gate) - First non-true response halts.
        // 'user' is realm-honest: /_upload serves BOTH universes, so a portal request reports
        // the portal user, never a staff facade read that would be null for a logged-in
        // portal uploader.
        //
        // The fork is on the REALM OF THE REQUEST, not on who is signed in. "A portal user
        // is logged in" is an identity test and gets BOTH directions wrong: an anonymous
        // portal upload would be handed the staff user, and a staff upload from a browser
        // that also holds a portal cookie would be authorized as the PORTAL user. See
        // docs.dev/audits/portal_realm_session_audit_2026_08_09.md.
        $is_portal = Rsx_Portal::is_portal_request();

        $auth_result = Rsx::trigger_gate('file.upload.authorize', [
            'request' => $request,
            'user' => $is_portal
                ? Portal_Session::get_portal_user()
                : Session::get_user(),
            'params' => $params,
            'file' => $file,
            'filename' => $file->getClientOriginalName(),
            'size' => (int) $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'extension' => strtolower($file->getClientOriginalExtension()),
            'tmp_path' => $file->getRealPath(),
        ]);

        if ($auth_result !== true) {
            // Handler returned a response (error JSON, redirect, etc)
            return $auth_result;
        }

        // site_id is derived ENTIRELY server-side — client input is never consulted.
        // Trusting a posted site_id was a cross-tenant write primitive (a logged-in
        // caller could stamp an attachment into another tenant), and it forced app JS
        // to care about tenancy. /_upload serves BOTH universes, so: portal REQUEST ->
        // Portal_Session's site (declared by the app in Portal_Main::init(), and it
        // throws rather than guess), otherwise the staff Session's site — the same
        // realm fork as the gate payload above, and for the same reason.
        $site_id = $is_portal
            ? Portal_Session::get_site_id()
            : Session::get_site_id();

        if (!$site_id) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to resolve a site for this upload',
            ], 400);
        }

        $upload_params = [
            'site_id' => $site_id,
            'filename_override' => $request->input('filename_override'),
        ];

        // Remove null values
        $upload_params = array_filter($upload_params, fn($v) => $v !== null);

        // Event: file.upload.params (filter) - Allow handlers to modify params
        // Note: Handlers can still add fileable_* params if needed for programmatic uploads
        $upload_params = Rsx::trigger_filter('file.upload.params', $upload_params);

        // Security: Files uploaded via web endpoint should NOT be pre-assigned to records
        // User-provided fileable_* params are ignored. Use attach_to()/add_to() after upload.
        // This prevents users from attaching files to records they don't own.
        unset($upload_params['fileable_type']);
        unset($upload_params['fileable_id']);
        unset($upload_params['fileable_category']);
        unset($upload_params['fileable_type_meta']);
        unset($upload_params['fileable_order']);

        // Create file attachment
        try {
            $attachment = File_Attachment_Model::create_from_upload($file, $upload_params);
        } catch (Unparseable_Upload_Exception $e) {
            // Strict reject-mode (config rsx.attachments.reject_unparseable_images): the image bytes
            // could not be parsed. create_from_upload() already cleaned up the orphan row + blob, so
            // just surface a 4xx validation error to the client.
            return response()->json([
                'success' => false,
                'error' => 'This image could not be processed and was not accepted.',
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'File upload failed: ' . $e->getMessage(),
            ], 500);
        }

        // Event: file.upload.complete (action) - Logging, notifications, etc
        Rsx::trigger_action('file.upload.complete', [
            'attachment' => $attachment,
            'request' => $request,
            'params' => $params,
        ]);

        // Build response data
        $response_data = [
            'success' => true,
            'attachment' => [
                'key' => $attachment->key,
                'file_name' => $attachment->file_name,
                'file_type' => $attachment->file_type_id__label,
                'file_extension' => $attachment->file_extension,
                'url' => $attachment->get_url(),
                'download_url' => $attachment->get_download_url(),
                'size' => $attachment->get_size(),
                'mime_type' => $attachment->mime_type,
                'has_thumbnail' => $attachment->has_thumbnail(),
                'width' => $attachment->width,
                'height' => $attachment->height,
                'is_animated' => $attachment->is_animated,
                'duration' => $attachment->duration,
                // True when an image's bytes could not be parsed and it was degraded to a generic,
                // non-previewable file (dimensions null, file_type OTHER). Lets the client know the
                // upload succeeded but the file is not usable as an image / has no preview.
                'preview_unavailable' => $attachment->preview_unavailable,
            ],
        ];

        // Event: file.upload.response (filter) - Allow handlers to add to response
        $response_data = Rsx::trigger_filter('file.upload.response', $response_data);

        return response()->json($response_data);
    }

    // ============================================================================================
    // DOWNLOAD & VIEW
    // ============================================================================================

    /**
     * Download file as attachment (forces download dialog)
     *
     * Route: /_download/:key
     *
     * Security: Checks BOTH file.thumbnail.authorize AND file.download.authorize
     */
    #[Route('/_download/:key', methods: ['GET'])]
    public static function download_file(Request $request, array $params = [])
    {
        $key = $params['key'] ?? null;
        if (!$key) {
            abort(404, 'File not found');
        }

        $attachment = File_Attachment_Model::where('key', $key)->first();
        if (!$attachment) {
            abort(404, 'File not found');
        }

        // Event: file.thumbnail.authorize (gate) - Check thumbnail access first
        $thumbnail_auth = Rsx::trigger_gate('file.thumbnail.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ]);

        if ($thumbnail_auth !== true) {
            return $thumbnail_auth;
        }

        // Event: file.download.authorize (gate) - Then check download-specific access
        $download_auth = Rsx::trigger_gate('file.download.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ]);

        if ($download_auth !== true) {
            return $download_auth;
        }

        // Handler freshness: if the handler opts in and reports stale, evict so resolve_storage()
        // re-materializes fresh bytes below.
        $attachment->apply_serve_freshness();

        // A handler may fully take over serving (stream from the external service, or 302 to a
        // short-lived pre-authorized URL). Called AFTER auth gates.
        $override = $attachment->handler_serve_download($request);
        if ($override !== null) {
            return $override;
        }

        // Materialize external bytes if needed, then serve the resident blob.
        $storage = $attachment->resolve_storage();
        if (!file_exists($storage->get_full_path())) {
            abort(404, 'File not found on disk');
        }

        // Content-Type from the attachment's RAW SNIFFED mime_type (NOT pipeline_mime()) - serving
        // is a security boundary (stored-XSS via HTML-in-a-.pdf must serve as its sniffed type, not
        // the extension's claim). Omit if genuinely unknown so the browser can sniff.
        $headers = ['Cache-Control' => 'public, max-age=31536000']; // 1 year
        if (!empty($attachment->mime_type)) {
            $headers['Content-Type'] = $attachment->mime_type;
        }

        // Return file with attachment disposition
        return Response::download(
            $storage->get_full_path(),
            $attachment->file_name,
            $headers
        );
    }

    /**
     * View file inline (display in browser)
     *
     * Route: /_inline/:key
     *
     * Security: Checks BOTH file.thumbnail.authorize AND file.download.authorize
     */
    #[Route('/_inline/:key', methods: ['GET'])]
    public static function inline(Request $request, array $params = [])
    {
        $key = $params['key'] ?? null;
        if (!$key) {
            abort(404, 'File not found');
        }

        $attachment = File_Attachment_Model::where('key', $key)->first();
        if (!$attachment) {
            abort(404, 'File not found');
        }

        // Event: file.thumbnail.authorize (gate) - Check thumbnail access first
        $thumbnail_auth = Rsx::trigger_gate('file.thumbnail.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ]);

        if ($thumbnail_auth !== true) {
            return $thumbnail_auth;
        }

        // Event: file.download.authorize (gate) - Then check download-specific access
        $download_auth = Rsx::trigger_gate('file.download.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ]);

        if ($download_auth !== true) {
            return $download_auth;
        }

        // Handler freshness: if the handler opts in and reports stale, evict so resolve_storage()
        // re-materializes fresh bytes below.
        $attachment->apply_serve_freshness();

        // A handler may fully take over serving. Called AFTER auth gates.
        $override = $attachment->handler_serve_inline($request);
        if ($override !== null) {
            return $override;
        }

        // Materialize external bytes if needed, then serve the resident blob.
        $storage = $attachment->resolve_storage();
        if (!file_exists($storage->get_full_path())) {
            abort(404, 'File not found on disk');
        }

        // Content-Type from the attachment's RAW SNIFFED mime_type (NOT pipeline_mime()) - serving
        // is a security boundary; keep the conservative sniffed type. Omit if genuinely unknown so
        // the browser can sniff.
        $headers = [
            'Cache-Control' => 'public, max-age=31536000', // 1 year
            'Content-Disposition' => 'inline; filename="' . $attachment->file_name . '"',
        ];
        if (!empty($attachment->mime_type)) {
            $headers['Content-Type'] = $attachment->mime_type;
        }

        // Return file with inline disposition
        return Response::file(
            $storage->get_full_path(),
            $headers
        );
    }

    /**
     * Stream a multi-file ZIP download for a minted download request.
     *
     * Route: /_download_zip/:key (GET)
     *
     * The file set is NOT posted by the browser: server-side app code first records it
     * with Zip_Download_Request_Model::create_request($files, $zip_name) - authorizing
     * nothing at that point - and hands the browser the resulting download URL. The
     * browser simply navigates to it (window.location = url), so the response streams
     * natively as a download. Members are archived ONE AT A TIME with constant memory
     * (see Zip_Stream); a multi-gigabyte set never lands in memory.
     *
     * The :key resolves to a stored request. An unknown key or an expired request throws
     * one opaque invalid/expired message BEFORE anything streams. Rows are NOT consumed on
     * use (a browser may retry a download); they die by expiry.
     *
     * PHASE 1 validates EVERYTHING before a single byte streams:
     *   - the request resolves and is not expired;
     *   - every entry resolves to an attachment AND passes BOTH the thumbnail and
     *     download gates (identical cascade to download_file());
     *   - the archive fits inside the non-ZIP64 streaming envelope (4GB / 65k entries).
     * Any failure throws BEFORE streaming, so a denied request never yields partial
     * bytes. A single denied file fails the WHOLE request with one opaque message that
     * never reveals which file (or whether it merely does not exist). Authorization runs
     * per-file HERE against the downloading session, never at request creation.
     *
     * PHASE 2 streams via StreamedResponse. Handler serve-takeover
     * (handler_serve_download) is deliberately NOT consulted for zip members: a 302 or a
     * proxied stream cannot be a zip entry, so bytes ALWAYS materialize through
     * resolve_storage(). A member whose bytes cannot be resolved (external fetch failed,
     * blob missing) or whose stream truncates mid-way is replaced by a zero-byte
     * '~ERROR~<name>.inf' marker (directory prefix preserved) and logged, so the archive
     * stays well-formed and the user sees exactly which files failed.
     *
     * Security: Checks BOTH file.thumbnail.authorize AND file.download.authorize per
     * member, exactly as download_file() / inline() do.
     *
     * @param Request $request
     * @param array $params
     * @return StreamedResponse
     */
    #[Route('/_download_zip/:key', methods: ['GET'])]
    public static function download_multiple_zip(Request $request, array $params = [])
    {
        // -----------------------------------------------------------------------------
        // PHASE 1 - resolve the request, then validate and authorize everything up front
        // (no bytes streamed yet).
        // -----------------------------------------------------------------------------
        $key = $params['key'] ?? null;

        $download_request = is_string($key) ? Zip_Download_Request_Model::find_by_download_key($key) : null;
        if ($download_request === null || $download_request->is_expired()) {
            // OWNER NOTE: this exception is a PLACEHOLDER - a future internal user-feedback
            // error mechanism will replace it. It is user feedback (the link is no longer
            // usable), NOT a server fault, and stays opaque about whether the key was
            // unknown or merely expired.
            throw new \RuntimeException('This download link is invalid or has expired.');
        }

        $entries = $download_request->get_files();

        // The opaque denial message. OWNER NOTE: this exception is a PLACEHOLDER - a
        // future internal user-feedback error mechanism will replace it. It is user
        // feedback (a request was refused), NOT a server fault. It deliberately does not
        // reveal which file failed, or whether the file even exists.
        $denied_message = 'Access to one or more of the requested files was denied. Please log in.';

        $prepared = [];
        $sizes = [];
        $names = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['key']) || !is_string($entry['key'])) {
                throw new \RuntimeException($denied_message);
            }

            $attachment = File_Attachment_Model::find_by_key($entry['key']);
            if (!$attachment) {
                throw new \RuntimeException($denied_message);
            }

            // Cascading auth gates - identical order to download_file().
            $thumbnail_auth = Rsx::trigger_gate('file.thumbnail.authorize', [
                'attachment' => $attachment,
                'user' => Session::get_user(),
                'request' => $request,
            ]);
            if ($thumbnail_auth !== true) {
                throw new \RuntimeException($denied_message);
            }

            $download_auth = Rsx::trigger_gate('file.download.authorize', [
                'attachment' => $attachment,
                'user' => Session::get_user(),
                'request' => $request,
            ]);
            if ($download_auth !== true) {
                throw new \RuntimeException($denied_message);
            }

            $provided = (isset($entry['name']) && is_string($entry['name'])) ? $entry['name'] : null;
            $name = static::_sanitize_zip_entry_name($provided, (string) $attachment->file_name);

            $prepared[] = ['attachment' => $attachment, 'name' => $name];
            $sizes[] = $attachment->get_size();
            $names[] = $name;
        }

        // ZIP64 envelope guard (the writer supports neither >4GB offsets nor >65k entries).
        $zip64_error = static::_zip64_limit_error($sizes);
        if ($zip64_error !== null) {
            throw new \RuntimeException($zip64_error);
        }

        // De-duplicate colliding archive names across the whole set (report.pdf -> report (2).pdf).
        $deduped = static::_dedupe_zip_names($names);
        foreach ($prepared as $i => $unused) {
            $prepared[$i]['name'] = $deduped[$i];
        }

        $zip_name = static::_sanitize_zip_filename($download_request->zip_name);

        // -----------------------------------------------------------------------------
        // PHASE 2 - stream the archive one member at a time.
        // -----------------------------------------------------------------------------
        $response = new StreamedResponse(function () use ($prepared) {
            set_time_limit(0);

            // Streaming requires bytes to leave PHP as they are produced - drain any
            // output buffers so nothing is held back (nginx buffering is disabled via
            // the X-Accel-Buffering header set below).
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $zip = new Zip_Stream();
            $zip->open();

            foreach ($prepared as $member) {
                /** @var File_Attachment_Model $attachment */
                $attachment = $member['attachment'];
                $name = $member['name'];

                // THE single sanctioned catch: materializing external bytes is an
                // expected-failure path (the remote fetch may fail, the blob may be
                // gone). On failure we log and emit the error marker instead of aborting
                // the stream (which would corrupt an already-partly-written archive).
                try {
                    $attachment->apply_serve_freshness();
                    $path = $attachment->resolve_storage()->get_full_path();
                } catch (\Throwable $e) {
                    Log::error(
                        "download_multiple_zip: could not resolve bytes for attachment {$attachment->id}: " . $e->getMessage()
                    );
                    $zip->add_empty_entry(static::_error_marker_name($name));
                    continue;
                }

                if (!file_exists($path)) {
                    Log::error("download_multiple_zip: resolved path missing for attachment {$attachment->id}: {$path}");
                    $zip->add_empty_entry(static::_error_marker_name($name));
                    continue;
                }

                // Per-entry Content-Type from the RAW SNIFFED mime_type (serve-time security
                // boundary, not pipeline_mime()).
                $complete = $zip->add_file_from_path($name, $path, $attachment->mime_type);
                if (!$complete) {
                    Log::error("download_multiple_zip: stream truncated for attachment {$attachment->id} ({$name})");
                    $zip->add_empty_entry(static::_error_marker_name($name));
                }
            }

            $zip->finish();
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $zip_name . '"');
        // Defeat nginx proxy buffering so the archive streams to the client immediately.
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    // ============================================================================================
    // ZIP DOWNLOAD HELPERS (pure - unit-testable seams for download_multiple_zip)
    // ============================================================================================

    /**
     * Resolve the archive-internal name for one member. A caller-provided name may carry
     * a directory prefix, but anything unsafe degrades SILENTLY to the attachment's own
     * file_name (never throws - a bad custom name must not fail the whole download).
     *
     * Rejected (-> attachment file_name): backslashes, control characters, any '..'
     * segment, and a value that cleans to empty. Sanitized: leading slashes stripped,
     * duplicate slashes collapsed.
     *
     * @param string|null $provided        Caller-supplied name (may be null).
     * @param string      $attachment_name The attachment's own file_name (the safe default).
     * @return string
     */
    public static function _sanitize_zip_entry_name(?string $provided, string $attachment_name): string
    {
        $safe_default = static::__clean_bare_filename($attachment_name);

        if ($provided === null || $provided === '') {
            return $safe_default;
        }

        // Reject backslashes and control characters outright.
        if (strpos($provided, '\\') !== false) {
            return $safe_default;
        }
        for ($i = 0, $len = strlen($provided); $i < $len; $i++) {
            $ord = ord($provided[$i]);
            if ($ord < 0x20 || $ord === 0x7f) {
                return $safe_default;
            }
        }

        // Strip leading slashes and collapse duplicate slashes.
        $clean = ltrim($provided, '/');
        while (strpos($clean, '//') !== false) {
            $clean = str_replace('//', '/', $clean);
        }
        $clean = trim($clean, '/');

        if ($clean === '') {
            return $safe_default;
        }

        // Reject any parent-directory traversal segment.
        foreach (explode('/', $clean) as $segment) {
            if ($segment === '..') {
                return $safe_default;
            }
        }

        return $clean;
    }

    /**
     * Reduce a value to a safe bare filename (no directory components, no control
     * characters). Used as the degraded target for an invalid custom entry name.
     *
     * @param string $name
     * @return string
     */
    private static function __clean_bare_filename(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));

        $out = '';
        for ($i = 0, $len = strlen($base); $i < $len; $i++) {
            $ord = ord($base[$i]);
            if ($ord >= 0x20 && $ord !== 0x7f) {
                $out .= $base[$i];
            }
        }

        $out = trim($out);

        return $out === '' ? 'file' : $out;
    }

    /**
     * De-duplicate colliding archive names across the ordered member set. A repeated name
     * gains a ' (2)', ' (3)', ... suffix inserted before its extension, preserving any
     * directory prefix ('docs/report.pdf' -> 'docs/report (2).pdf').
     *
     * @param string[] $names
     * @return string[] Same order, collisions resolved.
     */
    public static function _dedupe_zip_names(array $names): array
    {
        $seen = [];
        $result = [];

        foreach ($names as $name) {
            $candidate = $name;
            $n = 2;
            while (isset($seen[$candidate])) {
                $candidate = static::__append_dedupe_suffix($name, $n);
                $n++;
            }
            $seen[$candidate] = true;
            $result[] = $candidate;
        }

        return $result;
    }

    /**
     * Insert a ' (n)' disambiguating suffix before the extension of a name's basename,
     * preserving any directory prefix.
     *
     * @param string $name
     * @param int    $n
     * @return string
     */
    private static function __append_dedupe_suffix(string $name, int $n): string
    {
        $slash = strrpos($name, '/');
        $dir = $slash === false ? '' : substr($name, 0, $slash + 1);
        $base = $slash === false ? $name : substr($name, $slash + 1);

        $dot = strrpos($base, '.');
        if ($dot === false || $dot === 0) {
            // No extension (or a dotfile) - append at the end.
            return $dir . $base . ' (' . $n . ')';
        }

        $stem = substr($base, 0, $dot);
        $ext = substr($base, $dot);

        return $dir . $stem . ' (' . $n . ')' . $ext;
    }

    /**
     * Build the zero-byte error-marker name for a member that could not be streamed:
     * the basename becomes '~ERROR~<basename>.inf', preserving any directory prefix
     * ('reports/q2.pdf' -> 'reports/~ERROR~q2.pdf.inf').
     *
     * @param string $name
     * @return string
     */
    public static function _error_marker_name(string $name): string
    {
        $slash = strrpos($name, '/');
        if ($slash === false) {
            return '~ERROR~' . $name . '.inf';
        }

        $dir = substr($name, 0, $slash + 1);
        $base = substr($name, $slash + 1);

        return $dir . '~ERROR~' . $base . '.inf';
    }

    /**
     * Sanitize the download filename for the Content-Disposition header: drop path
     * components, strip control characters and double-quotes (header safety), and ensure
     * a '.zip' suffix. Empty / missing input yields 'download.zip'.
     *
     * @param string|null $zip_name
     * @return string
     */
    public static function _sanitize_zip_filename(?string $zip_name): string
    {
        if (!is_string($zip_name) || $zip_name === '') {
            return 'download.zip';
        }

        $name = basename(str_replace('\\', '/', $zip_name));

        $out = '';
        for ($i = 0, $len = strlen($name); $i < $len; $i++) {
            $ord = ord($name[$i]);
            if ($ord >= 0x20 && $ord !== 0x7f && $name[$i] !== '"') {
                $out .= $name[$i];
            }
        }

        $out = trim($out);
        if ($out === '') {
            return 'download.zip';
        }

        if (strtolower(substr($out, -4)) !== '.zip') {
            $out .= '.zip';
        }

        return $out;
    }

    /**
     * The non-ZIP64 streaming-envelope guard. Returns an error message if the requested
     * set exceeds what the streaming writer can encode without ZIP64 support, else null.
     * file_size is authoritative even for external (non-resident) attachments.
     *
     * Limits: total size > 4,000,000,000 bytes (4GB, with margin for headers/descriptors),
     * any single file >= 0xFFFFFFFF, or more than 65,000 entries.
     *
     * @param int[] $sizes Per-member file sizes in bytes.
     * @return string|null Error message if over-limit, else null.
     */
    public static function _zip64_limit_error(array $sizes): ?string
    {
        $limit_message = 'Requested archive exceeds the 4GB/65k-entry streaming limit';

        if (count($sizes) > 65000) {
            return $limit_message;
        }

        $total = 0;
        foreach ($sizes as $size) {
            $size = (int) $size;
            if ($size >= 0xFFFFFFFF) {
                return $limit_message;
            }
            $total += $size;
        }

        if ($total > 4000000000) {
            return $limit_message;
        }

        return null;
    }

    // ============================================================================================
    // THUMBNAILS
    // ============================================================================================

    /**
     * Generate and serve thumbnail with caching
     *
     * Common logic for both preset and dynamic thumbnails.
     *
     * @param File_Attachment_Model $attachment
     * @param string $type Thumbnail type: 'cover' or 'fit'
     * @param int $width Width in pixels
     * @param int|null $height Height in pixels
     * @param string $cache_type Either 'preset' or 'dynamic'
     * @param string $cache_filename Cache filename
     * @return Response
     */
    protected static function __generate_and_serve_thumbnail(
        $attachment,
        $type,
        $width,
        $height,
        $cache_type,
        $cache_filename
    ) {
        // Get storage file (materializing external bytes on demand). Handlers never affect
        // thumbnails - resolve_storage() is the only handler touchpoint here.
        $storage = $attachment->resolve_storage();
        if (!file_exists($storage->get_full_path())) {
            abort(404, 'File not found on disk');
        }

        // Build cache path
        $cache_path = static::_get_cache_path($cache_type, $cache_filename);

        // Check cache
        if (file_exists($cache_path)) {
            $response = static::_serve_cached_thumbnail($cache_path);
            if ($response !== null) {
                return $response;
            }
            // If null, file was deleted (race condition) - fall through to regeneration
        }

        // Produce the thumbnail bytes via the renderer registry (icon substitution lives inside).
        $thumbnail_data = static::_render_thumbnail_data(
            $attachment,
            $storage->get_full_path(),
            $type,
            $width,
            $height
        );

        // Save to cache
        static::_save_thumbnail_to_cache($cache_path, $thumbnail_data);

        // Enforce quota only for dynamic thumbnails
        if ($cache_type === 'dynamic') {
            static::_enforce_dynamic_quota();
        }

        // Return thumbnail (serve from memory, don't re-read from disk)
        return Response::make($thumbnail_data, 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000', // 1 year
        ]);
    }

    /**
     * Generate preset thumbnail for image attachments
     *
     * Route: /_thumbnail/preset/:key/:preset_name
     *
     * Security: Checks file.thumbnail.authorize only
     *
     * @param string $key Attachment key
     * @param string $preset_name Preset name from config
     */
    #[Route('/_thumbnail/preset/:key/:preset_name', methods: ['GET'])]
    public static function thumbnail_preset(Request $request, array $params = [])
    {
        $key = $params['key'] ?? null;
        $preset_name = $params['preset_name'] ?? null;

        // Validate inputs
        if (!$key || !$preset_name) {
            abort(400, 'Invalid parameters');
        }

        // Look up preset definition
        $presets = config('rsx.thumbnails.presets', []);
        if (!isset($presets[$preset_name])) {
            abort(404, "Thumbnail preset '{$preset_name}' not defined");
        }

        $preset = $presets[$preset_name];
        $type = $preset['type'];
        $width = $preset['width'];
        $height = $preset['height'] ?? null;

        // Find attachment
        $attachment = File_Attachment_Model::where('key', $key)->first();
        if (!$attachment) {
            abort(404, 'File not found');
        }

        // Event: file.thumbnail.authorize (gate) - Check thumbnail access
        $thumbnail_auth = Rsx::trigger_gate('file.thumbnail.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ]);

        if ($thumbnail_auth !== true) {
            return $thumbnail_auth;
        }

        // Generate cache filename (materialize external bytes to obtain the content hash).
        $cache_filename = static::_get_cache_filename_preset(
            $preset_name,
            $attachment->resolve_storage()->hash,
            $attachment->file_extension
        );

        // Generate and serve
        return static::__generate_and_serve_thumbnail(
            $attachment,
            $type,
            $width,
            $height,
            'preset',
            $cache_filename
        );
    }

    /**
     * Generate dynamic thumbnail for image attachments
     *
     * Route: /_thumbnail/dynamic/:key/:type/:width/:height?
     *
     * Security: Checks file.thumbnail.authorize only
     *
     * @param string $key    Attachment key
     * @param string $type   Thumbnail type: 'cover' or 'fit'
     * @param int    $width  Thumbnail width in pixels
     * @param int    $height Optional thumbnail height in pixels
     */
    #[Route('/_thumbnail/dynamic/:key/:type/:width/:height?', methods: ['GET'])]
    public static function thumbnail(Request $request, array $params = [])
    {
        $key = $params['key'] ?? null;
        $type = $params['type'] ?? 'fit';
        $width = (int)($params['width'] ?? 0);
        $height = isset($params['height']) ? (int)$params['height'] : null;

        // Validate inputs
        if (!$key || !in_array($type, ['cover', 'fit'])) {
            abort(400, 'Invalid parameters');
        }

        // Enforce minimum dimensions
        if ($width < 10) {
            $width = 10;
        }
        if ($height !== null && $height < 10) {
            $height = 10;
        }

        // Enforce maximum dimensions (configurable, base resolution before 2x scaling)
        $max_size = config('rsx.thumbnails.max_dynamic_size', 800);
        if ($width > $max_size) {
            abort(400, "Width must be between 10 and {$max_size}");
        }

        if ($height !== null && $height > $max_size) {
            abort(400, "Height must be between 10 and {$max_size}");
        }

        // Find attachment
        $attachment = File_Attachment_Model::where('key', $key)->first();
        if (!$attachment) {
            abort(404, 'File not found');
        }

        // Event: file.thumbnail.authorize (gate) - Check thumbnail access
        $thumbnail_auth = Rsx::trigger_gate('file.thumbnail.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ]);

        if ($thumbnail_auth !== true) {
            return $thumbnail_auth;
        }

        // Generate cache filename (materialize external bytes to obtain the content hash).
        $cache_filename = static::_get_cache_filename_dynamic(
            $type,
            $width,
            $height ?? $width,
            $attachment->resolve_storage()->hash,
            $attachment->file_extension
        );

        // Generate and serve
        return static::__generate_and_serve_thumbnail(
            $attachment,
            $type,
            $width,
            $height,
            'dynamic',
            $cache_filename
        );
    }

    // ============================================================================================
    // THUMBNAIL RENDERER REGISTRY
    // ============================================================================================

    /**
     * Resolve the thumbnail renderer (simple class name) registered for a mime type, or null if
     * none - in which case the pipeline falls back to a generic extension icon.
     *
     * Reads config('rsx.thumbnails.renderers') (an ordered map of fnmatch mime pattern => simple
     * renderer class; first match wins). Also enforces the LibreOffice master switch: when
     * rsx.libreoffice.enabled is false, the LibreOffice renderer is treated as unregistered so
     * document thumbnails silently use the icon (and has_thumbnail() reports false).
     *
     * @param string|null $mime
     * @return string|null Simple renderer class name, or null.
     */
    public static function renderer_class_for_mime(?string $mime): ?string
    {
        if ($mime === null || $mime === '') {
            return null;
        }

        $renderers = config('rsx.thumbnails.renderers', []);

        $matched = null;
        foreach ($renderers as $pattern => $class) {
            if (fnmatch($pattern, $mime)) {
                $matched = $class;
                break;
            }
        }

        if ($matched === null) {
            return null;
        }

        // Master switch: LibreOffice disabled => no renderer for its mimes (silent icon).
        if ($matched === 'Libreoffice_Thumbnail_Renderer' && !config('rsx.libreoffice.enabled', true)) {
            return null;
        }

        return $matched;
    }

    /**
     * Produce thumbnail WebP bytes for an attachment: dispatch to the registered renderer for its
     * mime type, then resize/crop/encode. On any renderer failure (or when the source exceeds
     * renderer_max_bytes for a non-image type) the pipeline substitutes an explicit generic
     * extension icon, with a logged error - that substitution lives HERE in the pipeline, never
     * inside a renderer.
     *
     * Shared by the serve path and the Thumbnails_Generate_Command (cache warming).
     *
     * @param File_Attachment_Model $attachment
     * @param string   $source_path Resident blob path.
     * @param string   $type        'cover' or 'fit'
     * @param int      $width
     * @param int|null $height
     * @return string WebP binary data.
     */
    public static function _render_thumbnail_data($attachment, string $source_path, string $type, int $width, ?int $height): string
    {
        // App thumbnail resolve chain (document.thumbnail_render). Contract:
        //   null                     = decline (fall through to the framework renderer registry)
        //   string                   = WebP bytes, returned as-is
        //   ['unsupported' => true]  = render the generic extension icon (same as the catch branch)
        // Anything else is a handler bug -> fail loud.
        $resolved = Rsx::trigger_resolve('document.thumbnail_render', [
            'attachment' => $attachment,
            'path' => $source_path,
            'type' => $type,
            'width' => $width,
            'height' => $height,
        ]);

        if ($resolved !== null) {
            if (is_string($resolved)) {
                return $resolved;
            }

            if (is_array($resolved) && ($resolved['unsupported'] ?? null) === true) {
                return File_Attachment_Icons::render_icon_as_thumbnail(
                    $attachment->file_extension,
                    $width,
                    $height ?? $width
                );
            }

            shouldnt_happen(
                'document.thumbnail_render handler returned an out-of-contract value for attachment '
                . "{$attachment->key}: expected null | string | [unsupported=>true], got " . gettype($resolved)
            );
        }

        // Route on the PIPELINE mime (extension-first for documents) so a zip-sniffed OOXML doc
        // resolves the LibreOffice renderer instead of a generic icon.
        $pipeline_mime = $attachment->pipeline_mime();
        $renderer_simple = static::renderer_class_for_mime($pipeline_mime);

        // Belt-and-suspenders: any raster image (by file_type) renders via Imagick even if its
        // mime_type is missing - preserves the historic image thumbnail path exactly.
        if ($renderer_simple === null && $attachment->is_image()) {
            $renderer_simple = 'Imagick_Thumbnail_Renderer';
        }

        if ($renderer_simple !== null) {
            // Size cap for heavy document renderers only - images are never capped (the historic
            // path had no size cap; preserving byte-identical behavior).
            $cap = config('rsx.thumbnails.renderer_max_bytes');
            $is_image_mime = str_starts_with($pipeline_mime, 'image/');

            if ($cap !== null && !$is_image_mime && $renderer_simple !== 'Imagick_Thumbnail_Renderer' && $attachment->get_size() > $cap) {
                error_log("Thumbnail renderer skipped for attachment {$attachment->key}: source size {$attachment->get_size()} exceeds renderer_max_bytes {$cap}");
            } else {
                try {
                    $fqcn = static::__resolve_renderer_fqcn($renderer_simple);
                    $max = config('rsx.thumbnails.max_dynamic_size', 800) * 2;
                    $image = $fqcn::render($source_path, $max, $max);

                    return static::__generate_thumbnail($image, $type, $width, $height);
                } catch (\Throwable $e) {
                    error_log("Thumbnail renderer '{$renderer_simple}' failed for attachment {$attachment->key}: " . $e->getMessage());
                    // Explicit extension-icon substitution below.
                }
            }
        }

        return File_Attachment_Icons::render_icon_as_thumbnail(
            $attachment->file_extension,
            $width,
            $height ?? $width
        );
    }

    /**
     * Resolve a simple renderer class name to its FQCN via the manifest (renderers may be app-
     * defined). Hard error if not found.
     *
     * @param string $simple_name
     * @return string Fully-qualified renderer class name.
     */
    protected static function __resolve_renderer_fqcn(string $simple_name): string
    {
        $metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_class($simple_name);
        $fqcn = $metadata['fqcn'] ?? null;
        if ($fqcn === null) {
            shouldnt_happen("Thumbnail renderer '{$simple_name}' not found in manifest");
        }

        return $fqcn;
    }

    /**
     * Generate thumbnail image using Imagick
     *
     * Applies 2x resolution scaling for HiDPI displays, but caps at 66% of source
     * image dimensions to avoid excessive upscaling. Output aspect ratio always
     * matches requested dimensions, but actual resolution may be lower.
     *
     * Takes an already-loaded Imagick raster handle (produced by a thumbnail renderer - see
     * _render_thumbnail_data / the renderer registry) rather than a path, so the "produce a
     * raster from bytes" step is pluggable while this resize/crop/encode step stays fixed. The
     * handle is consumed (destroyed) by this method.
     *
     * @param \Imagick $image  Source raster handle (owned/destroyed here).
     * @param string   $type   Thumbnail type: 'cover' or 'fit'
     * @param int      $width  Target width
     * @param int|null $height Target height (null = maintain aspect ratio)
     * @return string  Binary image data (WebP format)
     */
    protected static function __generate_thumbnail(
        \Imagick $image,
        string $type,
        int $width,
        ?int $height = null
    ): string {
        // Get original dimensions
        $original_width = $image->getImageWidth();
        $original_height = $image->getImageHeight();

        // Calculate height if not provided (maintain aspect ratio)
        if ($height === null) {
            $height = (int)round(($width / $original_width) * $original_height);
        }

        // Apply 2x scaling for HiDPI displays
        $target_width = $width * 2;
        $target_height = $height * 2;

        // Calculate 66% threshold of source dimensions
        $max_width = (int)round($original_width * 0.66);
        $max_height = (int)round($original_height * 0.66);

        // If target exceeds 66% of source on either dimension, cap at source dimensions
        if ($target_width > $max_width || $target_height > $max_height) {
            $target_width = $original_width;
            $target_height = $original_height;
        }

        // Constrain to configured max (doubled for 2x scaling)
        // Default: 800 base → 1600 max after 2x
        $max_size = config('rsx.thumbnails.max_dynamic_size', 800) * 2;
        if ($target_width > $max_size || $target_height > $max_size) {
            if ($target_width > $target_height) {
                $target_height = (int)round(($target_height / $target_width) * $max_size);
                $target_width = $max_size;
            } else {
                $target_width = (int)round(($target_width / $target_height) * $max_size);
                $target_height = $max_size;
            }
        }

        // Use target dimensions for actual generation
        $width = $target_width;
        $height = $target_height;

        if ($type === 'cover') {
            // Cover: Fill area completely, crop excess
            // Calculate aspect ratios
            $source_ratio = $original_width / $original_height;
            $target_ratio = $width / $height;

            if ($source_ratio > $target_ratio) {
                // Source is wider - scale to height, crop width
                $resize_height = $height;
                $resize_width = (int)round($height * $source_ratio);
            } else {
                // Source is taller - scale to width, crop height
                $resize_width = $width;
                $resize_height = (int)round($width / $source_ratio);
            }

            // Resize
            $image->resizeImage($resize_width, $resize_height, \Imagick::FILTER_LANCZOS, 1);

            // Crop to exact dimensions (center crop)
            $offset_x = (int)round(($resize_width - $width) / 2);
            $offset_y = (int)round(($resize_height - $height) / 2);
            $image->cropImage($width, $height, $offset_x, $offset_y);

        } else {
            // Fit: Maintain aspect ratio within bounds, transparent background
            // Calculate scaling to fit within bounds
            $scale_width = $width / $original_width;
            $scale_height = $height / $original_height;
            $scale = min($scale_width, $scale_height);

            $new_width = (int)round($original_width * $scale);
            $new_height = (int)round($original_height * $scale);

            // Resize image
            $image->resizeImage($new_width, $new_height, \Imagick::FILTER_LANCZOS, 1);

            // Create transparent canvas
            $canvas = new \Imagick();
            $canvas->newImage($width, $height, new \ImagickPixel('transparent'));
            $canvas->setImageFormat('webp');

            // Center the resized image on canvas
            $offset_x = (int)round(($width - $new_width) / 2);
            $offset_y = (int)round(($height - $new_height) / 2);
            $canvas->compositeImage($image, \Imagick::COMPOSITE_OVER, $offset_x, $offset_y);

            $image->destroy();
            $image = $canvas;
        }

        // Set image format to WebP
        $image->setImageFormat('webp');

        // Set quality
        $image->setImageCompressionQuality(85);

        // Get binary data
        $thumbnail_data = $image->getImageBlob();

        // Clean up
        $image->destroy();

        return $thumbnail_data;
    }

    // ============================================================================================
    // THUMBNAIL CACHING HELPERS
    // ============================================================================================

    /**
     * Generate cache filename for preset thumbnail
     *
     * Internal helper - do not call directly from application code.
     *
     * Format: {preset_name}_{hash}_{ext}.webp
     *
     * @param string $preset_name Preset name from config
     * @param string $hash File storage hash
     * @param string $extension File extension (normalized)
     * @return string Cache filename
     */
    public static function _get_cache_filename_preset($preset_name, $hash, $extension)
    {
        return "{$preset_name}_{$hash}_{$extension}.webp";
    }

    /**
     * Generate cache filename for dynamic thumbnail
     *
     * Internal helper - do not call directly from application code.
     *
     * Format: {type}_{width}x{height}_{hash}_{ext}.webp
     *
     * @param string $type Thumbnail type (cover or fit)
     * @param int $width Width in pixels
     * @param int $height Height in pixels
     * @param string $hash File storage hash
     * @param string $extension File extension (normalized)
     * @return string Cache filename
     */
    public static function _get_cache_filename_dynamic($type, $width, $height, $hash, $extension)
    {
        return "{$type}_{$width}x{$height}_{$hash}_{$extension}.webp";
    }

    /**
     * Get full cache path for thumbnail
     *
     * Internal helper - do not call directly from application code.
     *
     * @param string $cache_type Either 'preset' or 'dynamic'
     * @param string $filename Cache filename
     * @return string Full filesystem path
     */
    public static function _get_cache_path($cache_type, $filename)
    {
        return Rsx_File_Paths::thumbnails_root() . "/{$cache_type}/{$filename}";
    }

    /**
     * Serve cached thumbnail from disk with optional mtime touch
     *
     * Internal helper - do not call directly from application code.
     *
     * Handles race condition where file might be deleted between check and open.
     * Returns null if file cannot be opened (caller should regenerate).
     *
     * @param string $cache_path Full path to cached file
     * @return Response|null Response if successful, null if file unavailable
     */
    protected static function _serve_cached_thumbnail($cache_path)
    {
        // Attempt to open file
        $handle = @fopen($cache_path, 'r');

        if ($handle === false) {
            // File was deleted between exists check and open (race condition)
            return null;
        }

        fclose($handle);

        // Touch mtime if configured and old enough
        $touch_enabled = config('rsx.thumbnails.touch_on_read', true);
        $touch_interval = config('rsx.thumbnails.touch_interval', 600);

        if ($touch_enabled && $touch_interval > 0) {
            $mtime = filemtime($cache_path);
            $age = time() - $mtime;

            if ($age >= $touch_interval) {
                touch($cache_path);
            }
        }

        // Serve file
        return Response::file($cache_path, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000', // 1 year
        ]);
    }

    /**
     * Save generated thumbnail to cache
     *
     * Internal helper - do not call directly from application code.
     *
     * Creates directory if it doesn't exist.
     *
     * @param string $cache_path Full path where thumbnail should be saved
     * @param string $thumbnail_data Binary WebP data
     * @return void
     */
    public static function _save_thumbnail_to_cache($cache_path, $thumbnail_data)
    {
        // Ensure directory exists
        $dir = dirname($cache_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Save thumbnail
        file_put_contents_safe($cache_path, $thumbnail_data);
    }

    /**
     * Enforce quota for dynamic thumbnails
     *
     * Internal helper - do not call directly from application code.
     *
     * Scans dynamic thumbnail directory, calculates total size, and deletes
     * oldest files (by mtime) until under quota limit.
     *
     * Called synchronously after creating each new dynamic thumbnail.
     *
     * @return void
     */
    protected static function _enforce_dynamic_quota()
    {
        $max_bytes = config('rsx.thumbnails.quotas.dynamic_max_bytes');
        $dir = Rsx_File_Paths::thumbnails_root() . '/dynamic/';

        // Ensure directory exists
        if (!is_dir($dir)) {
            return;
        }

        // Calculate current usage
        $total_size = 0;
        $files = [];

        foreach (glob($dir . '*.webp') as $file) {
            $size = filesize($file);
            $mtime = filemtime($file);
            $total_size += $size;
            $files[] = ['path' => $file, 'size' => $size, 'mtime' => $mtime];
        }

        // Over quota? Delete oldest first
        if ($total_size > $max_bytes) {
            // Sort by mtime ascending (oldest first)
            usort($files, fn($a, $b) => $a['mtime'] <=> $b['mtime']);

            foreach ($files as $file) {
                @unlink($file['path']);
                $total_size -= $file['size'];

                if ($total_size <= $max_bytes) {
                    break;
                }
            }
        }
    }

    // ============================================================================================
    // ICON UTILITIES
    // ============================================================================================

    /**
     * Get file type icon as PNG by extension
     *
     * Route: /_icon_by_extension/:extension
     *
     * @param string $extension File extension (without dot)
     * @return Response PNG image data
     */
    #[Route('/_icon_by_extension/:extension', methods: ['GET'])]
    public static function icon_by_extension(Request $request, array $params = [])
    {
        $extension = $params['extension'] ?? '';
        $width = (int)($request->query('width', 64));
        $height = (int)($request->query('height', 64));

        // Validate extension is alphanumeric
        if (!ctype_alnum($extension)) {
            abort(400, 'Invalid extension');
        }

        // Enforce minimum dimensions
        if ($width < 10) {
            $width = 10;
        }
        if ($height < 10) {
            $height = 10;
        }

        // Enforce maximum dimensions
        if ($width > 256 || $height > 256) {
            abort(400, 'Dimensions must be between 10 and 256');
        }

        // Get icon as PNG
        $png_data = File_Attachment_Icons::get_icon_as_png($extension, $width, $height);

        // Return PNG with inline disposition
        return Response::make($png_data, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=86400', // 1 day
        ]);
    }
}
