<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

$exportsDir = dirname(__DIR__) . '/exports';
if (!is_dir($exportsDir)) {
    echo json_encode(['success' => true, 'files' => []]);
    exit;
}

$files = [];
$dh = opendir($exportsDir);
while (($f = readdir($dh)) !== false) {
    if ($f === '.' || $f === '..') continue;
    $full = $exportsDir . '/' . $f;
    if (!is_file($full)) continue;
    $files[] = [
        'name' => $f,
        'url' => 'backend/exports/' . $f,
        'size' => filesize($full),
        'mtime' => filemtime($full)
    ];
}
closedir($dh);

usort($files, function($a,$b){return $b['mtime']-$a['mtime'];});

echo json_encode(['success' => true, 'files' => $files]);

?>
