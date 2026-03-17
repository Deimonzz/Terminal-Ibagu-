<?php
require_once "/../../config/db.php";

$sql = "SELECT 
            ca.nombre AS aspecto_general,
            ca.descripcion,
            ca.peso,
            v.fecha_inicio,
            v.fecha_fin,
            v.nombre_visita,
            e.observacion,
            e.estado,
            e.plazo,
            e.recurrente,
            e.actividad,
            e.responsable,
            e.evidencia
        FROM evaluaciones e
        JOIN visitas v ON v.id = e.visita_id
        JOIN catalogo_aspectos ca ON ca.id = e.aspecto_id
        ORDER BY ca.nombre, v.fecha_inicio";

$stmt = $pdo->query($sql);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
