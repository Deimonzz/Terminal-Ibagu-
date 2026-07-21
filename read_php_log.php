<?php
$logFile = 'C:/xampp/php/logs/php_error_log';
if (!file_exists($logFile)) {
    echo 'NO_LOG';
    exit;
}
$lines = file($logFile);
$tail = array_slice($lines, -200);
header('Content-Type: text/plain; charset=utf-8');
echo implode('', $tail);
