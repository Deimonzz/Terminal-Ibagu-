<?php
require_once __DIR__ . '/../../config/db.php';

$sql = "SELECT 
          ca.id AS aspecto_id, 
          ca.nombre AS aspecto_nombre, 
          ca.descripcion, 
          ca.peso,
          e.id AS eval_id, 
          e.visita_id, 
          e.observacion, 
          e.estado, 
          e.recurrente, 
          e.plazo, 
          e.actividad, 
          e.responsable, 
          r.nombre AS responsable_nombre,
          e.evidencia AS evidencia_hallazgo, 
          e.created_at,
          v.nombre_visita, 
          v.fecha_inicio, 
          v.fecha_fin,
          ec.id AS cumplimiento_id, 
          ec.archivo AS evidencia_cumplimiento, 
          ec.uploaded_at
        FROM catalogo_aspectos ca
        LEFT JOIN evaluaciones e ON e.aspecto_id = ca.id
        LEFT JOIN visitas v ON v.id = e.visita_id
        LEFT JOIN evidencias_cumplimiento ec ON ec.evaluacion_id = e.id
        LEFT JOIN responsables r ON e.responsable = r.id
        ORDER BY ca.nombre, v.fecha_inicio DESC, e.created_at DESC";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$result = [];

foreach ($rows as $r) {
  $aid = $r['aspecto_id'];

  if (!isset($result[$aid])) {
    $result[$aid] = [
      'aspecto_id' => $aid,
      'nombre' => $r['aspecto_nombre'],
      'descripcion' => $r['descripcion'],
      'peso' => $r['peso'],
      'evaluaciones' => [],
      'estados' => [] // Guardamos todos los estados para el cálculo final
    ];
  }

  if ($r['eval_id']) {
    $evalId = $r['eval_id'];

    if (!isset($result[$aid]['evaluaciones'][$evalId])) {
      $result[$aid]['evaluaciones'][$evalId] = [
        'eval_id' => $evalId,
        'visita_id' => $r['visita_id'],
        'nombre_visita' => $r['nombre_visita'],
        'fecha_inicio' => $r['fecha_inicio'],
        'fecha_fin' => $r['fecha_fin'],
        'observacion' => $r['observacion'],
        'estado' => $r['estado'],
        'recurrente' => $r['recurrente'],
        'plazo' => $r['plazo'],
        'actividad' => $r['actividad'],
        'responsable' => $r['responsable_nombre'] ?? 'Sin asignar',
        'evidencia_hallazgo' => $r['evidencia_hallazgo'],
        'cumplimientos' => []
      ];
    }

    if ($r['cumplimiento_id']) {
      $result[$aid]['evaluaciones'][$evalId]['cumplimientos'][] = [
        'id' => $r['cumplimiento_id'],
        'archivo' => $r['evidencia_cumplimiento'],
        'uploaded_at' => $r['uploaded_at']
      ];
    }

    // Guardar estado para el cálculo global
    if (!empty($r['estado'])) {
      $result[$aid]['estados'][] = strtoupper($r['estado']);
    }
  }
}

/* 🔹 Calcular estado general del aspecto */
foreach ($result as &$aspecto) {
  $estados = array_unique($aspecto['estados']);
  
  if (empty($estados)) {
    $aspecto['estado_general'] = 'PENDIENTE';
  } elseif (count($estados) === 1 && $estados[0] === 'CUMPLE') {
    $aspecto['estado_general'] = 'CUMPLE';
  } elseif (in_array('NO CUMPLE', $estados) && in_array('CUMPLE', $estados)) {
    $aspecto['estado_general'] = 'PARCIAL';
  } elseif (in_array('PARCIAL', $estados)) {
    $aspecto['estado_general'] = 'PARCIAL';
  } elseif (in_array('NO CUMPLE', $estados)) {
    $aspecto['estado_general'] = 'NO CUMPLE';
  } else {
    $aspecto['estado_general'] = 'PENDIENTE';
  }

  // Convertir evaluaciones a lista
  $aspecto['evaluaciones'] = array_values($aspecto['evaluaciones']);
}

echo json_encode(array_values($result));
