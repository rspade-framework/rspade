<?php
/**
 * Save and restore THIS development box's database and blob store around a sample-data
 * refresh - a DEVELOPER tool for the procedure in ../CLAUDE.md:
 *
 *     php rsx/services/sample_data/resource/dev_snapshot.php save
 *     php rsx/services/sample_data/resource/dev_snapshot.php restore
 *
 * save     dumps the database (gzipped mysqldump) and archives the blob store into
 *          storage/sample_data_snapshot/. REFUSES when a snapshot is already there: that
 *          one holds data nobody has restored yet, and is never overwritten.
 * restore  inside a maintenance window: recreates the database from the dump, replaces
 *          the blob store with the archive, then purges the cached schema state and the
 *          volatile cache (both describe the database that was replaced). The snapshot is
 *          deleted only after everything succeeded.
 *
 * The dump and the database wipe are Rsx_Data_Wipe's, the same ones
 * rsx:db:rebuild_provision_cache_snapshot uses.
 */

$app = require __DIR__ . '/../../../../system/script.php';

use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Database\Rsx_Data_Wipe;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

$action = $argv[1] ?? '';
$dir = Rsx_Project_Paths::storage_path('sample_data_snapshot');
$dump = $dir . '/database.sql.gz';
$blobs = $dir . '/blobs.tar.gz';
$blob_root = rtrim(Rsx_File_Paths::blob_root(), '/');
$database = Rsx_Data_Wipe::database_name();

if ($action === 'save') {
    if (is_dir($dir)) {
        fwrite(STDERR, "[ERROR] A snapshot already exists at {$dir} - restore it (or delete it deliberately) first.\n");
        exit(1);
    }
    ensure_directory($dir);

    Rsx_Data_Wipe::stream(Rsx_Data_Wipe::mysqldump_command($database) . ' | gzip > ' . escapeshellarg($dump . '.partial'), Rsx_Data_Wipe::mysql_env(), 'dumping ' . $database);
    rename($dump . '.partial', $dump);
    Rsx_Data_Wipe::stream('set -o pipefail; tar -C ' . escapeshellarg($blob_root) . ' -czf ' . escapeshellarg($blobs) . ' .', [], 'archiving the blob store');

    echo "[OK] Saved {$database} and the blob store to {$dir}\n";
    exit(0);
}

if ($action === 'restore') {
    if (!is_file($dump) || !is_file($blobs)) {
        fwrite(STDERR, "[ERROR] No complete snapshot at {$dir}.\n");
        exit(1);
    }

    Rsx_Artisan::passthru('rsx:maintenance:enable', ['--reason=restoring the pre-sample-data development snapshot']);
    try {
        Rsx_Data_Wipe::recreate_database();
        Rsx_Data_Wipe::stream(
            'set -o pipefail; cat ' . escapeshellarg($dump) . ' | gunzip | mysql ' . Rsx_Data_Wipe::client_flags() . ' ' . escapeshellarg($database),
            Rsx_Data_Wipe::mysql_env(),
            'restoring ' . $database
        );
        Rsx_Data_Wipe::clear_directory_contents($blob_root);
        Rsx_Data_Wipe::stream('set -o pipefail; tar -C ' . escapeshellarg($blob_root) . ' -xzf ' . escapeshellarg($blobs), [], 'restoring the blob store');
    } finally {
        Rsx_Artisan::passthru('rsx:maintenance:disable');
    }

    Rsx_Schema::purge_schema_state();
    RsxCache::clear();
    exec_safe('rm -rf ' . escapeshellarg($dir));

    echo "[OK] Restored {$database} and the blob store; the snapshot is deleted.\n";
    exit(0);
}

fwrite(STDERR, "Usage: php rsx/services/sample_data/resource/dev_snapshot.php save|restore\n");
exit(1);
