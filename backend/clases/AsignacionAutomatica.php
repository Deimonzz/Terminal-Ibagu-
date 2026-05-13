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
    // Carga toda la información del mes en memoria con ~6 queries.
    // Elimina las N×M×D queries individuales del método original.
    // ─────────────────────────────────────────────────────────────────────────
    private function prefetchDisponibilidadMes($mes, $anio) {
        $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin    = date('Y-m-t', strtotime($fechaInicio));
        // Extender un día en cada extremo para las reglas T3→T1 y T1←T3
        $fechaFinExt = date('Y-m-d', strtotime($fechaFin   . ' +1 day'));
        $fechaIniExt = date('Y-m-d', strtotime($fechaInicio . ' -1 day'));

        // 1. Trabajadores activos (no supervisores)
        $todosActivos = $this->db->query(
            "SELECT id, nombre FROM trabajadores
             WHERE activo = true AND LOWER(COALESCE(cargo,'')) != 'supervisor'
             ORDER BY nombre"
        )->fetchAll(PDO::FETCH_ASSOC);

        // 2. Turnos ya asignados en el rango extendido
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

        // 3. Incapacidades activas que se solapan con el mes
        $stmtI = $this->db->prepare(
            "SELECT trabajador_id, fecha_inicio, fecha_fin
             FROM incapacidades
             WHERE estado = 'activa'
             AND fecha_inicio <= ? AND fecha_fin >= ?"
        );
        $stmtI->execute([$fechaFin, $fechaInicio]);
        $incapacidades = $stmtI->fetchAll(PDO::FETCH_ASSOC);

        // 4. Días especiales (libres, vacaciones, etc.)
        $stmtDE = $this->db->prepare(
            "SELECT trabajador_id, fecha_inicio,
                    COALESCE(fecha_fin, fecha_inicio) as fecha_fin
             FROM dias_especiales
             WHERE tipo IN ('LC','L','L8','VAC','SUS','ADM','ADMM','ADMT')
             AND estado IN ('programado','activo')
             AND fecha_inicio <= ? AND COALESCE(fecha_fin, fecha_inicio) >= ?"
        );
        $stmtDE->execute([$fechaFin, $fechaInicio]);
        $diasEspeciales = $stmtDE->fetchAll(PDO::FETCH_ASSOC);

        // 5. Restricciones de trabajadores vigentes en el mes
        $stmtR = $this->db->prepare(
            "SELECT trabajador_id, tipo_restriccion,
                    puesto_trabajo_id,
                    fecha_inicio, fecha_fin
             FROM restricciones_trabajador
             WHERE activa = true
             AND fecha_inicio <= ?
             AND (fecha_fin IS NULL OR fecha_fin >= ?)"
        );
        $stmtR->execute([$fechaFin, $fechaInicio]);
        $restricciones = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        // 6. Flags de puestos (fuerza física, movilidad)
        $stmtP = $this->db->prepare(
            "SELECT id, codigo, requiere_fuerza_fisica, requiere_movilidad
             FROM puestos_trabajo WHERE activo = TRUE"
        );
        $stmtP->execute();
        $puestosFlags = [];
        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $puestosFlags[$p['id']] = $p;
        }

        // 7. Conteo de turnos noche del mes (para el límite de 7 noches)
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
    // DISPONIBILIDAD EN MEMORIA (sin queries adicionales)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve trabajadores disponibles para puesto+turno+fecha.
     * Consulta únicamente estructuras en memoria cargadas por prefetchDisponibilidadMes().
     * Se actualiza $ctx['asignadosPorDia'] después de cada asignación exitosa,
     * por lo que es consistente dentro del mismo bucle de asignación.
     */
    private function getDisponibles($puestoId, $numeroTurno, $fecha, &$ctx, &$conteoTurnos) {
        $fechaAnterior  = date('Y-m-d', strtotime($fecha . ' -1 day'));
        $fechaSiguiente = date('Y-m-d', strtotime($fecha . ' +1 day'));

        $bloqueados = [];

        // Ya tiene turno hoy (cualquier turno)
        foreach ($ctx['asignadosPorDia'][$fecha] ?? [] as $trabId => $turnos) {
            $bloqueados[$trabId] = true;
        }

        // Incapacidad activa
        foreach ($ctx['incapacidades'] as $inc) {
            if ($fecha >= $inc['fecha_inicio'] && $fecha <= $inc['fecha_fin']) {
                $bloqueados[$inc['trabajador_id']] = true;
            }
        }

        // Día especial (libre, vacaciones, etc.)
        foreach ($ctx['diasEspeciales'] as $de) {
            if ($fecha >= $de['fecha_inicio'] && $fecha <= $de['fecha_fin']) {
                $bloqueados[$de['trabajador_id']] = true;
            }
        }

        // Regla: T1 no puede asignarse si el día anterior tuvo T3
        if ($numeroTurno == 1) {
            foreach ($ctx['asignadosPorDia'][$fechaAnterior] ?? [] as $trabId => $turnos) {
                if (in_array(3, $turnos)) $bloqueados[$trabId] = true;
            }
        }

        // Regla: T3 no puede asignarse si el día siguiente tiene T1
        if ($numeroTurno == 3) {
            foreach ($ctx['asignadosPorDia'][$fechaSiguiente] ?? [] as $trabId => $turnos) {
                if (in_array(1, $turnos)) $bloqueados[$trabId] = true;
            }
        }

        // Restricciones individuales de trabajadores
        foreach ($ctx['restricciones'] as $rest) {
            if ($fecha < $rest['fecha_inicio']) continue;
            if ($rest['fecha_fin'] !== null && $fecha > $rest['fecha_fin']) continue;

            switch ($rest['tipo_restriccion']) {
                case 'no_turno_noche':
                    if ($numeroTurno == 3) $bloqueados[$rest['trabajador_id']] = true;
                    break;
                case 'puesto_especifico':
                    if ($rest['puesto_trabajo_id'] == $puestoId) $bloqueados[$rest['trabajador_id']] = true;
                    break;
            }
        }

        // Flags del puesto (fuerza física, movilidad)
        $puesto = $ctx['puestosFlags'][$puestoId] ?? null;
        if ($puesto) {
            foreach ($ctx['restricciones'] as $rest) {
                if ($fecha < $rest['fecha_inicio']) continue;
                if ($rest['fecha_fin'] !== null && $fecha > $rest['fecha_fin']) continue;
                if (!empty($puesto['requiere_fuerza_fisica']) && $rest['tipo_restriccion'] === 'no_fuerza_fisica') {
                    $bloqueados[$rest['trabajador_id']] = true;
                }
                if (!empty($puesto['requiere_movilidad']) && $rest['tipo_restriccion'] === 'movilidad_limitada') {
                    $bloqueados[$rest['trabajador_id']] = true;
                }
            }
        }

        // Límite de 7 turnos noche por mes
        if ($numeroTurno == 3) {
            foreach ($ctx['nochesPorTrabajador'] as $trabId => $cnt) {
                if ($cnt >= 7) $bloqueados[$trabId] = true;
            }
        }

        // Construir lista de disponibles y ordenar por menor carga
        $disponibles = [];
        foreach ($ctx['todosActivos'] as $trab) {
            if (!isset($bloqueados[$trab['id']])) {
                $disponibles[] = $trab;
            }
        }

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

    /**
     * Disponibles para L4: sin L4 hoy, sin libre/incapacidad.
     * SÍ pueden tener T1/T2/T3 asignado (L4 es compatible).
     */
    private function getDisponiblesL4($puestoId, $fecha, &$ctx) {
        $bloqueados = [];

        // Ya tiene L4 (turno 4 o 5) hoy
        foreach ($ctx['asignadosPorDia'][$fecha] ?? [] as $trabId => $turnos) {
            if (in_array(4, $turnos) || in_array(5, $turnos)) {
                $bloqueados[$trabId] = true;
            }
        }

        // Incapacidad
        foreach ($ctx['incapacidades'] as $inc) {
            if ($fecha >= $inc['fecha_inicio'] && $fecha <= $inc['fecha_fin']) {
                $bloqueados[$inc['trabajador_id']] = true;
            }
        }

        // Día especial
        foreach ($ctx['diasEspeciales'] as $de) {
            if ($fecha >= $de['fecha_inicio'] && $fecha <= $de['fecha_fin']) {
                $bloqueados[$de['trabajador_id']] = true;
            }
        }

        // Restricción puesto específico
        foreach ($ctx['restricciones'] as $rest) {
            if ($rest['tipo_restriccion'] !== 'puesto_especifico') continue;
            if ($rest['puesto_id'] != $puestoId) continue;
            if ($fecha < $rest['fecha_inicio']) continue;
            if ($rest['fecha_fin'] !== null && $fecha > $rest['fecha_fin']) continue;
            $bloqueados[$rest['trabajador_id']] = true;
        }

        $disponibles = [];
        foreach ($ctx['todosActivos'] as $trab) {
            if (!isset($bloqueados[$trab['id']])) {
                $disponibles[] = $trab;
            }
        }
        return $disponibles;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MÉTODO PRINCIPAL
    // ─────────────────────────────────────────────────────────────────────────
    public function asignarMesCompleto($mes, $anio, $opciones = []) {
        $diasMes         = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $asignaciones    = [];
        $errores         = [];
        $libresAsignados = [];
        $libresErrores   = [];

        $fechaInicioMes = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFinMes    = date('Y-m-t', strtotime($fechaInicioMes));

        // ── Prefetch masivo ──────────────────────────────────────────────────
        $ctx = $this->prefetchDisponibilidadMes($mes, $anio);

        // Conteo de turnos en memoria para balanceo equitativo
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

        // Patrón de libres del mes anterior (continuidad entre meses)
        $mesAnterior  = $mes == 1 ? 12 : $mes - 1;
        $anioAnterior = $mes == 1 ? $anio - 1 : $anio;
        $patronLibres = $this->obtenerPatronLibresMesAnterior($mesAnterior, $anioAnterior, $ctx['todosActivos']);

        $puestos = $this->obtenerPuestos();

        // Configuración de turnos
        $turnosConfig = $this->db->query(
            "SELECT id, numero_turno FROM configuracion_turnos ORDER BY numero_turno"
        )->fetchAll();
        $turnoIdPorNumero = [];
        $numeroPorTurnoId = [];
        foreach ($turnosConfig as $tc) {
            $numeroPorTurnoId[$tc['id']] = $tc['numero_turno'];
            if (in_array($tc['numero_turno'], [1,2,3])) {
                $turnoIdPorNumero[$tc['numero_turno']] = $tc['id'];
            }
        }
        $turnos = array_keys($turnoIdPorNumero) ?: [1,2,3];

        // Puestos L4
        $puestosL4Map   = ['F5'=>9,'F15'=>9,'D2'=>10,'D1'=>10,'F11'=>9];
        $puestosL4Turno = ['F5'=>1,'F15'=>1,'D2'=>2,'D1'=>2,'F11'=>1];

        $stmtPuestosL4 = $this->db->prepare(
            "SELECT id, codigo FROM puestos_trabajo
             WHERE codigo IN ('F5','F15','D2','D1','F11') AND activo = TRUE"
        );
        $stmtPuestosL4->execute();
        $puestosL4Info = $stmtPuestosL4->fetchAll();

        $puestosNocturnos = ['V1','V2','C','D3','F6','F11'];
        $MAX_LIBRES_DIA   = 3;

        try {
            // ════════════════════════════════════════════════════════════════
            // PASO 1 — DÍAS LIBRES
            // ════════════════════════════════════════════════════════════════

            $semanas = $this->calcularSemanas($mes, $anio);

            // Prefetch extendido -14 días para continuidad con mes anterior
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

                        // Actualizar estado en memoria para que pasos 2 y 3 lo vean
                        $libresPorTrabajador[$trab['id']][] = ['fecha_inicio' => $mejorDia, 'fecha_fin' => null];
                        usort($libresPorTrabajador[$trab['id']], fn($a,$b) => strcmp($a['fecha_inicio'], $b['fecha_inicio']));
                        $cargaPorFecha[$mejorDia] = ($cargaPorFecha[$mejorDia] ?? 0) + 1;

                        // Sincronizar con el contexto de disponibilidad
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

            // Prefetch extendido -7 días para la primera semana del mes
            $turnosAsignadosPrefetch   = $this->prefetchTurnosAsignados($mes, $anio);
            $turnosPorTrabajadorSemana = $turnosAsignadosPrefetch['turnosPorTrabajadorSemana'];
            $turnosPorPuestoFecha      = $turnosAsignadosPrefetch['turnosPorPuestoFecha'];

            foreach ($semanasShuffled as $semana) {
                foreach ($trabajadoresShuffled as $trab) {
                    if ($this->tieneTurnoL4EnSemana($trab['id'], $semana['lunes'], $semana['domingo'], $turnosPorTrabajadorSemana)) {
                        continue;
                    }

                    $diasSemana = [];
                    for ($d = 0; $d <= 6; $d++) {
                        $ts  = strtotime($semana['lunes']) + $d * 86400;
                        $dow = (int)date('N', $ts);
                        if ($dow > 5) continue;
                        if ((int)date('n', $ts) != (int)$mes) continue;
                        $diasSemana[] = date('Y-m-d', $ts);
                    }
                    shuffle($diasSemana);

                    $puestosL4Mezclados = $puestosL4Info;
                    shuffle($puestosL4Mezclados);

                    $asignado = false;
                    foreach ($diasSemana as $fechaL4) {
                        if ($asignado) break;
                        foreach ($puestosL4Mezclados as $puesto) {
                            $turnoIdL4     = $puestosL4Map[$puesto['codigo']] ?? 9;
                            $numeroTurnoL4 = $numeroPorTurnoId[$turnoIdL4] ?? 4;

                            if ($this->estaPuestoOcupado($puesto['id'], $numeroTurnoL4, $fechaL4, $turnosPorPuestoFecha)) {
                                continue;
                            }

                            $disponiblesL4 = $this->getDisponiblesL4($puesto['id'], $fechaL4, $ctx);
                            $disponible    = array_filter($disponiblesL4, fn($t) => $t['id'] == $trab['id']);
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

                                // Actualizar contexto en memoria
                                $ctx['asignadosPorDia'][$fechaL4][$trab['id']][] = $numeroTurnoL4;
                                $conteoTurnos[$trab['id']] = ($conteoTurnos[$trab['id']] ?? 0) + 1;
                                $asignado = true;
                                break;
                            }
                        }
                    }

                    if (!$asignado) {
                        $errores[] = ['fecha'=>$semana['lunes'].' al '.$semana['domingo'],'puesto'=>'L4','turno'=>'L4','error'=>'Sin puesto L4 disponible para '.$trab['nombre']];
                    }
                }
            }

            // ════════════════════════════════════════════════════════════════
            // PASO 3 — TURNOS NORMALES (T1, T2, T3)
            //
            // CLAVE: $ctx['asignadosPorDia'] se actualiza inmediatamente
            // después de cada asignación exitosa → el mismo trabajador NO puede
            // ser seleccionado para otro puesto en la misma fecha.
            // ════════════════════════════════════════════════════════════════

            for ($dia = 1; $dia <= $diasMes; $dia++) {
                $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

                $puestosShuffled = $puestos;
                shuffle($puestosShuffled);

                foreach ($puestosShuffled as $puesto) {
                    $turnosShuffled = $turnos;
                    shuffle($turnosShuffled);

                    foreach ($turnosShuffled as $turno) {
                        $codigoPuesto = strtoupper($puesto['codigo']);

                        if ($turno == 3 && !in_array($codigoPuesto, $puestosNocturnos)) continue;

                        $turnoIdReal = $turnoIdPorNumero[$turno] ?? $turno;

                        if (isset($puestosL4Turno[$codigoPuesto]) && $puestosL4Turno[$codigoPuesto] == $turno) {
                            if ($this->tieneTurnoL4EnFecha($puesto['id'], $fecha, $turnosPorPuestoFecha)) {
                                continue;
                            }
                        }

                        if ($this->estaPuestoOcupado($puesto['id'], $turno, $fecha, $turnosPorPuestoFecha)) {
                            continue;
                        }

                        // Disponibilidad completamente en memoria, sin queries
                        $disponibles = $this->getDisponibles($puesto['id'], $turno, $fecha, $ctx, $conteoTurnos);

                        if (empty($disponibles)) {
                            $errores[] = ['fecha'=>$fecha,'puesto'=>$puesto['codigo'],'turno'=>$turno,'error'=>'Sin trabajadores disponibles'];
                            continue;
                        }

                        $sel = $disponibles[0]; // Menor carga primero

                        $resultado = $this->turnosAsignados->asignarDirecto([
                            'trabajador_id'     => $sel['id'],
                            'puesto_trabajo_id' => $puesto['id'],
                            'turno_id'          => $turnoIdReal,
                            'fecha'             => $fecha,
                            'observaciones'     => 'Asignacion automatica'
                        ]);

                        if ($resultado['success']) {
                            // Actualizar contexto en memoria INMEDIATAMENTE
                            $ctx['asignadosPorDia'][$fecha][$sel['id']][] = $turno;

                            if ($turno == 3) {
                                $ctx['nochesPorTrabajador'][$sel['id']] = ($ctx['nochesPorTrabajador'][$sel['id']] ?? 0) + 1;
                            }
                            $conteoTurnos[$sel['id']] = ($conteoTurnos[$sel['id']] ?? 0) + 1;
                            $turnosPorPuestoFecha[$puesto['id'].'|'.$turno.'|'.$fecha] = true;

                            $asignaciones[] = ['fecha'=>$fecha,'puesto'=>$puesto['codigo'],'turno'=>$turno,'trabajador'=>$sel['nombre']];
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
                'detalle_libres'   => $libresAsignados
            ];

        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Error en asignacion automatica: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 3 niveles de búsqueda para candidatos de día libre:
     * 1. Respeta separación de 6 días + carga máxima
     * 2. Solo respeta carga máxima
     * 3. Cualquier día entre semana del mes (fallback final)
     */
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
             WHERE tipo IN ('L','L8','LC','VAC','SUS','ADM','ADMM','ADMT')
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
            usort($fechas, fn($a,$b) => strcmp($a['fecha_inicio'], $b['fecha_inicio']));
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

        $stmt = $this->db->prepare(
            "SELECT ta.trabajador_id, ta.puesto_trabajo_id, ta.fecha, ct.numero_turno
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta.fecha BETWEEN ? AND ?
             AND ta.estado IN ('programado','activo')"
        );
        $stmt->execute([$fechaInicioPrev, $fechaFin]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $turnosPorTrabajadorSemana = [];
        $turnosPorPuestoFecha      = [];

        foreach ($result as $row) {
            $semanaKey = $this->getSemanaKey($row['fecha']);
            $turnosPorTrabajadorSemana[$row['trabajador_id']][$semanaKey][] = $row['numero_turno'];
            $turnosPorPuestoFecha[$row['puesto_trabajo_id'].'|'.$row['numero_turno'].'|'.$row['fecha']] = true;
        }

        return ['turnosPorTrabajadorSemana' => $turnosPorTrabajadorSemana, 'turnosPorPuestoFecha' => $turnosPorPuestoFecha];
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