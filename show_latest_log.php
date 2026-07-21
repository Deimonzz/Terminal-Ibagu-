<?php
// Show last lines of log
$logFile = __DIR__ . '/backend/logs/asignacion_errors.log';
$lines = file($logFile);
$lastLines = array_slice($lines, -30);
foreach ($lastLines as $line) {
    echo htmlspecialchars($line);
}
?>
