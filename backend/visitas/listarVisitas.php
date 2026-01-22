<?php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $sql = "SELECT 
                v.id AS visita_id,
                v.nombre_visita,
                v.fecha_inicio,
                v.fecha_fin,
                v.obs_adicionales,
                e.id AS evaluacion_id,
                e.observacion,
                TRIM(UPPER(e.estado)) AS estado,  -- Limpia y uniforma el texto
                e.recurrente,
                e.plazo,
                e.actividad,
                e.responsable AS responsable_id,
                r.nombre AS responsable_nombre,
                r.correo AS responsable_correo,
                e.evidencia AS evidencia_hallazgo,
                a.nombre AS aspecto_nombre,
                a.descripcion AS aspecto_desc,
                ec.id AS cumplimiento_id,
                ec.archivo AS evidencia_cumplimiento,
                ec.uploaded_at
            FROM visitas v
            LEFT JOIN evaluaciones e ON e.visita_id = v.id
            LEFT JOIN catalogo_aspectos a ON a.id = e.aspecto_id
            LEFT JOIN responsables r ON r.id = e.responsable
            LEFT JOIN evidencias_cumplimiento ec ON ec.evaluacion_id = e.id
            ORDER BY v.fecha_inicio DESC, v.id DESC";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $visitas = [];
    foreach ($rows as $r) {
        $id = $r['visita_id'];
        if (!isset($visitas[$id])) {
            $visitas[$id] = [
                'id' => $r['visita_id'],
                'nombre_visita' => $r['nombre_visita'],
                'fecha_inicio' => $r['fecha_inicio'],
                'fecha_fin' => $r['fecha_fin'],
                'obs_adicionales' => $r['obs_adicionales'],
                'aspectos' => []
            ];
        }

        if ($r['evaluacion_id']) {
            $evalId = $r['evaluacion_id'];
            $estado = trim(strtoupper($r['estado'] ?? ''));

            // Determina si cumple o no cumple (corrige valores inconsistentes)
            $esCumple = in_array($estado, ['CUMPLE', 'CUMPLE.']);
            $estadoFinal = $esCumple ? 'CUMPLE' : 'NO CUMPLE';

            if (!isset($visitas[$id]['aspectos'][$evalId])) {
                $visitas[$id]['aspectos'][$evalId] = [
                    'evaluacion_id' => $evalId,
                    'aspecto_nombre' => $r['aspecto_nombre'],
                    'aspecto_desc' => $r['aspecto_desc'],
                    'observacion' => $r['observacion'],
                    'estado' => $estadoFinal,
                    'recurrente' => $r['recurrente'],
                    'plazo' => $r['plazo'],
                    'actividad' => $r['actividad'],
                    'responsable_id' => $r['responsable_id'],
                    'responsable_nombre' => $r['responsable_nombre'],
                    'responsable_correo' => $r['responsable_correo'],
                    'evidencia_hallazgo' => $r['evidencia_hallazgo'],
                    'cumplimientos' => []
                ];
            }

            if (!empty($r['cumplimiento_id'])) {
                $visitas[$id]['aspectos'][$evalId]['cumplimientos'][] = [
                    'id' => $r['cumplimiento_id'],
                    'archivo' => $r['evidencia_cumplimiento'],
                    'uploaded_at' => $r['uploaded_at']
                ];
            }
        }
    }

    foreach ($visitas as &$v) {
        $v['aspectos'] = array_values($v['aspectos']);
    }

    echo json_encode(array_values($visitas));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
