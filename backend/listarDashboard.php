<?php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

try {
    // 1. Resumen general
    $anio = date('Y');
    $res = $pdo->query("SELECT COUNT(*) AS visitasAnio FROM visitas WHERE YEAR(fecha_inicio) = $anio");
    $visitasAnio = $res->fetch()['visitasAnio'] ?? 0;

    $res = $pdo->query("SELECT COUNT(*) AS pendientes FROM evaluaciones WHERE estado = 'NO CUMPLE'");
    $pendientes = $res->fetch()['pendientes'] ?? 0;

    $res = $pdo->query("SELECT COUNT(*) AS total, SUM(estado = 'CUMPLE') AS cumple FROM evaluaciones");
    $row = $res->fetch();
    $total = $row['total'] ?: 1;
    $cumple = $row['cumple'] ?: 0;
    $cumplimiento = round(($cumple / $total) * 100);

    // 2. Próximos vencimientos (solo NO CUMPLE)
    $proximos = $pdo->query("
        SELECT e.id, e.observacion AS descripcion, e.plazo AS fecha_limite, r.nombre AS responsable, e.estado
        FROM evaluaciones e
        LEFT JOIN responsables r ON e.responsable = r.id
        WHERE e.estado = 'NO CUMPLE' AND e.plazo IS NOT NULL AND e.plazo >= CURDATE()
        ORDER BY e.plazo ASC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Visitas recientes (últimas 5 evaluaciones)
    $visitas = $pdo->query("
        SELECT e.plazo AS fecha, v.nombre_visita, ca.nombre AS aspecto, e.estado, r.nombre AS responsable
        FROM evaluaciones e
        LEFT JOIN visitas v ON e.visita_id = v.id
        LEFT JOIN catalogo_aspectos ca ON e.aspecto_id = ca.id
        LEFT JOIN responsables r ON e.responsable = r.id
        ORDER BY e.id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 4. Estados de cumplimiento (para gráfico)
    $status = [];
    $res = $pdo->query("SELECT estado, COUNT(*) AS cantidad FROM evaluaciones GROUP BY estado");
    foreach ($res as $row) {
        $status[] = ['estado' => $row['estado'], 'cantidad' => (int)$row['cantidad']];
    }

    // 5. Responsables (para gráfico)
    $responsables = [];
    $res = $pdo->query("
        SELECT r.nombre AS responsable, COUNT(e.id) AS cantidad
        FROM responsables r
        LEFT JOIN evaluaciones e ON e.responsable = r.id
        GROUP BY r.id, r.nombre
    ");
    foreach ($res as $row) {
        $responsables[] = ['responsable' => $row['responsable'], 'cantidad' => (int)$row['cantidad']];
    }

    echo json_encode([
        'resumen' => [
            'visitasAnio' => $visitasAnio,
            'pendientes' => $pendientes,
            'cumplimiento' => $cumplimiento
        ],
        'proximos' => $proximos,
        'visitas' => $visitas,
        'status' => $status,
        'responsables' => $responsables
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
