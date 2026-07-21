<?php
require 'config/database.php';
require 'backend/clases/Trabajadores.php';
require 'backend/clases/TurnosAsignados.php';
require 'backend/clases/AsignacionAutomatica.php';

try {
    $asignador = new AsignacionAutomatica();
    echo 'OK';
} catch (Throwable $e) {
    header('Content-Type: text/plain');
    echo get_class($e) . ': ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString();
}
