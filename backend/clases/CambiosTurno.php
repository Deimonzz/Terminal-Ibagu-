<?php

//Gestión de Cambios de Turno
//Permite intercambios, coberturas y cambios de emergencia


require_once __DIR__ . '/../../config/database.php';

class CambiosTurno {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    
    //Obtener cambios de turno
    
    public function obtenerCambios($filtros = []) {
        $sql = "SELECT 
                ct.*,
                ts.nombre as trabajador_solicitante,
                tr.nombre as trabajador_reemplazo,
                ta.fecha,
                pt.nombre as puesto,
                cfg.nombre as turno
                FROM cambios_turno ct
                INNER JOIN trabajadores ts ON ct.trabajador_solicitante_id = ts.id
                LEFT JOIN trabajadores tr ON ct.trabajador_reemplazo_id = tr.id
                INNER JOIN turnos_asignados ta ON ct.turno_original_id = ta.id
                INNER JOIN puestos_trabajo pt ON ta.puesto_trabajo_id = pt.id
                INNER JOIN configuracion_turnos cfg ON ta.turno_id = cfg.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filtros['estado'])) {
            $sql .= " AND ct.estado = :estado";
            $params[':estado'] = $filtros['estado'];
        }
        
        if (!empty($filtros['trabajador_id'])) {
            $sql .= " AND (ct.trabajador_solicitante_id = :trabajador_id OR ct.trabajador_reemplazo_id = :trabajador_id)";
            $params[':trabajador_id'] = $filtros['trabajador_id'];
        }
        
        if (!empty($filtros['fecha'])) {
            $sql .= " AND ct.fecha_cambio = :fecha";
            $params[':fecha'] = $filtros['fecha'];
        }
        
        $sql .= " ORDER BY ct.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    
    //Solicitar cambio de turno
    
    public function solicitar($datos) {
        try {
            $sql = "INSERT INTO cambios_turno 
                    (turno_original_id, trabajador_solicitante_id, trabajador_reemplazo_id, 
                     fecha_cambio, motivo, tipo_cambio, estado) 
                    VALUES (:turno_id, :solicitante_id, :reemplazo_id, :fecha, :motivo, :tipo, 'pendiente')";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':turno_id' => $datos['turno_original_id'],
                ':solicitante_id' => $datos['trabajador_solicitante_id'],
                ':reemplazo_id' => $datos['trabajador_reemplazo_id'] ?? null,
                ':fecha' => $datos['fecha_cambio'],
                ':motivo' => $datos['motivo'],
                ':tipo' => $datos['tipo_cambio']
            ]);
            
            return [
                'success' => true,
                'id' => $this->db->lastInsertId(),
                'message' => 'Solicitud de cambio registrada'
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    
    //Aprobar cambio de turno
    
    public function aprobar($id, $aprobado_por) {
        try {
            $this->db->beginTransaction();
            
            // Obtener datos del cambio
            $cambio = $this->obtenerPorId($id);
            
            if (!$cambio) {
                throw new Exception('Cambio no encontrado');
            }
            
            if ($cambio['estado'] !== 'pendiente') {
                throw new Exception('El cambio ya ha sido procesado');
            }
            
            // Aprobar el cambio
            $sql = "UPDATE cambios_turno SET 
                    estado = 'aprobado',
                    aprobado_por = :aprobado_por,
                    fecha_aprobacion = NOW()
                    WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':aprobado_por' => $aprobado_por
            ]);
            
            // Procesar según el tipo de cambio
            switch ($cambio['tipo_cambio']) {
                case 'intercambio':
                    $this->procesarIntercambio($cambio);
                    break;
                case 'cobertura':
                    $this->procesarCobertura($cambio);
                    break;
                case 'emergencia':
                    $this->procesarEmergencia($cambio);
                    break;
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Cambio aprobado y procesado'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    
    //Rechazar cambio de turno
    
    public function rechazar($id, $motivo = null) {
        $sql = "UPDATE cambios_turno SET 
                estado = 'rechazado',
                observaciones = :motivo
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':motivo' => $motivo ?? 'Sin motivo especificado'
        ]);
        
        return [
            'success' => true,
            'message' => 'Cambio rechazado'
        ];
    }
    
    
    //Procesar intercambio de turnos
    
    private function procesarIntercambio($cambio) {
        // Obtener turno del trabajador que reemplaza
        $sql = "SELECT * FROM turnos_asignados 
                WHERE trabajador_id = :reemplazo_id 
                AND fecha = :fecha 
                AND estado IN ('programado', 'activo')";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':reemplazo_id' => $cambio['trabajador_reemplazo_id'],
            ':fecha' => $cambio['fecha_cambio']
        ]);
        
        $turno_reemplazo = $stmt->fetch();
        
        if (!$turno_reemplazo) {
            throw new Exception('El trabajador de reemplazo no tiene turno asignado para intercambiar');
        }
        
        // Intercambiar los turnos
        $sql = "UPDATE turnos_asignados SET 
                trabajador_id = CASE 
                    WHEN id = :turno_original THEN :reemplazo_id
                    WHEN id = :turno_reemplazo THEN :solicitante_id
                END
                WHERE id IN (:turno_original, :turno_reemplazo)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':turno_original' => $cambio['turno_original_id'],
            ':turno_reemplazo' => $turno_reemplazo['id'],
            ':reemplazo_id' => $cambio['trabajador_reemplazo_id'],
            ':solicitante_id' => $cambio['trabajador_solicitante_id']
        ]);
    }
    
    
    //Procesar cobertura de turno
    
    private function procesarCobertura($cambio) {
        // Simplemente cambiar el trabajador asignado
        $sql = "UPDATE turnos_asignados SET 
                trabajador_id = :reemplazo_id,
                observaciones = CONCAT(COALESCE(observaciones, ''), ' | Cobertura por: ', :solicitante_id)
                WHERE id = :turno_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':turno_id' => $cambio['turno_original_id'],
            ':reemplazo_id' => $cambio['trabajador_reemplazo_id'],
            ':solicitante_id' => $cambio['trabajador_solicitante_id']
        ]);
    }
    
    
    //Procesar cambio de emergencia
    
    private function procesarEmergencia($cambio) {
        // Cancelar turno original y crear uno nuevo si hay reemplazo
        $sql = "UPDATE turnos_asignados SET 
                estado = 'cancelado',
                observaciones = CONCAT(COALESCE(observaciones, ''), ' | Cambio de emergencia: ', :motivo)
                WHERE id = :turno_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':turno_id' => $cambio['turno_original_id'],
            ':motivo' => $cambio['motivo']
        ]);
        
        // Si hay trabajador de reemplazo, crear nuevo turno
        if ($cambio['trabajador_reemplazo_id']) {
            $turno_original = $this->obtenerTurnoOriginal($cambio['turno_original_id']);
            
            $sql = "INSERT INTO turnos_asignados 
                    (trabajador_id, puesto_trabajo_id, turno_id, fecha, estado, observaciones) 
                    VALUES (:reemplazo_id, :puesto_id, :turno_config_id, :fecha, 'programado', 
                            'Reemplazo por emergencia')";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':reemplazo_id' => $cambio['trabajador_reemplazo_id'],
                ':puesto_id' => $turno_original['puesto_trabajo_id'],
                ':turno_config_id' => $turno_original['turno_id'],
                ':fecha' => $cambio['fecha_cambio']
            ]);
        }
    }
    
    
    //Obtener cambio por ID
    
    public function obtenerPorId($id) {
        $sql = "SELECT * FROM cambios_turno WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    
    //Obtener turno original
    
    private function obtenerTurnoOriginal($turno_id) {
        $sql = "SELECT * FROM turnos_asignados WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $turno_id]);
        return $stmt->fetch();
    }
    
    //Obtener cambios pendientes de un trabajador
    
    public function obtenerPendientes($trabajador_id = null) {
        $sql = "SELECT 
                ct.*,
                ts.nombre as trabajador_solicitante,
                ta.fecha,
                pt.nombre as puesto
                FROM cambios_turno ct
                INNER JOIN trabajadores ts ON ct.trabajador_solicitante_id = ts.id
                INNER JOIN turnos_asignados ta ON ct.turno_original_id = ta.id
                INNER JOIN puestos_trabajo pt ON ta.puesto_trabajo_id = pt.id
                WHERE ct.estado = 'pendiente'";
        
        $params = [];
        
        if ($trabajador_id) {
            $sql .= " AND ct.trabajador_solicitante_id = :trabajador_id";
            $params[':trabajador_id'] = $trabajador_id;
        }
        
        $sql .= " ORDER BY ct.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
?>
