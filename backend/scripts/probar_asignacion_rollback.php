<?php
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';

$logPath = dirname(__DIR__) . '/logs/probar_asignacion_cli.log';
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', $logPath);

require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once __DIR__ . '/../clases/AsignacionAutomatica.php';

@set_time_limit(0);

default_timezone_set:
if (!date_default_timezone_get()) {
    date_default_timezone_set('America/Bogota');
}

$db = Database::getInstance()->getConnection();
@file_put_contents($logPath, '');

$mes = isset($argv[1]) ? (int)$argv[1] : 0;
$anio = isset($argv[2]) ? (int)$argv[2] : 0;

if ($mes <= 0 || $anio <= 0) {
    $row = $db->query(
        "SELECT YEAR(fecha) anio, MONTH(fecha) mes, COUNT(*) total
         FROM turnos_asignados
         GROUP BY YEAR(fecha), MONTH(fecha)
         ORDER BY total DESC, anio DESC, mes DESC
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        fwrite(STDERR, "No hay meses con turnos para probar.\n");
        exit(1);
    }

    $mes = (int)$row['mes'];
    $anio = (int)$row['anio'];
}

$inicio = microtime(true);
$resultado = null;
$error = null;

try {
    $db->beginTransaction();
    $asignacion = new AsignacionAutomatica();
    ob_start();
    $resultado = $asignacion->asignarMesCompleto($mes, $anio, []);
    ob_end_clean();
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    $error = [
        'success' => false,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ];
}

$duracion = round(microtime(true) - $inicio, 3);

if ($db->inTransaction()) {
    $db->rollBack();
}

$salida = [
    'mes_probado' => sprintf('%04d-%02d', $anio, $mes),
    'duracion_segundos' => $duracion,
    'resultado' => $error ?: $resultado,
];

echo json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
