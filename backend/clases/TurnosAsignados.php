<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Trabajadores.php';

class TurnosAsignados {
    private $db;
    private $trabajadores;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->trabajadores = new Trabajadores();
    }
    
    
    public function obtenerTurnos($filtros = []) {
        $sql = "SELECT 
                ta.id,
                ta.fecha,
                ta.estado,
                ta.observaciones,
                t.id as trabajador_id,
                t.nombre as trabajador,
                t.cedula,
                pt.codigo as puesto_codigo,
                pt.nombre as puesto_nombre,
                pt.area,
                ct.numero_turno,
                ct.nombre as turno_nombre,
                ct.hora_inicio,
                ct.hora_fin,
                ct.horas_laborales
                FROM turnos_asignados ta
                INNER JOIN trabajadores t ON ta.trabajador_id = t.id
                INNER JOIN puestos_trabajo pt ON ta.puesto_trabajo_id = pt.id
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filtros['fecha_inicio'])) {
            $sql .= " AND ta.fecha >= :fecha_inicio";
            $params[':fecha_inicio'] = $filtros['fecha_inicio'];
        }
        
        if (!empty($filtros['fecha_fin'])) {
            $sql .= " AND ta.fecha <= :fecha_fin";
            $params[':fecha_fin'] = $filtros['fecha_fin'];
        }
        
        if (!empty($filtros['fecha'])) {
            $sql .= " AND ta.fecha = :fecha";
            $params[':fecha'] = $filtros['fecha'];
        }
        
        if (!empty($filtros['trabajador_id'])) {
            $sql .= " AND ta.trabajador_id = :trabajador_id";
            $params[':trabajador_id'] = $filtros['trabajador_id'];
        }
        
        if (!empty($filtros['area'])) {
            $sql .= " AND pt.area = :area";
            $params[':area'] = $filtros['area'];
        }
        
        if (!empty($filtros['turno'])) {
            $sql .= " AND ct.numero_turno = :turno";
            $params[':turno'] = $filtros['turno'];
        }
        
        if (!empty($filtros['estado'])) {
            $sql .= " AND ta.estado = :estado";
            $params[':estado'] = $filtros['estado'];
        }
        
        $sql .= " ORDER BY ta.fecha DESC, ct.hora_inicio ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    
     // Validar asignación de turno
     
    public function validarAsignacion($trabajador_id, $puesto_id, $turno_id, $fecha) {
        $errores = [];
        
        // 1. Verificar si ya tiene turno asignado ese día
        $sql = "SELECT COUNT(*) as count FROM turnos_asignados 
                WHERE trabajador_id = :trabajador_id 
                AND fecha = :fecha 
                AND estado IN ('programado', 'activo')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':trabajador_id' => $trabajador_id, ':fecha' => $fecha]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            $errores[] = 'El trabajador ya tiene un turno asignado para esta fecha';
        }
        
        // 2. Verificar incapacidad activa
        $sql = "SELECT COUNT(*) as count, GROUP_CONCAT(tipo SEPARATOR ', ') as tipos
                FROM incapacidades 
                WHERE trabajador_id = :trabajador_id
                AND :fecha BETWEEN fecha_inicio AND fecha_fin
                AND estado = 'activa'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':trabajador_id' => $trabajador_id, ':fecha' => $fecha]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            $errores[] = 'El trabajador tiene incapacidad activa (' . $result['tipos'] . ')';
        }
        
        // 3. Verificar días especiales
        $sql = "SELECT COUNT(*) as count, GROUP_CONCAT(tipo SEPARATOR ', ') as tipos
                FROM dias_especiales 
                WHERE trabajador_id = :trabajador_id
                AND tipo IN ('LC', 'L', 'L8', 'VAC', 'SUS')
                AND :fecha BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
                AND estado IN ('programado', 'activo')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':trabajador_id' => $trabajador_id, ':fecha' => $fecha]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            $errores[] = 'El trabajador tiene día especial: ' . $result['tipos'];
        }
        
        // 4. Verificar si el turno es nocturno
        $sql = "SELECT es_nocturno FROM configuracion_turnos WHERE id = :turno_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':turno_id' => $turno_id]);
        $turno = $stmt->fetch();
        
        if ($turno['es_nocturno']) {
            if (!$this->trabajadores->puedeTrabajarNoche($trabajador_id, $fecha)) {
                $errores[] = 'El trabajador tiene restricción para trabajar en turno nocturno';
            }
        }
        
        // 5. Verificar requisitos del puesto
        $sql = "SELECT * FROM puestos_trabajo WHERE id = :puesto_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':puesto_id' => $puesto_id]);
        $puesto = $stmt->fetch();
        
        if ($puesto['requiere_fuerza_fisica']) {
            if (!$this->trabajadores->puedeHacerFuerza($trabajador_id, $fecha)) {
                $errores[] = 'El puesto requiere fuerza física y el trabajador tiene restricción';
            }
        }
        
        if ($puesto['requiere_movilidad']) {
            $sql = "SELECT COUNT(*) as count FROM restricciones_trabajador 
                    WHERE trabajador_id = :trabajador_id 
                    AND tipo_restriccion = 'movilidad_limitada'
                    AND activa = true
                    AND :fecha >= fecha_inicio
                    AND (:fecha <= fecha_fin OR fecha_fin IS NULL)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':trabajador_id' => $trabajador_id, ':fecha' => $fecha]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                $errores[] = 'El puesto requiere movilidad y el trabajador tiene restricción';
            }
        }
        
        return [
            'valido' => count($errores) === 0,
            'errores' => $errores
        ];
    }
    
    
    //Asignar turno
     
    public function asignar($datos) {
        // Validar primero
        $validacion = $this->validarAsignacion(
            $datos['trabajador_id'],
            $datos['puesto_trabajo_id'],
            $datos['turno_id'],
            $datos['fecha']
        );
        
        if (!$validacion['valido']) {
            return [
                'success' => false,
                'message' => 'No se puede asignar el turno',
                'errores' => $validacion['errores']
            ];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Insertar turno
            $sql = "INSERT INTO turnos_asignados 
                    (trabajador_id, puesto_trabajo_id, turno_id, fecha, estado, observaciones, created_by) 
                    VALUES (:trabajador_id, :puesto_id, :turno_id, :fecha, :estado, :observaciones, :created_by)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':trabajador_id' => $datos['trabajador_id'],
                ':puesto_id' => $datos['puesto_trabajo_id'],
                ':turno_id' => $datos['turno_id'],
                ':fecha' => $datos['fecha'],
                ':estado' => $datos['estado'] ?? 'programado',
                ':observaciones' => $datos['observaciones'] ?? null,
                ':created_by' => $datos['created_by'] ?? null
            ]);
            
            $turno_id = $this->db->lastInsertId();
            
            // Registrar en historial
            $this->registrarHistorial($turno_id, $datos['trabajador_id'], $datos['puesto_trabajo_id'], 
                                     $datos['turno_id'], $datos['fecha'], 'creado', $datos['created_by'] ?? null);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'id' => $turno_id,
                'message' => 'Turno asignado exitosamente'
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'Error al asignar turno: ' . $e->getMessage()
            ];
        }
    }
    
    
    //Asignar múltiples turnos (asignación masiva)
    
    public function asignarMasivo($asignaciones) {
        $exitosos = 0;
        $fallidos = 0;
        $errores = [];
        
        foreach ($asignaciones as $asignacion) {
            $resultado = $this->asignar($asignacion);
            if ($resultado['success']) {
                $exitosos++;
            } else {
                $fallidos++;
                $errores[] = [
                    'trabajador_id' => $asignacion['trabajador_id'],
                    'fecha' => $asignacion['fecha'],
                    'errores' => $resultado['errores'] ?? [$resultado['message']]
                ];
            }
        }
        
        return [
            'success' => true,
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'errores' => $errores
        ];
    }
    
    
    //Actualizar turno
    
    public function actualizar($id, $datos) {
        $sql = "UPDATE turnos_asignados SET 
                estado = :estado,
                observaciones = :observaciones
                WHERE id = :id";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':estado' => $datos['estado'],
                ':observaciones' => $datos['observaciones'] ?? null
            ]);
            
            // Obtener datos del turno para historial
            $turno = $this->obtenerPorId($id);
            $this->registrarHistorial($id, $turno['trabajador_id'], $turno['puesto_trabajo_id'], 
                                     $turno['turno_id'], $turno['fecha'], 'modificado', $datos['usuario_id'] ?? null);
            
            return [
                'success' => true,
                'message' => 'Turno actualizado'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    
    //Cancelar turno
    
    public function cancelar($id, $motivo = null, $usuario_id = null) {
        $sql = "UPDATE turnos_asignados SET 
                estado = 'cancelado',
                observaciones = CONCAT(COALESCE(observaciones, ''), ' | Cancelado: ', :motivo)
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':motivo' => $motivo ?? 'Sin motivo especificado'
        ]);
        
        // Registrar en historial
        $turno = $this->obtenerPorId($id);
        $this->registrarHistorial($id, $turno['trabajador_id'], $turno['puesto_trabajo_id'], 
                                 $turno['turno_id'], $turno['fecha'], 'cancelado', $usuario_id, $motivo);
        
        return [
            'success' => true,
            'message' => 'Turno cancelado'
        ];
    }
    
    
    //Obtener turno por ID
    
    public function obtenerPorId($id) {
        $sql = "SELECT ta.*, 
                t.nombre as trabajador,
                pt.nombre as puesto,
                ct.nombre as turno
                FROM turnos_asignados ta
                INNER JOIN trabajadores t ON ta.trabajador_id = t.id
                INNER JOIN puestos_trabajo pt ON ta.puesto_trabajo_id = pt.id
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                WHERE ta.id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    
    //Obtener calendario de turnos (vista mensual)
    
    public function obtenerCalendario($mes, $anio, $area = null) {
        $fecha_inicio = "$anio-$mes-01";
        $fecha_fin = date("Y-m-t", strtotime($fecha_inicio));
        
        $sql = "SELECT 
                DATE(ta.fecha) as fecha,
                COUNT(DISTINCT ta.id) as total_turnos,
                COUNT(DISTINCT CASE WHEN ct.numero_turno = 1 THEN ta.id END) as turno_1,
                COUNT(DISTINCT CASE WHEN ct.numero_turno = 2 THEN ta.id END) as turno_2,
                COUNT(DISTINCT CASE WHEN ct.numero_turno = 3 THEN ta.id END) as turno_3,
                GROUP_CONCAT(DISTINCT pt.area SEPARATOR ',') as areas
                FROM turnos_asignados ta
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                INNER JOIN puestos_trabajo pt ON ta.puesto_trabajo_id = pt.id
                WHERE ta.fecha BETWEEN :fecha_inicio AND :fecha_fin
                AND ta.estado IN ('programado', 'activo')";
        
        $params = [
            ':fecha_inicio' => $fecha_inicio,
            ':fecha_fin' => $fecha_fin
        ];
        
        if ($area) {
            $sql .= " AND pt.area = :area";
            $params[':area'] = $area;
        }
        
        $sql .= " GROUP BY DATE(ta.fecha) ORDER BY ta.fecha ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    
    //Registrar en historial
    
    private function registrarHistorial($turno_id, $trabajador_id, $puesto_id, $turno_config_id, $fecha, $accion, $usuario_id = null, $detalles = null) {
        $sql = "INSERT INTO historial_turnos 
                (turno_asignado_id, trabajador_id, puesto_trabajo_id, turno_id, fecha, accion, usuario_id, detalles) 
                VALUES (:turno_id, :trabajador_id, :puesto_id, :turno_config_id, :fecha, :accion, :usuario_id, :detalles)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':turno_id' => $turno_id,
            ':trabajador_id' => $trabajador_id,
            ':puesto_id' => $puesto_id,
            ':turno_config_id' => $turno_config_id,
            ':fecha' => $fecha,
            ':accion' => $accion,
            ':usuario_id' => $usuario_id,
            ':detalles' => $detalles
        ]);
    }
    
    
    //Obtener estadísticas de turnos
    
    public function obtenerEstadisticas($fecha_inicio, $fecha_fin) {
        $sql = "SELECT 
                COUNT(DISTINCT ta.id) as total_turnos,
                COUNT(DISTINCT ta.trabajador_id) as trabajadores_activos,
                COUNT(DISTINCT CASE WHEN ta.estado = 'completado' THEN ta.id END) as completados,
                COUNT(DISTINCT CASE WHEN ta.estado = 'cancelado' THEN ta.id END) as cancelados,
                SUM(ct.horas_laborales) as total_horas,
                pt.area,
                COUNT(DISTINCT CASE WHEN ct.numero_turno = 1 THEN ta.id END) as turno_1,
                COUNT(DISTINCT CASE WHEN ct.numero_turno = 2 THEN ta.id END) as turno_2,
                COUNT(DISTINCT CASE WHEN ct.numero_turno = 3 THEN ta.id END) as turno_3
                FROM turnos_asignados ta
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                INNER JOIN puestos_trabajo pt ON ta.puesto_trabajo_id = pt.id
                WHERE ta.fecha BETWEEN :fecha_inicio AND :fecha_fin
                GROUP BY pt.area";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':fecha_inicio' => $fecha_inicio,
            ':fecha_fin' => $fecha_fin
        ]);
        
        return $stmt->fetchAll();
    }
}
?>
