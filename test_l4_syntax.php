<?php
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$file = __DIR__ . '/backend/clases/AsignacionAutomatica.php';

if (!file_exists($file)) {
    echo "ERROR: File not found: $file\n";
    exit(1);
}

// Try to compile check
$result = @php_check_syntax($file, $errors);
if ($result === false) {
    echo "SYNTAX ERROR:\n";
    echo $errors;
    exit(1);
} else {
    echo "SYNTAX OK\n";
    exit(0);
}
?>
