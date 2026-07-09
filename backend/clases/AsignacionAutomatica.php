<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once __DIR__ . '/TurnosAsignados.php';
require_once __DIR__ . '/Trabajadores.php';

class AsignacionAutomatica {
    private $db;
    private $turnosAsignados;
    private $trabajadores;

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

        return [
            'todosActivos'        => $todosActivos,
            'asignadosPorDia'     => $asignadosPorDia,
            'incapacidades'       => $incapacidades,
            'diasEspeciales'      => $diasEspeciales,
            'restricciones'       => $restricciones,
            'puestosFlags'        => $puestosFlags,
            'nochesPorTrabajador' => $nochesPorTrabajador,
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

    private function getDisponiblesL4($puestoId, $turnoIdOrFecha, $fecha = null, &$ctx = []) {
        // Soporta dos firmas:
        // getDisponiblesL4(null, null, $fecha, $ctx)  → verificación general para ADMM/ADMT
        // getDisponiblesL4($puestoId, $fecha, $ctx)   → con puesto específico (L4)
        if ($puestoId === null) {
            return $this->trabajadores->obtenerDisponiblesL4(null, null, $fecha);
        }
        // Firma original: getDisponiblesL4($puestoId, $fecha, &$ctx)
        $fechaReal = $turnoIdOrFecha; // segundo param es la fecha en firma original
        $turnoId   = $ctx['puestosL4TurnoId'][$puestoId] ?? null;
        return $this->trabajadores->obtenerDisponiblesL4($puestoId, $turnoId, $fechaReal);
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

        // Trabajadores con ADMM o ADMT ese día — doble verificación:
        // 1. Desde ctx['diasEspeciales'] (prefetch + inserciones en tiempo real)
        // 2. Desde ctx['asignadosPorDia'] (registrado al insertar ADMM/ADMT)
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
        // También bloquear a quienes tienen ADMM/ADMT registrado en asignadosPorDia
        if (!empty($ctx['asignadosPorDia'][$fecha])) {
            foreach ($ctx['asignadosPorDia'][$fecha] as $trabId => $turnos) {
                if (in_array('ADMM', $turnos) || in_array('ADMT', $turnos)) {
                    $bloqueadosAdm[] = $trabId;
                }
            }
        }
        $bloqueadosAdm = array_unique($bloqueadosAdm);

        // Helper para filtrar bloqueados de una lista
        $filtrarAdm = function($lista) use ($bloqueadosAdm) {
            if (empty($bloqueadosAdm)) return $lista;
            return array_values(array_filter($lista, function($t) use ($bloqueadosAdm) {
                return !in_array($t['id'], $bloqueadosAdm);
            }));
        };

        // Nivel 1: normal, todos los filtros
        $disponibles = $filtrarAdm($this->getDisponibles($puestoId, $turnoId, $fecha, $ctx, $conteoTurnos));
        if (!empty($disponibles)) return ['lista' => $disponibles, 'nivel' => 1];

        // Nivel 2: ignorar límite de 7 noches (solo aplica a turno 3)
        if ($numeroTurno == 3) {
            $disponibles = $filtrarAdm($this->trabajadores->obtenerDisponiblesRelajado(
                $puestoId, $turnoId, $fecha, 'ignorar_limite_noches'
            ));
            if (!empty($disponibles)) {
                usort($disponibles, function($a, $b) use ($conteoTurnos) { return ($conteoTurnos[$a['id']] ?? 0) - ($conteoTurnos[$b['id']] ?? 0); });
                return ['lista' => $disponibles, 'nivel' => 2];
            }
        }

        // Nivel 3: ignorar restricción T1↔T3 consecutivos
        $disponibles = $filtrarAdm($this->trabajadores->obtenerDisponiblesRelajado(
            $puestoId, $turnoId, $fecha, 'ignorar_consecutivo'
        ));
        if (!empty($disponibles)) {
            usort($disponibles, function($a, $b) use ($conteoTurnos) { return ($conteoTurnos[$a['id']] ?? 0) - ($conteoTurnos[$b['id']] ?? 0); });
            return ['lista' => $disponibles, 'nivel' => 3];
        }

        // Nivel 4: mínimo absoluto, solo incapacidad y día libre
        $disponibles = $filtrarAdm($this->trabajadores->obtenerDisponiblesRelajado(
            $puestoId, $turnoId, $fecha, 'minimo'
        ));
        if (!empty($disponibles)) {
            usort($disponibles, function($a, $b) use ($conteoTurnos) { return ($conteoTurnos[$a['id']] ?? 0) - ($conteoTurnos[$b['id']] ?? 0); });
            return ['lista' => $disponibles, 'nivel' => 4];
        }

        return ['lista' => [], 'nivel' => 0];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MÉTODO PRINCIPAL
    // ─────────────────────────────────────────────────────────────────────────
    public function asignarMesCompleto($mes, $anio, $opciones = []) {
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
        // No asignar automáticamente el puesto C2; se asigna manualmente.
        $puestos = array_values(array_filter($puestos, function($p) {
            return strtoupper($p['codigo'] ?? '') !== 'C2';
        }));

        $turnosConfig = $this->db->query(
            "SELECT id, numero_turno, nombre, horas_laborales
             FROM configuracion_turnos
             WHERE activo = TRUE
             ORDER BY numero_turno, id"
        )->fetchAll();
        $turnoIdPorNumero = [];
        $turnoOpcionesPorNumero = [1 => [], 2 => [], 3 => []];
        $numeroPorTurnoId = [];
        $turnoMetaPorId   = [];
        $turnoIdL4Manana  = null;
        $turnoIdL4Tarde   = null;
        $hayTurno6h       = false;

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
                if ($turnoIdL4Manana === null || strpos($nombre, 'l4') !== false) {
                    $turnoIdL4Manana = $id;
                }
            }

            if ($num === 5 && $horas >= 3.5 && $horas <= 4.5) {
                if ($turnoIdL4Tarde === null || strpos($nombre, 'l4') !== false) {
                    $turnoIdL4Tarde = $id;
                }
            }

            if (in_array($num, [1,2,3]) && $horas >= 5.5 && $horas <= 6.5) {
                $hayTurno6h = true;
            }
        }

        foreach ([1,2,3] as $numBase) {
            if (empty($turnoOpcionesPorNumero[$numBase])) continue;
            usort($turnoOpcionesPorNumero[$numBase], function($a, $b) {
                if ($a['horas'] == $b['horas']) return $a['id'] <=> $b['id'];
                return $b['horas'] <=> $a['horas'];
            });

            // ID base para filtros de disponibilidad: preferir 8h, luego el primero disponible.
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
            'max_8h'    => 4,
            'max_6h'    => 1,
            'max_4h'    => 1,
        ];
        $maxHorasSemanalOperativo = $hayTurno6h ? 42.0 : 44.0;
        if (!$hayTurno6h) {
            $warnings[] = 'No hay turno activo de 6 horas en configuracion_turnos; se aplica perfil operativo de hasta 44h semanales (5x8 + 1x4) mientras se configura el turno de 6h.';
        }

        $turnoIdL4Manana = $turnoIdL4Manana ?? 9;
        $turnoIdL4Tarde  = $turnoIdL4Tarde ?? 10;

        // L4 en puestos definidos por negocio, incluyendo F11 en mañana.
        $puestosL4Map   = ['D2'=>$turnoIdL4Tarde,'D1'=>$turnoIdL4Tarde,'F11'=>$turnoIdL4Manana];
        $puestosL4Turno = ['D2'=>2,'D1'=>2,'F11'=>1];

        $stmtPuestosL4 = $this->db->prepare(
            "SELECT id, codigo FROM puestos_trabajo
             WHERE codigo IN ('D2','D1','F11') AND activo = TRUE"
        );
        $stmtPuestosL4->execute();
        $puestosL4Info = $stmtPuestosL4->fetchAll();

        $ctx['puestosL4TurnoId'] = [];
        foreach ($puestosL4Info as $pl4) {
            $ctx['puestosL4TurnoId'][$pl4['id']] = $puestosL4Map[$pl4['codigo']] ?? $turnoIdL4Tarde;
        }

        // Puestos prioritarios por turno — se procesan ANTES del shuffle
        $puestosPrioridadT1 = ['F6','D3','C','G','V1','V2','F14','F15','D1','D4','F2'];
        $puestosPrioridadT2 = ['F6','D3','C','G','V1','V2','F14','F15','F11','D1','D4','F2'];

        $puestosNocturnos = ['V1','V2','C','D3','F6','F11'];
        $MAX_LIBRES_DIA   = 3;

        try {
            // ════════════════════════════════════════════════════════════════
            // PASO 1 — DÍAS LIBRES
            // ════════════════════════════════════════════════════════════════

            $semanas = $this->calcularSemanas($mes, $anio);

            $diasEspecialesPrefetch = $this->prefetchDiasEspeciales($mes, $anio);
            $libresPorTrabajador    = $diasEspecialesPrefetch['libresPorTrabajador'];
            $cargaPorFecha          = $diasEspecialesPrefetch['cargaPorFecha'];

            $stmtInsLibre = $this->db->prepare(
                "INSERT INTO dias_especiales
                 (trabajador_id, tipo, fecha_inicio, fecha_fin, descripcion, estado)
                 VALUES (?, 'L', ?, NULL, 'AUTO: generado automáticamente', 'programado')"
            );

            $trabajadoresShuffled = $ctx['todosActivos'];
            shuffle($trabajadoresShuffled);
            $semanasShuffled = $semanas;
            shuffle($semanasShuffled);

            foreach ($semanasShuffled as $semana) {
                foreach ($trabajadoresShuffled as $trab) {
                    if ($this->tieneLibreEnRango($trab['id'], $semana['lunes'], $semana['domingo'], $libresPorTrabajador)) {
                        continue;
                    }

                    $tsUltimoLibre = $this->obtenerUltimoLibreAntes($trab['id'], $semana['lunes'], $libresPorTrabajador);

                    $candidatos = $this->buscarCandidatosLibre(
                        $trab['id'], $semana, $mes, $tsUltimoLibre, $cargaPorFecha, $MAX_LIBRES_DIA
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

                    $mejorDia = $candidatos[0]['fecha'];

                    try {
                        $stmtInsLibre->execute([$trab['id'], $mejorDia]);
                        $libresAsignados[] = ['trabajador' => $trab['nombre'], 'fecha' => $mejorDia];
                        $libresPorTrabajador[$trab['id']][] = ['fecha_inicio' => $mejorDia, 'fecha_fin' => null];
                        usort($libresPorTrabajador[$trab['id']], function($a, $b) { return strcmp($a['fecha_inicio'], $b['fecha_inicio']); });
                        $cargaPorFecha[$mejorDia] = ($cargaPorFecha[$mejorDia] ?? 0) + 1;
                        $ctx['diasEspeciales'][] = [
                            'trabajador_id' => $trab['id'],
                            'fecha_inicio'  => $mejorDia,
                            'fecha_fin'     => $mejorDia
                        ];
                    } catch (Exception $eL) {
                        $libresErrores[] = ['trabajador' => $trab['nombre'], 'semana' => $semana['lunes'], 'error' => $eL->getMessage()];
                    }
                }
            }

            // ════════════════════════════════════════════════════════════════
            // PASO 2 — TURNOS L4
            // ════════════════════════════════════════════════════════════════

            $turnosAsignadosPrefetch   = $this->prefetchTurnosAsignados($mes, $anio);
            $turnosPorTrabajadorSemana = $turnosAsignadosPrefetch['turnosPorTrabajadorSemana'];
            $turnosPorPuestoFecha      = $turnosAsignadosPrefetch['turnosPorPuestoFecha'];
            $perfilSemanal             = $turnosAsignadosPrefetch['perfilSemanal'];

            // Prefetch de ADMM/ADMT ya existentes en el mes (compatibilidad histórica)
            $stmtAdm = $this->db->prepare(
                "SELECT trabajador_id, tipo, fecha_inicio FROM dias_especiales
                 WHERE tipo IN ('ADMM','ADMT')
                 AND fecha_inicio BETWEEN ? AND ?
                 AND estado IN ('programado','activo')"
            );
            $stmtAdm->execute([$fechaInicioMes, $fechaFinMes]);
            $admPorTrabajadorSemana = [];
            foreach ($stmtAdm->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $semKey = $this->getSemanaKey($row['fecha_inicio']);
                $admPorTrabajadorSemana[$row['trabajador_id']][$semKey] = $row['tipo'];
                $this->actualizarPerfilSemanal($perfilSemanal, $row['trabajador_id'], $semKey, 4.0);
                // También registrar en asignadosPorDia para que Paso 3 los vea como ocupados
                $ctx['asignadosPorDia'][$row['fecha_inicio']][$row['trabajador_id']][] = $row['tipo'];
            }

            foreach ($semanasShuffled as $semana) {
                foreach ($trabajadoresShuffled as $trab) {
                    // Saltar si ya tiene L4, ADMM o ADMT esta semana
                    $tieneL4   = $this->tieneTurnoL4EnSemana($trab['id'], $semana['lunes'], $semana['domingo'], $turnosPorTrabajadorSemana);
                    $tieneAdm  = isset($admPorTrabajadorSemana[$trab['id']][$semana['lunes']]);
                    if ($tieneL4 || $tieneAdm) continue;

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
                    // D2 es de menor prioridad relativa: intentar antes otros puestos L4.
                    usort($puestosL4Mezclados, function($a, $b) {
                        $aEsD2 = strtoupper($a['codigo'] ?? '') === 'D2';
                        $bEsD2 = strtoupper($b['codigo'] ?? '') === 'D2';
                        if ($aEsD2 === $bEsD2) return 0;
                        return $aEsD2 ? 1 : -1;
                    });

                    foreach ($diasSemana as $fechaL4) {
                        if ($asignado) break;
                        foreach ($puestosL4Mezclados as $puesto) {
                            $turnoIdL4     = $puestosL4Map[$puesto['codigo']] ?? 9;
                            $numeroTurnoL4 = $numeroPorTurnoId[$turnoIdL4] ?? 4;

                            if ($this->estaPuestoOcupado($puesto['id'], $numeroTurnoL4, $fechaL4, $turnosPorPuestoFecha)) {
                                continue;
                            }

                            $disponiblesL4 = $this->getDisponiblesL4($puesto['id'], $fechaL4, $ctx);
                            $disponible    = array_filter($disponiblesL4, function($t) use ($trab) { return $t['id'] == $trab['id']; });
                            if (empty($disponible)) continue;

                            $resultado = $this->turnosAsignados->asignarDirecto([
                                'trabajador_id'     => $trab['id'],
                                'puesto_trabajo_id' => $puesto['id'],
                                'turno_id'          => $turnoIdL4,
                                'fecha'             => $fechaL4,
                                'observaciones'     => 'Asignacion automatica L4'
                            ]);

                            if ($resultado['success']) {
                                $asignaciones[] = ['fecha'=>$fechaL4,'puesto'=>$puesto['codigo'],'turno'=>'L4','trabajador'=>$trab['nombre']];
                                $turnosPorPuestoFecha[$puesto['id'].'|'.$numeroTurnoL4.'|'.$fechaL4] = true;

                                if (!isset($turnosPorTrabajadorSemana[$trab['id']][$semana['lunes']])) {
                                    $turnosPorTrabajadorSemana[$trab['id']][$semana['lunes']] = [];
                                }
                                $turnosPorTrabajadorSemana[$trab['id']][$semana['lunes']][] = $numeroTurnoL4;
                                $ctx['asignadosPorDia'][$fechaL4][$trab['id']][] = $numeroTurnoL4;
                                $this->actualizarPerfilSemanal($perfilSemanal, $trab['id'], $semana['lunes'], 4.0);
                                $conteoTurnos[$trab['id']] = ($conteoTurnos[$trab['id']] ?? 0) + 1;
                                $asignado = true;
                                break;
                            }
                        }
                    }

                    if (!$asignado) {
                        $errores[] = ['fecha'=>$semana['lunes'].' al '.$semana['domingo'],'puesto'=>'L4','turno'=>'L4','error'=>'Sin turno L4 disponible para '.$trab['nombre']];
                    }
                }
            }

            // ════════════════════════════════════════════════════════════════
            // PASO 3 — TURNOS NORMALES (T1, T2, T3) CON FALLBACK POR NIVELES
            // ════════════════════════════════════════════════════════════════

            for ($dia = 1; $dia <= $diasMes; $dia++) {
                $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

                // Ordenar puestos: primero los prioritarios de T1 y T2, luego el resto aleatorio
                $puestosResto = [];
                $puestosPrioT1 = [];
                $puestosPrioT2 = [];
                foreach ($puestos as $p) {
                    $cod = strtoupper($p['codigo']);
                    if (in_array($cod, $puestosPrioridadT1)) $puestosPrioT1[] = $p;
                    elseif (in_array($cod, $puestosPrioridadT2)) $puestosPrioT2[] = $p;
                    else $puestosResto[] = $p;
                }
                shuffle($puestosResto);

                // Orden final: prioritarios T1, prioritarios T2 (que no están en T1), resto
                $puestosOrdenados = array_merge($puestosPrioT1, $puestosPrioT2, $puestosResto);
                // D2 se procesa al final para priorizar cobertura de otros puestos.
                usort($puestosOrdenados, function($a, $b) {
                    $aEsD2 = strtoupper($a['codigo'] ?? '') === 'D2';
                    $bEsD2 = strtoupper($b['codigo'] ?? '') === 'D2';
                    if ($aEsD2 === $bEsD2) return 0;
                    return $aEsD2 ? 1 : -1;
                });

                foreach ($puestosOrdenados as $puesto) {
                    // T1 y T2 procesan sus turnos en orden fijo (no aleatorio) para respetar prioridad
                    $codigoPuesto = strtoupper($puesto['codigo']);
                    if (in_array($codigoPuesto, $puestosPrioridadT1) || in_array($codigoPuesto, $puestosPrioridadT2)) {
                        $turnosOrdenados = [1, 2, 3];
                    } else {
                        $turnosOrdenados = $turnos;
                        shuffle($turnosOrdenados);
                    }

                    foreach ($turnosOrdenados as $turno) {
                        $codigoPuesto = strtoupper($puesto['codigo']);
                        // Regla de negocio: D2 en mañana no se asigna automáticamente.
                        if ($codigoPuesto === 'D2' && (int)$turno === 1) continue;
                        $turnoIdBase = $turnoIdPorNumero[$turno] ?? null;
                        if ($turnoIdBase === null) continue;

                        if ($turno == 3 && !in_array($codigoPuesto, $puestosNocturnos)) continue;

                        // Regla de negocio:
                        // - C siempre debe quedar en 8h (todos sus turnos).
                        // - D3, V1, V2, F6 y F11 se fuerzan a 8h en tarde/noche.
                        $forzar8h = ($codigoPuesto === 'C')
                            || (in_array($codigoPuesto, ['D3','V1','V2','F6','F11']) && in_array((int)$turno, [2,3]));
                        if ($forzar8h && !$this->tieneOpcionTurnoMinHoras($turnoOpcionesPorNumero[$turno] ?? [], 7.5)) {
                            $errores[] = [
                                'fecha'  => $fecha,
                                'puesto' => $puesto['codigo'],
                                'turno'  => $turno,
                                'error'  => 'Configuracion invalida: este puesto en tarde/noche requiere turno >= 8h'
                            ];
                            continue;
                        }

                        if (isset($puestosL4Turno[$codigoPuesto]) && $puestosL4Turno[$codigoPuesto] == $turno) {
                            if ($this->tieneTurnoL4EnFecha($puesto['id'], $fecha, $turnosPorPuestoFecha)) {
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
                        $disponibles = $resultado_busqueda['lista'];
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
                            $codigoPuesto,
                            $forzar8h,
                            $turnoOpcionesPorNumero,
                            $perfilSemanal,
                            $perfilObjetivo,
                            $maxHorasSemanalOperativo,
                            $conteoTurnos
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

            return [
                'success'          => true,
                'asignaciones'     => count($asignaciones),
                'errores'          => count($errores),
                'libres_asignados' => count($libresAsignados),
                'libres_errores'   => count($libresErrores),
                'detalle_errores'  => $errores,
                'detalle_libres'   => $libresAsignados,
                'warnings'         => $warnings,
                // Estadísticas de fallback para diagnóstico
                'fallback_stats'   => [
                    'nivel_1_normal'              => $fallbackStats[1] ?? 0,
                    'nivel_2_ignorar_lim_noches'  => $fallbackStats[2] ?? 0,
                    'nivel_3_ignorar_consecutivo' => $fallbackStats[3] ?? 0,
                    'nivel_4_minimo'              => $fallbackStats[4] ?? 0,
                ]
            ];

        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Error en asignacion automatica: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function buscarCandidatosLibre($trabajadorId, $semana, $mes, $tsUltimoLibre, $cargaPorFecha, $maxLibresDia) {
        for ($nivel = 1; $nivel <= 3; $nivel++) {
            $candidatos = [];
            for ($d = 0; $d <= 6; $d++) {
                $ts       = strtotime($semana['lunes']) + $d * 86400;
                $dow      = (int)date('N', $ts);
                $fechaDia = date('Y-m-d', $ts);

                if ($dow > 5) continue;
                if ((int)date('n', $ts) != (int)$mes) continue;
                if ($nivel == 1 && $tsUltimoLibre && ($ts - $tsUltimoLibre) < (6 * 86400)) continue;

                $carga = $cargaPorFecha[$fechaDia] ?? 0;
                if ($nivel <= 2 && $carga >= $maxLibresDia) continue;

                $candidatos[] = ['fecha' => $fechaDia, 'carga' => $carga];
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
        $fechaInicioPrev = date('Y-m-d', strtotime($fechaInicio . ' -7 days'));

        $puestoCol    = Database::getColumnName('turnos_asignados', 'puesto_trabajo_id', 'puesto_id');
        $selectPuesto = $puestoCol ? "ta.$puestoCol as puesto_trabajo_id" : "NULL as puesto_trabajo_id";

        $stmt = $this->db->prepare(
            "SELECT ta.trabajador_id, " . $selectPuesto . ", ta.fecha, ct.numero_turno, ct.horas_laborales
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta.fecha BETWEEN ? AND ?
             AND ta.estado IN ('programado','activo')"
        );
        $stmt->execute([$fechaInicioPrev, $fechaFin]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $turnosPorTrabajadorSemana = [];
        $turnosPorPuestoFecha      = [];
        $perfilSemanal             = [];

        foreach ($result as $row) {
            $semanaKey = $this->getSemanaKey($row['fecha']);
            $turnosPorTrabajadorSemana[$row['trabajador_id']][$semanaKey][] = $row['numero_turno'];
            $this->actualizarPerfilSemanal($perfilSemanal, $row['trabajador_id'], $semanaKey, (float)$row['horas_laborales']);
            if ($row['puesto_trabajo_id'] !== null) {
                $turnosPorPuestoFecha[$row['puesto_trabajo_id'].'|'.$row['numero_turno'].'|'.$row['fecha']] = true;
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
        if ($horas >= 5.5) return 'h6';
        if ($horas >= 3.5) return 'h4';
        return 'otro';
    }

    private function actualizarPerfilSemanal(&$perfilSemanal, $trabajadorId, $semanaKey, $horas) {
        if (!isset($perfilSemanal[$trabajadorId][$semanaKey])) {
            $perfilSemanal[$trabajadorId][$semanaKey] = [
                'total_horas' => 0.0,
                'h8' => 0,
                'h6' => 0,
                'h4' => 0,
                'otro' => 0,
            ];
        }

        $perfilSemanal[$trabajadorId][$semanaKey]['total_horas'] += (float)$horas;
        $bloque = $this->clasificarBloqueHoras((float)$horas);
        $perfilSemanal[$trabajadorId][$semanaKey][$bloque]++;
    }

    private function tieneOpcionTurnoMinHoras($opcionesTurno, $minHoras) {
        foreach ($opcionesTurno as $op) {
            if ((float)($op['horas'] ?? 0) >= (float)$minHoras) return true;
        }
        return false;
    }

    private function elegirOpcionTurnoParaPerfil($opcionesTurno, $perfil, $perfilObjetivo, $forzar8h) {
        if (empty($opcionesTurno)) return null;

        $filtradas = array_values(array_filter($opcionesTurno, function($op) use ($forzar8h) {
            if (!$forzar8h) return true;
            return (float)($op['horas'] ?? 0) >= 7.5;
        }));
        if (empty($filtradas)) return null;

        $ops8 = array_values(array_filter($filtradas, function($op) { return (float)($op['horas'] ?? 0) >= 7.5; }));
        $ops6 = array_values(array_filter($filtradas, function($op) { $h = (float)($op['horas'] ?? 0); return $h >= 5.5 && $h <= 6.5; }));

        // Buscar 6h después de completar 4 turnos de 8h semanales.
        if (!$forzar8h && (int)($perfil['h8'] ?? 0) >= (int)$perfilObjetivo['max_8h'] && (int)($perfil['h6'] ?? 0) < (int)$perfilObjetivo['max_6h'] && !empty($ops6)) {
            usort($ops6, function($a, $b) { return $a['id'] <=> $b['id']; });
            return $ops6[0];
        }

        // Mientras no complete los 4 turnos de 8h, priorizar 8h.
        if ((int)($perfil['h8'] ?? 0) < (int)$perfilObjetivo['max_8h'] && !empty($ops8)) {
            usort($ops8, function($a, $b) { return $a['id'] <=> $b['id']; });
            return $ops8[0];
        }

        // Si ya completó 8h y aún le falta 6h, priorizar 6h.
        if (!$forzar8h && (int)($perfil['h6'] ?? 0) < (int)$perfilObjetivo['max_6h'] && !empty($ops6)) {
            usort($ops6, function($a, $b) { return $a['id'] <=> $b['id']; });
            return $ops6[0];
        }

        // Fallback estable: más horas primero para sostener cobertura.
        usort($filtradas, function($a, $b) {
            if ($a['horas'] == $b['horas']) return $a['id'] <=> $b['id'];
            return $b['horas'] <=> $a['horas'];
        });
        return $filtradas[0];
    }

    private function seleccionarMejorCandidatoSemanal($disponibles, $fecha, $turnoNumero, $codigoPuesto, $forzar8h, $turnoOpcionesPorNumero, $perfilSemanal, $perfilObjetivo, $maxHorasSemanalOperativo, $conteoTurnos) {
        if (empty($disponibles)) return null;

        $semanaKey = $this->getSemanaKey($fecha);
        $elegibles = [];
        $respaldo  = [];

        foreach ($disponibles as $trab) {
            $id = $trab['id'];
            $perfil = $perfilSemanal[$id][$semanaKey] ?? ['total_horas' => 0.0, 'h8' => 0, 'h6' => 0, 'h4' => 0, 'otro' => 0];

            $opcionTurno = $this->elegirOpcionTurnoParaPerfil(
                $turnoOpcionesPorNumero[$turnoNumero] ?? [],
                $perfil,
                $perfilObjetivo,
                $forzar8h
            );
            if (!$opcionTurno) continue;

            $turnoHoras = (float)($opcionTurno['horas'] ?? 0);
            $bloque = $this->clasificarBloqueHoras($turnoHoras);

            $totalActual = (float)$perfil['total_horas'];
            $totalProy   = $totalActual + (float)$turnoHoras;
            $conteoBase  = (int)($conteoTurnos[$id] ?? 0);

            $penalidad = 0;
            if ($bloque === 'h8') {
                if ((int)$perfil['h8'] >= (int)$perfilObjetivo['max_8h']) $penalidad += 20;
                else $penalidad -= 2;
            } elseif ($bloque === 'h6') {
                if ((int)$perfil['h6'] >= (int)$perfilObjetivo['max_6h']) $penalidad += 15;
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
            ];

            if ($totalProy <= $maxHorasSemanalOperativo + 0.001) $elegibles[] = $item;
            else $respaldo[] = $item;
        }

        $ordenar = function(&$lista) {
            usort($lista, function($a, $b) {
                if ($a['penalidad'] !== $b['penalidad']) return $a['penalidad'] <=> $b['penalidad'];
                if ($a['total'] !== $b['total']) return $a['total'] <=> $b['total'];
                if ($a['conteo'] !== $b['conteo']) return $a['conteo'] <=> $b['conteo'];
                return $a['proyectado'] <=> $b['proyectado'];
            });
        };

        if (!empty($elegibles)) {
            $ordenar($elegibles);
            return [
                'trabajador' => $elegibles[0]['trabajador'],
                'turno_id'   => $elegibles[0]['turno_id'],
                'turno_horas'=> $elegibles[0]['turno_horas'],
            ];
        }

        if (!empty($respaldo)) {
            $ordenar($respaldo);
            return [
                'trabajador' => $respaldo[0]['trabajador'],
                'turno_id'   => $respaldo[0]['turno_id'],
                'turno_horas'=> $respaldo[0]['turno_horas'],
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
        return in_array(4, $t) || in_array(5, $t);
    }

    private function tieneTurnoL4EnFecha($puesto_id, $fecha, $turnosPorPuestoFecha) {
        return isset($turnosPorPuestoFecha[$puesto_id.'|4|'.$fecha])
            || isset($turnosPorPuestoFecha[$puesto_id.'|5|'.$fecha]);
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
}
?>