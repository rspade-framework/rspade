<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;

/**
 * rsx:jqhtml:glossary
 *
 * Enumerates every <Define:> component across rsx/ with its extends= parent,
 * the first docblock summary line (the "Component_Name - summary" convention
 * from rsx:man jqhtmldoc / semantic_composition), and its co-located files.
 *
 * Run it before designing any new UI element so you reuse the existing concept
 * instead of reinventing it. --missing lists components lacking a parsable
 * summary line; keep that list empty for the vocabulary you own.
 */
class Jqhtml_Glossary_Command extends Command
{
    protected $signature = 'rsx:jqhtml:glossary {--json : Emit JSON instead of a table} {--missing : List only components lacking a parsable summary}';

    protected $description = 'Enumerate every jqhtml <Define:> component (name, extends, summary, files)';

    public function handle()
    {
        $rsx_root = base_path('../rsx');
        if (!is_dir($rsx_root)) {
            $rsx_root = base_path('rsx');
        }

        $components = $this->_scan($rsx_root);

        // Sort by name for deterministic output.
        usort($components, fn($a, $b) => strcmp($a['name'], $b['name']));

        if ($this->option('missing')) {
            return $this->_output_missing($components);
        }

        if ($this->option('json')) {
            $this->line(json_encode($components, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        return $this->_output_table($components);
    }

    /**
     * Walk rsx/ for .jqhtml files, extracting one component per <Define:> tag.
     */
    protected function _scan(string $root): array
    {
        $components = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'jqhtml') {
                continue;
            }

            $path = $file->getPathname();

            // Skip archived research/docs and third-party trees.
            if (preg_match('#/(resource/research|resource/docs|vendor|node_modules)/#', $path)) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            // Extract the <Define:Name ...> tag.
            if (!preg_match('/<Define:([A-Za-z0-9_]+)([^>]*)>/', $content, $m)) {
                continue;
            }

            $name = $m[1];
            $define_attrs = $m[2];

            $extends = '';
            if (preg_match('/\bextends\s*=\s*["\']([A-Za-z0-9_]+)["\']/', $define_attrs, $em)) {
                $extends = $em[1];
            }

            $summary = $this->_extract_summary($content, $name);

            $components[] = [
                'name'    => $name,
                'extends' => $extends,
                'summary' => $summary,
                'files'   => $this->_sibling_files($path),
            ];
        }

        return $components;
    }

    /**
     * Pull the summary from the leading docblock comment. Recognizes the
     * "Component_Name - summary" first-content-line convention. Returns '' when
     * no parsable summary is present.
     */
    protected function _extract_summary(string $content, string $name): string
    {
        // Grab the first jqhtml (<%-- --%>) or HTML (<!-- -->) comment block.
        if (preg_match('/<%--(.*?)--%>/s', $content, $cm)) {
            $comment = $cm[1];
        } elseif (preg_match('/<!--(.*?)-->/s', $content, $cm)) {
            $comment = $cm[1];
        } else {
            return '';
        }

        foreach (preg_split('/\r?\n/', $comment) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // "Name - summary" (hyphen, en dash, or em dash).
            if (preg_match('/^' . preg_quote($name, '/') . '\s*[-\x{2013}\x{2014}]\s*(.+)$/u', $line, $sm)) {
                return trim($sm[1]);
            }

            // First content line is the bare name -> no summary on this line;
            // the convention wants "Name - summary", so treat as missing.
            return '';
        }

        return '';
    }

    /**
     * Co-located files sharing the .jqhtml basename (.jqhtml, .js, .scss).
     */
    protected function _sibling_files(string $jqhtml_path): array
    {
        $dir  = dirname($jqhtml_path);
        $base = pathinfo($jqhtml_path, PATHINFO_FILENAME);

        $files = [];
        foreach (['jqhtml', 'js', 'scss'] as $ext) {
            $candidate = $dir . '/' . $base . '.' . $ext;
            if (is_file($candidate)) {
                $files[] = $this->_relative($candidate);
            }
        }

        return $files;
    }

    protected function _relative(string $path): string
    {
        $real = realpath($path) ?: $path;
        $marker = '/rsx/';
        $pos = strrpos($real, $marker);
        return $pos === false ? $real : 'rsx/' . substr($real, $pos + strlen($marker));
    }

    protected function _output_missing(array $components): int
    {
        $missing = array_values(array_filter($components, fn($c) => $c['summary'] === ''));

        if (empty($missing)) {
            $this->info('All ' . count($components) . ' components have a parsable summary line.');
            return self::SUCCESS;
        }

        $this->warn(count($missing) . ' of ' . count($components) . ' components lack a parsable summary:');
        foreach ($missing as $c) {
            $this->line('  ' . $c['name'] . '  (' . ($c['files'][0] ?? '?') . ')');
        }

        return self::SUCCESS;
    }

    protected function _output_table(array $components): int
    {
        if (empty($components)) {
            $this->warn('No <Define:> components found under rsx/.');
            return self::SUCCESS;
        }

        // Compute column widths for name and extends; summary/files wrap freely.
        $name_w = max(array_map(fn($c) => strlen($c['name']), $components));
        $name_w = max($name_w, strlen('COMPONENT'));
        $ext_w = 0;
        foreach ($components as $c) {
            $ext_w = max($ext_w, strlen($c['extends']));
        }
        $ext_w = max($ext_w, strlen('EXTENDS'));

        $header = str_pad('COMPONENT', $name_w) . '  ' . str_pad('EXTENDS', $ext_w) . '  SUMMARY';
        $this->line($header);
        $this->line(str_repeat('-', strlen($header)));

        foreach ($components as $c) {
            $summary = $c['summary'] !== '' ? $c['summary'] : '(no summary)';
            $this->line(
                str_pad($c['name'], $name_w) . '  ' .
                str_pad($c['extends'] !== '' ? $c['extends'] : '-', $ext_w) . '  ' .
                $summary
            );
        }

        $this->line('');
        $missing = count(array_filter($components, fn($c) => $c['summary'] === ''));
        $this->line(count($components) . ' components' . ($missing ? " ({$missing} missing a summary - run --missing)" : ''));

        return self::SUCCESS;
    }
}
