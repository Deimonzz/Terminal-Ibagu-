<?php
$turno_id = 1;
$fecha = '2026-07-01';
$fechaSiguiente = date('Y-m-d', strtotime($fecha . ' +1 day'));
$fechaAnterior = date('Y-m-d', strtotime($fecha . ' -1 day'));
$fechaInicioMes = date('Y-m-01', strtotime($fecha));
$fechaFinMes = date('Y-m-t', strtotime($fecha));
$numeroTurno = 1;

$sql = "SELECT DISTINCT t.id, t.nombre
                FROM trabajadores t
                WHERE t.activo = true
                AND LOWER(COALESCE(t.cargo, '')) != 'supervisor'
                AND t.id NOT IN (
                    SELECT trabajador_id FROM turnos_asignados
                    WHERE fecha = :fecha_assigned
                    AND estado IN ('programado', 'activo')
                )
                AND t.id NOT IN (
                    SELECT trabajador_id FROM incapacidades
                    WHERE :fecha_inca BETWEEN fecha_inicio AND fecha_fin
                    AND estado = 'activa'
                )
                AND t.id NOT IN (
                    SELECT trabajador_id FROM dias_especiales
                    WHERE tipo IN ('LC', 'L', 'L8', 'VAC', 'SUS', 'CAP')
                    AND :fecha_lib BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
                    AND estado IN ('programado', 'activo')
                )";

$params = [
    ':fecha_assigned' => $fecha,
    ':fecha_inca' => $fecha,
    ':fecha_lib' => $fecha,
    ':fecha' => $fecha,
    ':numero_turno' => $numeroTurno,
];

$sql .= "
            AND t.id NOT IN (
                SELECT trabajador_id FROM dias_especiales
                WHERE tipo IN ('ADM', 'ADMM', 'ADMT')
                AND :fecha BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
                AND estado IN ('programado', 'activo')
                AND (
                    tipo = 'ADM'
                    OR (tipo = 'ADMM' AND :numero_turno IN (2, 3))
                    OR (tipo = 'ADMT' AND :numero_turno IN (1, 3))
                )
            )";

if ($numeroTurno == 3) {
    $sql .= "
                AND t.id NOT IN (
                    SELECT trabajador_id FROM restricciones_trabajador
                    WHERE tipo_restriccion = 'no_turno_noche'
                    AND activa = true
                    AND :fecha_noche >= fecha_inicio
                    AND (:fecha_noche2 <= fecha_fin OR fecha_fin IS NULL)
                )
                AND t.id NOT IN (
                    SELECT ta.trabajador_id FROM turnos_asignados ta
                    INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                    WHERE ct.numero_turno = 3
                    AND ta.fecha BETWEEN :mes_inicio AND :mes_fin
                    AND ta.estado IN ('programado', 'activo')
                    GROUP BY ta.trabajador_id
                    HAVING COUNT(*) >= 7
                )
                AND t.id NOT IN (
                    SELECT ta2.trabajador_id FROM turnos_asignados ta2
                    INNER JOIN configuracion_turnos ct2 ON ta2.turno_id = ct2.id
                    WHERE ta2.fecha = :fecha_next
                    AND ct2.numero_turno = 1
                    AND ta2.estado IN ('programado', 'activo')
                )";
    $params[':fecha_noche'] = $fecha;
    $params[':fecha_noche2'] = $fecha;
    $params[':mes_inicio'] = $fechaInicioMes;
    $params[':mes_fin'] = $fechaFinMes;
    $params[':fecha_next'] = $fechaSiguiente;
}

if ($numeroTurno == 1) {
    $sql .= "
                AND t.id NOT IN (
                    SELECT ta2.trabajador_id FROM turnos_asignados ta2
                    INNER JOIN configuracion_turnos ct2 ON ta2.turno_id = ct2.id
                    WHERE ta2.fecha = :fecha_prev
                    AND ct2.numero_turno = 3
                    AND ta2.estado IN ('programado', 'activo')
                )";
    $params[':fecha_prev'] = $fechaAnterior;
}

preg_match_all('/(:[a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);
$placeholders = array_values(array_unique($matches[1]));
$paramKeys = array_keys($params);
print_r($placeholders);
echo "\n";
print_r($paramKeys);
echo "\n";
$missing = array_diff($placeholders, $paramKeys);
$extra = array_diff($paramKeys, $placeholders);
echo "MISSING="; print_r($missing); echo "\nEXTRA="; print_r($extra); echo "\n";
echo $sql;
