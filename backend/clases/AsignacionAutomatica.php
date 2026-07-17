<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once __DIR__ . '/TurnosAsignados.php';
require_once __DIR__ . '/Trabajadores.php';

class AsignacionAutomatica {
    private $db;
    private $turnosAsignados;
    private $trabajadores;
    private $validacionCandidatoCache = [];
    private $mesProceso = null;
    private $anioProceso = null;

    private const PUESTOS_FIJOS_8H = ['C', 'D3', 'F6', 'F11', 'F14', 'G', 'V1', 'V2'];
    private const PUESTOS_DIURNOS_7H = ['D1', 'D2', 'D4', 'F15', 'F2', 'F5'];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->turnosAsignados = new TurnosAsignados();
        $this->trabajadores    = new Trabajadores();
    }

    public function testConnection() {
        return $this->db->query("SELECT 1 as test")->fetch()['test'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PREFETCH MASIVO
    // ─────────────────────────────────────────────────────────────────────────
    private function prefetchDisponibilidadMes($mes, $anio) {
        $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin    = date('Y-m-t', strtotime($fechaInicio));
        $fechaFinExt = date('Y-m-d', strtotime($fechaFin    . ' +1 day'));
        $fechaIniExt = date('Y-m-d', strtotime($fechaInicio . ' -1 day'));

        $todosActivos = $this->db->query(
            "SELECT id, nombre FROM trabajadores
             WHERE activo = true AND LOWER(COALESCE(cargo,'')) != 'supervisor'
             ORDER BY nombre"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stmtTA = $this->db->prepare(
            "SELECT ta.trabajador_id, ta.fecha, ct.numero_turno
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta.fecha BETWEEN ? AND ?
             AND ta.estado IN ('programado','activo')"
        );
        $stmtTA->execute([$fechaIniExt, $fechaFinExt]);
        $asignadosPorDia = [];
        foreach ($stmtTA->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $asignadosPorDia[$row['fecha']][$row['trabajador_id']][] = (int)$row['numero_turno'];
        }

        $stmtI = $this->db->prepare(
            "SELECT trabajador_id, fecha_inicio, fecha_fin
             FROM incapacidades
             WHERE estado = 'activa'
             AND fecha_inicio <= ? AND fecha_fin >= ?"
        );
        $stmtI->execute([$fechaFin, $fechaInicio]);
        $incapacidades = $stmtI->fetchAll(PDO::FETCH_ASSOC);

        $stmtDE = $this->db->prepare(
            "SELECT trabajador_id, tipo, fecha_inicio,
                    COALESCE(fecha_fin, fecha_inicio) as fecha_fin
             FROM dias_especiales
             WHERE tipo IN ('LC','L','L8','VAC','SUS','CAP','ADM','ADMM','ADMT')
             AND estado IN ('programado','activo')
             AND fecha_inicio <= ? AND COALESCE(fecha_fin, fecha_inicio) >= ?"
        );
        $stmtDE->execute([$fechaFin, $fechaInicio]);
        $diasEspeciales = $stmtDE->fetchAll(PDO::FETCH_ASSOC);

        $puestoCol = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
        $sqlRestriccion = "SELECT trabajador_id, tipo_restriccion,
                    " . ($puestoCol ? $puestoCol : "NULL") . " as puesto_trabajo_id,
                    fecha_inicio, fecha_fin
             FROM restricciones_trabajador
             WHERE activa = true
             AND fecha_inicio <= ?
             AND (fecha_fin IS NULL OR fecha_fin >= ?)";
        $stmtR = $this->db->prepare($sqlRestriccion);
        $stmtR->execute([$fechaFin, $fechaInicio]);
        $restricciones = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        $stmtP = $this->db->prepare(
            "SELECT id, codigo, requiere_fuerza_fisica, requiere_movilidad
             FROM puestos_trabajo WHERE activo = TRUE"
        );
        $stmtP->execute();
        $puestosFlags = [];
        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $puestosFlags[$p['id']] = $p;
        }

        $stmtN = $this->db->prepare(
            "SELECT ta.trabajador_id, COUNT(*) as cnt
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ct.numero_turno = 3
             AND ta.fecha BETWEEN ? AND ?
             AND ta.estado IN ('programado','activo')
             GROUP BY ta.trabajador_id"
        );
        $stmtN->execute([$fechaInicio, $fechaFin]);
        $nochesPorTrabajador = [];
        foreach ($stmtN->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nochesPorTrabajador[$row['trabajador_id']] = (int)$row['cnt'];
        }

        $metricasCobertura = $this->construirMetricasCoberturaTrabajadores(
            $todosActivos,
            $restricciones,
            $incapacidades,
            $diasEspeciales,
            $asignadosPorDia,
            $puestosFlags,
            $mes,
            $anio
        );

        return [
            'todosActivos'        => $todosActivos,
            'asignadosPorDia'     => $asignadosPorDia,
            'incapacidades'       => $incapacidades,
            'diasEspeciales'      => $diasEspeciales,
            'restricciones'       => $restricciones,
            'puestosFlags'        => $puestosFlags,
            'nochesPorTrabajador' => $nochesPorTrabajador,
            'metricasCobertura'   => $metricasCobertura,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DISPONIBILIDAD NORMAL
    // ─────────────────────────────────────────────────────────────────────────
    private function getDisponibles($puestoId, $turnoId, $fecha, &$ctx, &$conteoTurnos) {
        $disponibles = $this->trabajadores->obtenerDisponibles($puestoId, $turnoId, $fecha);
        $numeroTurno = $ctx['turnoIdANumero'][$turnoId] ?? 0;
        if ($numeroTurno == 3) {
            usort($disponibles, function($a, $b) use ($conteoTurnos, $ctx) {
                $nA = $ctx['nochesPorTrabajador'][$a['id']] ?? 0;
                $nB = $ctx['nochesPorTrabajador'][$b['id']] ?? 0;
                if ($nA !== $nB) return $nA - $nB;
                return ($conteoTurnos[$a['id']] ?? 0) - ($conteoTurnos[$b['id']] ?? 0);
            });
        } else {
            usort($disponibles, function($a, $b) use ($conteoTurnos) {
                return ($conteoTurnos[$a['id']] ?? 0) - ($conteoTurnos[$b['id']] ?? 0);
            });
        }
        return $disponibles;
    }

    private function esPuestoFijo8h($codigoPuesto) {
        return in_array(strtoupper((string)$codigoPuesto), self::PUESTOS_FIJOS_8H, true);
    }

    private function getBloqueObjetivoPuesto($codigoPuesto, $numeroTurno) {
        $codigo = strtoupper((string)$codigoPuesto);
        if ($this->esPuestoFijo8h($codigo)) {
            return 'h8';
        }

        if (in_array((int)$numeroTurno, [1, 2], true)
            && in_array($codigo, self::PUESTOS_DIURNOS_7H, true)) {
            return 'h7';
        }

        return null;
    }

    private function getDisponiblesL4($puestoId, $turnoIdOrFecha, $fecha = null, &$ctx = []) {
        // Soporta dos firmas:
        // getDisponiblesL4(null, null, $fecha, $ctx)  → verificación general para ADMM/ADMT
        // getDisponiblesL4($puestoId, $fecha, $ctx)   → con puesto específico (L4)
        if ($puestoId === null) {
            return $this->trabajadores->obtenerDisponiblesL4(null, null, $fecha);
        }
        // Firma original: getDisponiblesL4($puestoId, $fecha, &$ctx)
        $fechaReal = $turnoIdOrFecha; // segundo param es la fecha en firma original
        $turnoIds  = $ctx['puestosL4TurnoIds'][$puestoId] ?? [];
        if (empty($turnoIds)) {
            $turnoIds = [($ctx['puestosL4TurnoId'][$puestoId] ?? null)];
        }

        $all = [];
        $seen = [];
        foreach ($turnoIds as $turnoId) {
            if (!$turnoId) continue;
            $lista = $this->trabajadores->obtenerDisponiblesL4($puestoId, $turnoId, $fechaReal);
            foreach ($lista as $t) {
                $tid = (int)($t['id'] ?? 0);
                if ($tid <= 0 || isset($seen[$tid])) continue;
                $seen[$tid] = true;
                $all[] = $t;
            }
        }
        return $all;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DISPONIBILIDAD CON FALLBACK POR NIVELES (mejora de cobertura)
    //
    // Nivel 1 — Normal: todos los filtros activos
    // Nivel 2 — ignorar_limite_noches: quita HAVING COUNT(*) >= 7 (solo T3)
    // Nivel 3 — ignorar_consecutivo: quita restricción T1↔T3 entre días
    // Nivel 4 — minimo: solo incapacidad y día libre (último recurso)
    // ─────────────────────────────────────────────────────────────────────────
    private function getDisponiblesConFallback($puestoId, $turnoId, $numeroTurno, $fecha, &$ctx, &$conteoTurnos) {
        // La asignación automática debe respetar estrictamente las restricciones.
        // No se permite usar niveles relajados que puedan asignar noches a trabajadores
        // con restricción no_turno_noche u otras restricciones del mismo tipo.
        $bloqueadosAdm = [];
        foreach ($ctx['diasEspeciales'] as $de) {
            if (!isset($de['tipo'])) continue;
            if (!in_array($de['tipo'], ['ADMM', 'ADMT'])) continue;
            $finicioAdm = $de['fecha_inicio'];
            $ffinAdm    = isset($de['fecha_fin']) && $de['fecha_fin'] ? $de['fecha_fin'] : $de['fecha_inicio'];
            if ($fecha >= $finicioAdm && $fecha <= $ffinAdm) {
                $bloqueadosAdm[] = $de['trabajador_id'];
            }
        }
        if (!empty($ctx['asignadosPorDia'][$fecha])) {
            foreach ($ctx['asignadosPorDia'][$fecha] as $trabId => $turnos) {
                if (in_array('ADMM', $turnos) || in_array('ADMT', $turnos)) {
                    $bloqueadosAdm[] = $trabId;
                }
            }
        }
        $bloqueadosAdm = array_unique($bloqueadosAdm);

        $filtrarAdm = function($lista) use ($bloqueadosAdm) {
            if (empty($bloqueadosAdm)) return $lista;
            return array_values(array_filter($lista, function($t) use ($bloqueadosAdm) {
                return !in_array($t['id'], $bloqueadosAdm);
            }));
        };

        $disponibles = $filtrarAdm($this->getDisponibles($puestoId, $turnoId, $fecha, $ctx, $conteoTurnos));
        if (!empty($disponibles)) {
            $this->ordenarDisponiblesPreliminar($disponibles, $numeroTurno, $ctx, $conteoTurnos);
            return ['lista' => $disponibles, 'nivel' => 1];
        }

        return ['lista' => [], 'nivel' => 0];
    }

    private function tieneRestriccionPuestoEspecificoEnFecha($trabajadorId, $puestoId, $fecha, &$ctx) {
        if (empty($ctx['restricciones']) || !$puestoId) return false;

        foreach ($ctx['restricciones'] as $r) {
            if ((int)($r['trabajador_id'] ?? 0) !== (int)$trabajadorId) continue;
            if (($r['tipo_restriccion'] ?? '') !== 'puesto_especifico') continue;
            if ((int)($r['puesto_trabajo_id'] ?? 0) !== (int)$puestoId) continue;

            $fi = (string)($r['fecha_inicio'] ?? '');
            $ff = (string)($r['fecha_fin'] ?? '');
            if ($fi !== '' && $fecha < $fi) continue;
            if ($ff !== '' && $fecha > $ff) continue;

            return true;
        }

        return false;
    }

    private function filtrarDisponiblesPorRestriccionPuestoEspecifico($disponibles, $puestoId, $fecha, &$ctx) {
        if (empty($disponibles)) return $disponibles;

        return array_values(array_filter($disponibles, function($t) use ($puestoId, $fecha, &$ctx) {
            return !$this->tieneRestriccionPuestoEspecificoEnFecha($t['id'], $puestoId, $fecha, $ctx);
        }));
    }

    private function filtrarDisponiblesPorRestriccionesObligatorias($disponibles, $puestoId, $turnoId, $fecha, $excludeId = null) {
        if (empty($disponibles)) {
            return [];
        }

        $filtrados = [];
        foreach ($disponibles as $trabajador) {
            $trabajadorId = (int)($trabajador['id'] ?? 0);
            if ($trabajadorId <= 0) {
                continue;
            }

            $validacion = $this->turnosAsignados->validarAsignacion(
                $trabajadorId,
                (int)$puestoId,
                (int)$turnoId,
                $fecha,
                $excludeId
            );

            if (!empty($validacion['valido'])) {
                $filtrados[] = $trabajador;
            }
        }

        return $filtrados;
    }

    private function verificarCancelacion($motivo = 'proceso') {
        if ($this->mesProceso === null || $this->anioProceso === null) {
            return;
        }

        $statePath = dirname(__DIR__) . '/logs/asignacion_' . intval($this->anioProceso) . '_' . intval($this->mesProceso) . '.state';
        if (file_exists($statePath)) {
            $state = json_decode(@file_get_contents($statePath), true);
            if (is_array($state) && !empty($state['cancel_requested'])) {
                throw new RuntimeException('Asignación cancelada por el usuario (' . $motivo . ')');
            }
        }

        if (connection_aborted()) {
            throw new RuntimeException('Conexión cerrada por el cliente; asignación cancelada (' . $motivo . ')');
        }
    }

    private function validarCandidatoAutomatico($trabajadorId, $puestoId, $turnoId, $fecha, $excludeId = null) {
        $cacheKey = (int)$trabajadorId . '|' . (int)$puestoId . '|' . (int)$turnoId . '|' . (string)$fecha . '|' . (string)$excludeId;
        if (array_key_exists($cacheKey, $this->validacionCandidatoCache)) {
            return $this->validacionCandidatoCache[$cacheKey];
        }

        if ((int)$trabajadorId <= 0 || (int)$puestoId <= 0 || (int)$turnoId <= 0 || !$fecha) {
            return $this->validacionCandidatoCache[$cacheKey] = false;
        }

        $validacion = $this->turnosAsignados->validarAsignacion(
            (int)$trabajadorId,
            (int)$puestoId,
            (int)$turnoId,
            $fecha,
            $excludeId
        );

        return $this->validacionCandidatoCache[$cacheKey] = !empty($validacion['valido']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MÉTODO PRINCIPAL
    // ─────────────────────────────────────────────────────────────────────────
    public function asignarMesCompleto($mes, $anio, $opciones = []) {
        $this->mesProceso = (int)$mes;
        $this->anioProceso = (int)$anio;
        $this->verificarCancelacion('inicio');

        $diasMes         = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $asignaciones    = [];
        $errores         = [];
        $warnings        = [];
        $libresAsignados = [];
        $libresErrores   = [];
        // Registro de cuántas veces se usó cada nivel de fallback
        $fallbackStats   = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        $fechaInicioMes = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFinMes    = date('Y-m-t', strtotime($fechaInicioMes));

        $manejaTransaccion = !$this->db->inTransaction();

        try {
            if ($manejaTransaccion) {
                $this->db->beginTransaction();
            }

            $resumenLimpieza = $this->limpiarAsignacionesAutomaticasMes($fechaInicioMes, $fechaFinMes);
            $warnings[] = 'Limpieza previa AUTO: turnos=' . (int)($resumenLimpieza['turnos_auto_eliminados'] ?? 0)
                . ', libres=' . (int)($resumenLimpieza['libres_auto_eliminados'] ?? 0) . '.';

            $ctx = $this->prefetchDisponibilidadMes($mes, $anio);

            $stmtConteo = $this->db->prepare(
                "SELECT ta.trabajador_id, COUNT(*) as total FROM turnos_asignados ta
                 WHERE ta.fecha BETWEEN :fi AND :ff AND ta.estado IN ('programado','activo')
                 GROUP BY ta.trabajador_id"
            );
            $stmtConteo->execute([':fi' => $fechaInicioMes, ':ff' => $fechaFinMes]);
            $conteoTurnos = [];
            foreach ($stmtConteo->fetchAll() as $row) {
                $conteoTurnos[$row['trabajador_id']] = (int)$row['total'];
            }

            $mesAnterior  = $mes == 1 ? 12 : $mes - 1;
            $anioAnterior = $mes == 1 ? $anio - 1 : $anio;
            $patronLibres = $this->obtenerPatronLibresMesAnterior($mesAnterior, $anioAnterior, $ctx['todosActivos']);

            $puestos = $this->obtenerPuestos();
            $puestos = array_values(array_filter($puestos, function($p) {
                return strtoupper($p['codigo'] ?? '') !== 'C2';
            }));

            $turnosConfig = $this->db->query(
                "SELECT id, numero_turno, nombre, horas_laborales
                 FROM configuracion_turnos
                 WHERE activo = TRUE
                 AND numero_turno NOT IN (4,5)
                 ORDER BY numero_turno, id"
            )->fetchAll();
            $turnoIdPorNumero = [];
            $turnoOpcionesPorNumero = [1 => [], 2 => [], 3 => []];
            $turnoL4OpcionesPorBase = [1 => [], 2 => []];
            $numeroPorTurnoId = [];
            $turnoMetaPorId   = [];
            $turnoIdL4Manana  = null;
            $turnoIdL4Tarde   = null;
            $hayTurno7h       = false;

            foreach ($turnosConfig as $tc) {
                $id     = (int)$tc['id'];
                $num    = (int)$tc['numero_turno'];
                $horas  = (float)$tc['horas_laborales'];
                $nombre = strtolower((string)($tc['nombre'] ?? ''));

                $numeroPorTurnoId[$id] = $num;
                $turnoMetaPorId[$id] = [
                    'numero_turno'    => $num,
                    'horas_laborales' => $horas,
                ];

                if (in_array($num, [1,2,3])) {
                    $turnoOpcionesPorNumero[$num][] = [
                        'id'    => $id,
                        'horas' => $horas,
                        'nombre'=> (string)($tc['nombre'] ?? ''),
                    ];
                }

                if ($num === 4 && $horas >= 3.5 && $horas <= 4.5) {
                    $turnoL4OpcionesPorBase[1][] = [
                        'id' => $id,
                        'nombre' => (string)($tc['nombre'] ?? ''),
                    ];
                    if ($turnoIdL4Manana === null || strpos($nombre, 'l4') !== false) {
                        $turnoIdL4Manana = $id;
                    }
                }

                if ($num === 5 && $horas >= 3.5 && $horas <= 4.5) {
                    $turnoL4OpcionesPorBase[2][] = [
                        'id' => $id,
                        'nombre' => (string)($tc['nombre'] ?? ''),
                    ];
                    if ($turnoIdL4Tarde === null || strpos($nombre, 'l4') !== false) {
                        $turnoIdL4Tarde = $id;
                    }
                }

                if (in_array($num, [1,2], true) && $horas >= 6.5 && $horas < 7.5) {
                    $hayTurno7h = true;
                }
            }

            foreach ([1,2,3] as $numBase) {
                if (empty($turnoOpcionesPorNumero[$numBase])) continue;
                usort($turnoOpcionesPorNumero[$numBase], function($a, $b) {
                    if ($a['horas'] == $b['horas']) return $a['id'] <=> $b['id'];
                    return $b['horas'] <=> $a['horas'];
                });

                $turnoIdPorNumero[$numBase] = $turnoOpcionesPorNumero[$numBase][0]['id'];
                foreach ($turnoOpcionesPorNumero[$numBase] as $op) {
                    if ($op['horas'] >= 7.5) {
                        $turnoIdPorNumero[$numBase] = $op['id'];
                        break;
                    }
                }
            }

            $turnos = array_keys($turnoIdPorNumero) ?: [1,2,3];
            $ctx['turnoIdANumero'] = $numeroPorTurnoId;

            $perfilObjetivo = [
                'max_horas' => 42.0,
                'max_8h'    => 3,
                'max_7h'    => 2,
                'max_4h'    => 1,
            ];
            $maxHorasSemanalOperativo = 42.0;
            $maxHorasSemanalCobertura = 42.0;
            if (!$hayTurno7h) {
                $warnings[] = 'No hay turno activo de 7 horas en configuracion_turnos para manana/tarde; el objetivo semanal 3x8h + 2x7h + 1x4h puede quedar incompleto hasta que exista esa configuracion.';
            }

            $turnoIdL4Manana = $turnoIdL4Manana ?? 9;
            $turnoIdL4Tarde  = $turnoIdL4Tarde ?? 10;
            $puestosL4Map   = [
                'D2'  => $turnoIdL4Tarde,
                'D1'  => $turnoIdL4Tarde,
                'F11' => $turnoIdL4Manana,
                'F5'  => $turnoIdL4Manana,
                'F15' => $turnoIdL4Manana,
            ];
            $puestosL4Turno = ['D2'=>2,'D1'=>2,'F11'=>1,'F5'=>1,'F15'=>1];

            $stmtPuestosL4 = $this->db->prepare(
                "SELECT id, codigo FROM puestos_trabajo
                  WHERE codigo IN ('D2','D1','F11','F5','F15') AND activo = TRUE"
            );
            $stmtPuestosL4->execute();
            $puestosL4Info = $stmtPuestosL4->fetchAll();

            $ctx['puestosL4TurnoId'] = [];
            $ctx['puestosL4TurnoIds'] = [];
            foreach ($puestosL4Info as $pl4) {
                $codigoL4 = strtoupper((string)($pl4['codigo'] ?? ''));
                $baseL4 = (int)($puestosL4Turno[$codigoL4] ?? 2);
                $opsBase = $turnoL4OpcionesPorBase[$baseL4] ?? [];

                // Priorizar turnos cuyo nombre contenga L4 y luego id mayor (subfranjas nuevas primero).
                usort($opsBase, function($a, $b) {
                    $aL4 = (strpos(strtolower((string)($a['nombre'] ?? '')), 'l4') !== false) ? 1 : 0;
                    $bL4 = (strpos(strtolower((string)($b['nombre'] ?? '')), 'l4') !== false) ? 1 : 0;
                    if ($aL4 !== $bL4) return $bL4 <=> $aL4;
                    return ((int)$b['id']) <=> ((int)$a['id']);
                });

                $ids = array_map(function($x) { return (int)$x['id']; }, $opsBase);
                if (empty($ids)) {
                    $ids = [(int)($puestosL4Map[$codigoL4] ?? $turnoIdL4Tarde)];
                }

                $ctx['puestosL4TurnoIds'][$pl4['id']] = $ids;
                $ctx['puestosL4TurnoId'][$pl4['id']] = (int)$ids[0];
            }

            $puestosCriticos = ['C','V1','V2','D3','F6'];
            $ordenCriticos = array_flip($puestosCriticos);
            $puestosPrioridadT1 = ['G','F14','F15','D1','D4','F2'];
            $puestosPrioridadT2 = ['G','F14','F15','F11','D1','D4','F2'];
            $puestosNocturnos = ['V1','V2','C','D3','F6','F11'];
            $MAX_LIBRES_DIA   = 2;

            // ════════════════════════════════════════════════════════════════
            // PREPARACIÓN DE SEMANAS Y DÍAS LIBRES (la asignación de L se hace al final)
            // ════════════════════════════════════════════════════════════════

            $semanas = $this->calcularSemanas($mes, $anio);

            $diasEspecialesPrefetch = $this->prefetchDiasEspeciales($mes, $anio);
            $libresPorTrabajador    = $diasEspecialesPrefetch['libresPorTrabajador'];
            $cargaPorFecha          = $diasEspecialesPrefetch['cargaPorFecha'];
            $libresAsignadosPorFecha = [];

            $trabajadoresShuffled = $ctx['todosActivos'];
            shuffle($trabajadoresShuffled);
            $semanasShuffled = $semanas;
            shuffle($semanasShuffled);

            // ════════════════════════════════════════════════════════════════
            // PASO 1 — TURNOS L4
            // ════════════════════════════════════════════════════════════════

            $turnosAsignadosPrefetch   = $this->prefetchTurnosAsignados($mes, $anio);
            $turnosPorTrabajadorSemana = $turnosAsignadosPrefetch['turnosPorTrabajadorSemana'];
            $turnosPorPuestoFecha      = $turnosAsignadosPrefetch['turnosPorPuestoFecha'];
            $perfilSemanal             = $turnosAsignadosPrefetch['perfilSemanal'];

            foreach ($semanasShuffled as $semana) {
                $this->verificarCancelacion('paso L4');
                foreach ($trabajadoresShuffled as $trab) {
                    $this->verificarCancelacion('paso L4 trabajador');
                    // Saltar si ya tiene L4 esta semana
                    $tieneL4   = $this->tieneTurnoL4EnSemana($trab['id'], $semana['lunes'], $semana['domingo'], $turnosPorTrabajadorSemana);
                    if ($tieneL4) continue;

                    // Días hábiles de la semana dentro del mes
                    $diasSemana = [];
                    for ($d = 0; $d <= 6; $d++) {
                        $ts  = strtotime($semana['lunes']) + $d * 86400;
                        $dow = (int)date('N', $ts);
                        if ($dow > 5) continue;
                        if ((int)date('n', $ts) != (int)$mes) continue;
                        $diasSemana[] = date('Y-m-d', $ts);
                    }
                    shuffle($diasSemana);

                    $asignado = false;

                    // ── Intentar asignar L4 ──────────────────────────────
                    $puestosL4Mezclados = $puestosL4Info;
                    shuffle($puestosL4Mezclados);
                    $preferirF11Semana = ((((int)$trab['id']) + ((int)date('W', strtotime($semana['lunes'])))) % 4) === 0;
                    // D2 es de menor prioridad relativa: intentar antes otros puestos L4.
                    usort($puestosL4Mezclados, function($a, $b) use ($preferirF11Semana) {
                        $aCodigo = strtoupper((string)($a['codigo'] ?? ''));
                        $bCodigo = strtoupper((string)($b['codigo'] ?? ''));
                        if ($preferirF11Semana) {
                            $aEsF11 = $aCodigo === 'F11';
                            $bEsF11 = $bCodigo === 'F11';
                            if ($aEsF11 !== $bEsF11) {
                                return $aEsF11 ? -1 : 1;
                            }
                        }
                        $aEsD2 = strtoupper($a['codigo'] ?? '') === 'D2';
                        $bEsD2 = strtoupper($b['codigo'] ?? '') === 'D2';
                        if ($aEsD2 === $bEsD2) return 0;
                        return $aEsD2 ? 1 : -1;
                    });

                    foreach ($diasSemana as $fechaL4) {
                        if ($asignado) break;
                        foreach ($puestosL4Mezclados as $puesto) {
                            $turnoIdsL4 = $ctx['puestosL4TurnoIds'][$puesto['id']] ?? [($puestosL4Map[$puesto['codigo']] ?? 9)];
                            $codigoL4Puesto = strtoupper((string)($puesto['codigo'] ?? ''));
                            $baseTurnoL4 = (int)($puestosL4Turno[$codigoL4Puesto] ?? 0);

                            // Regla de negocio: en el mismo puesto/base/dia se asigna
                            // o 1 turno de 7h/8h, o hasta 2 turnos L4 encadenados.
                            if ($baseTurnoL4 > 0 && $this->estaPuestoOcupado($puesto['id'], $baseTurnoL4, $fechaL4, $turnosPorPuestoFecha)) {
                                continue;
                            }
                            if ($baseTurnoL4 > 0 && $this->cantidadTurnosL4EnFechaYBase($puesto['id'], $fechaL4, $baseTurnoL4, $turnosPorPuestoFecha) >= 2) {
                                continue;
                            }

                            $disponiblesL4 = $this->getDisponiblesL4($puesto['id'], $fechaL4, $ctx);
                            $disponible    = array_filter($disponiblesL4, function($t) use ($trab) { return $t['id'] == $trab['id']; });
                            if (empty($disponible)) continue;

                            foreach ($turnoIdsL4 as $turnoIdL4) {
                                $turnoIdL4 = (int)$turnoIdL4;
                                if ($turnoIdL4 <= 0) continue;
                                $numeroTurnoL4 = $numeroPorTurnoId[$turnoIdL4] ?? 4;

                                $resultado = $this->turnosAsignados->asignarDirecto([
                                    'trabajador_id'     => $trab['id'],
                                    'puesto_trabajo_id' => $puesto['id'],
                                    'turno_id'          => $turnoIdL4,
                                    'fecha'             => $fechaL4,
                                    'observaciones'     => 'Asignacion automatica L4'
                                ]);

                                if ($resultado['success']) {
                                    $asignaciones[] = ['fecha'=>$fechaL4,'puesto'=>$puesto['codigo'],'turno'=>'L4','trabajador'=>$trab['nombre']];
                                    $turnosPorPuestoFecha[$puesto['id'].'|L4ID|'.$turnoIdL4.'|'.$fechaL4] = true;
                                    if ($baseTurnoL4 > 0) {
                                        $claveL4Base = $puesto['id'].'|L4|'.$baseTurnoL4.'|'.$fechaL4;
                                        $turnosPorPuestoFecha[$claveL4Base] = ($turnosPorPuestoFecha[$claveL4Base] ?? 0) + 1;
                                    }

                                    if (!isset($turnosPorTrabajadorSemana[$trab['id']][$semana['lunes']])) {
                                        $turnosPorTrabajadorSemana[$trab['id']][$semana['lunes']] = [];
                                    }
                                    $turnosPorTrabajadorSemana[$trab['id']][$semana['lunes']][] = $numeroTurnoL4;
                                    $ctx['asignadosPorDia'][$fechaL4][$trab['id']][] = $numeroTurnoL4;
                                    $this->actualizarPerfilSemanal($perfilSemanal, $trab['id'], $semana['lunes'], 4.0);
                                    $conteoTurnos[$trab['id']] = ($conteoTurnos[$trab['id']] ?? 0) + 1;
                                    $asignado = true;
                                    break 2;
                                }
                            }
                        }
                    }

                    if (!$asignado) {
                        $errores[] = ['fecha'=>$semana['lunes'].' al '.$semana['domingo'],'puesto'=>'L4','turno'=>'L4','error'=>'Sin turno L4 disponible para '.$trab['nombre']];
                    }
                }
            }

            // ════════════════════════════════════════════════════════════════
            // PASO 2 — TURNOS NORMALES (T1, T2, T3) CON FALLBACK POR NIVELES
            // ════════════════════════════════════════════════════════════════

            for ($dia = 1; $dia <= $diasMes; $dia++) {
                $this->verificarCancelacion('turnos normales');
                $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

                // Ordenar puestos: primero los prioritarios de T1 y T2, luego el resto aleatorio
                $puestosCriticosDia = [];
                $puestosResto = [];
                $puestosPrioT1 = [];
                $puestosPrioT2 = [];
                foreach ($puestos as $p) {
                    $cod = strtoupper($p['codigo']);
                    if (in_array($cod, $puestosCriticos, true)) $puestosCriticosDia[] = $p;
                    elseif (in_array($cod, $puestosPrioridadT1, true)) $puestosPrioT1[] = $p;
                    elseif (in_array($cod, $puestosPrioridadT2)) $puestosPrioT2[] = $p;
                    else $puestosResto[] = $p;
                }
                usort($puestosCriticosDia, function($a, $b) use ($ordenCriticos) {
                    $ca = strtoupper($a['codigo'] ?? '');
                    $cb = strtoupper($b['codigo'] ?? '');
                    $oa = $ordenCriticos[$ca] ?? 999;
                    $ob = $ordenCriticos[$cb] ?? 999;
                    return $oa <=> $ob;
                });
                shuffle($puestosResto);

                // Orden final: críticos, luego secundarios, luego resto.
                $puestosOrdenados = array_merge($puestosCriticosDia, $puestosPrioT1, $puestosPrioT2, $puestosResto);
                // D2 se procesa al final para priorizar cobertura de otros puestos.
                usort($puestosOrdenados, function($a, $b) {
                    $aEsD2 = strtoupper($a['codigo'] ?? '') === 'D2';
                    $bEsD2 = strtoupper($b['codigo'] ?? '') === 'D2';
                    if ($aEsD2 === $bEsD2) return 0;
                    return $aEsD2 ? 1 : -1;
                });

                $turnosOrdenados = [1, 2, 3];
                foreach ($turnosOrdenados as $turno) {
                    $turnoIdBase = $turnoIdPorNumero[$turno] ?? null;
                    if ($turnoIdBase === null) continue;

                    $puestosOrdenadosTurno = $this->ordenarPuestosPorEscasezYPrioridad(
                        $puestosOrdenados,
                        $turno,
                        $turnoIdBase,
                        $fecha,
                        $ctx,
                        $conteoTurnos
                    );

                    foreach ($puestosOrdenadosTurno as $puesto) {
                        $codigoPuesto = strtoupper($puesto['codigo']);
                        // Regla de negocio: D2 en mañana no se asigna automáticamente.
                        if ($codigoPuesto === 'D2' && (int)$turno === 1) continue;
                        if ($turno == 3 && !in_array($codigoPuesto, $puestosNocturnos)) continue;

                        $bloqueObjetivoPuesto = $this->getBloqueObjetivoPuesto($codigoPuesto, $turno);
                        if ($bloqueObjetivoPuesto === 'h8'
                            && !$this->tieneOpcionTurnoBloque($turnoOpcionesPorNumero[$turno] ?? [], 'h8')) {
                            $errores[] = [
                                'fecha'  => $fecha,
                                'puesto' => $puesto['codigo'],
                                'turno'  => $turno,
                                'error'  => 'Configuracion invalida: este puesto fijo requiere turno >= 8h'
                            ];
                            continue;
                        }
                        if ($bloqueObjetivoPuesto === 'h7'
                            && !$this->tieneOpcionTurnoBloque($turnoOpcionesPorNumero[$turno] ?? [], 'h7')) {
                            $errores[] = [
                                'fecha'  => $fecha,
                                'puesto' => $puesto['codigo'],
                                'turno'  => $turno,
                                'error'  => 'Configuracion invalida: este puesto requiere turno de 7 horas en manana/tarde'
                            ];
                            continue;
                        }

                        if (isset($puestosL4Turno[$codigoPuesto]) && $puestosL4Turno[$codigoPuesto] == $turno) {
                            if ($this->tieneTurnoL4EnFechaYBase($puesto['id'], $fecha, (int)$turno, $turnosPorPuestoFecha)) {
                                continue;
                            }
                        }

                        if ($this->estaPuestoOcupado($puesto['id'], $turno, $fecha, $turnosPorPuestoFecha)) {
                            continue;
                        }

                        // ── FALLBACK POR NIVELES ──────────────────────────
                        $resultado_busqueda = $this->getDisponiblesConFallback(
                            $puesto['id'], $turnoIdBase, $turno, $fecha, $ctx, $conteoTurnos
                        );
                        $disponibles = $this->filtrarDisponiblesPorRestriccionPuestoEspecifico(
                            $resultado_busqueda['lista'],
                            $puesto['id'],
                            $fecha,
                            $ctx
                        );
                        $disponibles = $this->filtrarDisponiblesPorRestriccionesObligatorias(
                            $disponibles,
                            $puesto['id'],
                            $turnoIdBase,
                            $fecha
                        );
                        $nivelUsado  = $resultado_busqueda['nivel'];
                        // ─────────────────────────────────────────────────

                        if (empty($disponibles)) {
                            $errores[] = [
                                'fecha'  => $fecha,
                                'puesto' => $puesto['codigo'],
                                'turno'  => $turno,
                                'error'  => 'Sin trabajadores disponibles (todos los niveles agotados)'
                            ];
                            continue;
                        }

                        $fallbackStats[$nivelUsado] = ($fallbackStats[$nivelUsado] ?? 0) + 1;

                        $planAsignacion = $this->seleccionarMejorCandidatoSemanal(
                            $disponibles,
                            $fecha,
                            $turno,
                            $puesto['id'],
                            $codigoPuesto,
                            $bloqueObjetivoPuesto,
                            $turnoOpcionesPorNumero,
                            $perfilSemanal,
                            $perfilObjetivo,
                            $maxHorasSemanalOperativo,
                            $conteoTurnos,
                            $ctx
                        );
                        if (!$planAsignacion) {
                            $errores[] = [
                                'fecha'  => $fecha,
                                'puesto' => $puesto['codigo'],
                                'turno'  => $turno,
                                'error'  => 'Sin trabajador elegible por limite de horas semanales'
                            ];
                            continue;
                        }

                        $sel        = $planAsignacion['trabajador'];
                        $turnoIdReal= (int)$planAsignacion['turno_id'];
                        $turnoHoras = (float)$planAsignacion['turno_horas'];

                        // Observación indica si se usó fallback
                        $obs = 'Asignacion automatica';
                        if ($nivelUsado == 2) $obs .= ' [fallback: ignorar limite noches]';
                        if ($nivelUsado == 3) $obs .= ' [fallback: ignorar consecutivo]';
                        if ($nivelUsado == 4) $obs .= ' [fallback: minimo]';

                        $validacionPrevia = $this->turnosAsignados->validarAsignacion(
                            (int)$sel['id'],
                            (int)$puesto['id'],
                            (int)$turnoIdReal,
                            $fecha
                        );
                        if (!$validacionPrevia['valido']) {
                            $errores[] = [
                                'fecha'  => $fecha,
                                'puesto' => $puesto['codigo'],
                                'turno'  => $turno,
                                'error'  => 'Candidato descartado por restricciones: ' . implode(', ', $validacionPrevia['errores'])
                            ];
                            continue;
                        }

                        $resultado = $this->turnosAsignados->asignarDirecto([
                            'trabajador_id'     => $sel['id'],
                            'puesto_trabajo_id' => $puesto['id'],
                            'turno_id'          => $turnoIdReal,
                            'fecha'             => $fecha,
                            'observaciones'     => $obs
                        ]);

                        if ($resultado['success']) {
                            $ctx['asignadosPorDia'][$fecha][$sel['id']][] = $turno;
                            if ($turno == 3) {
                                $ctx['nochesPorTrabajador'][$sel['id']] = ($ctx['nochesPorTrabajador'][$sel['id']] ?? 0) + 1;
                            }
                            $semKeySel = $this->getSemanaKey($fecha);
                            $this->actualizarPerfilSemanal($perfilSemanal, $sel['id'], $semKeySel, $turnoHoras);
                            $conteoTurnos[$sel['id']] = ($conteoTurnos[$sel['id']] ?? 0) + 1;
                            $turnosPorPuestoFecha[$puesto['id'].'|'.$turno.'|'.$fecha] = true;

                            $asignaciones[] = [
                                'fecha'      => $fecha,
                                'puesto'     => $puesto['codigo'],
                                'turno'      => $turno,
                                'trabajador' => $sel['nombre'],
                                'nivel'      => $nivelUsado
                            ];
                        } else {
                            $errores[] = ['fecha'=>$fecha,'puesto'=>$puesto['codigo'],'turno'=>$turno,'error'=>$resultado['message']];
                        }
                    }
                }
            }

            // ════════════════════════════════════════════════════════════════
            // PASO 2.5 — COBERTURA ADICIONAL (sin relajar restricciones)
            // ════════════════════════════════════════════════════════════════
            $resultadoOptimizacion = $this->optimizarCoberturaFinal(
                $mes,
                $anio,
                $puestos,
                $puestosNocturnos,
                $puestosL4Turno,
                $turnoIdPorNumero,
                $turnoOpcionesPorNumero,
                $perfilObjetivo,
                $ctx,
                $conteoTurnos,
                $perfilSemanal,
                $turnosPorPuestoFecha,
                $asignaciones
            );

            $coberturaExtra = (int)($resultadoOptimizacion['cobertura_extra'] ?? 0);
            $coberturaTotalExtra = (int)($resultadoOptimizacion['cobertura_total_extra'] ?? 0);

            if (!empty($resultadoOptimizacion['resumen'])) {
                $warnings[] = (string)$resultadoOptimizacion['resumen'];
            }

            foreach (($resultadoOptimizacion['warnings'] ?? []) as $warningOpt) {
                $warnings[] = (string)$warningOpt;
            }

            // ════════════════════════════════════════════════════════════════
            // PASO 2.6 — COBERTURA TOTAL DEL MES (incluye primera semana)
            // Recorre nuevamente todos los días para cerrar huecos restantes
            // con un criterio menos estricto de perfil semanal.
            // ════════════════════════════════════════════════════════════════
            if ($coberturaTotalExtra > 0) {
                $warnings[] = 'Cobertura total mejorada por optimizacion final: +' . $coberturaTotalExtra . ' puestos cubiertos.';
            }

            // Se omiten los ajustes finales de reequilibrio/rescate para evitar
            // que la generación se quede atascada en el cierre del proceso.
            // La cobertura base y la validación estricta ya se ejecutaron antes.
            $warnings[] = 'Se omitieron los ajustes finales de reequilibrio por rendimiento.';

            // ════════════════════════════════════════════════════════════════
            // PASO 3 — DÍAS LIBRES (AL FINAL)
            // ════════════════════════════════════════════════════════════════
            $stmtInsLibre = $this->db->prepare(
                "INSERT INTO dias_especiales
                 (trabajador_id, tipo, fecha_inicio, fecha_fin, descripcion, estado)
                 VALUES (?, 'L', ?, NULL, 'AUTO: generado automáticamente', 'programado')"
            );

            foreach ($semanasShuffled as $semana) {
                $this->verificarCancelacion('días libres');
                foreach ($trabajadoresShuffled as $trab) {
                    $this->verificarCancelacion('días libres trabajador');
                    if ($this->tieneLibreEnRango($trab['id'], $semana['lunes'], $semana['domingo'], $libresPorTrabajador)) {
                        continue;
                    }

                    $tsUltimoLibre = $this->obtenerUltimoLibreAntes($trab['id'], $semana['lunes'], $libresPorTrabajador);

                    $candidatos = $this->buscarCandidatosLibre(
                        $trab['id'], $semana, $mes, $tsUltimoLibre, $cargaPorFecha, $MAX_LIBRES_DIA, $ctx, $libresAsignadosPorFecha
                    );

                    if (empty($candidatos)) {
                        $libresErrores[] = ['trabajador' => $trab['nombre'], 'semana' => $semana['lunes'], 'error' => 'Sin día entre semana disponible'];
                        continue;
                    }

                    usort($candidatos, function($a, $b) use ($patronLibres, $trab) {
                        $diaPreferido = $patronLibres[$trab['id']] ?? null;
                        $dowA = (int)date('N', strtotime($a['fecha']));
                        $dowB = (int)date('N', strtotime($b['fecha']));
                        if ($diaPreferido) {
                            $prefA = ($dowA == $diaPreferido) ? 1 : 0;
                            $prefB = ($dowB == $diaPreferido) ? 1 : 0;
                            if ($prefA != $prefB) return $prefB - $prefA;
                        }
                        if ($a['carga'] != $b['carga']) return $a['carga'] - $b['carga'];
                        return date('j', strtotime($b['fecha'])) - date('j', strtotime($a['fecha']));
                    });

                    $mejorDia = null;
                    foreach ($candidatos as $cand) {
                        $fechaCand = (string)($cand['fecha'] ?? '');
                        if ($fechaCand === '') continue;
                        if (!empty($ctx['asignadosPorDia'][$fechaCand][$trab['id']])) {
                            continue;
                        }
                        $mejorDia = $fechaCand;
                        break;
                    }

                    if ($mejorDia === null) {
                        $libresErrores[] = ['trabajador' => $trab['nombre'], 'semana' => $semana['lunes'], 'error' => 'Sin día libre válido (todos tenían turno)'];
                        continue;
                    }

                    try {
                        $stmtInsLibre->execute([$trab['id'], $mejorDia]);
                        $libresAsignados[] = ['trabajador' => $trab['nombre'], 'fecha' => $mejorDia];
                        $libresPorTrabajador[$trab['id']][] = ['fecha_inicio' => $mejorDia, 'fecha_fin' => null];
                        usort($libresPorTrabajador[$trab['id']], function($a, $b) { return strcmp($a['fecha_inicio'], $b['fecha_inicio']); });
                        $cargaPorFecha[$mejorDia] = ($cargaPorFecha[$mejorDia] ?? 0) + 1;
                        $ctx['diasEspeciales'][] = [
                            'trabajador_id' => $trab['id'],
                            'fecha_inicio'  => $mejorDia,
                            'fecha_fin'     => $mejorDia,
                            'tipo'          => 'L'
                        ];
                        $ctx['asignadosPorDia'][$mejorDia][$trab['id']][] = 'L';
                        $libresAsignadosPorFecha[$mejorDia] = ($libresAsignadosPorFecha[$mejorDia] ?? 0) + 1;
                    } catch (Exception $eL) {
                        $libresErrores[] = ['trabajador' => $trab['nombre'], 'semana' => $semana['lunes'], 'error' => $eL->getMessage()];
                    }
                }
            }

            $inviablesPerfil = [];
            $this->verificarPerfilSemanalObjetivoRapido($mes, $anio, $perfilObjetivo, $perfilSemanal, $ctx['todosActivos'], $warnings, $inviablesPerfil);

            // Validación final obligatoria: nunca confirmar asignaciones que violen reglas.
            $this->verificarIntegridadAsignacionesAutomaticasMes($fechaInicioMes, $fechaFinMes);

            if ($manejaTransaccion && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'success'          => true,
                'asignaciones'     => count($asignaciones),
                'errores'          => count($errores),
                'libres_asignados' => count($libresAsignados),
                'libres_errores'   => count($libresErrores),
                'detalle_errores'  => $errores,
                'detalle_libres'   => $libresAsignados,
                'warnings'         => $warnings,
                'cobertura_extra'  => $coberturaExtra,
                'cobertura_total_extra' => $coberturaTotalExtra,
                'inviables_perfil' => $inviablesPerfil,
                'diagnostico_huecos' => $resultadoOptimizacion['huecos_imposibles'] ?? [],
                'optimizacion_cobertura' => [
                    'iteraciones'         => (int)($resultadoOptimizacion['iteraciones'] ?? 0),
                    'reasignaciones'      => (int)($resultadoOptimizacion['reasignaciones'] ?? 0),
                    'intercambios'        => (int)($resultadoOptimizacion['intercambios'] ?? 0),
                    'huecos_iniciales'    => (int)($resultadoOptimizacion['huecos_iniciales'] ?? 0),
                    'huecos_finales'      => (int)($resultadoOptimizacion['huecos_finales'] ?? 0),
                    'mejora_cobertura'    => (int)($resultadoOptimizacion['cobertura_total_extra'] ?? 0),
                ],
                // Estadísticas de fallback para diagnóstico
                'fallback_stats'   => [
                    'nivel_1_normal'              => $fallbackStats[1] ?? 0,
                    'nivel_2_ignorar_lim_noches'  => $fallbackStats[2] ?? 0,
                    'nivel_3_ignorar_consecutivo' => $fallbackStats[3] ?? 0,
                    'nivel_4_minimo'              => $fallbackStats[4] ?? 0,
                ]
            ];

        } catch (Throwable $e) {
            if ($manejaTransaccion && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('AsignacionAutomatica failure: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function limpiarAsignacionesAutomaticasMes($fechaInicioMes, $fechaFinMes) {
        $stmtLimpiarTurnosAuto = $this->db->prepare(
            "DELETE FROM turnos_asignados
             WHERE fecha BETWEEN ? AND ?
             AND (
                 LOWER(COALESCE(observaciones, '')) LIKE 'asignacion automatica%'
                 OR LOWER(COALESCE(observaciones, '')) LIKE 'asignación automática%'
                 OR LOWER(REPLACE(REPLACE(COALESCE(observaciones, ''), 'á', 'a'), 'ó', 'o')) LIKE 'asignacion automatica%'
             )"
        );
        $stmtLimpiarTurnosAuto->execute([$fechaInicioMes, $fechaFinMes]);
        $turnosEliminados = (int)$stmtLimpiarTurnosAuto->rowCount();

        $stmtLimpiarLibresAuto = $this->db->prepare(
            "DELETE FROM dias_especiales
             WHERE tipo IN ('L','L8','LC')
             AND fecha_inicio BETWEEN ? AND ?
             AND descripcion LIKE 'AUTO:%'"
        );
        $stmtLimpiarLibresAuto->execute([$fechaInicioMes, $fechaFinMes]);
        $libresEliminados = (int)$stmtLimpiarLibresAuto->rowCount();

        return [
            'turnos_auto_eliminados' => $turnosEliminados,
            'libres_auto_eliminados' => $libresEliminados,
        ];
    }

    private function verificarIntegridadAsignacionesAutomaticasMes($fechaInicioMes, $fechaFinMes) {
        $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
        if (!$puestoCol) {
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT id, trabajador_id, " . $puestoCol . " as puesto_trabajo_id, turno_id, fecha
             FROM turnos_asignados
             WHERE fecha BETWEEN ? AND ?
             AND observaciones LIKE 'Asignacion automatica%'"
        );
        $stmt->execute([$fechaInicioMes, $fechaFinMes]);

        $errores = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $validacion = $this->turnosAsignados->validarAsignacion(
                $row['trabajador_id'],
                $row['puesto_trabajo_id'],
                $row['turno_id'],
                $row['fecha'],
                $row['id']
            );

            if (!$validacion['valido']) {
                $errores[] = $row['fecha'] . ' trabajador #' . $row['trabajador_id'] . ': ' . implode(', ', $validacion['errores']);
                if (count($errores) >= 5) {
                    break;
                }
            }
        }

        if (!empty($errores)) {
            throw new RuntimeException('Validacion final de restricciones fallo: ' . implode(' | ', $errores));
        }
    }

    private function verificarPerfilSemanalObjetivoEstricto($mes, $anio, $perfilObjetivo, &$warnings = [], &$inviables = []) {
        $fechaInicioMes = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFinMes    = date('Y-m-t', strtotime($fechaInicioMes));

        $semanas = $this->calcularSemanas($mes, $anio);
        $semanasCompletas = [];
        foreach ($semanas as $sem) {
            if ((int)date('n', strtotime($sem['lunes'])) !== (int)$mes) continue;
            if ((int)date('n', strtotime($sem['domingo'])) !== (int)$mes) continue;
            $semanasCompletas[$sem['lunes']] = true;
        }
        if (empty($semanasCompletas)) {
            return;
        }

        $stmtTrab = $this->db->prepare(
            "SELECT id, nombre
             FROM trabajadores
             WHERE activo = true AND LOWER(COALESCE(cargo,'')) != 'supervisor'"
        );
        $stmtTrab->execute();
        $trabajadores = $stmtTrab->fetchAll(PDO::FETCH_ASSOC);

        $perfil = [];
        foreach ($trabajadores as $t) {
            foreach (array_keys($semanasCompletas) as $semKey) {
                $perfil[$t['id']][$semKey] = [
                    'nombre'      => $t['nombre'],
                    'total_horas' => 0.0,
                    'h8'          => 0,
                    'h7'          => 0,
                    'h4'          => 0,
                    'turnos'      => 0,
                ];
            }
        }

        $stmtTurnos = $this->db->prepare(
            "SELECT ta.trabajador_id, ta.fecha, ct.horas_laborales
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta.fecha BETWEEN ? AND ?
             AND ta.estado IN ('programado','activo')"
        );
        $stmtTurnos->execute([$fechaInicioMes, $fechaFinMes]);
        foreach ($stmtTurnos->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $semKey = $this->getSemanaKey($row['fecha']);
            if (!isset($semanasCompletas[$semKey])) continue;
            if (!isset($perfil[$row['trabajador_id']][$semKey])) continue;

            $horas = (float)$row['horas_laborales'];
            $perfil[$row['trabajador_id']][$semKey]['total_horas'] += $horas;
            $perfil[$row['trabajador_id']][$semKey]['turnos']++;
            $bloque = $this->clasificarBloqueHoras($horas);
            if ($bloque === 'h8') $perfil[$row['trabajador_id']][$semKey]['h8']++;
            if ($bloque === 'h7') $perfil[$row['trabajador_id']][$semKey]['h7']++;
            if ($bloque === 'h4') $perfil[$row['trabajador_id']][$semKey]['h4']++;
        }

        // ADMM/ADMT no forman parte del perfil semanal operativo (ya no se usan).

        $recursosFactibilidad = $this->construirRecursosFactibilidadPerfil();

        $errores = [];
        foreach ($perfil as $trabajadorId => $semanasTrab) {
            foreach ($semanasTrab as $semKey => $p) {
                $ok = true;
                $ok = $ok && ((int)$p['h8'] === (int)$perfilObjetivo['max_8h']);
                $ok = $ok && ((int)$p['h7'] === (int)$perfilObjetivo['max_7h']);
                $ok = $ok && ((int)$p['h4'] === (int)$perfilObjetivo['max_4h']);
                $ok = $ok && ((int)$p['turnos'] === 6);
                $ok = $ok && (abs((float)$p['total_horas'] - (float)$perfilObjetivo['max_horas']) < 0.01);
                if ($ok) continue;

                $faltantes = [
                    'h8'     => max(0, (int)$perfilObjetivo['max_8h'] - (int)$p['h8']),
                    'h7'     => max(0, (int)$perfilObjetivo['max_7h'] - (int)$p['h7']),
                    'h4'     => max(0, (int)$perfilObjetivo['max_4h'] - (int)$p['h4']),
                    'turnos' => max(0, 6 - (int)$p['turnos']),
                ];

                $excesos = [
                    'h8' => max(0, (int)$p['h8'] - (int)$perfilObjetivo['max_8h']),
                    'h7' => max(0, (int)$p['h7'] - (int)$perfilObjetivo['max_7h']),
                    'h4' => max(0, (int)$p['h4'] - (int)$perfilObjetivo['max_4h']),
                ];

                $sumFaltantesBloque = (int)$faltantes['h8'] + (int)$faltantes['h7'] + (int)$faltantes['h4'];
                $sinExcesos = ((int)$excesos['h8'] + (int)$excesos['h7'] + (int)$excesos['h4']) === 0;

                $detalle = sprintf(
                    '%s semana %s: h8=%d h7=%d h4=%d turnos=%d horas=%.1f',
                    $p['nombre'] ?? ('trabajador#' . $trabajadorId),
                    $semKey,
                    (int)$p['h8'],
                    (int)$p['h7'],
                    (int)$p['h4'],
                    (int)$p['turnos'],
                    (float)$p['total_horas']
                );

                $esCandidatoInviable =
                    $sinExcesos
                    && $sumFaltantesBloque === (int)$faltantes['turnos']
                    && (float)$p['total_horas'] <= (float)$perfilObjetivo['max_horas'];

                if ($esCandidatoInviable && $this->esPerfilSemanalInviablePorRestricciones((int)$trabajadorId, $semKey, $faltantes, $recursosFactibilidad)) {
                    $inviables[] = $detalle;
                    continue;
                }

                $errores[] = $detalle;

                if (count($errores) >= 12) {
                    break 2;
                }
            }
        }

        if (!empty($inviables)) {
            $warnings[] = 'Semanas inviables por restricciones (se mantiene cumplimiento legal, sin ADMM/ADMT): ' . implode(' | ', array_slice($inviables, 0, 8));
        }

        if (!empty($errores)) {
            $warnings[] = 'No se cumple el perfil semanal estricto (3x8h + 2x7h + 1x4h + 1 libre, sin ADMM/ADMT): ' . implode(' | ', $errores);
        }
    }

    private function verificarPerfilSemanalObjetivoRapido($mes, $anio, $perfilObjetivo, $perfilSemanal, $trabajadores, &$warnings = [], &$inviables = []) {
        $semanas = $this->calcularSemanas($mes, $anio);
        $semanasCompletas = [];
        foreach ($semanas as $sem) {
            if ((int)date('n', strtotime($sem['lunes'])) !== (int)$mes) continue;
            if ((int)date('n', strtotime($sem['domingo'])) !== (int)$mes) continue;
            $semanasCompletas[] = (string)$sem['lunes'];
        }
        if (empty($semanasCompletas)) {
            return;
        }

        $nombres = [];
        foreach ($trabajadores as $trab) {
            $nombres[(int)($trab['id'] ?? 0)] = (string)($trab['nombre'] ?? '');
        }

        $errores = [];
        foreach ($nombres as $trabajadorId => $nombre) {
            if ($trabajadorId <= 0) continue;

            foreach ($semanasCompletas as $semKey) {
                $perfil = $perfilSemanal[$trabajadorId][$semKey] ?? [
                    'total_horas' => 0.0,
                    'h8' => 0,
                    'h7' => 0,
                    'h4' => 0,
                    'otro' => 0,
                ];

                $turnos = (int)($perfil['h8'] ?? 0) + (int)($perfil['h7'] ?? 0) + (int)($perfil['h4'] ?? 0);
                $ok = true;
                $ok = $ok && ((int)($perfil['h8'] ?? 0) === (int)$perfilObjetivo['max_8h']);
                $ok = $ok && ((int)($perfil['h7'] ?? 0) === (int)$perfilObjetivo['max_7h']);
                $ok = $ok && ((int)($perfil['h4'] ?? 0) === (int)$perfilObjetivo['max_4h']);
                $ok = $ok && ($turnos === 6);
                $ok = $ok && (abs((float)($perfil['total_horas'] ?? 0.0) - (float)$perfilObjetivo['max_horas']) < 0.01);
                if ($ok) continue;

                $errores[] = sprintf(
                    '%s semana %s: h8=%d h7=%d h4=%d turnos=%d horas=%.1f',
                    $nombre !== '' ? $nombre : ('trabajador#' . $trabajadorId),
                    $semKey,
                    (int)($perfil['h8'] ?? 0),
                    (int)($perfil['h7'] ?? 0),
                    (int)($perfil['h4'] ?? 0),
                    $turnos,
                    (float)($perfil['total_horas'] ?? 0.0)
                );

                if (count($errores) >= 12) {
                    break 2;
                }
            }
        }

        if (!empty($errores)) {
            $warnings[] = 'No se cumple el perfil semanal estricto (resumen rapido): ' . implode(' | ', $errores);
        }
    }

    private function construirRecursosFactibilidadPerfil() {
        $turno7PorNumero = [1 => null, 2 => null];
        $turno8PorNumero = [1 => null, 2 => null, 3 => null];
        $turnoL4PorBase = [1 => [], 2 => []];

        $stmtCfg = $this->db->prepare(
            "SELECT id, numero_turno, horas_laborales
             FROM configuracion_turnos
             WHERE activo = TRUE
             ORDER BY numero_turno, id"
        );
        $stmtCfg->execute();
        foreach ($stmtCfg->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $num = (int)($r['numero_turno'] ?? 0);
            $horas = (float)($r['horas_laborales'] ?? 0);
            $idTurno = (int)($r['id'] ?? 0);
            if ($idTurno <= 0) continue;

            if (($num === 1 || $num === 2) && $horas >= 6.5 && $horas < 7.5 && $turno7PorNumero[$num] === null) {
                $turno7PorNumero[$num] = $idTurno;
            }
            if (($num === 1 || $num === 2 || $num === 3) && $horas >= 7.5 && $turno8PorNumero[$num] === null) {
                $turno8PorNumero[$num] = $idTurno;
            }
            if ($num === 4 && $horas >= 3.5 && $horas <= 4.5) {
                $turnoL4PorBase[1][] = $idTurno;
            }
            if ($num === 5 && $horas >= 3.5 && $horas <= 4.5) {
                $turnoL4PorBase[2][] = $idTurno;
            }
        }

        $stmtP7 = $this->db->prepare(
            "SELECT id, codigo
             FROM puestos_trabajo
             WHERE activo = TRUE
             AND codigo IN ('D1','D2','D4','F15','F2','F5')"
        );
        $stmtP7->execute();
        $puestos7h = $stmtP7->fetchAll(PDO::FETCH_ASSOC);

        $stmtPL4 = $this->db->prepare(
            "SELECT id, codigo
             FROM puestos_trabajo
             WHERE activo = TRUE
             AND codigo IN ('D1','D2','F11','F5','F15')"
        );
        $stmtPL4->execute();
        $puestosL4PorBase = [1 => [], 2 => []];
        foreach ($stmtPL4->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $cod = strtoupper((string)($p['codigo'] ?? ''));
            if ($cod === 'F11' || $cod === 'F5' || $cod === 'F15') {
                $puestosL4PorBase[1][] = ['id' => (int)$p['id'], 'codigo' => $cod];
            }
            if ($cod === 'D1' || $cod === 'D2') {
                $puestosL4PorBase[2][] = ['id' => (int)$p['id'], 'codigo' => $cod];
            }
        }

        $stmtP8 = $this->db->prepare(
            "SELECT id, codigo
             FROM puestos_trabajo
             WHERE activo = TRUE
               AND codigo NOT IN ('C2','D1','D2','D4','F15','F2','F5')"
        );
        $stmtP8->execute();
        $puestos8h = $stmtP8->fetchAll(PDO::FETCH_ASSOC);

        return [
            'turno7PorNumero' => $turno7PorNumero,
            'turno8PorNumero' => $turno8PorNumero,
            'turnoL4PorBase' => $turnoL4PorBase,
            'puestos7h' => $puestos7h,
            'puestos8h' => $puestos8h,
            'puestosL4PorBase' => $puestosL4PorBase,
        ];
    }

    private function esPerfilSemanalInviablePorRestricciones($trabajadorId, $semanaInicio, $faltantes, $recursosFactibilidad) {
        $faltanteTurnos = (int)($faltantes['turnos'] ?? 0);
        if ($faltanteTurnos <= 0) return false;

        $inicio = date('Y-m-d', strtotime($semanaInicio));
        $fin = date('Y-m-d', strtotime($inicio . ' +6 days'));

        $stmtDiasOcupados = $this->db->prepare(
            "SELECT DISTINCT fecha
             FROM turnos_asignados
             WHERE trabajador_id = ?
             AND fecha BETWEEN ? AND ?
             AND estado IN ('programado','activo')"
        );
        $stmtDiasOcupados->execute([(int)$trabajadorId, $inicio, $fin]);
        $ocupadosSet = [];
        foreach ($stmtDiasOcupados->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ocupadosSet[(string)$row['fecha']] = true;
        }

        $diasLibres = [];
        for ($d = 0; $d <= 6; $d++) {
            $fecha = date('Y-m-d', strtotime($inicio . ' +' . $d . ' days'));
            if (!empty($ocupadosSet[$fecha])) continue;
            $diasLibres[] = $fecha;
        }

        if (count($diasLibres) < $faltanteTurnos) {
            return true;
        }

        $turno7PorNumero = $recursosFactibilidad['turno7PorNumero'] ?? [];
        $turno8PorNumero = $recursosFactibilidad['turno8PorNumero'] ?? [];
        $turnoL4PorBase = $recursosFactibilidad['turnoL4PorBase'] ?? [];
        $puestos7h = $recursosFactibilidad['puestos7h'] ?? [];
        $puestos8h = $recursosFactibilidad['puestos8h'] ?? [];
        $puestosL4PorBase = $recursosFactibilidad['puestosL4PorBase'] ?? [1 => [], 2 => []];

        $stmtOcupadoNumero = $this->db->prepare(
            "SELECT COUNT(*) c
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta.estado IN ('programado','activo')
             AND ta.puesto_trabajo_id = ?
             AND ct.numero_turno = ?
             AND ta.fecha = ?"
        );

           $stmtOcupadoL4 = $this->db->prepare(
            "SELECT COUNT(*) c
               FROM turnos_asignados ta
             WHERE ta.estado IN ('programado','activo')
             AND ta.puesto_trabajo_id = ?
               AND ta.turno_id = ?
             AND ta.fecha = ?"
        );

        $bloquesRequeridos = [];
        for ($i = 0; $i < (int)($faltantes['h8'] ?? 0); $i++) $bloquesRequeridos[] = 'h8';
        for ($i = 0; $i < (int)($faltantes['h7'] ?? 0); $i++) $bloquesRequeridos[] = 'h7';
        for ($i = 0; $i < (int)($faltantes['h4'] ?? 0); $i++) $bloquesRequeridos[] = 'h4';
        if (count($bloquesRequeridos) !== $faltanteTurnos) return false;

        $opcionesPorDia = [];
        foreach ($diasLibres as $fecha) {
            $ops = [];

            if ((int)($faltantes['h8'] ?? 0) > 0) {
                foreach ($puestos8h as $p8) {
                    $codigo8 = strtoupper((string)($p8['codigo'] ?? ''));
                    foreach ([1,2,3] as $num8) {
                        if ($codigo8 === 'D2' && $num8 === 1) continue;
                        if ($num8 === 3 && !in_array($codigo8, ['V1','V2','C','D3','F6','F11'], true)) continue;
                        $turno8Id = (int)($turno8PorNumero[$num8] ?? 0);
                        if ($turno8Id <= 0) continue;

                        $stmtOcupadoNumero->execute([(int)$p8['id'], $num8, $fecha]);
                        $ocup = (int)($stmtOcupadoNumero->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                        if ($ocup > 0) continue;

                        $valid = $this->turnosAsignados->validarAsignacion((int)$trabajadorId, (int)$p8['id'], $turno8Id, $fecha, null);
                        if (!$valid['valido']) continue;
                        $ops['h8'] = true;
                        break 2;
                    }
                }
            }

            if ((int)($faltantes['h7'] ?? 0) > 0) {
                foreach ($puestos7h as $p7) {
                    $codigo7 = strtoupper((string)($p7['codigo'] ?? ''));
                    foreach ([1,2] as $num7) {
                        if ($codigo7 === 'D2' && $num7 === 1) continue;
                        $turno7Id = (int)($turno7PorNumero[$num7] ?? 0);
                        if ($turno7Id <= 0) continue;

                        $stmtOcupadoNumero->execute([(int)$p7['id'], $num7, $fecha]);
                        $ocup = (int)($stmtOcupadoNumero->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                        if ($ocup > 0) continue;

                        $valid = $this->turnosAsignados->validarAsignacion((int)$trabajadorId, (int)$p7['id'], $turno7Id, $fecha, null);
                        if (!$valid['valido']) continue;
                        $ops['h7'] = true;
                        break 2;
                    }
                }
            }

            if ((int)($faltantes['h4'] ?? 0) > 0) {
                foreach ([1,2] as $baseL4) {
                    $turnosL4Ids = $turnoL4PorBase[$baseL4] ?? [];
                    if (empty($turnosL4Ids)) continue;

                    foreach (($puestosL4PorBase[$baseL4] ?? []) as $pL4) {
                        foreach ($turnosL4Ids as $turnoL4Id) {
                            $turnoL4Id = (int)$turnoL4Id;
                            if ($turnoL4Id <= 0) continue;

                            $stmtOcupadoL4->execute([(int)$pL4['id'], $turnoL4Id, $fecha]);
                            $ocupL4 = (int)($stmtOcupadoL4->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                            if ($ocupL4 >= 2) continue;

                            $valid = $this->turnosAsignados->validarAsignacion((int)$trabajadorId, (int)$pL4['id'], $turnoL4Id, $fecha, null);
                            if (!$valid['valido']) continue;
                            $ops['h4'] = true;
                            break 3;
                        }
                    }
                }
            }

            if (!empty($ops)) {
                $opcionesPorDia[$fecha] = $ops;
            }
        }

        $diasDisponiblesOpciones = array_keys($opcionesPorDia);
        if (count($diasDisponiblesOpciones) < $faltanteTurnos) {
            return true;
        }

        $puedeCubrir = $this->puedeCubrirBloquesConDias($bloquesRequeridos, $diasDisponiblesOpciones, $opcionesPorDia, []);
        return !$puedeCubrir;
    }

    private function puedeCubrirBloquesConDias($bloquesRequeridos, $diasDisponibles, $opcionesPorDia, $diasUsados = []) {
        if (empty($bloquesRequeridos)) return true;

        $bloque = array_shift($bloquesRequeridos);
        foreach ($diasDisponibles as $idx => $fecha) {
            if (!empty($diasUsados[$fecha])) continue;
            if (empty($opcionesPorDia[$fecha][$bloque])) continue;

            $diasUsadosSig = $diasUsados;
            $diasUsadosSig[$fecha] = true;

            $diasRestantes = $diasDisponibles;
            unset($diasRestantes[$idx]);
            $diasRestantes = array_values($diasRestantes);

            if ($this->puedeCubrirBloquesConDias($bloquesRequeridos, $diasRestantes, $opcionesPorDia, $diasUsadosSig)) {
                return true;
            }
        }

        return false;
    }

    private function obtenerTurno7hPorNumero($turnoOpcionesPorNumero) {
        $map = [];
        foreach ([1, 2] as $num) {
            foreach (($turnoOpcionesPorNumero[$num] ?? []) as $op) {
                $h = (float)($op['horas'] ?? 0);
                if ($h >= 6.5 && $h < 7.5) {
                    $map[$num] = (int)$op['id'];
                    break;
                }
            }
        }
        return $map;
    }

    private function rebalancearPerfilSemanal($mes, $anio, &$ctx, &$perfilSemanal, $turnoOpcionesPorNumero, $perfilObjetivo, &$warnings) {
        $turno7PorNumero = $this->obtenerTurno7hPorNumero($turnoOpcionesPorNumero);
        if (empty($turno7PorNumero[1]) || empty($turno7PorNumero[2])) {
            $warnings[] = 'No se pudo rebalancear a 7h: falta turno 7h activo para manana o tarde.';
            return;
        }

        $turnoL4PorBase = [1 => [], 2 => []];
        $stmtL4Cfg = $this->db->prepare(
            "SELECT id, numero_turno, horas_laborales
             FROM configuracion_turnos
             WHERE activo = TRUE
             AND numero_turno IN (4,5)
             ORDER BY numero_turno, id"
        );
        $stmtL4Cfg->execute();
        foreach ($stmtL4Cfg->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int)$r['numero_turno'];
            $h = (float)$r['horas_laborales'];
            if ($h < 3.5 || $h > 4.5) continue;
            if ($n === 4) $turnoL4PorBase[1][] = (int)$r['id'];
            if ($n === 5) $turnoL4PorBase[2][] = (int)$r['id'];
        }

        $semanas = $this->calcularSemanas($mes, $anio);
        $semanasCompletas = [];
        foreach ($semanas as $sem) {
            if ((int)date('n', strtotime($sem['lunes'])) !== (int)$mes) continue;
            if ((int)date('n', strtotime($sem['domingo'])) !== (int)$mes) continue;
            $semanasCompletas[] = $sem;
        }
        if (empty($semanasCompletas)) return;

        $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
        if (!$puestoCol) {
            $warnings[] = 'No se pudo rebalancear perfil semanal: no se detecto columna de puesto en turnos_asignados.';
            return;
        }

        $puestosL4PorBase = [1 => [], 2 => []];
        $stmtPuestosL4 = $this->db->prepare(
            "SELECT id, codigo
             FROM puestos_trabajo
             WHERE activo = TRUE
             AND codigo IN ('D1','D2','F11','F5','F15')"
        );
        $stmtPuestosL4->execute();
        foreach ($stmtPuestosL4->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $cod = strtoupper((string)($p['codigo'] ?? ''));
            if ($cod === 'F11' || $cod === 'F5' || $cod === 'F15') $puestosL4PorBase[1][] = ['id' => (int)$p['id'], 'codigo' => $cod];
            if ($cod === 'D1' || $cod === 'D2') $puestosL4PorBase[2][] = ['id' => (int)$p['id'], 'codigo' => $cod];
        }

        $stmtPuestos7h = $this->db->prepare(
            "SELECT id, codigo
             FROM puestos_trabajo
             WHERE activo = TRUE
             AND codigo IN ('D1','D2','D4','F15','F2','F5')
             ORDER BY codigo"
        );
        $stmtPuestos7h->execute();
        $puestos7h = $stmtPuestos7h->fetchAll(PDO::FETCH_ASSOC);

        $turno8PorNumero = [1 => null, 2 => null, 3 => null];
        foreach ([1,2,3] as $numBase) {
            foreach (($turnoOpcionesPorNumero[$numBase] ?? []) as $op) {
                if ((float)($op['horas'] ?? 0) >= 7.5) {
                    $turno8PorNumero[$numBase] = (int)$op['id'];
                    break;
                }
            }
        }

        $stmtPuestos8h = $this->db->prepare(
            "SELECT id, codigo
             FROM puestos_trabajo
             WHERE activo = TRUE
               AND codigo NOT IN ('C2','D1','D2','D4','F15','F2','F5')
             ORDER BY codigo"
        );
        $stmtPuestos8h->execute();
        $puestos8h = $stmtPuestos8h->fetchAll(PDO::FETCH_ASSOC);

        $stmtSemana = $this->db->prepare(
            "SELECT ta.id, ta.trabajador_id, ta.fecha, ta.turno_id,
                    ct.numero_turno, ct.horas_laborales,
                    ta." . $puestoCol . " as puesto_trabajo_id,
                    pt.codigo as puesto_codigo
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             LEFT JOIN puestos_trabajo pt ON ta." . $puestoCol . " = pt.id
             WHERE ta.trabajador_id = ?
             AND ta.fecha BETWEEN ? AND ?
             AND ta.estado IN ('programado','activo')
             ORDER BY ta.fecha, ta.id"
        );

        $stmtUpdTurno = $this->db->prepare(
            "UPDATE turnos_asignados
             SET turno_id = ?, observaciones = CONCAT(COALESCE(observaciones,''), ' [rebalance 7h]')
             WHERE id = ?"
        );

        $stmtUpdTurnoL4 = $this->db->prepare(
            "UPDATE turnos_asignados
             SET turno_id = ?, " . $puestoCol . " = ?, observaciones = CONCAT(COALESCE(observaciones,''), ' [rebalance L4]')
             WHERE id = ?"
        );

        $stmtOcupadoNumero = $this->db->prepare(
            "SELECT COUNT(*) as c
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta." . $puestoCol . " = ?
             AND ct.numero_turno = ?
             AND ta.fecha = ?
             AND ta.estado IN ('programado','activo')"
        );

           $stmtOcupadoTurnoId = $this->db->prepare(
              "SELECT COUNT(*) as c
               FROM turnos_asignados ta
               WHERE ta." . $puestoCol . " = ?
               AND ta.turno_id = ?
               AND ta.fecha = ?
               AND ta.estado IN ('programado','activo')"
           );

        foreach ($ctx['todosActivos'] as $trab) {
            $trabId = (int)$trab['id'];
            foreach ($semanasCompletas as $sem) {
                $semKey = $sem['lunes'];
                $perfil = $perfilSemanal[$trabId][$semKey] ?? ['total_horas' => 0.0, 'h8' => 0, 'h7' => 0, 'h4' => 0, 'otro' => 0];

                $stmtSemana->execute([$trabId, $sem['lunes'], $sem['domingo']]);
                $asigsSemana = $stmtSemana->fetchAll(PDO::FETCH_ASSOC);
                $diasConTurno = [];
                foreach ($asigsSemana as $aDia) {
                    $diasConTurno[(string)$aDia['fecha']] = true;
                }

                $need7 = max(0, (int)$perfilObjetivo['max_7h'] - (int)($perfil['h7'] ?? 0));
                $extra8 = max(0, (int)($perfil['h8'] ?? 0) - (int)$perfilObjetivo['max_8h']);
                $aConvertir = min($need7, $extra8);

                if ($aConvertir > 0) {
                    foreach ($asigsSemana as $a) {
                        if ($aConvertir <= 0) break;
                        $numTurno = (int)($a['numero_turno'] ?? 0);
                        $h = (float)($a['horas_laborales'] ?? 0);
                        $codigoPuesto = strtoupper((string)($a['puesto_codigo'] ?? ''));
                        if (!in_array($numTurno, [1, 2], true)) continue;
                        if ($h < 7.5) continue;
                        if ($this->esPuestoFijo8h($codigoPuesto)) continue;

                        $nuevoTurnoId = (int)($turno7PorNumero[$numTurno] ?? 0);
                        if ($nuevoTurnoId <= 0) continue;

                        $valid = $this->turnosAsignados->validarAsignacion(
                            $trabId,
                            (int)$a['puesto_trabajo_id'],
                            $nuevoTurnoId,
                            $a['fecha'],
                            (int)$a['id']
                        );
                        if (!$valid['valido']) continue;

                        $stmtUpdTurno->execute([$nuevoTurnoId, (int)$a['id']]);
                        $perfilSemanal[$trabId][$semKey]['h8'] = max(0, (int)$perfilSemanal[$trabId][$semKey]['h8'] - 1);
                        $perfilSemanal[$trabId][$semKey]['h7'] = (int)$perfilSemanal[$trabId][$semKey]['h7'] + 1;
                        $perfilSemanal[$trabId][$semKey]['total_horas'] = (float)$perfilSemanal[$trabId][$semKey]['total_horas'] - 1.0;
                        $aConvertir--;
                    }
                }

                // Forzar bloque 4h real: convertir un turno T1/T2 existente a L4 cuando sea posible.
                if ((int)($perfilSemanal[$trabId][$semKey]['h4'] ?? 0) < (int)$perfilObjetivo['max_4h']) {
                    foreach ($asigsSemana as $a) {
                        $numTurno = (int)($a['numero_turno'] ?? 0);
                        if (!in_array($numTurno, [1, 2], true)) continue;
                        $base = $numTurno;
                        $turnosL4Ids = $turnoL4PorBase[$base] ?? [];
                        if (empty($turnosL4Ids)) continue;

                        foreach (($puestosL4PorBase[$base] ?? []) as $pL4) {
                            foreach ($turnosL4Ids as $turnoL4Id) {
                                $turnoL4Id = (int)$turnoL4Id;
                                if ($turnoL4Id <= 0) continue;

                                $stmtOcupadoTurnoId->execute([(int)$pL4['id'], $turnoL4Id, $a['fecha']]);
                                $ocup = (int)($stmtOcupadoTurnoId->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                                if ($ocup >= 2) continue;

                                $valid = $this->turnosAsignados->validarAsignacion(
                                    $trabId,
                                    (int)$pL4['id'],
                                    $turnoL4Id,
                                    $a['fecha'],
                                    (int)$a['id']
                                );
                                if (!$valid['valido']) continue;

                                $hOld = (float)($a['horas_laborales'] ?? 0);
                                $bloqueOld = $this->clasificarBloqueHoras($hOld);
                                if ($bloqueOld === 'h8') {
                                    $perfilSemanal[$trabId][$semKey]['h8'] = max(0, (int)$perfilSemanal[$trabId][$semKey]['h8'] - 1);
                                }
                                if ($bloqueOld === 'h7') {
                                    $perfilSemanal[$trabId][$semKey]['h7'] = max(0, (int)$perfilSemanal[$trabId][$semKey]['h7'] - 1);
                                }
                                if ($bloqueOld === 'h4') {
                                    $perfilSemanal[$trabId][$semKey]['h4'] = max(0, (int)$perfilSemanal[$trabId][$semKey]['h4'] - 1);
                                }

                                $stmtUpdTurnoL4->execute([$turnoL4Id, (int)$pL4['id'], (int)$a['id']]);
                                $perfilSemanal[$trabId][$semKey]['h4'] = (int)$perfilSemanal[$trabId][$semKey]['h4'] + 1;
                                $perfilSemanal[$trabId][$semKey]['total_horas'] = (float)$perfilSemanal[$trabId][$semKey]['total_horas'] - $hOld + 4.0;
                                break 3;
                            }
                        }
                    }
                }

                // Completar faltantes de 8h/7h y total de turnos semanales en días libres.
                $guard = 0;
                while (
                    ((int)($perfilSemanal[$trabId][$semKey]['h8'] ?? 0)
                        + (int)($perfilSemanal[$trabId][$semKey]['h7'] ?? 0)
                        + (int)($perfilSemanal[$trabId][$semKey]['h4'] ?? 0)) < 6
                    && $guard < 8
                ) {
                    $guard++;
                    $agregado = false;

                    $need8 = (int)$perfilObjetivo['max_8h'] - (int)($perfilSemanal[$trabId][$semKey]['h8'] ?? 0);
                    $need7 = (int)$perfilObjetivo['max_7h'] - (int)($perfilSemanal[$trabId][$semKey]['h7'] ?? 0);
                    $prefer8 = $need8 > 0;

                    for ($d = 0; $d <= 6 && !$agregado; $d++) {
                        $ts = strtotime($sem['lunes']) + $d * 86400;
                        $fecha = date('Y-m-d', $ts);
                        if ((int)date('n', $ts) !== (int)$mes) continue;
                        if (!empty($diasConTurno[$fecha])) continue;

                        if ($prefer8) {
                            foreach ($puestos8h as $p8) {
                                $codigo8 = strtoupper((string)($p8['codigo'] ?? ''));
                                foreach ([1,2,3] as $numBase8) {
                                    if ($codigo8 === 'D2' && $numBase8 === 1) continue;
                                    if ($numBase8 === 3 && !in_array($codigo8, ['V1','V2','C','D3','F6','F11'], true)) continue;
                                    $turno8Id = (int)($turno8PorNumero[$numBase8] ?? 0);
                                    if ($turno8Id <= 0) continue;

                                    $stmtOcupadoNumero->execute([(int)$p8['id'], $numBase8, $fecha]);
                                    $ocup = (int)($stmtOcupadoNumero->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                                    if ($ocup > 0) continue;

                                    $valid = $this->turnosAsignados->validarAsignacion(
                                        $trabId,
                                        (int)$p8['id'],
                                        $turno8Id,
                                        $fecha,
                                        null
                                    );
                                    if (!$valid['valido']) continue;

                                    $res = $this->turnosAsignados->asignarDirecto([
                                        'trabajador_id'     => $trabId,
                                        'puesto_trabajo_id' => (int)$p8['id'],
                                        'turno_id'          => $turno8Id,
                                        'fecha'             => $fecha,
                                        'observaciones'     => 'Asignacion automatica [rebalance faltante 8h]'
                                    ]);
                                    if (!$res['success']) continue;

                                    $diasConTurno[$fecha] = true;
                                    $ctx['asignadosPorDia'][$fecha][$trabId][] = $numBase8;
                                    $perfilSemanal[$trabId][$semKey]['h8'] = (int)$perfilSemanal[$trabId][$semKey]['h8'] + 1;
                                    $perfilSemanal[$trabId][$semKey]['total_horas'] = (float)$perfilSemanal[$trabId][$semKey]['total_horas'] + 8.0;
                                    $agregado = true;
                                    break 3;
                                }
                            }
                        }

                        if (!$agregado && $need7 > 0) {
                            foreach ($puestos7h as $p7) {
                                $codigo7 = strtoupper((string)($p7['codigo'] ?? ''));
                                foreach ([1,2] as $numBase) {
                                    if ($codigo7 === 'D2' && $numBase === 1) continue;
                                    $turno7Id = (int)($turno7PorNumero[$numBase] ?? 0);
                                    if ($turno7Id <= 0) continue;

                                    $stmtOcupadoNumero->execute([(int)$p7['id'], $numBase, $fecha]);
                                    $ocup = (int)($stmtOcupadoNumero->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                                    if ($ocup > 0) continue;

                                    $valid = $this->turnosAsignados->validarAsignacion(
                                        $trabId,
                                        (int)$p7['id'],
                                        $turno7Id,
                                        $fecha,
                                        null
                                    );
                                    if (!$valid['valido']) continue;

                                    $res = $this->turnosAsignados->asignarDirecto([
                                        'trabajador_id'     => $trabId,
                                        'puesto_trabajo_id' => (int)$p7['id'],
                                        'turno_id'          => $turno7Id,
                                        'fecha'             => $fecha,
                                        'observaciones'     => 'Asignacion automatica [rebalance faltante 7h]'
                                    ]);
                                    if (!$res['success']) continue;

                                    $diasConTurno[$fecha] = true;
                                    $ctx['asignadosPorDia'][$fecha][$trabId][] = $numBase;
                                    $perfilSemanal[$trabId][$semKey]['h7'] = (int)$perfilSemanal[$trabId][$semKey]['h7'] + 1;
                                    $perfilSemanal[$trabId][$semKey]['total_horas'] = (float)$perfilSemanal[$trabId][$semKey]['total_horas'] + 7.0;
                                    $agregado = true;
                                    break 3;
                                }
                            }
                        }
                    }

                    if (!$agregado) break;
                }

                // Refuerzo final: si aun falta h4 y el trabajador tiene dia libre, intentar L4 adicional real.
                if ((int)($perfilSemanal[$trabId][$semKey]['h4'] ?? 0) < (int)$perfilObjetivo['max_4h']) {
                    for ($d = 0; $d <= 6; $d++) {
                        $ts = strtotime($sem['lunes']) + $d * 86400;
                        $fecha = date('Y-m-d', $ts);
                        if ((int)date('n', $ts) !== (int)$mes) continue;
                        if (!empty($diasConTurno[$fecha])) continue;

                        foreach ([1,2] as $base) {
                            $turnosL4Ids = $turnoL4PorBase[$base] ?? [];
                            if (empty($turnosL4Ids)) continue;
                            foreach (($puestosL4PorBase[$base] ?? []) as $pL4) {
                                foreach ($turnosL4Ids as $turnoL4Id) {
                                    $turnoL4Id = (int)$turnoL4Id;
                                    if ($turnoL4Id <= 0) continue;
                                    $numL4 = ($base === 1) ? 4 : 5;

                                    $stmtOcupadoTurnoId->execute([(int)$pL4['id'], $turnoL4Id, $fecha]);
                                    $ocup = (int)($stmtOcupadoTurnoId->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                                    if ($ocup >= 2) continue;

                                    $valid = $this->turnosAsignados->validarAsignacion(
                                        $trabId,
                                        (int)$pL4['id'],
                                        $turnoL4Id,
                                        $fecha,
                                        null
                                    );
                                    if (!$valid['valido']) continue;

                                    $res = $this->turnosAsignados->asignarDirecto([
                                        'trabajador_id'     => $trabId,
                                        'puesto_trabajo_id' => (int)$pL4['id'],
                                        'turno_id'          => $turnoL4Id,
                                        'fecha'             => $fecha,
                                        'observaciones'     => 'Asignacion automatica [rebalance faltante 4h]'
                                    ]);
                                    if (!$res['success']) continue;

                                    $diasConTurno[$fecha] = true;
                                    $ctx['asignadosPorDia'][$fecha][$trabId][] = $numL4;
                                    $perfilSemanal[$trabId][$semKey]['h4'] = (int)$perfilSemanal[$trabId][$semKey]['h4'] + 1;
                                    $perfilSemanal[$trabId][$semKey]['total_horas'] = (float)$perfilSemanal[$trabId][$semKey]['total_horas'] + 4.0;
                                    break 4;
                                }
                            }
                        }
                    }
                }

                // Recontar turnos del perfil para mantener consistencia interna.
                $perfilSemanal[$trabId][$semKey]['otro'] = 0;
            }
        }

        // Segunda pasada global: intenta cerrar h4 para los que quedaron en 5 turnos/38h.
        $this->rebalanceGlobalH4Pendientes(
            $mes,
            $semanasCompletas,
            $perfilSemanal,
            $perfilObjetivo,
            $ctx,
            $turnoL4PorBase,
            $puestosL4PorBase,
            $warnings
        );
    }

    private function rebalanceGlobalH4Pendientes($mes, $semanasCompletas, &$perfilSemanal, $perfilObjetivo, &$ctx, $turnoL4PorBase, $puestosL4PorBase, &$warnings) {
        $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
        if (!$puestoCol) return;

        $stmtSemana = $this->db->prepare(
            "SELECT ta.fecha
             FROM turnos_asignados ta
             WHERE ta.trabajador_id = ?
             AND ta.fecha BETWEEN ? AND ?
             AND ta.estado IN ('programado','activo')"
        );

        $stmtOcupadoNumero = $this->db->prepare(
            "SELECT COUNT(*) as c
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta." . $puestoCol . " = ?
             AND ct.numero_turno = ?
             AND ta.fecha = ?
             AND ta.estado IN ('programado','activo')"
        );

        $stmtOcupadoTurnoId = $this->db->prepare(
            "SELECT COUNT(*) as c
             FROM turnos_asignados ta
             WHERE ta." . $puestoCol . " = ?
             AND ta.turno_id = ?
             AND ta.fecha = ?
             AND ta.estado IN ('programado','activo')"
        );

        $pendientes = [];
        foreach ($perfilSemanal as $trabId => $semanasTrab) {
            foreach ($semanasCompletas as $sem) {
                $semKey = (string)$sem['lunes'];
                $p = $semanasTrab[$semKey] ?? null;
                if (!$p) continue;

                $h8 = (int)($p['h8'] ?? 0);
                $h7 = (int)($p['h7'] ?? 0);
                $h4 = (int)($p['h4'] ?? 0);
                $turnos = $h8 + $h7 + $h4;

                if ($h4 >= (int)$perfilObjetivo['max_4h']) continue;
                if ($turnos >= 6) continue;
                if ((float)($p['total_horas'] ?? 0) > (float)$perfilObjetivo['max_horas']) continue;

                $pendientes[] = ['trabajador_id' => (int)$trabId, 'semana' => $sem, 'semKey' => $semKey];
            }
        }

        foreach ($pendientes as $pend) {
            $trabId = (int)$pend['trabajador_id'];
            $sem = $pend['semana'];
            $semKey = (string)$pend['semKey'];

            $stmtSemana->execute([$trabId, $sem['lunes'], $sem['domingo']]);
            $diasConTurno = [];
            foreach ($stmtSemana->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $diasConTurno[(string)$row['fecha']] = true;
            }

            $asignado = false;
            for ($d = 0; $d <= 6 && !$asignado; $d++) {
                $ts = strtotime($sem['lunes']) + $d * 86400;
                $fecha = date('Y-m-d', $ts);
                if ((int)date('n', $ts) !== (int)$mes) continue;
                if (!empty($diasConTurno[$fecha])) continue;

                foreach ([1,2] as $base) {
                    $turnosL4Ids = $turnoL4PorBase[$base] ?? [];
                    if (empty($turnosL4Ids)) continue;

                    foreach (($puestosL4PorBase[$base] ?? []) as $pL4) {
                        foreach ($turnosL4Ids as $turnoL4Id) {
                            $turnoL4Id = (int)$turnoL4Id;
                            if ($turnoL4Id <= 0) continue;

                            $stmtOcupadoTurnoId->execute([(int)$pL4['id'], $turnoL4Id, $fecha]);
                            $ocup = (int)($stmtOcupadoTurnoId->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                            if ($ocup >= 2) continue;

                            $valid = $this->turnosAsignados->validarAsignacion(
                                $trabId,
                                (int)$pL4['id'],
                                $turnoL4Id,
                                $fecha,
                                null
                            );
                            if (!$valid['valido']) continue;

                            $res = $this->turnosAsignados->asignarDirecto([
                                'trabajador_id'     => $trabId,
                                'puesto_trabajo_id' => (int)$pL4['id'],
                                'turno_id'          => $turnoL4Id,
                                'fecha'             => $fecha,
                                'observaciones'     => 'Asignacion automatica [rebalance h4 global]'
                            ]);
                            if (!$res['success']) continue;

                            $numL4 = ($base === 1) ? 4 : 5;
                            $ctx['asignadosPorDia'][$fecha][$trabId][] = $numL4;
                            $perfilSemanal[$trabId][$semKey]['h4'] = (int)($perfilSemanal[$trabId][$semKey]['h4'] ?? 0) + 1;
                            $perfilSemanal[$trabId][$semKey]['total_horas'] = (float)($perfilSemanal[$trabId][$semKey]['total_horas'] ?? 0) + 4.0;
                            $asignado = true;
                            break 4;
                        }
                    }
                }
            }

            if (!$asignado) {
                $warnings[] = 'Rebalance global h4: trabajador #' . $trabId . ' semana ' . $semKey . ' sin cupo legal para 4h.';
            }
        }
    }

    private function rescateFinalPerfilSemanal($mes, $anio, $perfilObjetivo, &$warnings, &$ctx) {
        $semanas = $this->calcularSemanas($mes, $anio);
        $semanasCompletas = [];
        foreach ($semanas as $sem) {
            if ((int)date('n', strtotime($sem['lunes'])) !== (int)$mes) continue;
            if ((int)date('n', strtotime($sem['domingo'])) !== (int)$mes) continue;
            $semanasCompletas[] = $sem;
        }
        if (empty($semanasCompletas)) return;

        $stmtTrab = $this->db->prepare(
            "SELECT id
             FROM trabajadores
             WHERE activo = true AND LOWER(COALESCE(cargo,'')) != 'supervisor'"
        );
        $stmtTrab->execute();
        $trabajadores = $stmtTrab->fetchAll(PDO::FETCH_ASSOC);
        if (empty($trabajadores)) return;

        $stmtPerf = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN ct.horas_laborales >= 7.5 THEN 1 ELSE 0 END),0) AS h8,
                COALESCE(SUM(CASE WHEN ct.horas_laborales >= 6.5 AND ct.horas_laborales < 7.5 THEN 1 ELSE 0 END),0) AS h7,
                COALESCE(SUM(CASE WHEN ct.horas_laborales >= 3.5 AND ct.horas_laborales <= 4.5 THEN 1 ELSE 0 END),0) AS h4,
                COALESCE(SUM(ct.horas_laborales),0) AS total_horas,
                COUNT(*) AS turnos
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta.trabajador_id = ?
             AND ta.fecha BETWEEN ? AND ?
             AND ta.estado IN ('programado','activo')"
        );

        $pendientes7 = [];
        $pendientes4 = [];
        foreach ($trabajadores as $t) {
            $trabId = (int)$t['id'];
            foreach ($semanasCompletas as $sem) {
                $stmtPerf->execute([$trabId, $sem['lunes'], $sem['domingo']]);
                $p = $stmtPerf->fetch(PDO::FETCH_ASSOC) ?: [];

                $h8 = (int)($p['h8'] ?? 0);
                $h7 = (int)($p['h7'] ?? 0);
                $h4 = (int)($p['h4'] ?? 0);
                $turnos = (int)($p['turnos'] ?? 0);
                $total = (float)($p['total_horas'] ?? 0);

                if ($turnos !== 5) continue;
                if ($total > (float)$perfilObjetivo['max_horas']) continue;

                if ($h8 === (int)$perfilObjetivo['max_8h'] && $h7 === ((int)$perfilObjetivo['max_7h'] - 1) && $h4 === (int)$perfilObjetivo['max_4h']) {
                    $pendientes7[] = ['trabajador_id' => $trabId, 'semana' => $sem];
                } elseif ($h8 === (int)$perfilObjetivo['max_8h'] && $h7 === (int)$perfilObjetivo['max_7h'] && $h4 === ((int)$perfilObjetivo['max_4h'] - 1)) {
                    $pendientes4[] = ['trabajador_id' => $trabId, 'semana' => $sem];
                }
            }
        }

        foreach ($pendientes7 as $p7) {
            $ok = $this->intentarAgregar7hSemana((int)$p7['trabajador_id'], $p7['semana'], $mes, $ctx);
            if (!$ok) {
                $warnings[] = 'Rescate final 7h: sin cupo legal trabajador #' . (int)$p7['trabajador_id'] . ' semana ' . (string)$p7['semana']['lunes'];
            }
        }

        foreach ($pendientes4 as $p4) {
            $ok = $this->intentarAgregar4hSemana((int)$p4['trabajador_id'], $p4['semana'], $mes, $ctx);
            if (!$ok) {
                $warnings[] = 'Rescate final 4h: sin cupo legal trabajador #' . (int)$p4['trabajador_id'] . ' semana ' . (string)$p4['semana']['lunes'];
            }
        }
    }

    private function intentarAgregar7hSemana($trabajadorId, $semana, $mes, &$ctx) {
        $turno7PorNumero = [1 => null, 2 => null];
        $stmtCfg = $this->db->prepare(
            "SELECT id, numero_turno, horas_laborales
             FROM configuracion_turnos
             WHERE activo = TRUE
             AND numero_turno IN (1,2)
             ORDER BY numero_turno, id"
        );
        $stmtCfg->execute();
        foreach ($stmtCfg->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $num = (int)$r['numero_turno'];
            $h = (float)$r['horas_laborales'];
            if ($h < 6.5 || $h >= 7.5) continue;
            if ($turno7PorNumero[$num] === null) $turno7PorNumero[$num] = (int)$r['id'];
        }
        if (empty($turno7PorNumero[1]) && empty($turno7PorNumero[2])) return false;

        $stmtP7 = $this->db->prepare(
            "SELECT id, codigo
             FROM puestos_trabajo
             WHERE activo = TRUE
             AND codigo IN ('D1','D2','D4','F15','F2','F5')
             ORDER BY codigo"
        );
        $stmtP7->execute();
        $puestos7h = $stmtP7->fetchAll(PDO::FETCH_ASSOC);
        if (empty($puestos7h)) return false;

        $stmtTieneTurnoDia = $this->db->prepare(
            "SELECT COUNT(*) c
             FROM turnos_asignados
             WHERE trabajador_id = ?
             AND fecha = ?
             AND estado IN ('programado','activo')"
        );

        $stmtOcupadoNumero = $this->db->prepare(
            "SELECT COUNT(*) c
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta.puesto_trabajo_id = ?
             AND ct.numero_turno = ?
             AND ta.fecha = ?
             AND ta.estado IN ('programado','activo')"
        );

        for ($d = 0; $d <= 6; $d++) {
            $ts = strtotime($semana['lunes']) + $d * 86400;
            $fecha = date('Y-m-d', $ts);
            if ((int)date('n', $ts) !== (int)$mes) continue;

            $stmtTieneTurnoDia->execute([$trabajadorId, $fecha]);
            if ((int)($stmtTieneTurnoDia->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) > 0) continue;

            foreach ($puestos7h as $p7) {
                $codigo7 = strtoupper((string)($p7['codigo'] ?? ''));
                foreach ([1,2] as $numBase) {
                    if ($codigo7 === 'D2' && $numBase === 1) continue;
                    $turno7Id = (int)($turno7PorNumero[$numBase] ?? 0);
                    if ($turno7Id <= 0) continue;

                    $stmtOcupadoNumero->execute([(int)$p7['id'], $numBase, $fecha]);
                    $ocup = (int)($stmtOcupadoNumero->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                    if ($ocup > 0) continue;

                    $valid = $this->turnosAsignados->validarAsignacion($trabajadorId, (int)$p7['id'], $turno7Id, $fecha, null);
                    if (!$valid['valido']) continue;

                    $res = $this->turnosAsignados->asignarDirecto([
                        'trabajador_id' => $trabajadorId,
                        'puesto_trabajo_id' => (int)$p7['id'],
                        'turno_id' => $turno7Id,
                        'fecha' => $fecha,
                        'observaciones' => 'Asignacion automatica [rescate final 7h]'
                    ]);
                    if (!$res['success']) continue;

                    $ctx['asignadosPorDia'][$fecha][$trabajadorId][] = $numBase;
                    return true;
                }
            }
        }

        return false;
    }

    private function intentarAgregar4hSemana($trabajadorId, $semana, $mes, &$ctx) {
        $turnoL4PorBase = [1 => [], 2 => []];
        $stmtCfg = $this->db->prepare(
            "SELECT id, numero_turno, horas_laborales
             FROM configuracion_turnos
             WHERE activo = TRUE
             AND numero_turno IN (4,5)
             ORDER BY numero_turno, id"
        );
        $stmtCfg->execute();
        foreach ($stmtCfg->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int)$r['numero_turno'];
            $h = (float)$r['horas_laborales'];
            if ($h < 3.5 || $h > 4.5) continue;
            if ($n === 4) $turnoL4PorBase[1][] = (int)$r['id'];
            if ($n === 5) $turnoL4PorBase[2][] = (int)$r['id'];
        }

        $stmtPuestosL4 = $this->db->prepare(
            "SELECT id, codigo
             FROM puestos_trabajo
             WHERE activo = TRUE
             AND codigo IN ('D1','D2','F11','F5','F15')"
        );
        $stmtPuestosL4->execute();
        $puestosL4PorBase = [1 => [], 2 => []];
        foreach ($stmtPuestosL4->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $cod = strtoupper((string)($p['codigo'] ?? ''));
            if ($cod === 'F11' || $cod === 'F5' || $cod === 'F15') $puestosL4PorBase[1][] = ['id' => (int)$p['id'], 'codigo' => $cod];
            if ($cod === 'D1' || $cod === 'D2') $puestosL4PorBase[2][] = ['id' => (int)$p['id'], 'codigo' => $cod];
        }

        $stmtTieneTurnoDia = $this->db->prepare(
            "SELECT COUNT(*) c
             FROM turnos_asignados
             WHERE trabajador_id = ?
             AND fecha = ?
             AND estado IN ('programado','activo')"
        );
        $stmtOcupadoTurnoId = $this->db->prepare(
            "SELECT COUNT(*) c
             FROM turnos_asignados
             WHERE puesto_trabajo_id = ?
             AND turno_id = ?
             AND fecha = ?
             AND estado IN ('programado','activo')"
        );

        for ($d = 0; $d <= 6; $d++) {
            $ts = strtotime($semana['lunes']) + $d * 86400;
            $fecha = date('Y-m-d', $ts);
            if ((int)date('n', $ts) !== (int)$mes) continue;

            $stmtTieneTurnoDia->execute([$trabajadorId, $fecha]);
            if ((int)($stmtTieneTurnoDia->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) > 0) continue;

            foreach ([1,2] as $base) {
                $turnosL4 = $turnoL4PorBase[$base] ?? [];
                if (empty($turnosL4)) continue;
                $numL4 = ($base === 1) ? 4 : 5;

                foreach (($puestosL4PorBase[$base] ?? []) as $pL4) {
                    foreach ($turnosL4 as $turnoL4Id) {
                        $turnoL4Id = (int)$turnoL4Id;
                        if ($turnoL4Id <= 0) continue;

                        $stmtOcupadoTurnoId->execute([(int)$pL4['id'], $turnoL4Id, $fecha]);
                        $ocup = (int)($stmtOcupadoTurnoId->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                        if ($ocup >= 2) continue;

                        $valid = $this->turnosAsignados->validarAsignacion($trabajadorId, (int)$pL4['id'], $turnoL4Id, $fecha, null);
                        if (!$valid['valido']) continue;

                        $res = $this->turnosAsignados->asignarDirecto([
                            'trabajador_id' => $trabajadorId,
                            'puesto_trabajo_id' => (int)$pL4['id'],
                            'turno_id' => $turnoL4Id,
                            'fecha' => $fecha,
                            'observaciones' => 'Asignacion automatica [rescate final 4h]'
                        ]);
                        if (!$res['success']) continue;

                        $ctx['asignadosPorDia'][$fecha][$trabajadorId][] = $numL4;
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function buscarCandidatosLibre($trabajadorId, $semana, $mes, $tsUltimoLibre, $cargaPorFecha, $maxLibresDia, $ctx = [], $libresAsignadosPorFecha = []) {
        for ($nivel = 1; $nivel <= 3; $nivel++) {
            $candidatos = [];
            for ($d = 0; $d <= 6; $d++) {
                $ts       = strtotime($semana['lunes']) + $d * 86400;
                $dow      = (int)date('N', $ts);
                $fechaDia = date('Y-m-d', $ts);

                if ((int)date('n', $ts) != (int)$mes) continue;
                if ($nivel == 1 && $tsUltimoLibre && ($ts - $tsUltimoLibre) < (6 * 86400)) continue;

                // Si el trabajador ya tiene turno/asignación ese día, no se puede usar como libre.
                if (!empty($ctx['asignadosPorDia'][$fechaDia][$trabajadorId])) continue;

                $carga = $cargaPorFecha[$fechaDia] ?? 0;
                $libresDia = $libresAsignadosPorFecha[$fechaDia] ?? 0;
                if ($nivel <= 2 && ($carga >= $maxLibresDia || $libresDia >= $maxLibresDia)) continue;

                $candidatos[] = ['fecha' => $fechaDia, 'carga' => $carga, 'libresDia' => $libresDia];
            }
            if (!empty($candidatos)) return $candidatos;
        }
        return [];
    }

    private function calcularSemanas($mes, $anio) {
        $diasMes = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $semanas = [];
        for ($dia = 1; $dia <= $diasMes; $dia++) {
            $ts       = mktime(0, 0, 0, $mes, $dia, $anio);
            $dow      = (int)date('N', $ts);
            $lunesTs  = $ts - ($dow - 1) * 86400;
            $lunesStr = date('Y-m-d', $lunesTs);
            $encontrado = false;
            foreach ($semanas as $sem) {
                if ($sem['lunes'] === $lunesStr) { $encontrado = true; break; }
            }
            if (!$encontrado) {
                $semanas[] = ['lunes' => $lunesStr, 'domingo' => date('Y-m-d', $lunesTs + 6 * 86400)];
            }
        }
        return $semanas;
    }

    private function obtenerPuestos() {
        $stmt = $this->db->prepare(
            "SELECT id, codigo, nombre, area FROM puestos_trabajo WHERE activo = TRUE ORDER BY area, codigo"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function construirMetricasCoberturaTrabajadores($todosActivos, $restricciones, $incapacidades, $diasEspeciales, $asignadosPorDia, $puestosFlags, $mes, $anio) {
        $diasMes = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $puestosNocturnos = ['V1', 'V2', 'C', 'D3', 'F6', 'F11'];
        $metricas = [];

        $restriccionesPorTrabajador = [];
        foreach ($restricciones as $r) {
            $restriccionesPorTrabajador[(int)($r['trabajador_id'] ?? 0)][] = $r;
        }

        $incapacidadesPorTrabajador = [];
        foreach ($incapacidades as $i) {
            $incapacidadesPorTrabajador[(int)($i['trabajador_id'] ?? 0)][] = $i;
        }

        $especialesPorTrabajador = [];
        foreach ($diasEspeciales as $d) {
            $especialesPorTrabajador[(int)($d['trabajador_id'] ?? 0)][] = $d;
        }

        $puestosPorTurno = [1 => [], 2 => [], 3 => []];
        foreach ($puestosFlags as $puestoId => $puesto) {
            $codigo = strtoupper((string)($puesto['codigo'] ?? ''));
            if ($codigo === 'C2') continue;

            if ($codigo !== 'D2') {
                $puestosPorTurno[1][$puestoId] = true;
            }
            $puestosPorTurno[2][$puestoId] = true;
            if (in_array($codigo, $puestosNocturnos, true)) {
                $puestosPorTurno[3][$puestoId] = true;
            }
        }

        foreach ($todosActivos as $trab) {
            $trabajadorId = (int)($trab['id'] ?? 0);
            $bloqueaNoche = false;
            $bloqueaFuerza = false;
            $bloqueaMovilidad = false;
            $puestosBloqueados = [];

            foreach (($restriccionesPorTrabajador[$trabajadorId] ?? []) as $r) {
                $tipo = (string)($r['tipo_restriccion'] ?? '');
                if ($tipo === 'no_turno_noche') $bloqueaNoche = true;
                if ($tipo === 'no_fuerza_fisica') $bloqueaFuerza = true;
                if ($tipo === 'movilidad_limitada') $bloqueaMovilidad = true;
                if ($tipo === 'puesto_especifico' && !empty($r['puesto_trabajo_id'])) {
                    $puestosBloqueados[(int)$r['puesto_trabajo_id']] = true;
                }
            }

            $puestosElegibles = [1 => 0, 2 => 0, 3 => 0];
            foreach ([1, 2, 3] as $numeroTurno) {
                foreach (array_keys($puestosPorTurno[$numeroTurno]) as $puestoId) {
                    $puesto = $puestosFlags[$puestoId] ?? null;
                    if (!$puesto) continue;
                    if (isset($puestosBloqueados[$puestoId])) continue;
                    if ($bloqueaFuerza && !empty($puesto['requiere_fuerza_fisica'])) continue;
                    if ($bloqueaMovilidad && in_array(strtoupper((string)($puesto['codigo'] ?? '')), ['V1', 'V2'], true)) continue;
                    if ($numeroTurno === 3 && $bloqueaNoche) continue;
                    $puestosElegibles[$numeroTurno]++;
                }
            }

            $diasDisponibles = [1 => 0, 2 => 0, 3 => 0];
            for ($dia = 1; $dia <= $diasMes; $dia++) {
                $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
                $bloqueado = [1 => false, 2 => false, 3 => false];

                if (!empty($asignadosPorDia[$fecha][$trabajadorId])) {
                    $bloqueado = [1 => true, 2 => true, 3 => true];
                }

                foreach (($incapacidadesPorTrabajador[$trabajadorId] ?? []) as $inc) {
                    if ($fecha >= (string)$inc['fecha_inicio'] && $fecha <= (string)$inc['fecha_fin']) {
                        $bloqueado = [1 => true, 2 => true, 3 => true];
                        break;
                    }
                }

                foreach (($especialesPorTrabajador[$trabajadorId] ?? []) as $esp) {
                    $fin = (string)($esp['fecha_fin'] ?? $esp['fecha_inicio']);
                    if ($fecha < (string)$esp['fecha_inicio'] || $fecha > $fin) continue;

                    $tipo = (string)($esp['tipo'] ?? '');
                    if (in_array($tipo, ['LC', 'L', 'L8', 'VAC', 'SUS', 'CAP', 'ADM'], true)) {
                        $bloqueado = [1 => true, 2 => true, 3 => true];
                        break;
                    }
                    if ($tipo === 'ADMM') {
                        $bloqueado[2] = true;
                        $bloqueado[3] = true;
                    }
                    if ($tipo === 'ADMT') {
                        $bloqueado[1] = true;
                        $bloqueado[3] = true;
                    }
                }

                if ($bloqueaNoche) {
                    $bloqueado[3] = true;
                }

                $fechaAnterior = date('Y-m-d', strtotime($fecha . ' -1 day'));
                $fechaSiguiente = date('Y-m-d', strtotime($fecha . ' +1 day'));
                if (!$bloqueado[1] && !empty($asignadosPorDia[$fechaAnterior][$trabajadorId]) && in_array(3, $asignadosPorDia[$fechaAnterior][$trabajadorId], true)) {
                    $bloqueado[1] = true;
                }
                if (!$bloqueado[3] && !empty($asignadosPorDia[$fechaSiguiente][$trabajadorId]) && in_array(1, $asignadosPorDia[$fechaSiguiente][$trabajadorId], true)) {
                    $bloqueado[3] = true;
                }

                foreach ([1, 2, 3] as $numeroTurno) {
                    if (!$bloqueado[$numeroTurno] && $puestosElegibles[$numeroTurno] > 0) {
                        $diasDisponibles[$numeroTurno]++;
                    }
                }
            }

            $opcionesPorTurno = [
                1 => $puestosElegibles[1] * $diasDisponibles[1],
                2 => $puestosElegibles[2] * $diasDisponibles[2],
                3 => $puestosElegibles[3] * $diasDisponibles[3],
            ];
            $opcionesReales = (int)array_sum($opcionesPorTurno);

            $metricas[$trabajadorId] = [
                'puestos_elegibles' => $puestosElegibles,
                'dias_disponibles'  => $diasDisponibles,
                'opciones_por_turno'=> $opcionesPorTurno,
                'opciones_reales'   => $opcionesReales,
                'bloquea_noche'     => $bloqueaNoche,
            ];
        }

        return $metricas;
    }

    private function prefetchDiasEspeciales($mes, $anio) {
        $fechaInicio     = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin        = date('Y-m-t', strtotime($fechaInicio));
        $fechaInicioPrev = date('Y-m-d', strtotime($fechaInicio . ' -14 days'));

        $stmt = $this->db->prepare(
            "SELECT trabajador_id, fecha_inicio FROM dias_especiales
               WHERE tipo IN ('L','L8','LC','VAC','SUS','CAP')
             AND fecha_inicio BETWEEN ? AND ?
             AND estado IN ('programado','activo')"
        );
        $stmt->execute([$fechaInicioPrev, $fechaFin]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $libresPorTrabajador = [];
        $cargaPorFecha       = [];

        foreach ($result as $row) {
            $libresPorTrabajador[$row['trabajador_id']][] = ['fecha_inicio' => $row['fecha_inicio'], 'fecha_fin' => null];
            if ($row['fecha_inicio'] >= $fechaInicio) {
                $cargaPorFecha[$row['fecha_inicio']] = ($cargaPorFecha[$row['fecha_inicio']] ?? 0) + 1;
            }
        }

        foreach ($libresPorTrabajador as &$fechas) {
            usort($fechas, function($a, $b) { return strcmp($a['fecha_inicio'], $b['fecha_inicio']); });
        }
        unset($fechas);

        return ['libresPorTrabajador' => $libresPorTrabajador, 'cargaPorFecha' => $cargaPorFecha];
    }

    private function tieneLibreEnRango($trabajador_id, $inicio, $fin, $libresPorTrabajador) {
        if (empty($libresPorTrabajador[$trabajador_id])) return false;
        foreach ($libresPorTrabajador[$trabajador_id] as $libre) {
            $f = $libre['fecha_inicio'];
            if ($f > $fin) break;
            if ($f >= $inicio) return true;
        }
        return false;
    }

    private function obtenerUltimoLibreAntes($trabajador_id, $fecha, $libresPorTrabajador) {
        if (empty($libresPorTrabajador[$trabajador_id])) return null;
        $ultimo = null;
        foreach ($libresPorTrabajador[$trabajador_id] as $libre) {
            $f = $libre['fecha_inicio'];
            if ($f >= $fecha) break;
            $ultimo = $f;
        }
        return $ultimo ? strtotime($ultimo) : null;
    }

    private function prefetchTurnosAsignados($mes, $anio) {
        $fechaInicio     = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin        = date('Y-m-t', strtotime($fechaInicio));

        $puestoCol    = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
        $selectPuesto = $puestoCol ? "ta.$puestoCol as puesto_trabajo_id" : "NULL as puesto_trabajo_id";

        $stmt = $this->db->prepare(
            "SELECT ta.trabajador_id, " . $selectPuesto . ", ta.fecha, ta.turno_id, ct.numero_turno, ct.horas_laborales
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta.fecha BETWEEN ? AND ?
             AND ta.estado IN ('programado','activo')"
        );
        // Importante: no arrastrar semana del mes anterior para no vaciar la primera semana del mes actual.
        $stmt->execute([$fechaInicio, $fechaFin]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $turnosPorTrabajadorSemana = [];
        $turnosPorPuestoFecha      = [];
        $perfilSemanal             = [];

        foreach ($result as $row) {
            $semanaKey = $this->getSemanaKey($row['fecha']);
            $turnosPorTrabajadorSemana[$row['trabajador_id']][$semanaKey][] = $row['numero_turno'];
            $this->actualizarPerfilSemanal($perfilSemanal, $row['trabajador_id'], $semanaKey, (float)$row['horas_laborales']);
            if ($row['puesto_trabajo_id'] !== null) {
                $numTurno = (int)$row['numero_turno'];
                $horas = (float)$row['horas_laborales'];
                if (in_array($numTurno, [4,5], true) && $horas >= 3.5 && $horas <= 4.5) {
                    $turnosPorPuestoFecha[$row['puesto_trabajo_id'].'|L4ID|'.$row['turno_id'].'|'.$row['fecha']] = true;
                    $baseTurno = ($numTurno === 4) ? 1 : 2;
                    $claveL4Base = $row['puesto_trabajo_id'].'|L4|'.$baseTurno.'|'.$row['fecha'];
                    $turnosPorPuestoFecha[$claveL4Base] = ($turnosPorPuestoFecha[$claveL4Base] ?? 0) + 1;
                } else {
                    $turnosPorPuestoFecha[$row['puesto_trabajo_id'].'|'.$numTurno.'|'.$row['fecha']] = true;
                }
            }
        }

        return [
            'turnosPorTrabajadorSemana' => $turnosPorTrabajadorSemana,
            'turnosPorPuestoFecha'      => $turnosPorPuestoFecha,
            'perfilSemanal'             => $perfilSemanal,
        ];
    }

    private function clasificarBloqueHoras($horas) {
        if ($horas >= 7.5) return 'h8';
        if ($horas >= 6.5) return 'h7';
        if ($horas >= 3.5) return 'h4';
        return 'otro';
    }

    private function actualizarPerfilSemanal(&$perfilSemanal, $trabajadorId, $semanaKey, $horas) {
        if (!isset($perfilSemanal[$trabajadorId][$semanaKey])) {
            $perfilSemanal[$trabajadorId][$semanaKey] = [
                'total_horas' => 0.0,
                'h8' => 0,
                'h7' => 0,
                'h4' => 0,
                'otro' => 0,
            ];
        }

        $perfilSemanal[$trabajadorId][$semanaKey]['total_horas'] += (float)$horas;
        $bloque = $this->clasificarBloqueHoras((float)$horas);
        $perfilSemanal[$trabajadorId][$semanaKey][$bloque]++;
    }

    private function tieneOpcionTurnoBloque($opcionesTurno, $bloqueObjetivo) {
        foreach ($opcionesTurno as $op) {
            if ($this->clasificarBloqueHoras((float)($op['horas'] ?? 0)) === $bloqueObjetivo) return true;
        }
        return false;
    }

    private function elegirOpcionTurnoParaPerfil($opcionesTurno, $perfil, $perfilObjetivo, $bloqueObjetivoPuesto = null) {
        if (empty($opcionesTurno)) return null;

        $filtradas = $opcionesTurno;
        $ops8 = array_values(array_filter($filtradas, function($op) { return (float)($op['horas'] ?? 0) >= 7.5; }));
        $ops7 = array_values(array_filter($filtradas, function($op) { $h = (float)($op['horas'] ?? 0); return $h >= 6.5 && $h < 7.5; }));
        $ops4 = array_values(array_filter($filtradas, function($op) { $h = (float)($op['horas'] ?? 0); return $h >= 3.5 && $h <= 4.5; }));

        $ordenarPorId = function(&$lista) {
            usort($lista, function($a, $b) { return $a['id'] <=> $b['id']; });
        };

        if ($bloqueObjetivoPuesto === 'h8') {
            if ((int)($perfil['h8'] ?? 0) >= (int)$perfilObjetivo['max_8h']) return null;
            if (empty($ops8)) return null;
            $ordenarPorId($ops8);
            return $ops8[0];
        }

        if ($bloqueObjetivoPuesto === 'h7') {
            if ((int)($perfil['h7'] ?? 0) >= (int)$perfilObjetivo['max_7h']) return null;
            if (empty($ops7)) return null;
            $ordenarPorId($ops7);
            return $ops7[0];
        }

        $h8 = (int)($perfil['h8'] ?? 0);
        $h7 = (int)($perfil['h7'] ?? 0);
        $h4 = (int)($perfil['h4'] ?? 0);

        if ($h8 < (int)$perfilObjetivo['max_8h']) {
            if (!empty($ops8)) {
                $ordenarPorId($ops8);
                return $ops8[0];
            }
        }

        if ($h7 < (int)$perfilObjetivo['max_7h']) {
            if (!empty($ops7)) {
                $ordenarPorId($ops7);
                return $ops7[0];
            }
        }

        if ($h4 < (int)$perfilObjetivo['max_4h']) {
            if (!empty($ops4)) {
                $ordenarPorId($ops4);
                return $ops4[0];
            }
        }

        // Perfil semanal completo: no asignar bloques adicionales para no romper 42h.
        return null;
    }

    private function contarDisponiblesPorPuestoTurno($puestoId, $turnoIdBase, $turnoNumero, $fecha, &$ctx, &$conteoTurnos) {
        $resultadoBusqueda = $this->getDisponiblesConFallback($puestoId, $turnoIdBase, $turnoNumero, $fecha, $ctx, $conteoTurnos);
        $disponibles = $this->filtrarDisponiblesPorRestriccionPuestoEspecifico(
            $resultadoBusqueda['lista'],
            $puestoId,
            $fecha,
            $ctx
        );
        $disponibles = $this->filtrarDisponiblesPorRestriccionesObligatorias(
            $disponibles,
            $puestoId,
            $turnoIdBase,
            $fecha
        );
        return count($disponibles);
    }

    private function ordenarPuestosPorEscasezYPrioridad($puestos, $turnoNumero, $turnoIdBase, $fecha, &$ctx, &$conteoTurnos) {
        $puestosOrdenados = array_values($puestos);
        usort($puestosOrdenados, function($a, $b) use ($turnoNumero, $turnoIdBase, $fecha, &$ctx, &$conteoTurnos) {
            $codigoA = strtoupper((string)($a['codigo'] ?? ''));
            $codigoB = strtoupper((string)($b['codigo'] ?? ''));
            $priorA = $this->getPrioridadCoberturaPuesto($codigoA, $turnoNumero);
            $priorB = $this->getPrioridadCoberturaPuesto($codigoB, $turnoNumero);
            if ($priorA !== $priorB) {
                return $priorA <=> $priorB;
            }

            $escA = $this->contarDisponiblesPorPuestoTurno((int)($a['id'] ?? 0), $turnoIdBase, $turnoNumero, $fecha, $ctx, $conteoTurnos);
            $escB = $this->contarDisponiblesPorPuestoTurno((int)($b['id'] ?? 0), $turnoIdBase, $turnoNumero, $fecha, $ctx, $conteoTurnos);
            if ($escA !== $escB) {
                return $escA <=> $escB;
            }

            return strcmp($codigoA, $codigoB);
        });

        return $puestosOrdenados;
    }

    private function seleccionarMejorCandidatoSemanal($disponibles, $fecha, $turnoNumero, $puestoId, $codigoPuesto, $bloqueObjetivoPuesto, $turnoOpcionesPorNumero, $perfilSemanal, $perfilObjetivo, $maxHorasSemanalOperativo, $conteoTurnos, &$ctx) {
        if (empty($disponibles)) return null;

        $semanaKey = $this->getSemanaKey($fecha);
        $elegibles = [];

        foreach ($disponibles as $trab) {
            $id = $trab['id'];
            $perfil = $perfilSemanal[$id][$semanaKey] ?? ['total_horas' => 0.0, 'h8' => 0, 'h7' => 0, 'h4' => 0, 'otro' => 0];

            $opcionTurno = $this->elegirOpcionTurnoParaPerfil(
                $turnoOpcionesPorNumero[$turnoNumero] ?? [],
                $perfil,
                $perfilObjetivo,
                $bloqueObjetivoPuesto
            );
            if (!$opcionTurno) continue;

            $turnoHoras = (float)($opcionTurno['horas'] ?? 0);
            $bloque = $this->clasificarBloqueHoras($turnoHoras);

            if ($bloque === 'h8' && (int)$perfil['h8'] >= (int)$perfilObjetivo['max_8h']) continue;
            if ($bloque === 'h7' && (int)$perfil['h7'] >= (int)$perfilObjetivo['max_7h']) continue;
            if ($bloque === 'h4' && (int)$perfil['h4'] >= (int)$perfilObjetivo['max_4h']) continue;

            $totalActual = (float)$perfil['total_horas'];
            $totalProy   = $totalActual + (float)$turnoHoras;
            $conteoBase  = (int)($conteoTurnos[$id] ?? 0);

            $penalidad = 0;
            if ($bloque === 'h8') {
                if ((int)$perfil['h8'] >= (int)$perfilObjetivo['max_8h']) $penalidad += 20;
                else $penalidad -= 2;
            } elseif ($bloque === 'h7') {
                if ((int)$perfil['h7'] >= (int)$perfilObjetivo['max_7h']) $penalidad += 15;
                else $penalidad -= 2;
            } elseif ($bloque === 'h4') {
                if ((int)$perfil['h4'] >= (int)$perfilObjetivo['max_4h']) $penalidad += 10;
                else $penalidad -= 2;
            }

            $item = [
                'trabajador' => $trab,
                'turno_id'   => (int)$opcionTurno['id'],
                'turno_horas'=> $turnoHoras,
                'penalidad'  => $penalidad,
                'total'      => $totalActual,
                'proyectado' => $totalProy,
                'conteo'     => $conteoBase,
                'score'      => $this->calcularScoreCandidatoCobertura($id, $turnoNumero, $codigoPuesto, $turnoHoras, $perfil, $conteoBase, $ctx, $penalidad),
            ];

            if ($totalProy <= $maxHorasSemanalOperativo + 0.001) {
                $elegibles[] = $item;
            }
        }

        $ordenar = function(&$lista) {
            usort($lista, function($a, $b) use ($turnoNumero, &$ctx) {
                if ($a['score'] !== $b['score']) return $a['score'] <=> $b['score'];
                if ($a['penalidad'] !== $b['penalidad']) return $a['penalidad'] <=> $b['penalidad'];
                if ($a['total'] !== $b['total']) return $a['total'] <=> $b['total'];
                if ($a['conteo'] !== $b['conteo']) return $a['conteo'] <=> $b['conteo'];
                $escA = $this->getEscasezRelativaCandidato((int)($a['trabajador']['id'] ?? 0), $turnoNumero, $ctx);
                $escB = $this->getEscasezRelativaCandidato((int)($b['trabajador']['id'] ?? 0), $turnoNumero, $ctx);
                if ($escA !== $escB) return $escA <=> $escB;
                return $a['proyectado'] <=> $b['proyectado'];
            });
        };

        if (!empty($elegibles)) {
            $ordenar($elegibles);
            foreach ($elegibles as $elegible) {
                $trabajadorId = (int)($elegible['trabajador']['id'] ?? 0);
                $turnoId = (int)($elegible['turno_id'] ?? 0);
                if (!$this->validarCandidatoAutomatico($trabajadorId, $puestoId, $turnoId, $fecha, null)) {
                    continue;
                }

                return [
                    'trabajador' => $elegible['trabajador'],
                    'turno_id'   => $turnoId,
                    'turno_horas'=> $elegible['turno_horas'],
                ];
            }
        }

        return null;
    }

    private function seleccionarCandidatoCobertura($disponibles, $fecha, $turnoNumero, $puestoId, $bloqueObjetivoPuesto, $turnoOpcionesPorNumero, $perfilSemanal, $perfilObjetivo, $maxHorasSemanalCobertura, $conteoTurnos, &$ctx, $codigoPuesto = '') {
        if (empty($disponibles)) return null;

        $semanaKey = $this->getSemanaKey($fecha);
        $candidatos = [];

        foreach ($disponibles as $trab) {
            $id = $trab['id'];
            $perfil = $perfilSemanal[$id][$semanaKey] ?? ['total_horas' => 0.0, 'h8' => 0, 'h7' => 0, 'h4' => 0, 'otro' => 0];
            $totalActual = (float)$perfil['total_horas'];

            $opciones = $turnoOpcionesPorNumero[$turnoNumero] ?? [];
            if ($bloqueObjetivoPuesto !== null) {
                $opciones = array_values(array_filter($opciones, function($op) use ($bloqueObjetivoPuesto) {
                    return $this->clasificarBloqueHoras((float)($op['horas'] ?? 0)) === $bloqueObjetivoPuesto;
                }));
            }
            $opciones = array_values(array_filter($opciones, function($op) use ($perfil, $perfilObjetivo) {
                $bloque = $this->clasificarBloqueHoras((float)($op['horas'] ?? 0));
                if ($bloque === 'h8') return (int)($perfil['h8'] ?? 0) < (int)($perfilObjetivo['max_8h'] ?? 0);
                if ($bloque === 'h7') return (int)($perfil['h7'] ?? 0) < (int)($perfilObjetivo['max_7h'] ?? 0);
                if ($bloque === 'h4') return (int)($perfil['h4'] ?? 0) < (int)($perfilObjetivo['max_4h'] ?? 0);
                return false;
            }));
            if (empty($opciones)) continue;

            usort($opciones, function($a, $b) {
                if ($a['horas'] == $b['horas']) return $a['id'] <=> $b['id'];
                return $b['horas'] <=> $a['horas'];
            });

            $opcionElegida = null;
            foreach ($opciones as $op) {
                $horas = (float)($op['horas'] ?? 0);
                if (($totalActual + $horas) <= ($maxHorasSemanalCobertura + 0.001)) {
                    $opcionElegida = $op;
                    break;
                }
            }
            if (!$opcionElegida) continue;

            $candidatos[] = [
                'trabajador' => $trab,
                'turno_id'   => (int)$opcionElegida['id'],
                'turno_horas'=> (float)$opcionElegida['horas'],
                'conteo'     => (int)($conteoTurnos[$id] ?? 0),
                'total'      => $totalActual,
                'score'      => $this->calcularScoreCandidatoCobertura($id, $turnoNumero, $codigoPuesto, (float)$opcionElegida['horas'], $perfil, (int)($conteoTurnos[$id] ?? 0), $ctx),
            ];
        }

        if (empty($candidatos)) return null;

        usort($candidatos, function($a, $b) use ($turnoNumero, &$ctx) {
            if ($a['score'] !== $b['score']) return $a['score'] <=> $b['score'];
            if ($a['conteo'] !== $b['conteo']) return $a['conteo'] <=> $b['conteo'];
            if ($a['total'] !== $b['total']) return $a['total'] <=> $b['total'];
            $escA = $this->getEscasezRelativaCandidato((int)($a['trabajador']['id'] ?? 0), $turnoNumero, $ctx);
            $escB = $this->getEscasezRelativaCandidato((int)($b['trabajador']['id'] ?? 0), $turnoNumero, $ctx);
            if ($escA !== $escB) return $escA <=> $escB;
            return $a['turno_horas'] <=> $b['turno_horas'];
        });

        foreach ($candidatos as $candidato) {
            $trabajadorId = (int)($candidato['trabajador']['id'] ?? 0);
            $turnoId = (int)($candidato['turno_id'] ?? 0);
            if (!$this->validarCandidatoAutomatico($trabajadorId, $puestoId, $turnoId, $fecha, null)) {
                continue;
            }

            return [
                'trabajador' => $candidato['trabajador'],
                'turno_id'   => $turnoId,
                'turno_horas'=> $candidato['turno_horas'],
            ];
        }

        return null;
    }

    private function seleccionarCandidatoCoberturaTotal($disponibles, $fecha, $turnoNumero, $puestoId, $bloqueObjetivoPuesto, $turnoOpcionesPorNumero, $perfilSemanal, $maxHorasSemanalTotal, $conteoTurnos, &$ctx, $codigoPuesto = '') {
        if (empty($disponibles)) return null;

        $semanaKey = $this->getSemanaKey($fecha);
        $opciones = $turnoOpcionesPorNumero[$turnoNumero] ?? [];
        if ($bloqueObjetivoPuesto !== null) {
            $opciones = array_values(array_filter($opciones, function($op) use ($bloqueObjetivoPuesto) {
                return $this->clasificarBloqueHoras((float)($op['horas'] ?? 0)) === $bloqueObjetivoPuesto;
            }));
        }
        if (empty($opciones)) return null;

        usort($opciones, function($a, $b) {
            if ($a['horas'] == $b['horas']) return $a['id'] <=> $b['id'];
            return $b['horas'] <=> $a['horas'];
        });

        $candidatos = [];
        foreach ($disponibles as $trab) {
            $id = (int)($trab['id'] ?? 0);
            if ($id <= 0) continue;

            $perfil = $perfilSemanal[$id][$semanaKey] ?? ['total_horas' => 0.0];
            $totalActual = (float)($perfil['total_horas'] ?? 0.0);

            $opcionElegida = null;
            foreach ($opciones as $op) {
                $horas = (float)($op['horas'] ?? 0);
                if (($totalActual + $horas) <= ($maxHorasSemanalTotal + 0.001)) {
                    $opcionElegida = $op;
                    break;
                }
            }
            if (!$opcionElegida) {
                $opcionElegida = $opciones[count($opciones) - 1];
            }

            $candidatos[] = [
                'trabajador' => $trab,
                'turno_id'   => (int)$opcionElegida['id'],
                'turno_horas'=> (float)$opcionElegida['horas'],
                'conteo'     => (int)($conteoTurnos[$id] ?? 0),
                'total'      => $totalActual,
                'score'      => $this->calcularScoreCandidatoCobertura($id, $turnoNumero, $codigoPuesto, (float)$opcionElegida['horas'], $perfil, (int)($conteoTurnos[$id] ?? 0), $ctx),
            ];
        }

        if (empty($candidatos)) return null;

        usort($candidatos, function($a, $b) use ($turnoNumero, &$ctx) {
            if ($a['score'] !== $b['score']) return $a['score'] <=> $b['score'];
            if ($a['conteo'] !== $b['conteo']) return $a['conteo'] <=> $b['conteo'];
            if ($a['total'] !== $b['total']) return $a['total'] <=> $b['total'];
            $escA = $this->getEscasezRelativaCandidato((int)($a['trabajador']['id'] ?? 0), $turnoNumero, $ctx);
            $escB = $this->getEscasezRelativaCandidato((int)($b['trabajador']['id'] ?? 0), $turnoNumero, $ctx);
            if ($escA !== $escB) return $escA <=> $escB;
            return $a['turno_horas'] <=> $b['turno_horas'];
        });

        foreach ($candidatos as $candidato) {
            $trabajadorId = (int)($candidato['trabajador']['id'] ?? 0);
            $turnoId = (int)($candidato['turno_id'] ?? 0);
            if (!$this->validarCandidatoAutomatico($trabajadorId, $puestoId, $turnoId, $fecha, null)) {
                continue;
            }

            return [
                'trabajador' => $candidato['trabajador'],
                'turno_id'   => $turnoId,
                'turno_horas'=> $candidato['turno_horas'],
            ];
        }

        return null;
    }

    private function getSemanaKey($fecha) {
        $ts      = strtotime($fecha);
        $dow     = (int)date('N', $ts);
        $lunesTs = $ts - ($dow - 1) * 86400;
        return date('Y-m-d', $lunesTs);
    }

    private function tieneTurnoL4EnSemana($trabajador_id, $lunes, $domingo, $turnosPorTrabajadorSemana) {
        if (!isset($turnosPorTrabajadorSemana[$trabajador_id][$lunes])) return false;
        $t = $turnosPorTrabajadorSemana[$trabajador_id][$lunes];
        foreach ($t as $num) {
            if (in_array((int)$num, [4,5], true)) return true;
        }
        return false;
    }

    private function tieneTurnoL4EnFecha($puesto_id, $fecha, $turnosPorPuestoFecha) {
        return isset($turnosPorPuestoFecha[$puesto_id.'|4|'.$fecha])
            || isset($turnosPorPuestoFecha[$puesto_id.'|5|'.$fecha]);
    }

    private function tieneTurnoL4EnFechaYBase($puesto_id, $fecha, $baseTurno, $turnosPorPuestoFecha) {
        return $this->cantidadTurnosL4EnFechaYBase($puesto_id, $fecha, $baseTurno, $turnosPorPuestoFecha) > 0;
    }

    private function cantidadTurnosL4EnFechaYBase($puesto_id, $fecha, $baseTurno, $turnosPorPuestoFecha) {
        return (int)($turnosPorPuestoFecha[$puesto_id.'|L4|'.$baseTurno.'|'.$fecha] ?? 0);
    }

    private function tieneTurnoL4EnFechaYTurnoId($puesto_id, $fecha, $turnoId, $turnosPorPuestoFecha) {
        return isset($turnosPorPuestoFecha[$puesto_id.'|L4ID|'.$turnoId.'|'.$fecha]);
    }

    private function estaPuestoOcupado($puesto_id, $numero_turno, $fecha, $turnosPorPuestoFecha) {
        return isset($turnosPorPuestoFecha[$puesto_id.'|'.$numero_turno.'|'.$fecha]);
    }

    private function obtenerPatronLibresMesAnterior($mes, $anio, $trabajadores) {
        $fechaInicio  = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin     = date('Y-m-t', strtotime($fechaInicio));
        $ultimoDia    = strtotime($fechaFin);
        $dowUltimo    = (int)date('N', $ultimoDia);
        $ultimoLunes  = $ultimoDia - ($dowUltimo - 1) * 86400;
        $inicioSemana = date('Y-m-d', $ultimoLunes);
        $finSemana    = date('Y-m-d', $ultimoLunes + 6 * 86400);

        $stmt = $this->db->prepare(
            "SELECT trabajador_id, fecha_inicio FROM dias_especiales
             WHERE tipo IN ('L','L8','LC','VAC','SUS')
             AND fecha_inicio BETWEEN ? AND ?
             AND estado IN ('programado','activo')"
        );
        $stmt->execute([$inicioSemana, $finSemana]);
        $libres = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $patron = [];
        foreach ($trabajadores as $trab) {
            $diasSemana = [];
            foreach ($libres as $libre) {
                if ($libre['trabajador_id'] == $trab['id']) {
                    $diasSemana[] = (int)date('N', strtotime($libre['fecha_inicio']));
                }
            }
            if (!empty($diasSemana)) {
                $conteo = array_count_values($diasSemana);
                arsort($conteo);
                $patron[$trab['id']] = key($conteo);
            } else {
                $patron[$trab['id']] = null;
            }
        }
        return $patron;
    }

    private function ordenarDisponiblesPreliminar(&$disponibles, $numeroTurno, &$ctx, &$conteoTurnos) {
        usort($disponibles, function($a, $b) use ($numeroTurno, &$ctx, &$conteoTurnos) {
            $idA = (int)($a['id'] ?? 0);
            $idB = (int)($b['id'] ?? 0);
            $metA = $ctx['metricasCobertura'][$idA] ?? [];
            $metB = $ctx['metricasCobertura'][$idB] ?? [];
            $opcA = (int)($metA['opciones_por_turno'][$numeroTurno] ?? PHP_INT_MAX);
            $opcB = (int)($metB['opciones_por_turno'][$numeroTurno] ?? PHP_INT_MAX);

            if ($numeroTurno === 3) {
                $nA = (int)($ctx['nochesPorTrabajador'][$idA] ?? 0);
                $nB = (int)($ctx['nochesPorTrabajador'][$idB] ?? 0);
                if ($nA !== $nB) return $nA <=> $nB;
            }

            if ($opcA !== $opcB) return $opcA <=> $opcB;

            $realA = (int)($metA['opciones_reales'] ?? PHP_INT_MAX);
            $realB = (int)($metB['opciones_reales'] ?? PHP_INT_MAX);
            if ($realA !== $realB) return $realA <=> $realB;

            return (int)($conteoTurnos[$idA] ?? 0) <=> (int)($conteoTurnos[$idB] ?? 0);
        });
    }

    private function getPrioridadCoberturaPuesto($codigoPuesto, $turnoNumero) {
        $codigo = strtoupper((string)$codigoPuesto);
        $criticos = ['C', 'V1', 'V2', 'D3', 'F6'];
        $prioridadT1 = ['G', 'F14', 'F15', 'D1', 'D4', 'F2'];
        $prioridadT2 = ['G', 'F14', 'F15', 'F11', 'D1', 'D4', 'F2'];

        if (in_array($codigo, $criticos, true)) return 0;
        if ((int)$turnoNumero === 1 && in_array($codigo, $prioridadT1, true)) return 1;
        if ((int)$turnoNumero === 2 && in_array($codigo, $prioridadT2, true)) return 1;
        if ((int)$turnoNumero === 3 && $codigo === 'F11') return 1;
        return 2;
    }

    private function calcularScoreCandidatoCobertura($trabajadorId, $turnoNumero, $codigoPuesto, $turnoHoras, $perfil, $conteoBase, &$ctx, $penalidadBase = 0) {
        $metricas = $ctx['metricasCobertura'][$trabajadorId] ?? [];
        $opcionesTurno = max(1, (int)($metricas['opciones_por_turno'][$turnoNumero] ?? 1));
        $opcionesReales = max(1, (int)($metricas['opciones_reales'] ?? $opcionesTurno));
        $prioridadPuesto = $this->getPrioridadCoberturaPuesto($codigoPuesto, $turnoNumero);
        $factorCritico = $prioridadPuesto === 0 ? 1.6 : ($prioridadPuesto === 1 ? 1.25 : 1.0);

        $pesoEscasezTurno = $turnoNumero === 1 ? 180.0 : ($turnoNumero === 2 ? 110.0 : 45.0);
        $bonusEscasez = ($pesoEscasezTurno / $opcionesTurno) * $factorCritico;
        $bonusGlobal = (60.0 / $opcionesReales) * $factorCritico;
        $bonusScarcityExtra = ($opcionesTurno <= 2 ? 18.0 : 0.0) + ($opcionesReales <= 3 ? 12.0 : 0.0);

        $score = 0.0;
        $score += (float)$penalidadBase * 6.0;
        $score += (float)($perfil['total_horas'] ?? 0.0) * 1.75;
        $score += (int)$conteoBase * 14.0;
        $score += (float)$turnoHoras * 0.5;

        if ((int)$turnoNumero === 3) {
            $score += (float)((int)($ctx['nochesPorTrabajador'][$trabajadorId] ?? 0) * 7.0);
        }

        $score -= $bonusEscasez;
        $score -= $bonusGlobal;
        $score -= $bonusScarcityExtra;
        if ($prioridadPuesto === 0) $score -= 10.0;
        if ($prioridadPuesto === 1) $score -= 4.0;

        return $score;
    }

    private function getEscasezRelativaCandidato($trabajadorId, $turnoNumero, &$ctx) {
        $metricas = $ctx['metricasCobertura'][$trabajadorId] ?? [];
        $opcionesTurno = max(1, (int)($metricas['opciones_por_turno'][$turnoNumero] ?? 1));
        $opcionesReales = max(1, (int)($metricas['opciones_reales'] ?? $opcionesTurno));
        return ($opcionesTurno * 1000) + $opcionesReales;
    }

    private function optimizarCoberturaFinal($mes, $anio, $puestos, $puestosNocturnos, $puestosL4Turno, $turnoIdPorNumero, $turnoOpcionesPorNumero, $perfilObjetivo, &$ctx, &$conteoTurnos, &$perfilSemanal, &$turnosPorPuestoFecha, &$asignaciones) {
        $reasignaciones = 0;
        $intercambios = 0;
        $coberturaExtra = 0;
        $warnings = [];

        $huecosIniciales = $this->construirHuecosPendientesMes(
            $mes,
            $anio,
            $puestos,
            $puestosNocturnos,
            $puestosL4Turno,
            $turnoIdPorNumero,
            $turnoOpcionesPorNumero,
            $turnosPorPuestoFecha
        );

        $cantidadInicial = count($huecosIniciales);
        $inicioOptimizacion = microtime(true);
        $maxSegundos = 20.0;

        if ($cantidadInicial > 30) {
            $warnings[] = 'Optimización final omitida por exceso de huecos pendientes (' . $cantidadInicial . '), para evitar demoras excesivas.';
            return [
                'iteraciones' => 0,
                'reasignaciones' => 0,
                'intercambios' => 0,
                'huecos_iniciales' => $cantidadInicial,
                'huecos_finales' => $cantidadInicial,
                'cobertura_extra' => 0,
                'cobertura_total_extra' => 0,
                'huecos_imposibles' => [],
                'resumen' => 'Optimización final omitida por exceso de huecos pendientes.',
                'warnings' => $warnings,
            ];
        }

        $maxIteraciones = 1;
        if ($cantidadInicial <= 4) {
            $maxIteraciones = 3;
        } elseif ($cantidadInicial <= 12) {
            $maxIteraciones = 2;
        }
        $iteracion = 0;

        while ($iteracion < $maxIteraciones) {
            $this->verificarCancelacion('optimización final');
            if ((microtime(true) - $inicioOptimizacion) > $maxSegundos) {
                $warnings[] = 'Optimización final detenida por límite de tiempo de ' . $maxSegundos . 's.';
                break;
            }

            $iteracion++;

            $huecosPendientes = $this->construirHuecosPendientesMes(
                $mes,
                $anio,
                $puestos,
                $puestosNocturnos,
                $puestosL4Turno,
                $turnoIdPorNumero,
                $turnoOpcionesPorNumero,
                $turnosPorPuestoFecha
            );

            if (empty($huecosPendientes)) {
                break;
            }

            usort($huecosPendientes, function($a, $b) {
                if ((int)$a['prioridad'] !== (int)$b['prioridad']) {
                    return (int)$a['prioridad'] <=> (int)$b['prioridad'];
                }
                if ((int)$a['escasez_estim'] !== (int)$b['escasez_estim']) {
                    return (int)$a['escasez_estim'] <=> (int)$b['escasez_estim'];
                }
                if ((string)$a['fecha'] !== (string)$b['fecha']) {
                    return strcmp((string)$a['fecha'], (string)$b['fecha']);
                }
                if ((int)$a['turno_numero'] !== (int)$b['turno_numero']) {
                    return (int)$a['turno_numero'] <=> (int)$b['turno_numero'];
                }
                return strcmp((string)$a['puesto_codigo'], (string)$b['puesto_codigo']);
            });

            $mejorasIteracion = 0;
            foreach ($huecosPendientes as $hueco) {
                $this->verificarCancelacion('optimización final hueco');
                $detalleMovimiento = [];
                $diagnostico = null;
                $ok = $this->intentarReasignacionLocalHueco(
                    $hueco,
                    $turnoOpcionesPorNumero,
                    $perfilObjetivo,
                    $ctx,
                    $conteoTurnos,
                    $perfilSemanal,
                    $turnosPorPuestoFecha,
                    $asignaciones,
                    $detalleMovimiento,
                    $diagnostico
                );

                if (!$ok) {
                    continue;
                }

                $mejorasIteracion++;
                if (($detalleMovimiento['tipo'] ?? '') === 'intercambio') {
                    $intercambios++;
                } else {
                    $reasignaciones++;
                }
            }

            if ($mejorasIteracion === 0) {
                break;
            }

            $coberturaExtra += $mejorasIteracion;
        }

        $huecosFinales = $this->construirHuecosPendientesMes(
            $mes,
            $anio,
            $puestos,
            $puestosNocturnos,
            $puestosL4Turno,
            $turnoIdPorNumero,
            $turnoOpcionesPorNumero,
            $turnosPorPuestoFecha
        );

        $diagnosticoHuecos = [];
        foreach ($huecosFinales as $huecoPendiente) {
            $diagnosticoHuecos[] = [
                'fecha' => (string)$huecoPendiente['fecha'],
                'puesto' => (string)$huecoPendiente['puesto_codigo'],
                'turno' => (int)$huecoPendiente['turno_numero'],
                'motivo' => $this->diagnosticarHuecoPendiente(
                    $huecoPendiente,
                    $turnoOpcionesPorNumero,
                    $perfilObjetivo,
                    $ctx,
                    $conteoTurnos,
                    $perfilSemanal
                )
            ];
        }

        if (empty($huecosFinales)) {
            $resumen = 'Optimizacion final: cobertura completa alcanzada respetando todas las restricciones.';
        } else {
            $resumen = 'Optimizacion final: quedaron ' . count($huecosFinales) . ' huecos no cubiertos tras agotar mejoras locales validas.';
            $warnings[] = 'Huecos restantes detectados como inviables con las restricciones actuales. Revisar diagnostico_huecos para detalle.';
        }

        return [
            'iteraciones' => $iteracion,
            'reasignaciones' => $reasignaciones,
            'intercambios' => $intercambios,
            'huecos_iniciales' => $cantidadInicial,
            'huecos_finales' => count($huecosFinales),
            'cobertura_extra' => $coberturaExtra,
            'cobertura_total_extra' => max(0, $cantidadInicial - count($huecosFinales)),
            'huecos_imposibles' => $diagnosticoHuecos,
            'resumen' => $resumen,
            'warnings' => $warnings,
        ];
    }

    private function intentarReasignacionLocalHueco($hueco, $turnoOpcionesPorNumero, $perfilObjetivo, &$ctx, &$conteoTurnos, &$perfilSemanal, &$turnosPorPuestoFecha, &$asignaciones, &$detalleMovimiento = [], &$diagnostico = null) {
        if (!empty($hueco['config_invalida'])) {
            $diagnostico = 'Configuracion de turnos incompatible con el bloque requerido del puesto.';
            return false;
        }

        $fecha = (string)$hueco['fecha'];
        $puestoId = (int)$hueco['puesto_id'];
        $turnoNumero = (int)$hueco['turno_numero'];
        $turnoIdBase = (int)$hueco['turno_id_base'];
        $codigoPuesto = (string)$hueco['puesto_codigo'];
        $bloqueObjetivo = $hueco['bloque_objetivo'] ?? null;

        $opcionesHueco = $this->obtenerOpcionesTurnoHueco($turnoNumero, $bloqueObjetivo, $turnoOpcionesPorNumero);
        if (empty($opcionesHueco)) {
            $diagnostico = 'No existe opcion de turno activa para el bloque requerido.';
            return false;
        }

        $resultadoBusqueda = $this->getDisponiblesConFallback($puestoId, $turnoIdBase, $turnoNumero, $fecha, $ctx, $conteoTurnos);
        $disponibles = $this->filtrarDisponiblesPorRestriccionPuestoEspecifico($resultadoBusqueda['lista'], $puestoId, $fecha, $ctx);

        foreach ($opcionesHueco as $op) {
            $turnoIdObjetivo = (int)$op['id'];
            $turnoHorasObjetivo = (float)$op['horas'];

            $candidato = $this->seleccionarCandidatoExacto(
                $disponibles,
                $fecha,
                $turnoNumero,
                $puestoId,
                $codigoPuesto,
                $turnoIdObjetivo,
                $turnoHorasObjetivo,
                $perfilSemanal,
                $perfilObjetivo,
                $conteoTurnos,
                $ctx
            );
            if (!$candidato) continue;

            $res = $this->turnosAsignados->asignarDirecto([
                'trabajador_id' => (int)$candidato['trabajador']['id'],
                'puesto_trabajo_id' => $puestoId,
                'turno_id' => $turnoIdObjetivo,
                'fecha' => $fecha,
                'observaciones' => 'Asignacion automatica [optimizacion final directa]'
            ]);

            if (!$res['success']) {
                continue;
            }

            $this->registrarAsignacionNuevaContexto(
                $fecha,
                (int)$candidato['trabajador']['id'],
                $turnoNumero,
                $turnoHorasObjetivo,
                $conteoTurnos,
                $perfilSemanal,
                $ctx
            );

            $turnosPorPuestoFecha[$puestoId . '|' . $turnoNumero . '|' . $fecha] = true;
            $asignaciones[] = [
                'fecha' => $fecha,
                'puesto' => $codigoPuesto,
                'turno' => $turnoNumero,
                'trabajador' => (string)($candidato['trabajador']['nombre'] ?? ('#' . (int)$candidato['trabajador']['id'])),
                'nivel' => 'opt-final-directa'
            ];

            $detalleMovimiento = ['tipo' => 'directa'];
            return true;
        }

        $asignacionesDia = $this->obtenerAsignacionesOperativasDia($fecha, $turnoNumero);
        foreach ($asignacionesDia as $asigDia) {
            $idAsignacionMover = (int)($asigDia['id'] ?? 0);
            $trabajadorMover = (int)($asigDia['trabajador_id'] ?? 0);
            $puestoMover = (int)($asigDia['puesto_trabajo_id'] ?? 0);
            $turnoMoverId = (int)($asigDia['turno_id'] ?? 0);
            $turnoMoverNum = (int)($asigDia['numero_turno'] ?? 0);
            $horasMover = (float)($asigDia['horas_laborales'] ?? 0);
            $codigoPuestoMover = (string)($asigDia['puesto_codigo'] ?? '');
            if ($idAsignacionMover <= 0 || $trabajadorMover <= 0 || $puestoMover <= 0 || $turnoMoverId <= 0) continue;

            $resultadoBusquedaReemplazo = $this->getDisponiblesConFallback($puestoMover, $turnoMoverId, $turnoMoverNum, $fecha, $ctx, $conteoTurnos);
            $disponiblesReemplazo = $this->filtrarDisponiblesPorRestriccionPuestoEspecifico($resultadoBusquedaReemplazo['lista'], $puestoMover, $fecha, $ctx);
            $disponiblesReemplazo = $this->filtrarDisponiblesPorRestriccionesObligatorias(
                $disponiblesReemplazo,
                $puestoMover,
                $turnoMoverId,
                $fecha
            );
            $disponiblesReemplazo = array_values(array_filter($disponiblesReemplazo, function($t) use ($trabajadorMover) {
                return (int)($t['id'] ?? 0) !== $trabajadorMover;
            }));

            if (empty($disponiblesReemplazo)) {
                continue;
            }

            $candidatoReemplazo = $this->seleccionarCandidatoExacto(
                $disponiblesReemplazo,
                $fecha,
                $turnoMoverNum,
                $puestoMover,
                $codigoPuestoMover,
                $turnoMoverId,
                $horasMover,
                $perfilSemanal,
                $perfilObjetivo,
                $conteoTurnos,
                $ctx
            );
            if (!$candidatoReemplazo) {
                continue;
            }

            foreach ($opcionesHueco as $op) {
                $turnoIdObjetivo = (int)$op['id'];
                $turnoHorasObjetivo = (float)$op['horas'];

                $validPivot = $this->turnosAsignados->validarAsignacion(
                    $trabajadorMover,
                    $puestoId,
                    $turnoIdObjetivo,
                    $fecha,
                    $idAsignacionMover
                );
                if (empty($validPivot['valido'])) {
                    continue;
                }

                $actualizacion = $this->turnosAsignados->actualizar($idAsignacionMover, [
                    'trabajador_id' => (int)$candidatoReemplazo['trabajador']['id'],
                    'observaciones' => 'Asignacion automatica [optimizacion final intercambio]'
                ]);
                if (empty($actualizacion['success'])) {
                    continue;
                }

                $this->moverAsignacionEnContexto(
                    $fecha,
                    $turnoMoverNum,
                    $horasMover,
                    $trabajadorMover,
                    (int)$candidatoReemplazo['trabajador']['id'],
                    $conteoTurnos,
                    $perfilSemanal,
                    $ctx
                );

                $resNuevo = $this->turnosAsignados->asignarDirecto([
                    'trabajador_id' => $trabajadorMover,
                    'puesto_trabajo_id' => $puestoId,
                    'turno_id' => $turnoIdObjetivo,
                    'fecha' => $fecha,
                    'observaciones' => 'Asignacion automatica [optimizacion final intercambio]'
                ]);

                if (!empty($resNuevo['success'])) {
                    $this->registrarAsignacionNuevaContexto(
                        $fecha,
                        $trabajadorMover,
                        $turnoNumero,
                        $turnoHorasObjetivo,
                        $conteoTurnos,
                        $perfilSemanal,
                        $ctx
                    );

                    $turnosPorPuestoFecha[$puestoId . '|' . $turnoNumero . '|' . $fecha] = true;
                    $asignaciones[] = [
                        'fecha' => $fecha,
                        'puesto' => $codigoPuesto,
                        'turno' => $turnoNumero,
                        'trabajador' => (string)($asigDia['trabajador_nombre'] ?? ('#' . $trabajadorMover)),
                        'nivel' => 'opt-final-intercambio'
                    ];

                    $detalleMovimiento = ['tipo' => 'intercambio'];
                    return true;
                }

                // Revertir movimiento intermedio si no se pudo completar el intercambio.
                $this->turnosAsignados->actualizar($idAsignacionMover, [
                    'trabajador_id' => $trabajadorMover,
                    'observaciones' => 'Asignacion automatica [rollback optimizacion final]'
                ]);
                $this->moverAsignacionEnContexto(
                    $fecha,
                    $turnoMoverNum,
                    $horasMover,
                    (int)$candidatoReemplazo['trabajador']['id'],
                    $trabajadorMover,
                    $conteoTurnos,
                    $perfilSemanal,
                    $ctx
                );
            }
        }

        $diagnostico = $this->diagnosticarHuecoPendiente(
            $hueco,
            $turnoOpcionesPorNumero,
            $perfilObjetivo,
            $ctx,
            $conteoTurnos,
            $perfilSemanal
        );
        return false;
    }

    private function construirHuecosPendientesMes($mes, $anio, $puestos, $puestosNocturnos, $puestosL4Turno, $turnoIdPorNumero, $turnoOpcionesPorNumero, $turnosPorPuestoFecha) {
        $diasMes = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $huecos = [];

        for ($dia = 1; $dia <= $diasMes; $dia++) {
            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

            foreach ($puestos as $puesto) {
                $codigoPuesto = strtoupper((string)($puesto['codigo'] ?? ''));
                if ($codigoPuesto === 'C2') continue;

                foreach ([1, 2, 3] as $turnoNumero) {
                    if ($codigoPuesto === 'D2' && $turnoNumero === 1) continue;
                    if ($turnoNumero === 3 && !in_array($codigoPuesto, $puestosNocturnos, true)) continue;

                    $turnoIdBase = (int)($turnoIdPorNumero[$turnoNumero] ?? 0);
                    if ($turnoIdBase <= 0) continue;

                    if (($puestosL4Turno[$codigoPuesto] ?? null) == $turnoNumero) {
                        if ($this->tieneTurnoL4EnFechaYBase((int)$puesto['id'], $fecha, $turnoNumero, $turnosPorPuestoFecha)) {
                            continue;
                        }
                    }

                    if ($this->estaPuestoOcupado((int)$puesto['id'], $turnoNumero, $fecha, $turnosPorPuestoFecha)) {
                        continue;
                    }

                    $bloqueObjetivo = $this->getBloqueObjetivoPuesto($codigoPuesto, $turnoNumero);
                    $opciones = $this->obtenerOpcionesTurnoHueco($turnoNumero, $bloqueObjetivo, $turnoOpcionesPorNumero);

                    $huecos[] = [
                        'fecha' => $fecha,
                        'puesto_id' => (int)$puesto['id'],
                        'puesto_codigo' => $codigoPuesto,
                        'turno_numero' => $turnoNumero,
                        'turno_id_base' => $turnoIdBase,
                        'bloque_objetivo' => $bloqueObjetivo,
                        'config_invalida' => empty($opciones),
                        'prioridad' => $this->getPrioridadCoberturaPuesto($codigoPuesto, $turnoNumero),
                        'escasez_estim' => (int)(($this->esPuestoFijo8h($codigoPuesto) || $bloqueObjetivo !== null) ? 1 : 2),
                    ];
                }
            }
        }

        return $huecos;
    }

    private function obtenerOpcionesTurnoHueco($turnoNumero, $bloqueObjetivo, $turnoOpcionesPorNumero) {
        $opciones = $turnoOpcionesPorNumero[$turnoNumero] ?? [];
        if ($bloqueObjetivo !== null) {
            $opciones = array_values(array_filter($opciones, function($op) use ($bloqueObjetivo) {
                return $this->clasificarBloqueHoras((float)($op['horas'] ?? 0)) === $bloqueObjetivo;
            }));
        }

        usort($opciones, function($a, $b) {
            $hA = (float)($a['horas'] ?? 0);
            $hB = (float)($b['horas'] ?? 0);
            if ($hA === $hB) return ((int)$a['id']) <=> ((int)$b['id']);
            return $hB <=> $hA;
        });

        return $opciones;
    }

    private function diagnosticarHuecoPendiente($hueco, $turnoOpcionesPorNumero, $perfilObjetivo, &$ctx, &$conteoTurnos, &$perfilSemanal) {
        if (!empty($hueco['config_invalida'])) {
            return 'No existe configuracion de turno compatible con el bloque requerido para este puesto.';
        }

        $fecha = (string)$hueco['fecha'];
        $puestoId = (int)$hueco['puesto_id'];
        $turnoNumero = (int)$hueco['turno_numero'];
        $turnoIdBase = (int)$hueco['turno_id_base'];
        $codigoPuesto = (string)$hueco['puesto_codigo'];
        $bloqueObjetivo = $hueco['bloque_objetivo'] ?? null;

        $opcionesHueco = $this->obtenerOpcionesTurnoHueco($turnoNumero, $bloqueObjetivo, $turnoOpcionesPorNumero);
        if (empty($opcionesHueco)) {
            return 'No existe opcion de turno activa para cubrir este hueco.';
        }

        $resultadoBusqueda = $this->getDisponiblesConFallback($puestoId, $turnoIdBase, $turnoNumero, $fecha, $ctx, $conteoTurnos);
        $disponibles = $this->filtrarDisponiblesPorRestriccionPuestoEspecifico($resultadoBusqueda['lista'], $puestoId, $fecha, $ctx);
        if (empty($disponibles)) {
            return 'Sin disponibles incluso con fallback minimo (incapacidades/dias especiales/descansos o conflictos entre dias).';
        }

        $conteoErrores = [];
        $encontroCupoPotencial = false;
        foreach ($disponibles as $trab) {
            $trabajadorId = (int)($trab['id'] ?? 0);
            if ($trabajadorId <= 0) continue;

            foreach ($opcionesHueco as $op) {
                $turnoId = (int)($op['id'] ?? 0);
                if ($turnoId <= 0) continue;

                $valid = $this->turnosAsignados->validarAsignacion($trabajadorId, $puestoId, $turnoId, $fecha, null);
                if (!empty($valid['valido'])) {
                    $encontroCupoPotencial = true;
                    break;
                }

                foreach (($valid['errores'] ?? []) as $err) {
                    $errTxt = trim((string)$err);
                    if ($errTxt === '') continue;
                    $conteoErrores[$errTxt] = ($conteoErrores[$errTxt] ?? 0) + 1;
                }
            }

            if ($encontroCupoPotencial) break;
        }

        if ($encontroCupoPotencial) {
            return 'Existe cupo potencial, pero requiere una reasignacion local adicional para no romper restricciones.';
        }

        if (empty($conteoErrores)) {
            return 'No existe candidato valido por restricciones combinadas, perfil semanal y disponibilidad real.';
        }

        arsort($conteoErrores);
        $top = [];
        $i = 0;
        foreach ($conteoErrores as $motivo => $cnt) {
            $top[] = $motivo . ' (x' . (int)$cnt . ')';
            $i++;
            if ($i >= 4) break;
        }

        return 'Restricciones bloqueantes: ' . implode(' | ', $top) . ' [puesto ' . $codigoPuesto . ' T' . $turnoNumero . ' ' . $fecha . ']';
    }

    private function obtenerAsignacionesOperativasDia($fecha, $turnoNumero = null) {
        $puestoCol = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
        if (!$puestoCol) return [];

        $sql = "SELECT ta.id,
                       ta.trabajador_id,
                       ta." . $puestoCol . " as puesto_trabajo_id,
                       ta.turno_id,
                       ct.numero_turno,
                       ct.horas_laborales,
                       pt.codigo as puesto_codigo,
                       t.nombre as trabajador_nombre
                  FROM turnos_asignados ta
            INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             LEFT JOIN puestos_trabajo pt ON ta." . $puestoCol . " = pt.id
            INNER JOIN trabajadores t ON ta.trabajador_id = t.id
                 WHERE ta.fecha = :fecha
                   AND ta.estado IN ('programado','activo')";

        $params = [':fecha' => $fecha];
        if ($turnoNumero !== null) {
            $sql .= " AND ct.numero_turno = :numero_turno";
            $params[':numero_turno'] = (int)$turnoNumero;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function moverAsignacionEnContexto($fecha, $turnoNumero, $horas, $trabajadorOrigen, $trabajadorDestino, &$conteoTurnos, &$perfilSemanal, &$ctx) {
        $fecha = (string)$fecha;
        $turnoNumero = (int)$turnoNumero;
        $trabajadorOrigen = (int)$trabajadorOrigen;
        $trabajadorDestino = (int)$trabajadorDestino;

        if ($trabajadorOrigen <= 0 || $trabajadorDestino <= 0) return;

        $this->removerTurnoDiaDeContexto($fecha, $trabajadorOrigen, $turnoNumero, $ctx);
        $ctx['asignadosPorDia'][$fecha][$trabajadorDestino][] = $turnoNumero;

        $semanaKey = $this->getSemanaKey($fecha);
        $this->ajustarPerfilSemanalDelta($perfilSemanal, $trabajadorOrigen, $semanaKey, (float)$horas, -1);
        $this->ajustarPerfilSemanalDelta($perfilSemanal, $trabajadorDestino, $semanaKey, (float)$horas, 1);

        $conteoTurnos[$trabajadorOrigen] = max(0, (int)($conteoTurnos[$trabajadorOrigen] ?? 0) - 1);
        $conteoTurnos[$trabajadorDestino] = (int)($conteoTurnos[$trabajadorDestino] ?? 0) + 1;

        if ($turnoNumero === 3) {
            $ctx['nochesPorTrabajador'][$trabajadorOrigen] = max(0, (int)($ctx['nochesPorTrabajador'][$trabajadorOrigen] ?? 0) - 1);
            $ctx['nochesPorTrabajador'][$trabajadorDestino] = (int)($ctx['nochesPorTrabajador'][$trabajadorDestino] ?? 0) + 1;
        }
    }

    private function registrarAsignacionNuevaContexto($fecha, $trabajadorId, $turnoNumero, $horas, &$conteoTurnos, &$perfilSemanal, &$ctx) {
        $fecha = (string)$fecha;
        $trabajadorId = (int)$trabajadorId;
        $turnoNumero = (int)$turnoNumero;

        if ($trabajadorId <= 0) return;

        $ctx['asignadosPorDia'][$fecha][$trabajadorId][] = $turnoNumero;

        if ($turnoNumero === 3) {
            $ctx['nochesPorTrabajador'][$trabajadorId] = (int)($ctx['nochesPorTrabajador'][$trabajadorId] ?? 0) + 1;
        }

        $semanaKey = $this->getSemanaKey($fecha);
        $this->ajustarPerfilSemanalDelta($perfilSemanal, $trabajadorId, $semanaKey, (float)$horas, 1);
        $conteoTurnos[$trabajadorId] = (int)($conteoTurnos[$trabajadorId] ?? 0) + 1;
    }

    private function removerTurnoDiaDeContexto($fecha, $trabajadorId, $turnoNumero, &$ctx) {
        if (empty($ctx['asignadosPorDia'][$fecha][$trabajadorId])) {
            return;
        }

        $lista = $ctx['asignadosPorDia'][$fecha][$trabajadorId];
        $removido = false;
        $nueva = [];
        foreach ($lista as $item) {
            if (!$removido && (int)$item === (int)$turnoNumero) {
                $removido = true;
                continue;
            }
            $nueva[] = $item;
        }

        if (empty($nueva)) {
            unset($ctx['asignadosPorDia'][$fecha][$trabajadorId]);
            return;
        }

        $ctx['asignadosPorDia'][$fecha][$trabajadorId] = $nueva;
    }

    private function ajustarPerfilSemanalDelta(&$perfilSemanal, $trabajadorId, $semanaKey, $horas, $deltaSigno) {
        if (!isset($perfilSemanal[$trabajadorId][$semanaKey])) {
            $perfilSemanal[$trabajadorId][$semanaKey] = [
                'total_horas' => 0.0,
                'h8' => 0,
                'h7' => 0,
                'h4' => 0,
                'otro' => 0,
            ];
        }

        $deltaSigno = $deltaSigno >= 0 ? 1 : -1;
        $bloque = $this->clasificarBloqueHoras((float)abs($horas));
        $perfilSemanal[$trabajadorId][$semanaKey]['total_horas'] += ((float)$horas * (float)$deltaSigno);

        if (!isset($perfilSemanal[$trabajadorId][$semanaKey][$bloque])) {
            $perfilSemanal[$trabajadorId][$semanaKey][$bloque] = 0;
        }
        $perfilSemanal[$trabajadorId][$semanaKey][$bloque] += $deltaSigno;
        if ($perfilSemanal[$trabajadorId][$semanaKey][$bloque] < 0) {
            $perfilSemanal[$trabajadorId][$semanaKey][$bloque] = 0;
        }
    }

    private function seleccionarCandidatoExacto($disponibles, $fecha, $turnoNumero, $puestoId, $codigoPuesto, $turnoIdExacto, $turnoHoras, $perfilSemanal, $perfilObjetivo, $conteoTurnos, &$ctx) {
        if (empty($disponibles)) return null;

        $semanaKey = $this->getSemanaKey($fecha);
        $bloque = $this->clasificarBloqueHoras((float)$turnoHoras);
        $candidatos = [];

        foreach ($disponibles as $trab) {
            $trabajadorId = (int)($trab['id'] ?? 0);
            if ($trabajadorId <= 0) continue;

            $perfil = $perfilSemanal[$trabajadorId][$semanaKey] ?? ['total_horas' => 0.0, 'h8' => 0, 'h7' => 0, 'h4' => 0, 'otro' => 0];
            if ($bloque === 'h8' && (int)($perfil['h8'] ?? 0) >= (int)($perfilObjetivo['max_8h'] ?? 0)) continue;
            if ($bloque === 'h7' && (int)($perfil['h7'] ?? 0) >= (int)($perfilObjetivo['max_7h'] ?? 0)) continue;
            if ($bloque === 'h4' && (int)($perfil['h4'] ?? 0) >= (int)($perfilObjetivo['max_4h'] ?? 0)) continue;

            $totalActual = (float)($perfil['total_horas'] ?? 0.0);
            if (($totalActual + (float)$turnoHoras) > 42.001) continue;

            $candidatos[] = [
                'trabajador' => $trab,
                'turno_id' => (int)$turnoIdExacto,
                'turno_horas' => (float)$turnoHoras,
                'score' => $this->calcularScoreCandidatoCobertura($trabajadorId, $turnoNumero, $codigoPuesto, (float)$turnoHoras, $perfil, (int)($conteoTurnos[$trabajadorId] ?? 0), $ctx),
                'conteo' => (int)($conteoTurnos[$trabajadorId] ?? 0),
                'total' => $totalActual,
            ];
        }

        if (empty($candidatos)) return null;

        usort($candidatos, function($a, $b) use ($turnoNumero, &$ctx) {
            if ($a['score'] !== $b['score']) return $a['score'] <=> $b['score'];
            if ($a['conteo'] !== $b['conteo']) return $a['conteo'] <=> $b['conteo'];
            $escA = $this->getEscasezRelativaCandidato((int)($a['trabajador']['id'] ?? 0), $turnoNumero, $ctx);
            $escB = $this->getEscasezRelativaCandidato((int)($b['trabajador']['id'] ?? 0), $turnoNumero, $ctx);
            if ($escA !== $escB) return $escA <=> $escB;
            return $a['total'] <=> $b['total'];
        });

        foreach ($candidatos as $candidato) {
            $trabajadorId = (int)($candidato['trabajador']['id'] ?? 0);
            $turnoId = (int)($candidato['turno_id'] ?? 0);
            if (!$this->validarCandidatoAutomatico($trabajadorId, $puestoId, $turnoId, $fecha, null)) {
                continue;
            }

            return $candidato;
        }

        return null;
    }
}
?>