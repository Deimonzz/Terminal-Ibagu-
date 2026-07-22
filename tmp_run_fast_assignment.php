<?php
@ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/backend/clases/Trabajadores.php';
require_once __DIR__ . '/backend/clases/TurnosAsignados.php';
require_once __DIR__ . '/backend/clases/AsignacionAutomatica.php';

try {
    $asignador = new AsignacionAutomatica();
    $resultado = $asignador->asignarMesCompleto(8, 2026, ['modo_rapido' => true]);
    echo "RESULTADO:\n";
    print_r($resultado);
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
