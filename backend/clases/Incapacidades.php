<?php

//Gestión de Incapacidades

require_once __DIR__ . '/../../config/database.php';

class Incapacidades {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
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
            $sql .= " AND i.estado = 'activa' AND i.fecha_fin >= CURDATE()";
        }
        
        $sql .= " ORDER BY i.fecha_inicio DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    
    //Crear incapacidad
    
    public function crear($datos) {
        // Validar que no haya solapamiento
        $sql = "SELECT COUNT(*) as count FROM incapacidades 
                WHERE trabajador_id = :trabajador_id
                AND estado = 'activa'
                AND (
                    (:fecha_inicio BETWEEN fecha_inicio AND fecha_fin)
                    OR (:fecha_fin BETWEEN fecha_inicio AND fecha_fin)
                    OR (fecha_inicio BETWEEN :fecha_inicio AND :fecha_fin)
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':trabajador_id' => $datos['trabajador_id'],
            ':fecha_inicio' => $datos['fecha_inicio'],
            ':fecha_fin' => $datos['fecha_fin']
        ]);
        
        $result = $stmt->fetch();
        if ($result['count'] > 0) {
            return [
                'success' => false,
                'message' => 'Ya existe una incapacidad activa en estas fechas'
            ];
        }
        
        // Calcular días
        $fecha_inicio = new DateTime($datos['fecha_inicio']);
        $fecha_fin = new DateTime($datos['fecha_fin']);
        $dias = $fecha_inicio->diff($fecha_fin)->days + 1;
        
        try {
            $this->db->beginTransaction();
            
            $sql = "INSERT INTO incapacidades 
                    (trabajador_id, tipo, fecha_inicio, fecha_fin, dias_incapacidad, descripcion, documento_soporte, eps, estado) 
                    VALUES (:trabajador_id, :tipo, :fecha_inicio, :fecha_fin, :dias, :descripcion, :documento, :eps, :estado)";
            
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
                ':estado' => 'activa'
            ]);
            
            $incapacidad_id = $this->db->lastInsertId();
            
            // Cancelar turnos asignados durante la incapacidad
            $this->cancelarTurnosEnRango($datos['trabajador_id'], $datos['fecha_inicio'], $datos['fecha_fin']);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'id' => $incapacidad_id,
                'message' => 'Incapacidad registrada y turnos cancelados'
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            return [
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
        $sql = "UPDATE incapacidades SET 
                fecha_fin = :nueva_fecha_fin,
                estado = 'prorrogada'
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':nueva_fecha_fin' => $nueva_fecha_fin
        ]);
        
        // Obtener incapacidad para cancelar turnos adicionales
        $incapacidad = $this->obtenerPorId($id);
        $this->cancelarTurnosEnRango(
            $incapacidad['trabajador_id'], 
            $incapacidad['fecha_fin'], 
            $nueva_fecha_fin
        );
        
        return [
            'success' => true,
            'message' => 'Incapacidad prorrogada y turnos cancelados'
        ];
    }
    
    
    //Obtener incapacidad por ID
    
    public function obtenerPorId($id) {
        $sql = "SELECT i.*, t.nombre as trabajador 
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
    
    
    //Cancelar turnos en rango de fechas
    
    private function cancelarTurnosEnRango($trabajador_id, $fecha_inicio, $fecha_fin) {
        $sql = "UPDATE turnos_asignados SET 
                estado = 'cancelado',
                observaciones = CONCAT(COALESCE(observaciones, ''), ' | Cancelado por incapacidad')
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