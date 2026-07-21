<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/backend/clases/AsignacionAutomatica.php';
require __DIR__ . '/backend/clases/TurnosAsignados.php';
require __DIR__ . '/backend/clases/Trabajadores.php';

$a = new AsignacionAutomatica();
$test = $a->testConnection();
echo 'connection=' . $test . PHP_EOL;

$ref = new ReflectionMethod($a, 'asignarMesCompleto');
$src = file_get_contents($ref->getFileName());

$checks = [
    'limpieza fuera de trans'  => strpos($src, 'La limpieza previa se hace ANTES de la transaccion') !== false,
    'watchdog 5 dias'          => strpos($src, '($dia % 5) === 1') !== false,
    'sin filtrarPorRestOblig'  => strpos($src, 'filtrarDisponiblesPorRestriccionesObligatorias(') === false,
    'huecosVisitados'          => strpos($src, 'huecosVisitados') !== false,
    'maxSegundos 10'           => strpos($src, '$maxSegundos = 10.0;') !== false,
    'WATCHDOG_SEGUNDOS'        => strpos($src, 'WATCHDOG_SEGUNDOS') !== false,
];
foreach ($checks as $k => $v) {
    echo $k . '=' . ($v ? 'OK' : 'FAIL') . PHP_EOL;
}
