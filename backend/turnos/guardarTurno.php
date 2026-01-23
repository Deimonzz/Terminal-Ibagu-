<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../config/database.php";

$response = [
    "ok" => false,
    "mensaje" => ""
];

// Validar método
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $response["mensaje"] = "Método no permitido";
    echo json_encode($response);
    exit;
}

// Recibir datos
$empleado_id  = $_POST["empleado_id"] ?? null;
$fecha        = $_POST["fecha"] ?? null;
$hora_inicio  = $_POST["hora_inicio"] ?? null;
$hora_fin     = $_POST["hora_fin"] ?? null;
$tipo_turno   = $_POST["tipo_turno"] ?? null;
$observacion  = $_POST["observacion"] ?? null;

// Validaciones básicas
if (!$empleado_id || !$fecha || !$hora_inicio || !$hora_fin) {
    $response["mensaje"] = "Faltan datos obligatorios";
    echo json_encode($response);
    exit;
}

try {
    $sql = "INSERT INTO turnos 
            (empleado_id, fecha, hora_inicio, hora_fin, tipo_turno, observacion)
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $empleado_id,
        $fecha,
        $hora_inicio,
        $hora_fin,
        $tipo_turno,
        $observacion
    ]);

    $response["ok"] = true;
    $response["mensaje"] = "Turno agendado correctamente";

} catch (PDOException $e) {
    $response["mensaje"] = "Error al guardar el turno";
}

echo json_encode($response);