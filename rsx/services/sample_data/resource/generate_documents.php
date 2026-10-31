<?php
/**
 * Generate the sample documents the seeder attaches - a DEVELOPER tool, run by hand when
 * sample_data.json gains or changes a document:
 *
 *     php rsx/services/sample_data/resource/generate_documents.php
 *
 * Reads every clients[].documents[] entry of sample_data.json and writes the file into
 * resource/documents/: pdf, png and jpg through the shipped headless Chromium
 * (render_html.js), docx through LibreOffice, xlsx through PhpSpreadsheet. The output is
 * committed; the seeder only ever reads it, so seeding needs none of these tools.
 * Rewrites every file each run. Output is visually stable but not byte-identical
 * (the converters stamp creation times), so commit what you meant to change.
 */

$app = require __DIR__ . '/../../../../system/script.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Process\Process;

$resource = __DIR__;
$out_dir = $resource . '/documents';
$data = json_decode((string) file_get_contents($resource . '/sample_data.json'), true, 512, JSON_THROW_ON_ERROR);

$e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

/** A letterhead page: the client's name, the document title and its paragraphs. */
$page_html = function (array $client, array $doc) use ($e): string {
    $paragraphs = '';
    foreach ($doc['body'] as $paragraph) {
        $paragraphs .= '<p>' . $e($paragraph) . '</p>';
    }

    return '<!doctype html><html><head><meta charset="utf-8"><style>
        body { font-family: Georgia, "Times New Roman", serif; color: #1f2933; margin: 0; }
        .head { border-bottom: 3px solid #3b5b7a; padding-bottom: 10px; margin-bottom: 28px; }
        .org { font-family: Helvetica, Arial, sans-serif; font-size: 13px; letter-spacing: 2px; text-transform: uppercase; color: #3b5b7a; }
        h1 { font-size: 26px; margin: 6px 0 0; }
        p { font-size: 14px; line-height: 1.6; margin: 0 0 14px; }
        .foot { margin-top: 36px; font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #7b8794; }
    </style></head><body>
        <div class="head"><div class="org">' . $e($client['name']) . '</div><h1>' . $e($doc['title']) . '</h1></div>
        ' . $paragraphs . '
        <div class="foot">Sample document - fictional content for demonstration.</div>
    </body></html>';
};

/** An image: a card of labelled lines - a diagram for png, a captioned "photo" for jpg. */
$image_html = function (array $client, array $doc) use ($e): string {
    $is_photo = $doc['type'] === 'jpg';
    $rows = '';
    foreach ($doc['lines'] as $i => $line) {
        $rows .= '<div class="row"><span class="dot">' . ($i + 1) . '</span>' . $e($line) . '</div>';
    }
    $background = $is_photo
        ? 'radial-gradient(circle at 30% 35%, #f7d9a3 0, #c98b4a 35%, #5a3b24 75%)'
        : 'linear-gradient(135deg, #eef2f7 0%, #dbe4ee 100%)';

    return '<!doctype html><html><head><meta charset="utf-8"><style>
        body { margin: 0; width: 1200px; height: 800px; background: ' . $background . '; font-family: Helvetica, Arial, sans-serif; display: flex; align-items: center; justify-content: center; }
        .card { width: 820px; background: rgba(255,255,255,0.94); border-radius: 14px; padding: 40px 48px; box-shadow: 0 18px 48px rgba(0,0,0,0.25); }
        .org { font-size: 14px; letter-spacing: 2px; text-transform: uppercase; color: #3b5b7a; }
        h1 { font-size: 34px; margin: 8px 0 24px; color: #1f2933; }
        .row { font-size: 22px; color: #323f4b; padding: 12px 0; border-top: 1px solid #e4e7eb; display: flex; align-items: center; }
        .dot { display: inline-flex; width: 34px; height: 34px; border-radius: 50%; background: #3b5b7a; color: #fff; font-size: 16px; align-items: center; justify-content: center; margin-right: 18px; }
    </style></head><body><div class="card"><div class="org">' . $e($client['name']) . '</div><h1>' . $e($doc['title']) . '</h1>' . $rows . '</div></body></html>';
};

$render_jobs = [];
$docx = [];
$written = 0;

foreach ($data['clients'] as $client) {
    foreach ($client['documents'] as $doc) {
        $out = $out_dir . '/' . $doc['file'];

        switch ($doc['type']) {
            case 'pdf':
                $render_jobs[] = ['html' => $page_html($client, $doc), 'out' => $out, 'type' => 'pdf'];
                break;
            case 'png':
            case 'jpg':
                $render_jobs[] = ['html' => $image_html($client, $doc), 'out' => $out, 'type' => $doc['type']];
                break;
            case 'docx':
                $docx[] = ['html' => $page_html($client, $doc), 'out' => $out];
                break;
            case 'xlsx':
                $book = new Spreadsheet();
                $sheet = $book->getActiveSheet();
                $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $doc['title']), 0, 31));
                $sheet->fromArray($doc['rows'], null, 'A1', true);
                $last_column = $sheet->getHighestColumn();
                $sheet->getStyle('A1:' . $last_column . '1')->getFont()->setBold(true);
                foreach (range('A', $last_column) as $column) {
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }
                (new Xlsx($book))->save($out);
                echo "wrote {$out}\n";
                $written++;
                break;
            default:
                throw new RuntimeException("Unknown document type '{$doc['type']}' for {$doc['file']}");
        }
    }
}

// PDF, PNG and JPG: one headless browser for the whole batch.
$process = new Process(['node', $resource . '/render_html.js']);
$process->setTimeout(null);
$process->setInput(json_encode($render_jobs, JSON_THROW_ON_ERROR));
$process->mustRun(fn ($type, $buffer) => print($buffer));
$written += count($render_jobs);

// DOCX: LibreOffice converts each page's HTML.
$scratch = sys_get_temp_dir() . '/sample_docx_' . getmypid();
@mkdir($scratch, 0777, true);
foreach ($docx as $job) {
    $html = $scratch . '/' . pathinfo($job['out'], PATHINFO_FILENAME) . '.html';
    file_put_contents($html, $job['html']);
    $convert = new Process(['soffice', '--headless', '--convert-to', 'docx:MS Word 2007 XML', '--outdir', dirname($job['out']), $html]);
    $convert->setTimeout(null);
    $convert->setEnv(['HOME' => $scratch]);
    $convert->mustRun();
    echo "wrote {$job['out']}\n";
    $written++;
}
exec_safe('rm -rf ' . escapeshellarg($scratch));

echo "Generated {$written} document(s) in {$out_dir}\n";
