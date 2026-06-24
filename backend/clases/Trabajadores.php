<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';

class Trabajadores {
    private $db;
    private $restriccionPuestoColumn;
    private $puestoCache = [];
    private $turnoCache = [];
    private $disponiblesTurnoCache = [];
    private $disponiblesL4Cache = [];
    private $restriccionPuestoEspecificoCache = [];
    private $restriccionTipoCache = [];
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->restriccionPuestoColumn = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
    }

    private function getPuesto($puesto_id) {
        if (!isset($this->puestoCache[$puesto_id])) {
            $stmt = $this->db->prepare("SELECT * FROM puestos_trabajo WHERE id = :puesto_id");
            $stmt->execute([':puesto_id' => $puesto_id]);
            $this->puestoCache[$puesto_id] = $stmt->fetch();
        }
        return $this->puestoCache[$puesto_id];
    }

    private function getTurno($turno_id) {
        if (!isset($this->turnoCache[$turno_id])) {
            $stmt = $this->db->prepare("SELECT es_nocturno, numero_turno FROM configuracion_turnos WHERE id = :turno_id");
            $stmt->execute([':turno_id' => $turno_id]);
            $this->turnoCache[$turno_id] = $stmt->fetch();
        }
        return $this->turnoCache[$turno_id];
    }

    public function obtenerDisponiblesTurno($turno_id, $fecha) {
        $cacheKey = $turno_id . '|' . $fecha;
        if (isset($this->disponiblesTurnoCache[$cacheKey])) {
            return $this->disponiblesTurnoCache[$cacheKey];
        }

        $turno = $this->getTurno($turno_id);
        $numeroTurno = $turno['numero_turno'] ?? null;
        $fechaSiguiente = date('Y-m-d', strtotime($fecha . ' +1 day'));
        $fechaAnterior = date('Y-m-d', strtotime($fecha . ' -1 day'));
        $fechaInicioMes = date('Y-m-01', strtotime($fecha));
        $fechaFinMes = date('Y-m-t', strtotime($fecha));

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
                    WHERE tipo IN ('LC', 'L', 'L8', 'VAC', 'SUS', 'ADM', 'ADMM', 'ADMT')
                    AND :fecha_lib BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
                    AND estado IN ('programado', 'activo')
                )";

        $params = [
            ':fecha_assigned' => $fecha,
            ':fecha_inca' => $fecha,
            ':fecha_lib' => $fecha
        ];

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

        $sql .= " ORDER BY t.nombre ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $this->disponiblesTurnoCache[$cacheKey] = $stmt->fetchAll();
        return $this->disponiblesTurnoCache[$cacheKey];
    }

    private function obtenerTrabajadoresConRestriccionPuestoEspecifico($puesto_id, $fecha) {
        $cacheKey = $puesto_id . '|' . $fecha;
        if (isset($this->restriccionPuestoEspecificoCache[$cacheKey])) {
            return $this->restriccionPuestoEspecificoCache[$cacheKey];
        }

        $sql = "SELECT trabajador_id FROM restricciones_trabajador
                WHERE tipo_restriccion = 'puesto_especifico'
                AND activa = true
                AND " . $this->restriccionPuestoColumn . " = :puesto_id
                AND :fecha >= fecha_inicio
                AND (:fecha2 <= fecha_fin OR fecha_fin IS NULL)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':puesto_id' => $puesto_id, ':fecha' => $fecha, ':fecha2' => $fecha]);
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->restriccionPuestoEspecificoCache[$cacheKey] = $result;
        return $result;
    }

    private function obtenerTrabajadoresConRestriccionTipoFecha($tipo, $fecha) {
        $cacheKey = $tipo . '|' . $fecha;
        if (isset($this->restriccionTipoCache[$cacheKey])) {
            return $this->restriccionTipoCache[$cacheKey];
        }

        $sql = "SELECT trabajador_id FROM restricciones_trabajador
                WHERE tipo_restriccion = :tipo
                AND activa = true
                AND :fecha >= fecha_inicio
                AND (:fecha2 <= fecha_fin OR fecha_fin IS NULL)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tipo' => $tipo, ':fecha' => $fecha, ':fecha2' => $fecha]);
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->restriccionTipoCache[$cacheKey] = $result;
        return $result;
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
        return ['success' => true, 'message' => 'Trabajador activado'];
    }
    
    public function desactivar($id) {
        $sql = "UPDATE trabajadores SET activo = false WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return ['success' => true, 'message' => 'Trabajador desactivado'];
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
        $resultados = $stmt->fetchAll();
        
        if (!empty($resultados) && $this->restriccionPuestoColumn) {
            foreach ($resultados as $key => $restriccion) {
                if (isset($restriccion['tipo_restriccion']) && $restriccion['tipo_restriccion'] === 'puesto_especifico') {
                    if ((empty($restriccion['puesto_codigo']) && empty($restriccion['puesto_nombre'])) 
                        && !empty($restriccion[$this->restriccionPuestoColumn])) {
                        try {
                            $puesto = $this->getPuesto($restriccion[$this->restriccionPuestoColumn]);
                            if ($puesto) {
                                $resultados[$key]['puesto_trabajo_id'] = $puesto['id'] ?? null;
                                $resultados[$key]['puesto_codigo'] = $puesto['codigo'] ?? null;
                                $resultados[$key]['puesto_nombre'] = $puesto['nombre'] ?? null;
                            }
                        } catch (Exception $e) {}
                    }
                }
            }
        }
        
        return $resultados;
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
        $resultados = $stmt->fetchAll();
        
        if (!empty($resultados) && $this->restriccionPuestoColumn) {
            foreach ($resultados as $key => $restriccion) {
                if (isset($restriccion['tipo_restriccion']) && $restriccion['tipo_restriccion'] === 'puesto_especifico') {
                    if ((empty($restriccion['puesto_codigo']) && empty($restriccion['puesto_nombre'])) 
                        && !empty($restriccion[$this->restriccionPuestoColumn])) {
                        try {
                            $puesto = $this->getPuesto($restriccion[$this->restriccionPuestoColumn]);
                            if ($puesto) {
                                $resultados[$key]['puesto_trabajo_id'] = $puesto['id'] ?? null;
                                $resultados[$key]['puesto_codigo'] = $puesto['codigo'] ?? null;
                                $resultados[$key]['puesto_nombre'] = $puesto['nombre'] ?? null;
                            }
                        } catch (Exception $e) {}
                    }
                }
            }
        }
        
        return $resultados;
    }
    
    public function agregarRestriccion($datos) {
        $columnName = $this->restriccionPuestoColumn;
        $puestoTrabId = $datos['puesto_trabajo_id'] ?? null;
        
        if ($datos['tipo_restriccion'] === 'puesto_especifico' && $puestoTrabId && !$columnName) {
            $this->asegurarColumnaRestricciones();
            $columnName = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
        }
        
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
            if ($columnName) {
                $params[':puesto_id'] = $puestoTrabId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return [
                'success' => true,
                'id' => $this->db->lastInsertId(),
                'message' => 'Restricción agregada exitosamente'
            ];
        } catch (PDOException $e) {
            error_log("[Trabajadores::agregarRestriccion] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    private function asegurarColumnaRestricciones() {
        try {
            $columnExists = Database::hasColumn('restricciones_trabajador', 'puesto_trabajo_id') ||
                           Database::hasColumn('restricciones_trabajador', 'puesto_id');
            if ($columnExists) return true;
            
            if (DB_DRIVER === 'pgsql') {
                $sql = "ALTER TABLE restricciones_trabajador ADD COLUMN IF NOT EXISTS puesto_trabajo_id INTEGER";
            } else {
                $sql = "ALTER TABLE `restricciones_trabajador` ADD COLUMN `puesto_trabajo_id` INT NULL AFTER `tipo_restriccion`";
            }
            $this->db->exec($sql);
            return true;
        } catch (Exception $e) {
            error_log("[Trabajadores::asegurarColumnaRestricciones] Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function actualizarRestriccion($id, $datos) {
        $columnName = $this->restriccionPuestoColumn;
        $puestoTrabId = $datos['puesto_trabajo_id'] ?? null;
        
        if ($datos['tipo_restriccion'] === 'puesto_especifico' && $puestoTrabId && !$columnName) {
            $this->asegurarColumnaRestricciones();
            $columnName = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
        }
        
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
            if ($columnName) {
                $params[':puesto_id'] = $puestoTrabId;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['success' => true, 'message' => 'Restricción actualizada'];
        } catch (PDOException $e) {
            error_log("[Trabajadores::actualizarRestriccion] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function eliminarRestriccion($id) {
        $sql = "UPDATE restricciones_trabajador SET activa = false WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return ['success' => true, 'message' => 'Restricción desactivada'];
    }
    
    public function puedeTrabajarNoche($trabajador_id, $fecha) {
        $sql = "SELECT COUNT(*) as count FROM restricciones_trabajador 
                WHERE trabajador_id = :id 
                AND tipo_restriccion = 'no_turno_noche'
                AND activa = true
                AND :fecha >= fecha_inicio
                AND (:fecha2 <= fecha_fin OR fecha_fin IS NULL)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $trabajador_id, ':fecha' => $fecha, ':fecha2' => $fecha]);
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
        $stmt->execute([':id' => $trabajador_id, ':fecha' => $fecha, ':fecha2' => $fecha]);
        $result = $stmt->fetch();
        return $result['count'] == 0;
    }
    
    public function obtenerDisponibles($puesto_id, $turno_id, $fecha) {
        $puesto = $this->getPuesto($puesto_id);
        $disponibles = $this->obtenerDisponiblesTurno($turno_id, $fecha);

        $bloqueados = [];
        if ($puesto && $this->restriccionPuestoColumn) {
            $bloqueados = array_merge($bloqueados, $this->obtenerTrabajadoresConRestriccionPuestoEspecifico($puesto['id'], $fecha));
        }
        if ($puesto && !empty($puesto['requiere_fuerza_fisica'])) {
            $bloqueados = array_merge($bloqueados, $this->obtenerTrabajadoresConRestriccionTipoFecha('no_fuerza_fisica', $fecha));
        }
        if ($puesto && !empty($puesto['requiere_movilidad'])) {
            $bloqueados = array_merge($bloqueados, $this->obtenerTrabajadoresConRestriccionTipoFecha('movilidad_limitada', $fecha));
        }

        if (!empty($bloqueados)) {
            $bloqueados = array_unique($bloqueados);
            $disponibles = array_values(array_filter($disponibles, function ($t) use ($bloqueados) {
                return !in_array($t['id'], $bloqueados);
            }));
        }

        return $disponibles;
    }

    /**
     * Versión relajada de obtenerDisponibles para fallback de cobertura.
     *
     * Modos:
     *  - 'ignorar_limite_noches': quita el HAVING COUNT(*) >= 7 para turno 3
     *  - 'ignorar_consecutivo':   quita restricción T1↔T3 entre días consecutivos
     *  - 'minimo':                solo bloquea incapacidad activa y día libre (último recurso)
     */
    public function obtenerDisponiblesRelajado($puesto_id, $turno_id, $fecha, $modo = 'minimo') {
        $turno       = $this->getTurno($turno_id);
        $numeroTurno = $turno['numero_turno'] ?? null;
        $fechaSig    = date('Y-m-d', strtotime($fecha . ' +1 day'));
        $fechaAnt    = date('Y-m-d', strtotime($fecha . ' -1 day'));
        $fechaIniMes = date('Y-m-01', strtotime($fecha));
        $fechaFinMes = date('Y-m-t',  strtotime($fecha));

        // Bloqueos siempre activos (incapacidad + día libre + ya asignado hoy)
        $sql = "SELECT DISTINCT t.id, t.nombre
                FROM trabajadores t
                WHERE t.activo = true
                AND LOWER(COALESCE(t.cargo, '')) != 'supervisor'
                AND t.id NOT IN (
                    SELECT trabajador_id FROM turnos_asignados
                    WHERE fecha = :fecha_assigned
                    AND estado IN ('programado','activo')
                )
                AND t.id NOT IN (
                    SELECT trabajador_id FROM incapacidades
                    WHERE :fecha_inca BETWEEN fecha_inicio AND fecha_fin
                    AND estado = 'activa'
                )
                AND t.id NOT IN (
                    SELECT trabajador_id FROM dias_especiales
                    WHERE tipo IN ('LC','L','L8','VAC','SUS','ADM','ADMM','ADMT')
                    AND :fecha_lib BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
                    AND estado IN ('programado','activo')
                )";

        $params = [
            ':fecha_assigned' => $fecha,
            ':fecha_inca'     => $fecha,
            ':fecha_lib'      => $fecha,
        ];

        // Restricción no_turno_noche: se mantiene excepto en modo 'minimo'
        if ($numeroTurno == 3 && $modo !== 'minimo') {
            $sql .= "
                AND t.id NOT IN (
                    SELECT trabajador_id FROM restricciones_trabajador
                    WHERE tipo_restriccion = 'no_turno_noche'
                    AND activa = true
                    AND :fecha_noche >= fecha_inicio
                    AND (:fecha_noche2 <= fecha_fin OR fecha_fin IS NULL)
                )";
            $params[':fecha_noche']  = $fecha;
            $params[':fecha_noche2'] = $fecha;
        }

        // Límite 7 noches: solo se aplica en modo 'ignorar_consecutivo' (no en los otros relajados)
        if ($numeroTurno == 3 && $modo === 'ignorar_consecutivo') {
            $sql .= "
                AND t.id NOT IN (
                    SELECT ta.trabajador_id FROM turnos_asignados ta
                    INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                    WHERE ct.numero_turno = 3
                    AND ta.fecha BETWEEN :mes_inicio AND :mes_fin
                    AND ta.estado IN ('programado','activo')
                    GROUP BY ta.trabajador_id
                    HAVING COUNT(*) >= 7
                )";
            $params[':mes_inicio'] = $fechaIniMes;
            $params[':mes_fin']    = $fechaFinMes;
        }

        // Restricción T3→T1: no asignar T3 si mañana tiene T1
        // Se mantiene en 'ignorar_limite_noches', se quita en 'ignorar_consecutivo' y 'minimo'
        if ($numeroTurno == 3 && $modo === 'ignorar_limite_noches') {
            $sql .= "
                AND t.id NOT IN (
                    SELECT ta2.trabajador_id FROM turnos_asignados ta2
                    INNER JOIN configuracion_turnos ct2 ON ta2.turno_id = ct2.id
                    WHERE ta2.fecha = :fecha_next
                    AND ct2.numero_turno = 1
                    AND ta2.estado IN ('programado','activo')
                )";
            $params[':fecha_next'] = $fechaSig;
        }

        // Restricción T1→T3: no asignar T1 si ayer tuvo T3
        // Se mantiene en 'ignorar_limite_noches', se quita en 'ignorar_consecutivo' y 'minimo'
        if ($numeroTurno == 1 && $modo === 'ignorar_limite_noches') {
            $sql .= "
                AND t.id NOT IN (
                    SELECT ta2.trabajador_id FROM turnos_asignados ta2
                    INNER JOIN configuracion_turnos ct2 ON ta2.turno_id = ct2.id
                    WHERE ta2.fecha = :fecha_prev
                    AND ct2.numero_turno = 3
                    AND ta2.estado IN ('programado','activo')
                )";
            $params[':fecha_prev'] = $fechaAnt;
        }

        $sql .= " ORDER BY t.nombre ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $disponibles = $stmt->fetchAll();

        // Restricciones de puesto: se aplican en todos los modos excepto 'minimo'
        if ($modo !== 'minimo' && !empty($disponibles)) {
            $puesto     = $this->getPuesto($puesto_id);
            $bloqueados = [];
            if ($puesto && $this->restriccionPuestoColumn) {
                $bloqueados = array_merge($bloqueados,
                    $this->obtenerTrabajadoresConRestriccionPuestoEspecifico($puesto_id, $fecha));
            }
            if ($puesto && !empty($puesto['requiere_fuerza_fisica'])) {
                $bloqueados = array_merge($bloqueados,
                    $this->obtenerTrabajadoresConRestriccionTipoFecha('no_fuerza_fisica', $fecha));
            }
            if ($puesto && !empty($puesto['requiere_movilidad'])) {
                $bloqueados = array_merge($bloqueados,
                    $this->obtenerTrabajadoresConRestriccionTipoFecha('movilidad_limitada', $fecha));
            }
            if (!empty($bloqueados)) {
                $bloqueados  = array_unique($bloqueados);
                $disponibles = array_values(array_filter($disponibles,
                    fn($t) => !in_array($t['id'], $bloqueados)));
            }
        }

        return $disponibles;
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

    public function obtenerDisponiblesL4($puesto_id, $turno_id, $fecha) {
        $cacheKey = $fecha;
        if (!isset($this->disponiblesL4Cache[$cacheKey])) {
            $sql = "SELECT DISTINCT t.id, t.nombre
                    FROM trabajadores t
                    WHERE t.activo = true
                    AND LOWER(COALESCE(t.cargo, '')) != 'supervisor'
                    AND t.id NOT IN (
                        SELECT ta.trabajador_id FROM turnos_asignados ta
                        INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                        WHERE ta.fecha = :fecha1
                        AND ct.numero_turno IN (4, 5)
                        AND ta.estado IN ('programado', 'activo')
                    )
                    AND t.id NOT IN (
                        SELECT trabajador_id FROM incapacidades
                        WHERE :fecha2 BETWEEN fecha_inicio AND fecha_fin AND estado = 'activa'
                    )
                    AND t.id NOT IN (
                        SELECT trabajador_id FROM dias_especiales
                        WHERE tipo IN ('LC','L','L8','VAC','SUS','ADM','ADMM','ADMT')
                        AND :fecha3 BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
                        AND estado IN ('programado','activo')
                    )
                    ORDER BY t.nombre";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':fecha1' => $fecha, ':fecha2' => $fecha, ':fecha3' => $fecha]);
            $this->disponiblesL4Cache[$cacheKey] = $stmt->fetchAll();
        }

        $disponibles = $this->disponiblesL4Cache[$cacheKey];
        if (!empty($puesto_id) && $this->restriccionPuestoColumn) {
            $bloqueados = $this->obtenerTrabajadoresConRestriccionPuestoEspecifico($puesto_id, $fecha);
            if (!empty($bloqueados)) {
                $disponibles = array_values(array_filter($disponibles, function ($t) use ($bloqueados) {
                    return !in_array($t['id'], $bloqueados);
                }));
            }
        }

        return $disponibles;
    }
}
?>