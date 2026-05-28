<?php
/**
 * Visor de logs de asignación automática
 */

header('Content-Type: application/json; charset=utf-8');

$logDir = __DIR__ . '/../logs';
$logFile = $logDir . '/asignacion_errors.log';

if (!file_exists($logFile)) {
    echo json_encode([
        'status' => 'ok',
        'message' => 'Log file no existe aún',
        'logs' => []
    ]);
    exit;
}

// Leer últimas 100 líneas del log
$lines = [];
$file = new SplFileObject($logFile, 'r');
$file->seek(PHP_INT_MAX);
$lastLine = $file->key();

$startLine = max(0, $lastLine - 100);
$file->seek($startLine);

while (!$file->eof()) {
    $line = trim($file->fgets());
    if (!empty($line)) {
        $lines[] = $line;
    }
}

echo json_encode([
    'status' => 'ok',
    'file' => $logFile,
    'total_lines_shown' => count($lines),
    'logs' => $lines
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
