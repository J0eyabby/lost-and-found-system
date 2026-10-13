<?php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/database_backup_helpers.php";

$backupDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "lost_found_backups";
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    fwrite(STDERR, "Unable to create the private backup directory: " . $backupDirectory . PHP_EOL);
    exit(1);
}

$backupPath = $backupDirectory . DIRECTORY_SEPARATOR . "lost_found_backup_" . date("Ymd_His") . ".sql.gz";
$compressed = gzopen($backupPath, "wb9");
if ($compressed === false) {
    fwrite(STDERR, "Unable to create the compressed backup file." . PHP_EOL);
    exit(1);
}

try {
    $tableCount = writeDatabaseSqlDump($conn, static function (string $chunk) use ($compressed): void {
        $written = gzwrite($compressed, $chunk);
        if ($written === false || $written !== strlen($chunk)) {
            throw new RuntimeException("Unable to write all backup data.");
        }
    });
    if (!gzclose($compressed)) {
        throw new RuntimeException("Unable to finalize the compressed backup.");
    }
    $conn->close();
    echo "Created " . $backupPath . " (" . $tableCount . " tables)." . PHP_EOL;
} catch (Throwable $error) {
    gzclose($compressed);
    if (is_file($backupPath)) {
        unlink($backupPath);
    }
    error_log("Scheduled database backup failed: " . $error->getMessage());
    fwrite(STDERR, "Backup failed: " . $error->getMessage() . PHP_EOL);
    $conn->close();
    exit(1);
}

$backupFiles = [];
foreach (new DirectoryIterator($backupDirectory) as $entry) {
    if (!$entry->isFile() || $entry->isLink()
        || preg_match('/^lost_found_backup_[0-9]{8}_[0-9]{6}\.sql\.gz$/D', $entry->getFilename()) !== 1) {
        continue;
    }
    $backupFiles[] = $entry->getPathname();
}
rsort($backupFiles, SORT_STRING);
foreach (array_slice($backupFiles, 7) as $oldBackup) {
    if (is_file($oldBackup) && !is_link($oldBackup)
        && basename($oldBackup) !== basename($backupPath)
        && !unlink($oldBackup)) {
        error_log("Unable to remove expired database backup: " . $oldBackup);
    }
}
?>
