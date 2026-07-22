<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Trabajadores.php';

class TurnosAsignados {
    private $db;
    private $trabajadores;
    private $validacionCache = [];
    private $turnoCache = [];
    private $puestoCache = [];
    private const PUESTOS_FIJOS_8H = ['C', 'D3', 'F6', 'F11', 'F14', 'G', 'V1', 'V2'];
    private const PUESTOS_DIURNOS_7H = ['D1', 'D2', 'D4', 'F15', 'F2', 'F5'];
    private const PUESTOS_MOVILIDAD_LIMITADA = ['V1', 'V2'];
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->trabajadores = new Trabajadores();
    }

    private function getTurno($turno_id) {
        $turno_id = (int)$turno_id;
        if ($turno_id <= 0) {
            return null;
        }
        if (!isset($this->turnoCache[$turno_id])) {
            $stmt = $this->db->prepare("SELECT es_nocturno, numero_turno, horas_laborales FROM configuracion_turnos WHERE id = :turno_id");
            $stmt->execute([':turno_id' => $turno_id]);
            $this->turnoCache[$turno_id] = $stmt->fetch();
        }
        return $this->turnoCache[$turno_id];
    }

    private function getPuesto($puesto_id) {
        $puesto_id = (int)$puesto_id;
        if ($puesto_id <= 0) {
            return null;
        }
        if (!isset($this->puestoCache[$puesto_id])) {
            $stmt = $this->db->prepare("SELECT * FROM puestos_trabajo WHERE id = :puesto_id");
            $stmt->execute([':puesto_id' => $puesto_id]);
            $this->puestoCache[$puesto_id] = $stmt->fetch();
        }
        return $this->puestoCache[$puesto_id];
    }
    
    public function obtenerTurnos($filtros = []) {
        // Detectar el nombre correcto de la columna puesto
        $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
        $selectPuesto = $puestoCol ? "ta.$puestoCol" : "NULL";
        $puestoJoin = $puestoCol ? "INNER JOIN puestos_trabajo pt ON ta.$puestoCol = pt.id" : "";
        
        $sql = "SELECT 
                ta.id,
                ta.fecha,
                ta.estado,
                ta.observaciones,
                t.id as trabajador_id,
                t.nombre as trabajador,
                t.cedula,
                " . ($puestoCol ? "pt.id as puesto_id," : "NULL as puesto_id,") . "
                " . ($puestoCol ? "CASE WHEN pt.codigo = 'F4' THEN 'F2' ELSE pt.codigo END as puesto_codigo," : "NULL as puesto_codigo,") . "
                " . ($puestoCol ? "pt.nombre as puesto_nombre," : "NULL as puesto_nombre,") . "
                " . ($puestoCol ? "pt.area," : "NULL as area,") . "
                ct.numero_turno,
                ct.nombre as turno_nombre,
                ct.hora_inicio,
                ct.hora_fin,
                ct.horas_laborales
                FROM turnos_asignados ta
                INNER JOIN trabajadores t ON ta.trabajador_id = t.id
                " . $puestoJoin . "
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
     
    private function invalidarCacheValidaciones($trabajador_id = null, $fecha = null) {
        if ($trabajador_id === null) {
            $this->validacionCache = [];
            return;
        }

        $trabajador_id = (int)$trabajador_id;
        $fecha = $fecha !== null ? (string)$fecha : null;
        $prefix = 'v|' . $trabajador_id . '|';

        foreach (array_keys($this->validacionCache) as $cacheKey) {
            if (strpos($cacheKey, $prefix) !== 0) {
                continue;
            }

            if ($fecha === null) {
                unset($this->validacionCache[$cacheKey]);
                continue;
            }

            $parts = explode('|', $cacheKey);
            if (isset($parts[4]) && $parts[4] === $fecha) {
                unset($this->validacionCache[$cacheKey]);
            }
        }
    }

    public function validarAsignacion($trabajador_id, $puesto_id, $turno_id, $fecha, $exclude_id = null) {
        $cacheKey = 'v|' . (int)$trabajador_id . '|' . (int)($puesto_id ?? 0) . '|' . (int)$turno_id . '|' . (string)$fecha . '|' . (string)($exclude_id ?? '');
        if (array_key_exists($cacheKey, $this->validacionCache)) {
            return $this->validacionCache[$cacheKey];
        }

        $errores = [];
        
        // Detectar el nombre correcto de la columna puesto en turnos_asignados
        $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');

        // Detectar si el turno actual es un L4, porque L4 puede coexistir con el turno base
        $esL4 = false;
        $turnoMeta = $this->getTurno($turno_id);
        if ($turnoMeta) {
            $esL4 = in_array((int)($turnoMeta['numero_turno'] ?? 0), [4, 5], true)
                && (float)($turnoMeta['horas_laborales'] ?? 0) >= 3.5
                && (float)($turnoMeta['horas_laborales'] ?? 0) <= 4.5;
        }

        // 1. Verificar si el puesto ya está ocupado en ese turno ese día
        // (Solo si la columna de puesto existe y no es un L4)
        if ($puestoCol && !$esL4) {
            $sql = "SELECT COUNT(*) as count, t.nombre as trabajador_asignado
                    FROM turnos_asignados ta
                    INNER JOIN trabajadores t ON ta.trabajador_id = t.id
                    INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                    INNER JOIN configuracion_turnos ct2 ON ct2.id = :turno_id
                    WHERE ta." . $puestoCol . " = :puesto_id
                    AND ct.numero_turno = ct2.numero_turno
                    AND ta.fecha = :fecha
                    AND ta.estado IN ('programado', 'activo')";

            $params = [
                ':puesto_id' => $puesto_id,
                ':turno_id' => $turno_id,
                ':fecha' => $fecha
            ];

            if ($exclude_id !== null) {
                $sql .= " AND ta.id != :exclude_id";
                $params[':exclude_id'] = $exclude_id;
            }

            $sql .= " GROUP BY t.nombre";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();

            if ($result && $result['count'] > 0) {
                $errores[] = 'El puesto ya está ocupado por: ' . $result['trabajador_asignado'];
            }
        }
        
        // 2. Verificar incapacidad activa
        $sql = "SELECT COUNT(*) as count, " . Database::groupConcat('tipo', ', ') . " as tipos
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
        $sql = "SELECT COUNT(*) as count, " . Database::groupConcat('tipo', ', ') . " as tipos
                FROM dias_especiales 
                WHERE trabajador_id = :trabajador_id
                AND tipo IN ('LC', 'L', 'L8', 'VAC', 'SUS', 'CAP', 'ADM', 'ADMM', 'ADMT')
                AND :fecha BETWEEN fecha_inicio AND COALESCE(fecha_fin, fecha_inicio)
                AND estado IN ('programado', 'activo')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':trabajador_id' => $trabajador_id, ':fecha' => $fecha]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            $errores[] = 'El trabajador tiene día especial: ' . $result['tipos'];
        }

        // 3.1 Verificar que el trabajador no tenga ya otro turno el mismo día.
        // El índice unique_asignacion en la base de datos impide más de una asignación
        // activa por trabajador/fecha, por lo que cualquier turno adicional ese día
        // debe rechazarse aquí también.
        $sql = "SELECT COUNT(*) as count
                FROM turnos_asignados
                WHERE trabajador_id = :trabajador_id
                AND fecha = :fecha
                AND estado IN ('programado', 'activo')";
        $params = [
            ':trabajador_id' => $trabajador_id,
            ':fecha' => $fecha
        ];
        if ($exclude_id !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $exclude_id;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        if ((int)($result['count'] ?? 0) > 0) {
            $errores[] = 'El trabajador ya tiene otro turno asignado ese día';
        }
        
        // 4. Verificar restricciones obligatorias de forma general para cualquier turno
        try {
            $sql = "SELECT es_nocturno, numero_turno, horas_laborales FROM configuracion_turnos WHERE id = :turno_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':turno_id' => $turno_id]);
            $turno = $stmt->fetch();
            
            $esTurnoNocturno = $turno && (
                !empty($turno['es_nocturno'])
                || (int)($turno['numero_turno'] ?? 0) === 3
            );

            if ($esTurnoNocturno && !$this->trabajadores->puedeTrabajarNoche($trabajador_id, $fecha)) {
                $errores[] = 'El trabajador no puede trabajar en turno de noche';
            } elseif (!$this->trabajadores->puedeAsignarTurno($trabajador_id, $puesto_id, $turno_id, $fecha)) {
                $errores[] = 'El trabajador tiene una restricción que impide trabajar en este turno';
            }

            if ($esTurnoNocturno) {
                $mes = (int)date('n', strtotime($fecha));
                $anio = (int)date('Y', strtotime($fecha));
                if ($this->trabajadores->contarTurnosNocheEnMes($trabajador_id, $mes, $anio, $exclude_id) >= 6) {
                    $errores[] = 'El trabajador ya tiene el máximo de 6 turnos de noche en el mes';
                }

                if ($this->trabajadores->tieneTurnoMananaDiaSiguiente($trabajador_id, $fecha)) {
                    $errores[] = 'El trabajador no puede tener turno de noche si tiene turno en la mañana siguiente';
                }
            }

            if ($turno && (int)$turno['numero_turno'] === 1) {
                if ($this->trabajadores->tieneTurnoNocheDiaAnterior($trabajador_id, $fecha)) {
                    $errores[] = 'El trabajador no puede tener turno en la mañana si tuvo turno de noche la noche anterior';
                }
            }

            // Regla de coexistencia por puesto/base:
            // en un mismo puesto y dia, una base (manana/tarde) permite:
            // - 1 turno normal (7h/8h), o
            // - hasta 2 turnos L4 encadenados.
            if ($puestoCol && $turno && $puesto_id) {
                $numTurnoActual = (int)($turno['numero_turno'] ?? 0);
                $horasTurnoActual = (float)($turno['horas_laborales'] ?? 0);
                $esL4Actual = in_array($numTurnoActual, [4, 5], true)
                    && $horasTurnoActual >= 3.5
                    && $horasTurnoActual <= 4.5;

                $baseTurno = null;
                if ($esL4Actual) {
                    $baseTurno = ($numTurnoActual === 4) ? 1 : 2;
                } elseif (in_array($numTurnoActual, [1, 2], true)) {
                    $baseTurno = $numTurnoActual;
                }

                if ($baseTurno !== null) {
                    $l4Numero = ($baseTurno === 1) ? 4 : 5;

                    if ($esL4Actual) {
                        // En este flujo el L4 puede coexistir con la base normal; solo aplicamos
                        // el límite de cantidad de L4 por base/día para evitar saturar el puesto.
                        $sqlNormalBase = "SELECT COUNT(*) as count
                                          FROM turnos_asignados ta
                                          INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                                          WHERE ta." . $puestoCol . " = :puesto_id
                                          AND ta.fecha = :fecha
                                          AND ta.estado IN ('programado', 'activo')
                                          AND ct.numero_turno = :base_turno";
                        $paramsNormalBase = [
                            ':puesto_id' => $puesto_id,
                            ':fecha' => $fecha,
                            ':base_turno' => $baseTurno
                        ];
                        if ($exclude_id !== null) {
                            $sqlNormalBase .= " AND ta.id != :exclude_id";
                            $paramsNormalBase[':exclude_id'] = $exclude_id;
                        }

                        $stmtNormalBase = $this->db->prepare($sqlNormalBase);
                        $stmtNormalBase->execute($paramsNormalBase);
                        $countNormalBase = (int)($stmtNormalBase->fetch()['count'] ?? 0);
                        if ($countNormalBase > 0) {
                            // No bloquear el L4 por la existencia de un turno base. El L4 se permite.
                        }

                        // Maximo 2 L4 por base/dia.
                        $sqlL4Base = "SELECT COUNT(*) as count
                                      FROM turnos_asignados ta
                                      INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                                      WHERE ta." . $puestoCol . " = :puesto_id
                                      AND ta.fecha = :fecha
                                      AND ta.estado IN ('programado', 'activo')
                                      AND ct.numero_turno = :l4_turno
                                      AND ct.horas_laborales BETWEEN 3.5 AND 4.5";
                        $paramsL4Base = [
                            ':puesto_id' => $puesto_id,
                            ':fecha' => $fecha,
                            ':l4_turno' => $l4Numero
                        ];
                        if ($exclude_id !== null) {
                            $sqlL4Base .= " AND ta.id != :exclude_id";
                            $paramsL4Base[':exclude_id'] = $exclude_id;
                        }

                        $stmtL4Base = $this->db->prepare($sqlL4Base);
                        $stmtL4Base->execute($paramsL4Base);
                        $countL4Base = (int)($stmtL4Base->fetch()['count'] ?? 0);
                        if ($countL4Base >= 2) {
                            $errores[] = 'No se puede asignar L4: ya hay dos turnos L4 en esa franja para este puesto';
                        }
                    } else {
                        // Si existe L4 en esa base, no permitir turno normal.
                        $sqlL4Existente = "SELECT COUNT(*) as count
                                           FROM turnos_asignados ta
                                           INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                                           WHERE ta." . $puestoCol . " = :puesto_id
                                           AND ta.fecha = :fecha
                                           AND ta.estado IN ('programado', 'activo')
                                           AND ct.numero_turno = :l4_turno
                                           AND ct.horas_laborales BETWEEN 3.5 AND 4.5";
                        $paramsL4Existente = [
                            ':puesto_id' => $puesto_id,
                            ':fecha' => $fecha,
                            ':l4_turno' => $l4Numero
                        ];
                        if ($exclude_id !== null) {
                            $sqlL4Existente .= " AND ta.id != :exclude_id";
                            $paramsL4Existente[':exclude_id'] = $exclude_id;
                        }

                        $stmtL4Existente = $this->db->prepare($sqlL4Existente);
                        $stmtL4Existente->execute($paramsL4Existente);
                        $countL4Existente = (int)($stmtL4Existente->fetch()['count'] ?? 0);
                        if ($countL4Existente > 0) {
                            $errores[] = 'No se puede asignar turno normal: el puesto ya tiene L4 en esa franja';
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Si hay error con restricciones nocturnas, continuar
        }
        
        // 5. Verificar requisitos del puesto
        try {
            $puesto = $this->getPuesto($puesto_id);
            $codigoPuesto = strtoupper((string)($puesto['codigo'] ?? ''));

            $esPuestoFijo8h = $puesto && in_array($codigoPuesto, self::PUESTOS_FIJOS_8H, true);
            $esPuestoDiurno7h = $puesto
                && $turno
                && in_array((int)($turno['numero_turno'] ?? 0), [1, 2], true)
                && in_array($codigoPuesto, self::PUESTOS_DIURNOS_7H, true);

            if (!$esL4 && $esPuestoFijo8h && $turno && (float)($turno['horas_laborales'] ?? 0) < 7.5) {
                $errores[] = 'Este puesto fijo solo puede asignarse con turnos de 8 horas';
            }

            if (!$esL4 && $esPuestoDiurno7h && $turno) {
                $horasTurno = (float)($turno['horas_laborales'] ?? 0);
                if ($horasTurno < 6.5 || $horasTurno >= 7.5) {
                    $errores[] = 'Este puesto en manana/tarde solo puede asignarse con turnos de 7 horas';
                }
            }

            if ($puesto && !empty($puesto['requiere_fuerza_fisica']) && !$this->trabajadores->puedeHacerFuerza($trabajador_id, $fecha)) {
                $errores[] = 'El puesto requiere fuerza física y el trabajador tiene restricción';
            }

            if ($puesto && in_array($codigoPuesto, self::PUESTOS_MOVILIDAD_LIMITADA, true)
                && $this->trabajadores->tieneRestriccionTipoFechaParaTrabajador($trabajador_id, $fecha, 'movilidad_limitada')) {
                $errores[] = 'El puesto requiere movilidad y el trabajador tiene restricción';
            }
        } catch (Exception $e) {
            $errores[] = 'Error en validación: ' . $e->getMessage();
        }

        $resultado = [
            'valido' => empty($errores),
            'errores' => $errores
        ];

        $this->validacionCache[$cacheKey] = $resultado;
        return $resultado;
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
            
            // Detectar el nombre correcto de la columna puesto
            $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
            
            // Construir la inserción solo con las columnas que existen
            if ($puestoCol) {
                $sql = "INSERT INTO turnos_asignados 
                        (trabajador_id, " . $puestoCol . ", turno_id, fecha, estado, observaciones, created_by) 
                        VALUES (:trabajador_id, :puesto_id, :turno_id, :fecha, :estado, :observaciones, :created_by)";
                $datos_finales = [
                    ':trabajador_id' => $datos['trabajador_id'] ?? null,
                    ':puesto_id'     => $datos['puesto_trabajo_id'] ?? null,
                    ':turno_id'      => $datos['turno_id'] ?? null,
                    ':fecha'         => $datos['fecha'] ?? null,
                    ':estado'        => $datos['estado'] ?? 'programado',
                    ':observaciones' => $datos['observaciones'] ?? '',
                    ':created_by'    => $datos['created_by'] ?? 1
                ];
            } else {
                $sql = "INSERT INTO turnos_asignados 
                        (trabajador_id, turno_id, fecha, estado, observaciones, created_by) 
                        VALUES (:trabajador_id, :turno_id, :fecha, :estado, :observaciones, :created_by)";
                $datos_finales = [
                    ':trabajador_id' => $datos['trabajador_id'] ?? null,
                    ':turno_id'      => $datos['turno_id'] ?? null,
                    ':fecha'         => $datos['fecha'] ?? null,
                    ':estado'        => $datos['estado'] ?? 'programado',
                    ':observaciones' => $datos['observaciones'] ?? '',
                    ':created_by'    => $datos['created_by'] ?? 1
                ];
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($datos_finales);
            
            $turno_id = $this->db->lastInsertId();
            
            // Registrar en historial
            $this->registrarHistorial($turno_id, $datos['trabajador_id'], $datos['puesto_trabajo_id'], 
                                     $datos['turno_id'], $datos['fecha'], 'creado', $datos['created_by'] ?? null);
            
            $this->db->commit();
            $this->invalidarCacheValidaciones($datos['trabajador_id'], $datos['fecha']);
            
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

    public function asignarDirecto($datos, $skipValidacion = false) {
        // Aunque se salte la validación para acelerar ciertas fases, siempre
        // hacemos una comprobación mínima de integridad para evitar inserciones
        // duplicadas en la base de datos y garantizar la consistencia.
        $validacion = $this->validarAsignacion(
            $datos['trabajador_id'],
            $datos['puesto_trabajo_id'] ?? null,
            $datos['turno_id'] ?? null,
            $datos['fecha'] ?? null
        );
        
        if (!$validacion['valido']) {
            error_log("[TurnosAsignados::asignarDirecto] VALIDACIÓN FALLIDA: " . json_encode($validacion['errores']));
            return [
                'success' => false,
                'message' => 'No se puede asignar el turno: ' . implode(', ', $validacion['errores']),
                'errores' => $validacion['errores']
            ];
        }
        
        try {
            $manejaTransaccion = !$this->db->inTransaction();
            if ($manejaTransaccion) {
                $this->db->beginTransaction();
            }
            
            // Detectar el nombre correcto de la columna puesto
            $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
            
            // Construir la inserción solo con las columnas que existen
            if ($puestoCol) {
                $sql = "INSERT INTO turnos_asignados 
                        (trabajador_id, " . $puestoCol . ", turno_id, fecha, estado, observaciones, created_by) 
                        VALUES (:trabajador_id, :puesto_id, :turno_id, :fecha, :estado, :observaciones, :created_by)";
                $datos_finales = [
                    ':trabajador_id' => $datos['trabajador_id'] ?? null,
                    ':puesto_id'     => $datos['puesto_trabajo_id'] ?? null,
                    ':turno_id'      => $datos['turno_id'] ?? null,
                    ':fecha'         => $datos['fecha'] ?? null,
                    ':estado'        => $datos['estado'] ?? 'programado',
                    ':observaciones' => $datos['observaciones'] ?? '',
                    ':created_by'    => $datos['created_by'] ?? 1
                ];
            } else {
                $sql = "INSERT INTO turnos_asignados 
                        (trabajador_id, turno_id, fecha, estado, observaciones, created_by) 
                        VALUES (:trabajador_id, :turno_id, :fecha, :estado, :observaciones, :created_by)";
                $datos_finales = [
                    ':trabajador_id' => $datos['trabajador_id'] ?? null,
                    ':turno_id'      => $datos['turno_id'] ?? null,
                    ':fecha'         => $datos['fecha'] ?? null,
                    ':estado'        => $datos['estado'] ?? 'programado',
                    ':observaciones' => $datos['observaciones'] ?? '',
                    ':created_by'    => $datos['created_by'] ?? 1
                ];
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($datos_finales);
            $turno_id = $this->db->lastInsertId();

            $this->registrarHistorial($turno_id, $datos['trabajador_id'], $datos['puesto_trabajo_id'], 
                                     $datos['turno_id'], $datos['fecha'], 'creado', $datos['created_by'] ?? null);

            if ($manejaTransaccion) {
                $this->db->commit();
            }
            $this->invalidarCacheValidaciones($datos['trabajador_id'], $datos['fecha']);
            
            error_log("[TurnosAsignados::asignarDirecto] ✅ Turno asignado exitosamente: trabajador=" . $datos['trabajador_id'] . 
                     ", puesto=" . ($datos['puesto_trabajo_id'] ?? 'NULL') . ", fecha=" . $datos['fecha']);
            
            return [
                'success' => true,
                'id' => $turno_id,
                'message' => 'Turno asignado exitosamente'
            ];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("[TurnosAsignados::asignarDirecto] ❌ Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al asignar turno directamente: ' . $e->getMessage()
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
        // Si se proporciona un cambio de trabajador, primero validar y actualizar trabajador_id
        if (!empty($datos['trabajador_id'])) {
            // Obtener detalles actuales del turno
            $actual = $this->obtenerPorId($id);
            if (!$actual) {
                return [ 'success' => false, 'message' => 'Turno no encontrado' ];
            }

            // Intentar validar la asignación al nuevo trabajador
            $valid = $this->validarAsignacion($datos['trabajador_id'], $actual['puesto_trabajo_id'] ?? $actual['puesto_id'], $actual['turno_id'] ?? null, $actual['fecha'], $id);
            if (!$valid['valido']) {
                return [ 'success' => false, 'message' => 'No se puede reasignar', 'errores' => $valid['errores'] ];
            }

            try {
                $sqlUpd = "UPDATE turnos_asignados SET trabajador_id = :trabajador_id, observaciones = :observaciones WHERE id = :id";
                $stmt = $this->db->prepare($sqlUpd);
                $stmt->execute([
                    ':trabajador_id' => $datos['trabajador_id'],
                    ':observaciones' => $datos['observaciones'] ?? null,
                    ':id' => $id
                ]);

                $turno = $this->obtenerPorId($id);
                $this->registrarHistorial($id, $turno['trabajador_id'], $turno['puesto_trabajo_id'], $turno['turno_id'], $turno['fecha'], 'reasignado', $datos['usuario_id'] ?? null, json_encode(['from' => $actual['trabajador'], 'to' => $turno['trabajador']]));

                return [ 'success' => true, 'message' => 'Turno reasignado' ];
            } catch (PDOException $e) {
                return [ 'success' => false, 'message' => 'Error reasignando: ' . $e->getMessage() ];
            }
        }

        // Cambio de turno y/o puesto
        if (!empty($datos['turno_id']) || !empty($datos['puesto_trabajo_id'])) {
            $actual = $this->obtenerPorId($id);
            if (!$actual) return ['success' => false, 'message' => 'Turno no encontrado'];

            $nuevoTurnoId  = $datos['turno_id']         ?? $actual['turno_id'];
            $nuevoPuestoId = $datos['puesto_trabajo_id'] ?? ($actual['puesto_id'] ?? $actual['puesto_trabajo_id']);
            $fecha         = $datos['fecha']             ?? $actual['fecha'];
            $trabId        = $datos['trabajador_id']     ?? $actual['trabajador_id'];

            // Validar que el nuevo puesto+turno no esté ya ocupado (excluyendo este turno)
            $valid = $this->validarAsignacion($trabId, $nuevoPuestoId, $nuevoTurnoId, $fecha, $id);
            if (!$valid['valido']) {
                return ['success' => false, 'message' => implode(', ', $valid['errores'])];
            }

            try {
                // Detectar el nombre correcto de la columna puesto
                $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
                
                if ($puestoCol) {
                    $stmt = $this->db->prepare(
                        "UPDATE turnos_asignados SET turno_id = :turno_id, " . $puestoCol . " = :puesto_id WHERE id = :id"
                    );
                    $stmt->execute([':turno_id' => $nuevoTurnoId, ':puesto_id' => $nuevoPuestoId, ':id' => $id]);
                } else {
                    $stmt = $this->db->prepare(
                        "UPDATE turnos_asignados SET turno_id = :turno_id WHERE id = :id"
                    );
                    $stmt->execute([':turno_id' => $nuevoTurnoId, ':id' => $id]);
                }
                return ['success' => true, 'message' => 'Turno actualizado'];
            } catch (PDOException $e) {
                return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
            }
        }

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
                observaciones = " . (DB_DRIVER === 'pgsql' ? "COALESCE(observaciones, '') || ' | Cancelado: ' || :motivo" : "CONCAT(COALESCE(observaciones, ''), ' | Cancelado: ', :motivo)") . "
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

    public function eliminar($id) {
        try {
            // Verificar que existe
            $turno = $this->obtenerPorId($id);
            if (!$turno) {
                return ['success' => false, 'message' => 'Turno no encontrado'];
            }

            $stmt = $this->db->prepare("DELETE FROM turnos_asignados WHERE id = :id");
            $stmt->execute([':id' => $id]);

            return ['success' => true, 'message' => 'Turno eliminado'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error al eliminar: ' . $e->getMessage()];
        }
    }
    
    
    //Obtener turno por ID
    
    public function obtenerPorId($id) {
        // Detectar el nombre correcto de la columna puesto
        $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
        $puestoJoin = $puestoCol ? "INNER JOIN puestos_trabajo pt ON ta.$puestoCol = pt.id" : "";
        
        $sql = "SELECT ta.*, 
                t.nombre as trabajador,
                " . ($puestoCol ? "pt.nombre as puesto," : "NULL as puesto,") . "
                ct.nombre as turno
                FROM turnos_asignados ta
                INNER JOIN trabajadores t ON ta.trabajador_id = t.id
                " . $puestoJoin . "
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                WHERE ta.id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    
    //Obtener calendario de turnos (vista mensual)
    
    public function obtenerCalendario($mes, $anio, $area = null) {
        // Detectar el nombre correcto de la columna puesto
        $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
        $puestoJoin = $puestoCol ? "INNER JOIN puestos_trabajo pt ON ta.$puestoCol = pt.id" : "";
        
        $fecha_inicio = "$anio-" . str_pad($mes, 2, '0', STR_PAD_LEFT) . "-01";
        $fecha_fin = date("Y-m-t", strtotime($fecha_inicio));
        
        $sql = "SELECT 
                ta.id,
                ta.fecha,
                ta.estado,
                t.nombre as trabajador,
                t.cedula,
                " . ($puestoCol ? "CASE WHEN pt.codigo = 'F4' THEN 'F2' ELSE pt.codigo END as puesto_codigo," : "NULL as puesto_codigo,") . "
                " . ($puestoCol ? "pt.nombre as puesto_nombre," : "NULL as puesto_nombre,") . "
                " . ($puestoCol ? "pt.area," : "NULL as area,") . "
                ct.numero_turno,
                ct.nombre as turno_nombre,
                ct.hora_inicio,
                ct.hora_fin
                FROM turnos_asignados ta
                INNER JOIN trabajadores t ON ta.trabajador_id = t.id
                " . $puestoJoin . "
                INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                WHERE ta.fecha BETWEEN :fecha_inicio AND :fecha_fin
                AND ta.estado IN ('programado', 'activo', 'completado')";
        
        $params = [
            ':fecha_inicio' => $fecha_inicio,
            ':fecha_fin' => $fecha_fin
        ];
        
        if ($area) {
            $sql .= " AND pt.area = :area";
            $params[':area'] = $area;
        }
        
        $sql .= " ORDER BY ta.fecha ASC, ct.hora_inicio ASC";
        
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