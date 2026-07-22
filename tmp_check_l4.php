<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/backend/clases/AsignacionAutomatica.php';

$a = new AsignacionAutomatica();
$method = new ReflectionMethod(AsignacionAutomatica::class, 'getDisponiblesL4');
$method->setAccessible(true);

$ctx = ['puestosL4TurnoId' => [1 => 9], 'puestosL4TurnoIds' => [1 => [9]]];
$result = $method->invoke($a, 1, '2026-08-01', $ctx);

if (empty($result)) {
    fwrite(STDERR, "Fallo: getDisponiblesL4 devolvio una lista vacia para 2026-08-01\n");
    exit(1);
}

fwrite(STDOUT, "OK: getDisponiblesL4 devolvio " . count($result) . " candidatos\n");
