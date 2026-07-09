<?php

//Gestión de Incapacidades

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/TurnosAsignados.php';
require_once __DIR__ . '/Trabajadores.php';

class Incapacidades {
    private $db;
    private $turnosAsignados;
    private $trabajadores;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->turnosAsignados = new TurnosAsignados();
        $this->trabajadores = new Trabajadores();
    }
    
    
    //Obtener incapacidades con filtros
    
    public function obtenerIncapacidades($filtros = []) {
        $sql = "SELECT i.*, t.nombre as trabajador, t.cedula
                FROM incapacidades i
                INNER JOIN trabajadores t ON i.trabajador_id = t.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filtros['trabajador_id'])) {
            $sql .= " AND i.trabajador_id = :trabajador_id";
            $params[':trabajador_id'] = $filtros['trabajador_id'];
        }
        
        if (!empty($filtros['estado'])) {
            $sql .= " AND i.estado = :estado";
            $params[':estado'] = $filtros['estado'];
        }
        
        if (!empty($filtros['fecha'])) {
            $sql .= " AND :fecha BETWEEN i.fecha_inicio AND i.fecha_fin";
            $params[':fecha'] = $filtros['fecha'];
        }
        
        if (!empty($filtros['activas'])) {
            $sql .= " AND i.estado = 'activa' AND i.fecha_fin >= " . Database::currentDate();
        }

        if (!empty($filtros['fecha_inicio']) && !empty($filtros['fecha_fin'])) {
            $sql .= " AND i.fecha_inicio <= :rango_fin
                      AND i.fecha_fin >= :rango_inicio";
            $params[':rango_inicio'] = $filtros['fecha_inicio'];
            $params[':rango_fin']    = $filtros['fecha_fin'];
        }
        
        $sql .= " ORDER BY i.fecha_inicio DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    
    //Crear incapacidad
    
    public function crear($datos) {
        $sql = "SELECT COUNT(*) as count FROM incapacidades
                WHERE trabajador_id = :trabajador_id
                AND estado = 'activa'
                AND (
                    (:fecha_inicio BETWEEN fecha_inicio AND fecha_fin)
                    OR (:fecha_fin BETWEEN fecha_inicio AND fecha_fin)
                    OR (fecha_fin BETWEEN :fecha_inicio2 AND :fecha_fin2)
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':trabajador_id' => $datos['trabajador_id'],
            ':fecha_inicio' => $datos['fecha_inicio'],
            ':fecha_fin' => $datos['fecha_fin'],
            ':fecha_inicio2' => $datos['fecha_inicio'],
            ':fecha_fin2' => $datos['fecha_fin']
        ]);

        $result = $stmt->fetch();
        if ($result['count'] > 0) {
            return [
                'success' => false,
                'message' => 'Ya existe una incapacidad activa en estas fechas'
            ];
        }

        $fecha_inicio = new DateTime($datos['fecha_inicio']);
        $fecha_fin = new DateTime($datos['fecha_fin']);
        $dias = $fecha_inicio->diff($fecha_fin)->days + 1;

        try {
            $this->db->beginTransaction();

            // Capturar turnos afectados antes de cancelarlos para intentar cobertura automática.
            $turnosAfectados = $this->obtenerTurnosAfectados(
                $datos['trabajador_id'],
                $datos['fecha_inicio'],
                $datos['fecha_fin']
            );

            $sql = "INSERT INTO incapacidades
                    (trabajador_id, tipo, fecha_inicio, fecha_fin, dias_incapacidad, descripcion, documento_soporte, eps, estado, genera_restriccion, tipo_restriccion_generada, restriccion_permanente, fecha_fin_restriccion)
                    VALUES
                    (:trabajador_id, :tipo, :fecha_inicio, :fecha_fin, :dias, :descripcion, :documento, :eps, 'activa', :genera_restriccion, :tipo_restriccion, :restriccion_permanente, :fecha_fin_restriccion)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':trabajador_id' => $datos['trabajador_id'],
                ':tipo' => $datos['tipo'],
                ':fecha_inicio' => $datos['fecha_inicio'],
                ':fecha_fin' => $datos['fecha_fin'],
                ':dias' => $dias,
                ':descripcion' => $datos['descripcion'] ?? null,
                ':documento' => $datos['documento_soporte'] ?? null,
                ':eps' => $datos['eps'] ?? null,
                ':genera_restriccion' => filter_var($datos['genera_restriccion'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ':tipo_restriccion' => $datos['tipo_restriccion_generada'] ?? null,
                ':restriccion_permanente' => filter_var($datos['restriccion_permanente'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ':fecha_fin_restriccion' => $datos['fecha_fin_restriccion'] ?? null
            ]);

            $incapacidad_id = $this->db->lastInsertId();

            if (!empty($datos['genera_restriccion']) && !empty($datos['tipo_restriccion_generada'])) {
                $sqlRestr = "INSERT INTO restricciones_trabajador
                            (trabajador_id, tipo_restriccion, descripcion, fecha_inicio, fecha_fin, activa)
                            VALUES
                            (:trabajador_id, :tipo, :descripcion, :fecha_inicio, :fecha_fin, :activa)";

                $stmtRestr = $this->db->prepare($sqlRestr);
                $restriccionPermanente = (int) filter_var($datos['restriccion_permanente'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $stmtRestr->execute([
                    ':trabajador_id' => $datos['trabajador_id'],
                    ':tipo' => $datos['tipo_restriccion_generada'],
                    ':descripcion' => 'Generada por incapacidad: ' . ($datos['descripcion'] ?? 'Cirugia'),
                    ':fecha_inicio' => $datos['fecha_fin'],
                    ':fecha_fin' => $restriccionPermanente ? null : $datos['fecha_fin_restriccion'],
                    ':activa' => true
                ]);
            }

            $this->cancelarTurnosEnRango($datos['trabajador_id'], $datos['fecha_inicio'], $datos['fecha_fin']);

            $this->db->commit();

            // Tras confirmar la incapacidad, intentar cubrir automáticamente los turnos cancelados.
            $cobertura = $this->cubrirTurnosAfectados(
                $datos['trabajador_id'],
                $turnosAfectados,
                'incapacidad'
            );

            $msgCobertura = '';
            if ($cobertura['total'] > 0) {
                $msgCobertura = ' | Cobertura automática: ' . $cobertura['cubiertos'] . '/' . $cobertura['total'];
            }

            return [
                'success' => true,
                'id' => $incapacidad_id,
                'message' => 'Incapacidad Registrada' . (!empty($datos['genera_restriccion']) ? ' y restriccion creada' : '') . $msgCobertura,
                'cobertura_automatica' => $cobertura
            ];
        } catch(PDOException $e) {
            $this->db->rollback();
            return[
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    //Actualizar incapacidad
    
    public function actualizar($id, $datos) {
        try {
            $sql = "UPDATE incapacidades SET 
                    tipo = :tipo,
                    fecha_inicio = :fecha_inicio,
                    fecha_fin = :fecha_fin,
                    dias_incapacidad = :dias,
                    descripcion = :descripcion,
                    eps = :eps,
                    estado = :estado
                    WHERE id = :id";
            
            // Recalcular días
            $fecha_inicio = new DateTime($datos['fecha_inicio']);
            $fecha_fin = new DateTime($datos['fecha_fin']);
            $dias = $fecha_inicio->diff($fecha_fin)->days + 1;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':tipo' => $datos['tipo'],
                ':fecha_inicio' => $datos['fecha_inicio'],
                ':fecha_fin' => $datos['fecha_fin'],
                ':dias' => $dias,
                ':descripcion' => $datos['descripcion'] ?? null,
                ':eps' => $datos['eps'] ?? null,
                ':estado' => $datos['estado'] ?? 'activa'
            ]);
            
            return [
                'success' => true,
                'message' => 'Incapacidad actualizada'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    
    //Finalizar incapacidad
    
    public function finalizar($id) {
        $sql = "UPDATE incapacidades SET estado = 'finalizada' WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        return [
            'success' => true,
            'message' => 'Incapacidad finalizada'
        ];
    }
    
    
    //Prorrogar incapacidad
   
    public function prorrogar($id, $nueva_fecha_fin) {
        $incapacidad = $this->obtenerPorId($id);
        if (!$incapacidad) {
            return [
                'success' => false,
                'message' => 'Incapacidad no encontrada'
            ];
        }

        $fechaFinAnterior = $incapacidad['fecha_fin'];
        $inicioExtension = date('Y-m-d', strtotime($fechaFinAnterior . ' +1 day'));

        if ($nueva_fecha_fin < $inicioExtension) {
            return [
                'success' => false,
                'message' => 'La nueva fecha fin debe ser posterior a la fecha fin actual'
            ];
        }

        $turnosAfectados = $this->obtenerTurnosAfectados(
            $incapacidad['trabajador_id'],
            $inicioExtension,
            $nueva_fecha_fin
        );

        $sql = "UPDATE incapacidades SET 
                fecha_fin = :nueva_fecha_fin,
                estado = 'prorrogada'
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':nueva_fecha_fin' => $nueva_fecha_fin
        ]);
        
        $this->cancelarTurnosEnRango(
            $incapacidad['trabajador_id'], 
            $inicioExtension,
            $nueva_fecha_fin
        );

        $cobertura = $this->cubrirTurnosAfectados(
            $incapacidad['trabajador_id'],
            $turnosAfectados,
            'prorroga_incapacidad'
        );

        $msgCobertura = '';
        if ($cobertura['total'] > 0) {
            $msgCobertura = ' | Cobertura automática: ' . $cobertura['cubiertos'] . '/' . $cobertura['total'];
        }
        
        return [
            'success' => true,
            'message' => 'Incapacidad prorrogada y turnos cancelados' . $msgCobertura,
            'cobertura_automatica' => $cobertura
        ];
    }
    
    
    //Obtener incapacidad por ID
    
    public function obtenerPorId($id) {
        $sql = "SELECT i.*, t.nombre as trabajador, t.cedula
                FROM incapacidades i
                INNER JOIN trabajadores t ON i.trabajador_id = t.id
                WHERE i.id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    
    //Verificar si trabajador tiene incapacidad activa en fecha
    
    public function tieneIncapacidadActiva($trabajador_id, $fecha) {
        $sql = "SELECT COUNT(*) as count FROM incapacidades 
                WHERE trabajador_id = :trabajador_id
                AND :fecha BETWEEN fecha_inicio AND fecha_fin
                AND estado = 'activa'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':trabajador_id' => $trabajador_id,
            ':fecha' => $fecha
        ]);
        
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }
    
    
    //Eliminar incapacidad

    public function eliminar($id) {
        try {
            $incapacidad = $this->obtenerPorId($id);
            if (!$incapacidad) {
                return ['success' => false, 'message' => 'Incapacidad no encontrada'];
            }

            $stmt = $this->db->prepare("DELETE FROM incapacidades WHERE id = :id");
            $stmt->execute([':id' => $id]);

            return ['success' => true, 'message' => 'Incapacidad eliminada'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error al eliminar: ' . $e->getMessage()];
        }
    }


    //Cancelar turnos en rango de fechas
    
    private function cancelarTurnosEnRango($trabajador_id, $fecha_inicio, $fecha_fin) {
        $observaciones_expr = DB_DRIVER === 'pgsql' 
            ? "COALESCE(observaciones, '')" 
            : "IFNULL(observaciones, '')";
        
        $concat_expr = DB_DRIVER === 'pgsql'
            ? "$observaciones_expr || ' - Cancelado por incapacidad'"
            : "CONCAT($observaciones_expr, ' - Cancelado por incapacidad')";
        
        $sql = "UPDATE turnos_asignados 
                SET estado = 'cancelado', 
                    observaciones = $concat_expr
                WHERE trabajador_id = :trabajador_id 
                AND fecha BETWEEN :fecha_inicio AND :fecha_fin
                AND estado IN ('programado', 'activo')";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':trabajador_id' => $trabajador_id,
            ':fecha_inicio' => $fecha_inicio,
            ':fecha_fin' => $fecha_fin
    ]);
}

    private function obtenerTurnosAfectados($trabajador_id, $fecha_inicio, $fecha_fin) {
        $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
        $selectPuesto = $puestoCol ? ("ta." . $puestoCol . " as puesto_trabajo_id") : "NULL as puesto_trabajo_id";

        $sql = "SELECT ta.id, ta.trabajador_id, ta.turno_id, ta.fecha, " . $selectPuesto . "
                FROM turnos_asignados ta
                WHERE ta.trabajador_id = :trabajador_id
                AND ta.fecha BETWEEN :fecha_inicio AND :fecha_fin
                AND ta.estado IN ('programado', 'activo')
                ORDER BY ta.fecha ASC, ta.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':trabajador_id' => $trabajador_id,
            ':fecha_inicio' => $fecha_inicio,
            ':fecha_fin' => $fecha_fin
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function cubrirTurnosAfectados($trabajador_id_original, $turnosAfectados, $motivo = 'incapacidad') {
        $resultado = [
            'total' => count($turnosAfectados),
            'cubiertos' => 0,
            'sin_cobertura' => 0,
            'detalle' => []
        ];

        if (empty($turnosAfectados)) {
            return $resultado;
        }

        $stmtTrab = $this->db->prepare("SELECT nombre FROM trabajadores WHERE id = :id");
        $stmtTrab->execute([':id' => $trabajador_id_original]);
        $nombreOriginal = ($stmtTrab->fetch(PDO::FETCH_ASSOC)['nombre'] ?? ('Trabajador #' . $trabajador_id_original));

        foreach ($turnosAfectados as $turno) {
            $puestoId = $turno['puesto_trabajo_id'] ?? null;
            if (!$puestoId) {
                $resultado['sin_cobertura']++;
                $resultado['detalle'][] = [
                    'turno_id' => $turno['id'],
                    'fecha' => $turno['fecha'],
                    'status' => 'sin_cobertura',
                    'motivo' => 'Turno sin puesto asociado'
                ];
                continue;
            }

            $disponibles = $this->trabajadores->obtenerDisponibles($puestoId, $turno['turno_id'], $turno['fecha']);
            $cubierto = false;

            foreach ($disponibles as $cand) {
                $valid = $this->turnosAsignados->validarAsignacion(
                    $cand['id'],
                    $puestoId,
                    $turno['turno_id'],
                    $turno['fecha']
                );

                if (!$valid['valido']) {
                    continue;
                }

                $obs = 'AUTO-COBERTURA por ' . $motivo . ': reemplaza a ' . $nombreOriginal . ' | turno original #' . $turno['id'];
                $ok = $this->insertarTurnoCobertura($cand['id'], $puestoId, $turno['turno_id'], $turno['fecha'], $obs);
                if ($ok) {
                    $resultado['cubiertos']++;
                    $resultado['detalle'][] = [
                        'turno_id' => $turno['id'],
                        'fecha' => $turno['fecha'],
                        'status' => 'cubierto',
                        'reemplazo_trabajador_id' => $cand['id'],
                        'reemplazo_trabajador' => $cand['nombre'] ?? null
                    ];
                    $cubierto = true;
                    break;
                }
            }

            if (!$cubierto) {
                $resultado['sin_cobertura']++;
                $resultado['detalle'][] = [
                    'turno_id' => $turno['id'],
                    'fecha' => $turno['fecha'],
                    'status' => 'sin_cobertura',
                    'motivo' => 'Sin reemplazo disponible'
                ];
            }
        }

        return $resultado;
    }

    private function insertarTurnoCobertura($trabajador_id, $puesto_id, $turno_id, $fecha, $observaciones) {
        try {
            $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
            if ($puestoCol) {
                $sql = "INSERT INTO turnos_asignados
                        (trabajador_id, " . $puestoCol . ", turno_id, fecha, estado, observaciones, created_by)
                        VALUES (:trabajador_id, :puesto_id, :turno_id, :fecha, 'programado', :observaciones, :created_by)";
                $params = [
                    ':trabajador_id' => $trabajador_id,
                    ':puesto_id' => $puesto_id,
                    ':turno_id' => $turno_id,
                    ':fecha' => $fecha,
                    ':observaciones' => $observaciones,
                    ':created_by' => 1
                ];
            } else {
                $sql = "INSERT INTO turnos_asignados
                        (trabajador_id, turno_id, fecha, estado, observaciones, created_by)
                        VALUES (:trabajador_id, :turno_id, :fecha, 'programado', :observaciones, :created_by)";
                $params = [
                    ':trabajador_id' => $trabajador_id,
                    ':turno_id' => $turno_id,
                    ':fecha' => $fecha,
                    ':observaciones' => $observaciones,
                    ':created_by' => 1
                ];
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return true;
        } catch (PDOException $e) {
            error_log('[Incapacidades::insertarTurnoCobertura] ' . $e->getMessage());
            return false;
        }
    }
    
    
    // Actualizar estados automáticamente según fecha actual

    public function actualizarEstados() {
        try {
            // activa → finalizada cuando fecha_fin < hoy
            $sql = "UPDATE incapacidades 
                    SET estado = 'finalizada'
                    WHERE estado = 'activa'
                    AND fecha_fin < " . Database::currentDate();
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return 0;
        }
    }


    //Obtener estadísticas de incapacidades
    
    public function obtenerEstadisticas($fecha_inicio, $fecha_fin) {
        $sql = "SELECT 
                COUNT(DISTINCT i.id) as total_incapacidades,
                SUM(i.dias_incapacidad) as total_dias,
                AVG(i.dias_incapacidad) as promedio_dias,
                i.tipo,
                COUNT(DISTINCT i.id) as cantidad_por_tipo
                FROM incapacidades i
                WHERE i.fecha_inicio BETWEEN :fecha_inicio AND :fecha_fin
                GROUP BY i.tipo";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':fecha_inicio' => $fecha_inicio,
            ':fecha_fin' => $fecha_fin
        ]);
        
        return $stmt->fetchAll();
    }
}
?>