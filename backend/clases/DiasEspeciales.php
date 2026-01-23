<?php

//Gestión de Días Especiales
//LC, L, L4, L8, SUS, VAC, ADMM, ADMT, ADM

require_once __DIR__ . '/../../config/database.php';

class DiasEspeciales {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    //Obtener días especiales
    
    public function obtener($filtros = []) {
        $sql = "SELECT de.*, t.nombre as trabajador, t.cedula
                FROM dias_especiales de
                INNER JOIN trabajadores t ON de.trabajador_id = t.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filtros['trabajador_id'])) {
            $sql .= " AND de.trabajador_id = :trabajador_id";
            $params[':trabajador_id'] = $filtros['trabajador_id'];
        }
        
        if (!empty($filtros['tipo'])) {
            $sql .= " AND de.tipo = :tipo";
            $params[':tipo'] = $filtros['tipo'];
        }
        
        if (!empty($filtros['fecha'])) {
            $sql .= " AND :fecha BETWEEN de.fecha_inicio AND COALESCE(de.fecha_fin, de.fecha_inicio)";
            $params[':fecha'] = $filtros['fecha'];
        }
        
        if (!empty($filtros['mes'])) {
            $sql .= " AND MONTH(de.fecha_inicio) = :mes";
            $params[':mes'] = $filtros['mes'];
        }
        
        $sql .= " ORDER BY de.fecha_inicio DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    
    //Crear día especial
    
    public function crear($datos) {
        // Validar solapamiento según el tipo
        if (in_array($datos['tipo'], ['LC', 'L', 'L8', 'VAC', 'SUS'])) {
            $validacion = $this->validarSolapamiento($datos['trabajador_id'], $datos['fecha_inicio'], 
                                                     $datos['fecha_fin'] ?? $datos['fecha_inicio']);
            if (!$validacion['valido']) {
                return [
                    'success' => false,
                    'message' => $validacion['mensaje']
                ];
            }
        }
        
        try {
            $this->db->beginTransaction();
            
            $sql = "INSERT INTO dias_especiales 
                    (trabajador_id, tipo, fecha_inicio, fecha_fin, horas_inicio, horas_fin, descripcion, estado) 
                    VALUES (:trabajador_id, :tipo, :fecha_inicio, :fecha_fin, :horas_inicio, :horas_fin, 
                            :descripcion, :estado)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':trabajador_id' => $datos['trabajador_id'],
                ':tipo' => $datos['tipo'],
                ':fecha_inicio' => $datos['fecha_inicio'],
                ':fecha_fin' => $datos['fecha_fin'] ?? null,
                ':horas_inicio' => $datos['horas_inicio'] ?? null,
                ':horas_fin' => $datos['horas_fin'] ?? null,
                ':descripcion' => $datos['descripcion'] ?? null,
                ':estado' => 'programado'
            ]);
            
            $id = $this->db->lastInsertId();
            
            // Si es un día de descanso completo, cancelar turnos
            if (in_array($datos['tipo'], ['LC', 'L', 'L8', 'VAC', 'SUS'])) {
                $this->cancelarTurnos($datos['trabajador_id'], $datos['fecha_inicio'], 
                                     $datos['fecha_fin'] ?? $datos['fecha_inicio']);
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'id' => $id,
                'message' => 'Día especial registrado'
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    //Actualizar día especial
    
    public function actualizar($id, $datos) {
        try {
            $sql = "UPDATE dias_especiales SET 
                    tipo = :tipo,
                    fecha_inicio = :fecha_inicio,
                    fecha_fin = :fecha_fin,
                    horas_inicio = :horas_inicio,
                    horas_fin = :horas_fin,
                    descripcion = :descripcion,
                    estado = :estado
                    WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':tipo' => $datos['tipo'],
                ':fecha_inicio' => $datos['fecha_inicio'],
                ':fecha_fin' => $datos['fecha_fin'] ?? null,
                ':horas_inicio' => $datos['horas_inicio'] ?? null,
                ':horas_fin' => $datos['horas_fin'] ?? null,
                ':descripcion' => $datos['descripcion'] ?? null,
                ':estado' => $datos['estado'] ?? 'programado'
            ]);
            
            return [
                'success' => true,
                'message' => 'Día especial actualizado'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    
    //Eliminar día especial
    
    public function eliminar($id) {
        $sql = "DELETE FROM dias_especiales WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        return [
            'success' => true,
            'message' => 'Día especial eliminado'
        ];
    }
    
    
    //Validar solapamiento
    
    private function validarSolapamiento($trabajador_id, $fecha_inicio, $fecha_fin) {
        $sql = "SELECT COUNT(*) as count, GROUP_CONCAT(tipo SEPARATOR ', ') as tipos
                FROM dias_especiales 
                WHERE trabajador_id = :trabajador_id
                AND estado IN ('programado', 'activo')
                AND tipo IN ('LC', 'L', 'L8', 'VAC', 'SUS')
                AND (
                    (:fecha_inicio BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio))
                    OR (:fecha_fin BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio))
                    OR (fecha_inicio BETWEEN :fecha_inicio AND :fecha_fin)
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':trabajador_id' => $trabajador_id,
            ':fecha_inicio' => $fecha_inicio,
            ':fecha_fin' => $fecha_fin
        ]);
        
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            return [
                'valido' => false,
                'mensaje' => 'Ya existe un día especial en estas fechas: ' . $result['tipos']
            ];
        }
        
        return ['valido' => true];
    }
    
    
    //Cancelar turnos en rango
    
    private function cancelarTurnos($trabajador_id, $fecha_inicio, $fecha_fin) {
        $sql = "UPDATE turnos_asignados SET 
                estado = 'cancelado',
                observaciones = CONCAT(COALESCE(observaciones, ''), ' | Cancelado por día especial')
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
    
    
    //Obtener cumpleaños del mes
    
    public function obtenerCumpleanosDelMes($mes, $anio) {
        // Asumir que los cumpleaños están en un campo adicional de trabajadores
        // o calcular desde la fecha de nacimiento si existe
        $sql = "SELECT t.id, t.nombre, t.cedula, 
                DATE_FORMAT(t.fecha_nacimiento, '%d') as dia_cumpleanos
                FROM trabajadores t
                WHERE MONTH(t.fecha_nacimiento) = :mes
                AND t.activo = true
                ORDER BY DAY(t.fecha_nacimiento)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':mes' => $mes]);
        return $stmt->fetchAll();
    }
    
    
    //Registrar libre cumpleaños automático
    
    public function registrarLibreCumpleanos($trabajador_id, $fecha_cumpleanos) {
        return $this->crear([
            'trabajador_id' => $trabajador_id,
            'tipo' => 'LC',
            'fecha_inicio' => $fecha_cumpleanos,
            'descripcion' => 'Libre cumpleaños - Ley 2101'
        ]);
    }
    
    
    //Obtener trabajadores disponibles en una fecha (considerando ADMM, ADMT, ADM)
    
    public function obtenerDisponibles($fecha, $horario = null) {
        $sql = "SELECT de.*, t.nombre as trabajador
                FROM dias_especiales de
                INNER JOIN trabajadores t ON de.trabajador_id = t.id
                WHERE de.tipo IN ('ADMM', 'ADMT', 'ADM')
                AND de.fecha_inicio = :fecha
                AND de.estado IN ('programado', 'activo')";
        
        $params = [':fecha' => $fecha];
        
        if ($horario === 'manana') {
            $sql .= " AND de.tipo IN ('ADMM', 'ADM')";
        } elseif ($horario === 'tarde') {
            $sql .= " AND de.tipo IN ('ADMT', 'ADM')";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    
    //Verificar si trabajador tiene día especial que impide asignación
    
    public function impideAsignacion($trabajador_id, $fecha) {
        $sql = "SELECT COUNT(*) as count, GROUP_CONCAT(tipo SEPARATOR ', ') as tipos
                FROM dias_especiales 
                WHERE trabajador_id = :trabajador_id
                AND tipo IN ('LC', 'L', 'L8', 'VAC', 'SUS')
                AND :fecha BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
                AND estado IN ('programado', 'activo')";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':trabajador_id' => $trabajador_id,
            ':fecha' => $fecha
        ]);
        
        $result = $stmt->fetch();
        
        return [
            'impide' => $result['count'] > 0,
            'tipos' => $result['tipos']
        ];
    }
}
?>
