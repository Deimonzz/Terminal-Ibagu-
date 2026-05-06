<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';

class Trabajadores {
    private $db;
    private $restriccionPuestoColumn;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->restriccionPuestoColumn = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
    }
    
    public function obtenerTodos($filtros = []) {
        $sql = "SELECT t.*, 
                (SELECT " . Database::groupConcat('rt.tipo_restriccion', ', ') . "
                 FROM restricciones_trabajador rt
                 WHERE rt.trabajador_id = t.id
                   AND rt.activa = true
                   AND (rt.fecha_fin IS NULL OR rt.fecha_fin >= " . Database::currentDate() . ")
                ) as restricciones
                FROM trabajadores t";

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
        
        $sql .= " ORDER BY t.nombre ASC";
        
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
        $puestoJoin = '';
        $selectPuesto = 'NULL AS puesto_trabajo_id, NULL AS puesto_codigo, NULL AS puesto_nombre';
        if ($this->restriccionPuestoColumn) {
            $selectPuesto = 'pt.id AS puesto_trabajo_id, pt.codigo AS puesto_codigo, pt.nombre AS puesto_nombre';
            $puestoJoin = 'LEFT JOIN puestos_trabajo pt ON rt.' . $this->restriccionPuestoColumn . ' = pt.id';
        }

        $sql = "SELECT rt.*, " . $selectPuesto . " FROM restricciones_trabajador rt " . $puestoJoin . "
                WHERE rt.trabajador_id = :id 
                AND rt.activa = true 
                AND (rt.fecha_fin IS NULL OR rt.fecha_fin >= " . Database::currentDate() . ")
                ORDER BY rt.fecha_inicio DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $trabajador_id]);
        return $stmt->fetchAll();
    }

    public function obtenerListaRestricciones($filtros = []) {
        $puestoJoin = '';
        $selectPuesto = 'NULL AS puesto_trabajo_id, NULL AS puesto_codigo, NULL AS puesto_nombre';
        if ($this->restriccionPuestoColumn) {
            $selectPuesto = 'pt.id AS puesto_trabajo_id, pt.codigo AS puesto_codigo, pt.nombre AS puesto_nombre';
            $puestoJoin = 'LEFT JOIN puestos_trabajo pt ON rt.' . $this->restriccionPuestoColumn . ' = pt.id';
        }

        $sql = "SELECT rt.*, t.nombre as trabajador_nombre, t.cedula, " . $selectPuesto . "
                FROM restricciones_trabajador rt
                INNER JOIN trabajadores t ON rt.trabajador_id = t.id
                " . $puestoJoin . "
                WHERE rt.activa = true";
        $params = [];

        if (!empty($filtros['trabajador_id'])) {
            $sql .= " AND rt.trabajador_id = :trabajador_id";
            $params[':trabajador_id'] = $filtros['trabajador_id'];
        }

        if (!empty($filtros['tipo'])) {
            $sql .= " AND rt.tipo_restriccion = :tipo";
            $params[':tipo'] = $filtros['tipo'];
        }

        $sql .= " ORDER BY rt.fecha_inicio DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function agregarRestriccion($datos) {
        $columnName = $this->restriccionPuestoColumn;
        $fields = 'trabajador_id, tipo_restriccion, descripcion, fecha_inicio, fecha_fin, documento_soporte';
        $values = ':trabajador_id, :tipo, :descripcion, :fecha_inicio, :fecha_fin, :documento';
        if ($columnName) {
            $fields = 'trabajador_id, tipo_restriccion, ' . $columnName . ', descripcion, fecha_inicio, fecha_fin, documento_soporte';
            $values = ':trabajador_id, :tipo, :puesto_id, :descripcion, :fecha_inicio, :fecha_fin, :documento';
        }

        $sql = "INSERT INTO restricciones_trabajador (" . $fields . ") VALUES (" . $values . ")";
        
        try {
            $params = [
                ':trabajador_id' => $datos['trabajador_id'],
                ':tipo' => $datos['tipo_restriccion'],
                ':descripcion' => $datos['descripcion'] ?? null,
                ':fecha_inicio' => $datos['fecha_inicio'],
                ':fecha_fin' => $datos['fecha_fin'] ?? null,
                ':documento' => $datos['documento_soporte'] ?? null
            ];
            if ($this->restriccionPuestoColumn) {
                $params[':puesto_id'] = $datos['puesto_trabajo_id'] ?? null;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
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
        $columnName = $this->restriccionPuestoColumn;
        $puestoSql = '';
        if ($columnName) {
            $puestoSql = "{$columnName} = :puesto_id,";
        }

        $sql = "UPDATE restricciones_trabajador SET 
                tipo_restriccion = :tipo,
                " . $puestoSql . "
                descripcion = :descripcion,
                fecha_inicio = :fecha_inicio,
                fecha_fin = :fecha_fin,
                activa = :activa
                WHERE id = :id";
        
        try {
            $params = [
                ':id' => $id,
                ':tipo' => $datos['tipo_restriccion'],
                ':descripcion' => $datos['descripcion'] ?? null,
                ':fecha_inicio' => $datos['fecha_inicio'],
                ':fecha_fin' => $datos['fecha_fin'] ?? null,
                ':activa' => $datos['activa'] ?? true
            ];
            if ($this->restriccionPuestoColumn) {
                $params[':puesto_id'] = $datos['puesto_trabajo_id'] ?? null;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
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
        
        $sqlTurno = "SELECT es_nocturno, numero_turno FROM configuracion_turnos WHERE id = :turno_id";
        $stmtTurno = $this->db->prepare($sqlTurno);
        $stmtTurno->execute([':turno_id' => $turno_id]);
        $turno = $stmtTurno->fetch();
        $numeroTurno = $turno['numero_turno'] ?? null;
        $fechaInicioMes = date('Y-m-01', strtotime($fecha));
        $fechaFinMes = date('Y-m-t', strtotime($fecha));
        $fechaSiguiente = date('Y-m-d', strtotime($fecha . ' +1 day'));
        $fechaAnterior = date('Y-m-d', strtotime($fecha . ' -1 day'));
        
        $sql = "SELECT DISTINCT t.*, 
                (SELECT " . Database::groupConcat('DISTINCT rt.tipo_restriccion', ', ') . "
                 FROM restricciones_trabajador rt
                 WHERE rt.trabajador_id = t.id
                   AND rt.activa = true
                   AND :fecha1 >= rt.fecha_inicio
                   AND (:fecha2 <= rt.fecha_fin OR rt.fecha_fin IS NULL)
                ) as restricciones
                FROM trabajadores t
                WHERE t.activo = true
                AND LOWER(COALESCE(t.cargo, '')) != 'supervisor'";
        
        $params = [
            ':fecha1' => $fecha,
            ':fecha2' => $fecha
        ];
        
        // Excluir trabajadores que ya tienen cualquier turno ese día
        $sql .= " AND t.id NOT IN (
            SELECT trabajador_id FROM turnos_asignados 
            WHERE fecha = :fecha3 AND estado IN ('programado', 'activo')
        )";
        $params[':fecha3'] = $fecha;

        // Nota: la verificación de puesto ya ocupado se maneja en validarAsignacion()
        
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

        if ($turno && $turno['es_nocturno']) {
            $sql .= " AND t.id NOT IN (
                SELECT ta.trabajador_id FROM turnos_asignados ta
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                WHERE ta.trabajador_id = t.id
                AND ct.numero_turno = 3
                AND ta.fecha BETWEEN :mes_inicio AND :mes_fin
                AND ta.estado IN ('programado', 'activo')
                GROUP BY ta.trabajador_id
                HAVING COUNT(*) >= 7
            )";
            $sql .= " AND t.id NOT IN (
                SELECT ta2.trabajador_id FROM turnos_asignados ta2
                INNER JOIN configuracion_turnos ct2 ON ta2.turno_id = ct2.id
                WHERE ta2.trabajador_id = t.id
                AND ta2.fecha = :fecha_next
                AND ct2.numero_turno = 1
                AND ta2.estado IN ('programado', 'activo')
            )";
            $params[':mes_inicio'] = $fechaInicioMes;
            $params[':mes_fin'] = $fechaFinMes;
            $params[':fecha_next'] = $fechaSiguiente;
        }

        if ($numeroTurno == 1) {
            $sql .= " AND t.id NOT IN (
                SELECT ta2.trabajador_id FROM turnos_asignados ta2
                INNER JOIN configuracion_turnos ct2 ON ta2.turno_id = ct2.id
                WHERE ta2.trabajador_id = t.id
                AND ta2.fecha = :fecha_prev
                AND ct2.numero_turno = 3
                AND ta2.estado IN ('programado', 'activo')
            )";
            $params[':fecha_prev'] = $fechaAnterior;
        }

        if ($puesto && $this->restriccionPuestoColumn) {
            $sql .= " AND t.id NOT IN (
                SELECT trabajador_id FROM restricciones_trabajador 
                WHERE tipo_restriccion = 'puesto_especifico'
                AND activa = true
                AND " . $this->restriccionPuestoColumn . " = :puesto_id_restriccion
                AND :fecha12 >= fecha_inicio
                AND (:fecha13 <= fecha_fin OR fecha_fin IS NULL)
            )";
            $params[':puesto_id_restriccion'] = $puesto['id'];
            $params[':fecha12'] = $fecha;
            $params[':fecha13'] = $fecha;
        }
        
        $sql .= " ORDER BY t.nombre ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    public function contarTurnosNocheEnMes($trabajador_id, $mes, $anio) {
        $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin = date('Y-m-t', strtotime($fechaInicio));

        $sql = "SELECT COUNT(*) as count FROM turnos_asignados ta
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                WHERE ta.trabajador_id = :id
                AND ct.numero_turno = 3
                AND ta.fecha BETWEEN :fi AND :ff
                AND ta.estado IN ('programado', 'activo')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $trabajador_id, ':fi' => $fechaInicio, ':ff' => $fechaFin]);
        $row = $stmt->fetch();
        return (int)($row['count'] ?? 0);
    }

    public function obtenerConteoTurnosNochePorMes($mes, $anio) {
        $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin = date('Y-m-t', strtotime($fechaInicio));

        $sql = "SELECT ta.trabajador_id, COUNT(*) as count FROM turnos_asignados ta
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                WHERE ct.numero_turno = 3
                AND ta.fecha BETWEEN :fi AND :ff
                AND ta.estado IN ('programado', 'activo')
                GROUP BY ta.trabajador_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['trabajador_id']] = (int)$row['count'];
        }
        return $result;
    }

    public function tieneTurnoNocheDiaAnterior($trabajador_id, $fecha) {
        $fechaAnterior = date('Y-m-d', strtotime($fecha . ' -1 day'));

        $sql = "SELECT COUNT(*) as count FROM turnos_asignados ta
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                WHERE ta.trabajador_id = :id
                AND ta.fecha = :fecha
                AND ct.numero_turno = 3
                AND ta.estado IN ('programado', 'activo')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $trabajador_id, ':fecha' => $fechaAnterior]);
        $row = $stmt->fetch();
        return (int)($row['count'] ?? 0) > 0;
    }

    public function tieneTurnoMananaDiaSiguiente($trabajador_id, $fecha) {
        $fechaSiguiente = date('Y-m-d', strtotime($fecha . ' +1 day'));

        $sql = "SELECT COUNT(*) as count FROM turnos_asignados ta
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                WHERE ta.trabajador_id = :id
                AND ta.fecha = :fecha
                AND ct.numero_turno = 1
                AND ta.estado IN ('programado', 'activo')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $trabajador_id, ':fecha' => $fechaSiguiente]);
        $row = $stmt->fetch();
        return (int)($row['count'] ?? 0) > 0;
    }

    // Disponibles para L4: trabajadores activos sin L4 ese día, sin día libre/incapacidad
    // Pueden tener turno normal (T1/T2/T3) — el L4 es compatible con ellos
    public function obtenerDisponiblesL4($puesto_id, $turno_id, $fecha) {
        $sql = "SELECT DISTINCT t.id, t.nombre
                FROM trabajadores t
                WHERE t.activo = true
                AND LOWER(COALESCE(t.cargo, '')) != 'supervisor'
                AND t.id NOT IN (
                    -- Excluir si ya tiene un L4 ese día
                    SELECT ta.trabajador_id FROM turnos_asignados ta
                    INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                    WHERE ta.fecha = :fecha1
                    AND ct.numero_turno IN (4, 5)
                    AND ta.estado IN ('programado', 'activo')
                )
                AND t.id NOT IN (
                    -- Excluir incapacidades activas
                    SELECT trabajador_id FROM incapacidades
                    WHERE :fecha2 BETWEEN fecha_inicio AND fecha_fin AND estado = 'activa'
                )
                AND t.id NOT IN (
                    -- Excluir días libres/vacaciones
                    SELECT trabajador_id FROM dias_especiales
                    WHERE tipo IN ('LC','L','L8','VAC','SUS')
                    AND :fecha3 BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
                    AND estado IN ('programado','activo')
                )";
        if (!empty($puesto_id) && $this->restriccionPuestoColumn) {
            $sql .= "
                AND t.id NOT IN (
                    SELECT trabajador_id FROM restricciones_trabajador
                    WHERE tipo_restriccion = 'puesto_especifico'
                    AND activa = true
                    AND " . $this->restriccionPuestoColumn . " = :puesto_id_l4
                    AND :fecha4 >= fecha_inicio
                    AND (:fecha5 <= fecha_fin OR fecha_fin IS NULL)
                )";
        }
        $sql .= "
                ORDER BY t.nombre";

        $stmt = $this->db->prepare($sql);
        $params = [
            ':fecha1' => $fecha,
            ':fecha2' => $fecha,
            ':fecha3' => $fecha
        ];
        if (!empty($puesto_id) && $this->restriccionPuestoColumn) {
            $params[':fecha4'] = $fecha;
            $params[':fecha5'] = $fecha;
            $params[':puesto_id_l4'] = $puesto_id;
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
?>