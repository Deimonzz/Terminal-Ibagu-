<?php
require 'C:\xampp\htdocs\Terminal-Ibagu-\backend\clases\AsignacionAutomatica.php';
$a = new AsignacionAutomatica();
try {
    $r = $a->asignarMesCompleto(7, 2026);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $e) {
    echo 'EXCEPTION: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}
