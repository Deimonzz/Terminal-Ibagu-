<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

$input = json_decode(file_get_contents('php://input'), true);
$name = isset($input['name']) ? $input['name'] : '';
if (!$name) {
    echo json_encode(['success' => false, 'message' => 'name required']);
    exit;
}

$exportsDir = dirname(__DIR__) . '/exports';
$base = basename($name);
$path = $exportsDir . '/' . $base;

// basic safety: ensure path is inside exports
$realExports = realpath($exportsDir);
$realPath = realpath($path);
if ($realPath === false || strpos($realPath, $realExports) !== 0) {
    echo json_encode(['success' => false, 'message' => 'invalid path']);
    exit;
}

if (!file_exists($realPath)) {
    echo json_encode(['success' => false, 'message' => 'file not found']);
    exit;
}

if (!@unlink($realPath)) {
    echo json_encode(['success' => false, 'message' => 'cannot delete']);
    exit;
}

echo json_encode(['success' => true]);

?>
