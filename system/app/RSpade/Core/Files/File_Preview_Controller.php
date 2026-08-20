<?php

namespace App\RSpade\Core\Files;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Libreoffice;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * File_Preview_Controller
 *
 * Serves the document PREVIEW surface consumed by the Document_Preview component: a PDF rendition
 * of an attachment (for pdf.js), the lazily-loaded pdf.js module bytes, and the server-side viewer
 * resolution (get_preview_info). Byte content still routes through File_Attachment_Model:
 * resolve_storage() materializes external bytes; renditions are content-addressed and cached.
 *
 * AUTHORIZATION: the class gate is 'public'; per-file authorization runs through the
 * file.thumbnail.authorize / file.download.authorize event gates documented below. Those
 * hooks are the record layer (may this caller touch THIS attachment) and stay inline.
 *
 * ================================================================================================
 * ENDPOINTS
 * ================================================================================================
 *
 * GET /_preview/pdf/:key       - PDF rendition of the attachment (application/pdf, inline)
 * GET /_preview/pdfjs.mjs       - pdf.js main module bytes (lazy-loaded; NOT in any bundle)
 * GET /_preview/pdf_worker.mjs  - pdf.js worker module bytes (lazy-loaded)
 * POST (Ajax) get_preview_info  - {viewer, mime, file_name, extension, preview_unavailable, urls} for a Document_Preview
 *
 * ================================================================================================
 * FILTER / GATE CHAINS
 * ================================================================================================
 *
 * The rendition endpoint participates in TWO chains, in this order:
 *
 * 1. AUTHORIZATION GATE CASCADE (file.thumbnail.authorize -> file.download.authorize)
 *    A PDF rendition exposes the FULL document content, so it is gated exactly like inline() /
 *    download_file(): BOTH gates must return true. The thumbnail gate runs first (so thumbnail
 *    security automatically protects the rendition), then the download gate (stricter download-only
 *    rules). First non-true response denies. Payload for each:
 *        ['attachment' => File_Attachment_Model, 'user' => User|null, 'request' => Request]
 *    get_preview_info runs ONLY the thumbnail gate (metadata, not full content).
 *
 * 2. document.preview_rendition (RESOLVE - Rsx::trigger_resolve, first non-null handler wins)
 *    Lets an app override or extend how an attachment becomes a PDF rendition (e.g. an external
 *    conversion service, a per-tenant renderer). Runs AFTER the auth gates. Payload:
 *        ['attachment' => File_Attachment_Model, 'request' => Request]
 *    Return contract (enforced; anything else fails loud via shouldnt_happen):
 *        null                      DECLINE - fall through to the framework rendition pipeline.
 *        Response                  TAKEOVER - the handler serves the response directly.
 *        ['path' => <abs pdf>]     SERVE - the framework serves that PDF file inline.
 *        ['unsupported' => true]   415 - no PDF rendition available for this attachment.
 *    Example:
 *        #[OnEvent('document.preview_rendition')]
 *        public static function convert_cad($data) {
 *            if ($data['attachment']->file_extension !== 'dwg') return null; // decline
 *            return ['path' => Cad_Service::to_pdf($data['attachment'])];    // serve
 *        }
 *
 * FRAMEWORK RENDITION PIPELINE (when the resolve chain declines):
 *   - mime application/pdf                              -> serve the resident blob as-is.
 *   - mime in rsx.preview.convertible AND LibreOffice enabled
 *                                                       -> cached soffice->PDF rendition
 *                                                          (storage/rsx-renditions/{hash}.pdf).
 *   - anything else                                     -> 415.
 */
#[Auth('public')]
#[Auth_Realm('any')]
class File_Preview_Controller extends Rsx_Controller_Abstract
{
    /**
     * Shared cluster-wide soffice slot pool. HISTORICAL name - it means "libreoffice slots"
     * cluster-wide, shared with Libreoffice_Thumbnail_Renderer and Libreoffice_Text_Extractor
     * (one soffice invocation pool regardless of what the conversion targets).
     */
    protected const SEMAPHORE_NAME = 'libreoffice_thumbnail';

    // ============================================================================================
    // PDF RENDITION
    // ============================================================================================

    /**
     * Serve a PDF rendition of the attachment (the pdf.js document source).
     *
     * Route: /_preview/pdf/:key
     * Security: BOTH file.thumbnail.authorize AND file.download.authorize (rendition = full content).
     *
     * @param Request $request
     * @param array $params
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/_preview/pdf/:key', methods: ['GET'])]
    public static function pdf_rendition(Request $request, array $params = [])
    {
        $key = $params['key'] ?? null;
        if (!$key) {
            abort(404, 'File not found');
        }

        $attachment = File_Attachment_Model::where('key', $key)->first();
        if (!$attachment) {
            abort(404, 'File not found');
        }

        // Dual authorization gate cascade - identical to inline()/download_file().
        $thumbnail_auth = Rsx::trigger_gate('file.thumbnail.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ]);
        if ($thumbnail_auth !== true) {
            return $thumbnail_auth;
        }

        $download_auth = Rsx::trigger_gate('file.download.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ]);
        if ($download_auth !== true) {
            return $download_auth;
        }

        // App resolve chain (override/extend the rendition). Runs AFTER auth.
        $resolved = Rsx::trigger_resolve('document.preview_rendition', [
            'attachment' => $attachment,
            'request' => $request,
        ]);
        if ($resolved !== null) {
            return static::__apply_rendition_resolution($resolved, $attachment);
        }

        // Framework rendition pipeline. Route on the PIPELINE mime (extension-first for documents)
        // so a zip-sniffed OOXML doc is still recognized as convertible, not 415'd to Icon_Viewer.
        $mime = $attachment->pipeline_mime();

        // Already a PDF -> serve the resident (content-addressed) blob as-is.
        if ($mime === 'application/pdf') {
            $storage = $attachment->resolve_storage();
            $path = $storage->get_full_path();
            if (!file_exists($path)) {
                abort(404, 'File not found on disk');
            }

            return static::__serve_pdf($path, $attachment->file_name);
        }

        // Convertible office document + LibreOffice enabled -> cached soffice->PDF rendition.
        if (static::__is_convertible($mime) && config('rsx.libreoffice.enabled', true)) {
            $rendition_path = static::__resolve_rendition($attachment);

            return static::__serve_pdf($rendition_path, static::__pdf_filename($attachment->file_name));
        }

        // No PDF rendition is possible for this file type.
        abort(415, "No PDF preview available for this file type ({$mime}). Download the file to view it.");
    }

    /**
     * Apply a non-null result from the document.preview_rendition resolve chain, enforcing its
     * contract. See the class docblock for the full contract.
     *
     * @param mixed $resolved
     * @param File_Attachment_Model $attachment
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected static function __apply_rendition_resolution($resolved, File_Attachment_Model $attachment)
    {
        // TAKEOVER: the handler produced a full response (stream, redirect, etc).
        if ($resolved instanceof \Symfony\Component\HttpFoundation\Response) {
            return $resolved;
        }

        if (is_array($resolved)) {
            // SERVE: the handler produced a PDF file on disk for the framework to serve.
            if (isset($resolved['path'])) {
                $path = $resolved['path'];
                if (!is_string($path) || !file_exists($path)) {
                    shouldnt_happen('document.preview_rendition returned a path that does not exist: ' . var_export($path, true));
                }

                return static::__serve_pdf($path, static::__pdf_filename($attachment->file_name));
            }

            // 415: the handler declares this attachment has no rendition.
            if (($resolved['unsupported'] ?? null) === true) {
                abort(415, 'No PDF preview available for this file.');
            }
        }

        // Out of contract - a handler bug. Fail loud.
        shouldnt_happen(
            'document.preview_rendition handler returned an out-of-contract value for attachment '
            . "{$attachment->key}: expected null | Response | [path=>...] | [unsupported=>true], got " . gettype($resolved)
        );
    }

    /**
     * Resolve (converting + caching on miss) the PDF rendition of a convertible document, returning
     * the absolute path to the cached PDF. Content-addressed on the blob hash so identical bytes
     * convert exactly once; touch()'d on a cache hit for LRU freshness.
     *
     * @param File_Attachment_Model $attachment
     * @return string Absolute path to the cached PDF rendition.
     */
    protected static function __resolve_rendition(File_Attachment_Model $attachment): string
    {
        $storage = $attachment->resolve_storage();
        $source_path = $storage->get_full_path();
        if (!file_exists($source_path)) {
            abort(404, 'File not found on disk');
        }

        $dir = Rsx_File_Paths::renditions_root();
        $cache_path = static::rendition_cache_path($storage);

        if (file_exists($cache_path)) {
            // LRU: keep recently served renditions from being evicted.
            touch($cache_path);

            return $cache_path;
        }

        // Lazy-create the rendition cache directory.
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        static::__convert_to_pdf($source_path, (string) $attachment->file_extension, $cache_path);

        return $cache_path;
    }

    /**
     * The content-addressed cache path for an attachment's PDF rendition, derived from the
     * deduplicated blob hash so identical bytes convert exactly once. The same path is globbed by
     * File_Rendition_Service for LRU cleanup.
     *
     * @param File_Storage_Model $storage
     * @return string Absolute path (storage/rsx-renditions/{hash}.pdf).
     */
    public static function rendition_cache_path(File_Storage_Model $storage): string
    {
        return Rsx_File_Paths::renditions_root() . '/' . $storage->hash . '.pdf';
    }

    /**
     * Convert a source document to PDF via headless LibreOffice, publishing atomically into the
     * rendition cache. Shares the soffice binary-discovery routine and the cluster-wide concurrency
     * semaphore with the thumbnail renderer and text extractor (one soffice slot pool). Fail loud.
     *
     * @param string $source_path Resident blob path (extensionless, content-addressed).
     * @param string $extension The attachment's real extension (staged so soffice detects the format).
     * @param string $cache_path Destination path for the published PDF rendition.
     * @return void
     * @throws Exception
     */
    protected static function __convert_to_pdf(string $source_path, string $extension, string $cache_path): void
    {
        $soffice = Libreoffice::find_soffice();
        if ($soffice === null) {
            throw new Exception('LibreOffice (soffice) is not installed or not configured');
        }

        $timeout = (int) config('rsx.preview.rendition_timeout', 60);
        $max_concurrent = (int) config('rsx.libreoffice.max_concurrent', 2);
        $slot_wait = (int) config('rsx.libreoffice.slot_wait_timeout', 30);

        // One shared cluster-wide soffice slot pool (name shared with the thumbnail renderer + text
        // extractor). A crashed conversion's slot is freed the instant its process dies.
        $slot = RsxLocks::acquire_semaphore(static::SEMAPHORE_NAME, $max_concurrent, $slot_wait);
        if ($slot === null) {
            throw new Exception('LibreOffice concurrency slot unavailable');
        }

        // Private work dir - LibreOffice needs an isolated user profile and an output dir.
        $work_dir = sys_get_temp_dir() . '/rsx_soffice_pdf_' . bin2hex(random_bytes(8));
        if (!mkdir($work_dir, 0700, true) && !is_dir($work_dir)) {
            RsxLocks::release_semaphore($slot);
            throw new Exception("Failed to create LibreOffice work dir: {$work_dir}");
        }

        try {
            // Stage the extensionless blob under its real extension so soffice reliably detects the
            // input format; soffice names its output after this basename.
            $safe_ext = preg_replace('/[^a-z0-9]/i', '', $extension);
            if ($safe_ext === '') {
                $safe_ext = 'bin';
            }
            $staged = $work_dir . '/source.' . $safe_ext;
            if (!copy($source_path, $staged)) {
                throw new Exception('Failed to stage source document for PDF rendition');
            }

            $process = new Process([
                $soffice,
                '-env:UserInstallation=file://' . $work_dir . '/profile',
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $work_dir,
                $staged,
            ]);

            // Hard cap the conversion, measured from now (after slot acquisition).
            $process->setTimeout($timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new Exception('LibreOffice PDF conversion failed: ' . trim($process->getErrorOutput()));
            }

            $pdf_path = $work_dir . '/source.pdf';
            if (!file_exists($pdf_path)) {
                throw new Exception('LibreOffice produced no PDF rendition');
            }

            // Atomic publish: copy into a temp file BESIDE the cache path (same filesystem), then
            // rename over the destination. The work dir may be on a different filesystem, so a
            // direct rename from there is not guaranteed atomic.
            $tmp = $cache_path . '.tmp.' . bin2hex(random_bytes(4));
            if (!copy($pdf_path, $tmp)) {
                throw new Exception('Failed to write rendition temp file');
            }
            if (!rename($tmp, $cache_path)) {
                @unlink($tmp);
                throw new Exception('Failed to publish rendition file');
            }
        } finally {
            RsxLocks::release_semaphore($slot);
            static::__rmdir_recursive($work_dir);
        }
    }

    /**
     * Serve a PDF file inline with immutable long-cache headers (content-addressed renditions never
     * change for a given URL).
     *
     * @param string $path Absolute path to a PDF file.
     * @param string $file_name Display filename for the Content-Disposition header.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected static function __serve_pdf(string $path, string $file_name)
    {
        return Response::file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file_name . '"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * True if this mime is listed in rsx.preview.convertible (fnmatch globs).
     *
     * @param string|null $mime
     * @return bool
     */
    protected static function __is_convertible(?string $mime): bool
    {
        if ($mime === null || $mime === '') {
            return false;
        }

        foreach (config('rsx.preview.convertible', []) as $pattern) {
            if (fnmatch($pattern, $mime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Derive a ".pdf" display filename from the source filename (swap/append the extension).
     *
     * @param string $file_name
     * @return string
     */
    protected static function __pdf_filename(string $file_name): string
    {
        $base = pathinfo($file_name, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'document';
        }

        return $base . '.pdf';
    }

    // ============================================================================================
    // PDF.JS MODULE BYTES (lazy-loaded - NOT in any bundle)
    // ============================================================================================

    /**
     * Stream the pdf.js main module from node_modules. Clients append ?v=build_key for cache-busting.
     *
     * Route: /_preview/pdfjs.mjs
     *
     * @param Request $request
     * @param array $params
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/_preview/pdfjs.mjs', methods: ['GET'])]
    public static function pdfjs(Request $request, array $params = [])
    {
        return static::__serve_module(base_path('node_modules/pdfjs-dist/build/pdf.min.mjs'));
    }

    /**
     * Stream the pdf.js worker module from node_modules. Clients append ?v=build_key.
     *
     * Route: /_preview/pdf_worker.mjs
     *
     * @param Request $request
     * @param array $params
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/_preview/pdf_worker.mjs', methods: ['GET'])]
    public static function pdf_worker(Request $request, array $params = [])
    {
        return static::__serve_module(base_path('node_modules/pdfjs-dist/build/pdf.worker.min.mjs'));
    }

    /**
     * Serve an ES module file with a long cache, or a 404 with actionable remediation when the
     * pdfjs-dist package is not installed.
     *
     * @param string $path
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected static function __serve_module(string $path)
    {
        if (!file_exists($path)) {
            return Response::make(
                'pdfjs-dist not installed - run npm install in system/',
                404,
                ['Content-Type' => 'text/plain']
            );
        }

        return Response::file($path, [
            'Content-Type' => 'text/javascript',
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    // ============================================================================================
    // PREVIEW INFO (server-side viewer resolution)
    // ============================================================================================

    /**
     * Resolve everything the Document_Preview component needs to render a viewer for an attachment:
     * the viewer component name (first fnmatch match in rsx.preview.viewers), the mime/name/
     * extension, and the URLs it will fetch. Viewer resolution is server-side; no config is exported.
     *
     * Security: file.thumbnail.authorize gate (metadata, not full content - same as thumbnails).
     *
     * @param Request $request
     * @param array $params Requires attachment_id.
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_preview_info(Request $request, array $params = [])
    {
        $attachment_id = $params['attachment_id'] ?? null;
        if (!$attachment_id) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'attachment_id is required');
        }

        $attachment = File_Attachment_Model::find($attachment_id);
        if (!$attachment) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, 'Attachment not found');
        }

        // Authorization: thumbnail gate (same payload shape as the thumbnail handlers).
        //
        // 'user' is realm-honest. This endpoint is #[Auth_Realm('any')], so a portal page's
        // <Document_Preview> reaches it as a genuine PORTAL request; handing the app's gate
        // a staff-facade read there gives it either null or - in prefix mode, where the
        // browser still carries the staff cookie - the STAFF user, which would authorize a
        // portal viewer against staff permissions. Same idiom as /_upload. See
        // docs.dev/audits/portal_realm_session_audit_2026_08_09.md.
        $thumbnail_auth = Rsx::trigger_gate('file.thumbnail.authorize', [
            'attachment' => $attachment,
            'user' => \App\RSpade\Core\Portal\Rsx_Portal::is_portal_request()
                ? \App\RSpade\Core\Portal\Portal_Session::get_portal_user()
                : Session::get_user(),
            'request' => $request,
        ]);
        if ($thumbnail_auth !== true) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED, 'Not authorized to preview this file');
        }

        return [
            // Viewer routes on the PIPELINE mime (extension-first for documents) so a zip-sniffed
            // docx resolves to Pdf_Viewer, not Icon_Viewer. 'mime' echoes the raw sniff (metadata).
            'viewer' => static::viewer_for_mime($attachment->pipeline_mime()),
            'mime' => $attachment->mime_type,
            'file_name' => $attachment->file_name,
            'extension' => $attachment->file_extension,
            // An unparseable image degraded to a generic file: the viewer already resolves to
            // Icon_Viewer (pipeline_mime -> octet-stream), but this lets Document_Preview render an
            // explicit "Preview unavailable" state rather than a bare file icon.
            'preview_unavailable' => $attachment->preview_unavailable,
            'urls' => [
                'rendition' => Rsx::Route('File_Preview_Controller::pdf_rendition', ['key' => $attachment->key]),
                'inline' => $attachment->get_url(),
                'icon' => Rsx::Route('File_Attachment_Controller::icon_by_extension', ['extension' => $attachment->file_extension]),
            ],
        ];
    }

    /**
     * Resolve the viewer component (simple name) for a mime from rsx.preview.viewers (fnmatch, first
     * match wins). The config's trailing '*' entry (Icon_Viewer) guarantees a terminal result.
     *
     * @param string|null $mime
     * @return string Viewer component simple name.
     */
    public static function viewer_for_mime(?string $mime): string
    {
        $mime = ($mime === null || $mime === '') ? 'application/octet-stream' : $mime;

        foreach (config('rsx.preview.viewers', []) as $pattern => $viewer) {
            if (fnmatch($pattern, $mime)) {
                return $viewer;
            }
        }

        // The config ships a terminal '*' entry, so this is only reached on a misconfigured map.
        return 'Icon_Viewer';
    }

    // ============================================================================================
    // INTERNAL
    // ============================================================================================

    /**
     * Recursively remove a directory tree (best-effort cleanup of the temp work dir).
     *
     * @param string $dir
     * @return void
     */
    protected static function __rmdir_recursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                static::__rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
