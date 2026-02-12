<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';

class Trabajadores {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function obtenerTodos($filtros = []) {
        $sql = "SELECT t.*, 
                GROUP_CONCAT(DISTINCT rt.tipo_restriccion SEPARATOR ', ') as restricciones
                FROM trabajadores t
                LEFT JOIN restricciones_trabajador rt ON t.id = rt.trabajador_id 
                    AND rt.activa = true 
                    AND (rt.fecha_fin IS NULL OR rt.fecha_fin >= CURDATE())";

        // Por defecto solo activos, pero se puede incluir inactivos mediante filtro
        if (empty($filtros['incluir_inactivos'])) {
            $sql .= " WHERE t.activo = true";
        } else {
            $sql .= " WHERE 1=1";
        }
        
        $params = [];
        
        if (!empty($filtros['area'])) {
            $sql .= " AND t.area = :area";
            $params[':area'] = $filtros['area'];
        }
        
        if (!empty($filtros['search'])) {
            $sql .= " AND (t.nombre LIKE :search OR t.cedula LIKE :search)";
            $params[':search'] = '%' . $filtros['search'] . '%';
        }
        
        $sql .= " GROUP BY t.id ORDER BY t.nombre ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function obtenerPorId($id) {
        $sql = "SELECT * FROM trabajadores WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $trabajador = $stmt->fetch();
        
        if ($trabajador) {
            $trabajador['restricciones'] = $this->obtenerRestricciones($id);
        }
        
        return $trabajador;
    }
    
    public function crear($datos) {
        $sql = "INSERT INTO trabajadores (nombre, cedula, cargo, area, telefono, email, fecha_ingreso) 
                VALUES (:nombre, :cedula, :cargo, :area, :telefono, :email, :fecha_ingreso)";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':nombre' => $datos['nombre'],
                ':cedula' => $datos['cedula'],
                ':cargo' => $datos['cargo'] ?? null,
                ':area' => $datos['area'] ?? null,
                ':telefono' => $datos['telefono'] ?? null,
                ':email' => $datos['email'] ?? null,
                ':fecha_ingreso' => $datos['fecha_ingreso'] ?? date('Y-m-d')
            ]);
            
            return [
                'success' => true,
                'id' => $this->db->lastInsertId(),
                'message' => 'Trabajador creado exitosamente'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error al crear trabajador: ' . $e->getMessage()
            ];
        }
    }
    
    public function actualizar($id, $datos) {
        $sql = "UPDATE trabajadores SET 
                nombre = :nombre, 
                cedula = :cedula, 
                cargo = :cargo, 
                area = :area, 
                telefono = :telefono, 
                email = :email
                WHERE id = :id";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':nombre' => $datos['nombre'],
                ':cedula' => $datos['cedula'],
                ':cargo' => $datos['cargo'] ?? null,
                ':area' => $datos['area'] ?? null,
                ':telefono' => $datos['telefono'] ?? null,
                ':email' => $datos['email'] ?? null
            ]);
            
            return [
                'success' => true,
                'message' => 'Trabajador actualizado exitosamente'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ];
        }
    }

    public function eliminar($id) {
        // Verificar si tiene turnos asignados
        $sql = "SELECT COUNT(*) as count FROM turnos_asignados WHERE trabajador_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            return [
                'success' => false,
                'message' => 'No se puede eliminar. El trabajador tiene turnos asignados. Use "Desactivar" en su lugar.'
            ];
        }
        
        $sql = "DELETE FROM trabajadores WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        return [
            'success' => true,
            'message' => 'Trabajador eliminado exitosamente'
        ];
    }

    public function activar($id) {
        $sql = "UPDATE trabajadores SET activo = true WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);

        return [
            'success' => true,
            'message' => 'Trabajador activado'
        ];
    }
    
    public function desactivar($id) {
        $sql = "UPDATE trabajadores SET activo = false WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        return [
            'success' => true,
            'message' => 'Trabajador desactivado'
        ];
    }
    
    public function obtenerRestricciones($trabajador_id) {
        $sql = "SELECT * FROM restricciones_trabajador 
                WHERE trabajador_id = :id 
                AND activa = true 
                AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
                ORDER BY fecha_inicio DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $trabajador_id]);
        return $stmt->fetchAll();
    }
    
    public function agregarRestriccion($datos) {
        $sql = "INSERT INTO restricciones_trabajador 
                (trabajador_id, tipo_restriccion, descripcion, fecha_inicio, fecha_fin, documento_soporte) 
                VALUES (:trabajador_id, :tipo, :descripcion, :fecha_inicio, :fecha_fin, :documento)";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':trabajador_id' => $datos['trabajador_id'],
                ':tipo' => $datos['tipo_restriccion'],
                ':descripcion' => $datos['descripcion'] ?? null,
                ':fecha_inicio' => $datos['fecha_inicio'],
                ':fecha_fin' => $datos['fecha_fin'] ?? null,
                ':documento' => $datos['documento_soporte'] ?? null
            ]);
            
            return [
                'success' => true,
                'id' => $this->db->lastInsertId(),
                'message' => 'Restricción agregada exitosamente'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    public function actualizarRestriccion($id, $datos) {
        $sql = "UPDATE restricciones_trabajador SET 
                tipo_restriccion = :tipo,
                descripcion = :descripcion,
                fecha_inicio = :fecha_inicio,
                fecha_fin = :fecha_fin,
                activa = :activa
                WHERE id = :id";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':tipo' => $datos['tipo_restriccion'],
                ':descripcion' => $datos['descripcion'] ?? null,
                ':fecha_inicio' => $datos['fecha_inicio'],
                ':fecha_fin' => $datos['fecha_fin'] ?? null,
                ':activa' => $datos['activa'] ?? true
            ]);
            
            return [
                'success' => true,
                'message' => 'Restricción actualizada'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    public function eliminarRestriccion($id) {
        $sql = "UPDATE restricciones_trabajador SET activa = false WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        return [
            'success' => true,
            'message' => 'Restricción desactivada'
        ];
    }
    
    public function puedeTrabajarNoche($trabajador_id, $fecha) {
        $sql = "SELECT COUNT(*) as count FROM restricciones_trabajador 
                WHERE trabajador_id = :id 
                AND tipo_restriccion = 'no_turno_noche'
                AND activa = true
                AND :fecha >= fecha_inicio
                AND (:fecha2 <= fecha_fin OR fecha_fin IS NULL)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $trabajador_id,
            ':fecha' => $fecha,
            ':fecha2' => $fecha
        ]);
        
        $result = $stmt->fetch();
        return $result['count'] == 0;
    }
    
    public function puedeHacerFuerza($trabajador_id, $fecha) {
        $sql = "SELECT COUNT(*) as count FROM restricciones_trabajador 
                WHERE trabajador_id = :id 
                AND tipo_restriccion = 'no_fuerza_fisica'
                AND activa = true
                AND :fecha >= fecha_inicio
                AND (:fecha2 <= fecha_fin OR fecha_fin IS NULL)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $trabajador_id,
            ':fecha' => $fecha,
            ':fecha2' => $fecha
        ]);
        
        $result = $stmt->fetch();
        return $result['count'] == 0;
    }
    
    public function obtenerDisponibles($puesto_id, $turno_id, $fecha) {
        $sqlPuesto = "SELECT * FROM puestos_trabajo WHERE id = :puesto_id";
        $stmtPuesto = $this->db->prepare($sqlPuesto);
        $stmtPuesto->execute([':puesto_id' => $puesto_id]);
        $puesto = $stmtPuesto->fetch();
        
        $sqlTurno = "SELECT es_nocturno FROM configuracion_turnos WHERE id = :turno_id";
        $stmtTurno = $this->db->prepare($sqlTurno);
        $stmtTurno->execute([':turno_id' => $turno_id]);
        $turno = $stmtTurno->fetch();
        
        $sql = "SELECT DISTINCT t.*, 
                GROUP_CONCAT(DISTINCT rt.tipo_restriccion SEPARATOR ', ') as restricciones
                FROM trabajadores t
                LEFT JOIN restricciones_trabajador rt ON t.id = rt.trabajador_id 
                    AND rt.activa = true 
                    AND :fecha1 >= rt.fecha_inicio
                    AND (:fecha2 <= rt.fecha_fin OR rt.fecha_fin IS NULL)
                WHERE t.activo = true";
        
        $params = [
            ':fecha1' => $fecha,
            ':fecha2' => $fecha
        ];
        
        $sql .= " AND t.id NOT IN (
            SELECT trabajador_id FROM turnos_asignados 
            WHERE fecha = :fecha3 AND estado IN ('programado', 'activo')
        )";
        $params[':fecha3'] = $fecha;
        
        $sql .= " AND t.id NOT IN (
            SELECT trabajador_id FROM incapacidades 
            WHERE :fecha4 BETWEEN fecha_inicio AND fecha_fin AND estado = 'activa'
        )";
        $params[':fecha4'] = $fecha;
        
        $sql .= " AND t.id NOT IN (
            SELECT trabajador_id FROM dias_especiales 
            WHERE tipo IN ('LC', 'L', 'L8', 'VAC', 'SUS')
            AND :fecha5 BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
            AND estado IN ('programado', 'activo')
        )";
        $params[':fecha5'] = $fecha;
        
        if ($turno && $turno['es_nocturno']) {
            $sql .= " AND t.id NOT IN (
                SELECT trabajador_id FROM restricciones_trabajador 
                WHERE tipo_restriccion = 'no_turno_noche'
                AND activa = true
                AND :fecha6 >= fecha_inicio
                AND (:fecha7 <= fecha_fin OR fecha_fin IS NULL)
            )";
            $params[':fecha6'] = $fecha;
            $params[':fecha7'] = $fecha;
        }
        
        if ($puesto && $puesto['requiere_fuerza_fisica']) {
            $sql .= " AND t.id NOT IN (
                SELECT trabajador_id FROM restricciones_trabajador 
                WHERE tipo_restriccion = 'no_fuerza_fisica'
                AND activa = true
                AND :fecha8 >= fecha_inicio
                AND (:fecha9 <= fecha_fin OR fecha_fin IS NULL)
            )";
            $params[':fecha8'] = $fecha;
            $params[':fecha9'] = $fecha;
        }
        
        if ($puesto && $puesto['requiere_movilidad']) {
            $sql .= " AND t.id NOT IN (
                SELECT trabajador_id FROM restricciones_trabajador 
                WHERE tipo_restriccion = 'movilidad_limitada'
                AND activa = true
                AND :fecha10 >= fecha_inicio
                AND (:fecha11 <= fecha_fin OR fecha_fin IS NULL)
            )";
            $params[':fecha10'] = $fecha;
            $params[':fecha11'] = $fecha;
        }
        
        $sql .= " GROUP BY t.id ORDER BY t.nombre ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
}
?>