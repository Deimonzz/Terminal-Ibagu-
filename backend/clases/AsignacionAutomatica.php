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

    public function asignarMesCompleto($mes, $anio, $opciones = []) {
        // Calcular días del mes (alternativa a cal_days_in_month)
        $diasMes = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $asignaciones  = [];
        $errores       = [];
        $libresAsignados = [];
        $libresErrores   = [];

        // ── Datos base ──────────────────────────────────────────
        $stmt = $this->db->query(
            "SELECT id, nombre FROM trabajadores WHERE activo = true AND LOWER(COALESCE(cargo, '')) != 'supervisor' ORDER BY nombre"
        );
        $trabajadoresActivos = $stmt->fetchAll();
        $turnosNochePorTrabajador = $this->trabajadores->obtenerConteoTurnosNochePorMes($mes, $anio);

        // Obtener patrón de libres del mes anterior
        $mesAnterior = $mes == 1 ? 12 : $mes - 1;
        $anioAnterior = $mes == 1 ? $anio - 1 : $anio;
        $patronLibresMesAnterior = $this->obtenerPatronLibresMesAnterior($mesAnterior, $anioAnterior, $trabajadoresActivos);

        $puestos = $this->obtenerPuestos();

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

        // Puestos L4 y el turno_id que corresponde
        $puestosL4Map   = ['F5'=>9,'F15'=>9,'D2'=>10,'D1'=>10,'F11'=>9];
        $puestosL4Turno = ['F5'=>1,'F15'=>1,'D2'=>2,'D1'=>2,'F11'=>1];

        $stmtPuestosL4 = $this->db->prepare(
            "SELECT id, codigo FROM puestos_trabajo
             WHERE codigo IN ('F5','F15','D2','D1','F11') AND activo = TRUE"
        );
        $stmtPuestosL4->execute();
        $puestosL4Info = $stmtPuestosL4->fetchAll();

        // Puestos nocturnos
        $puestosNocturnos = ['V1','V2','C','D3','F6','F11'];

        // Máximo libres permitidos el mismo día
        $MAX_LIBRES_DIA = 4;

        try {
            // ════════════════════════════════════════════════════
            // PASO 1 — DÍAS LIBRES
            // ════════════════════════════════════════════════════

            $semanas = $this->calcularSemanas($mes, $anio);
            $diasEspecialesPrefetch = $this->prefetchDiasEspeciales($mes, $anio);
            $libresPorTrabajador = $diasEspecialesPrefetch['libresPorTrabajador'];
            $cargaPorFecha = $diasEspecialesPrefetch['cargaPorFecha'];

            $stmtInsLibre = $this->db->prepare(
                "INSERT INTO dias_especiales
                 (trabajador_id, tipo, fecha_inicio, fecha_fin, descripcion, estado)
                 VALUES (?, 'L', ?, NULL, 'AUTO: generado automáticamente', 'programado')"
            );

            foreach ($semanas as $semana) {
                foreach ($trabajadoresActivos as $trab) {
                    if ($this->tieneLibreEnRango($trab['id'], $semana['lunes'], $semana['domingo'], $libresPorTrabajador)) {
                        continue;
                    }

                    $tsUltimoLibre = $this->obtenerUltimoLibreAntes($trab['id'], $semana['lunes'], $libresPorTrabajador);

                    $candidatos = [];
                    for ($d = 0; $d <= 6; $d++) {
                        $ts = strtotime($semana['lunes']) + $d * 86400;
                        $dow = (int)date('N', $ts);
                        $fechaDia = date('Y-m-d', $ts);

                        if ($dow > 5) continue;
                        if ((int)date('n', $ts) != (int)$mes) continue;
                        if ($tsUltimoLibre && ($ts - $tsUltimoLibre) < (6 * 86400)) continue;

                        $carga = $cargaPorFecha[$fechaDia] ?? 0;
                        if ($carga < $MAX_LIBRES_DIA) {
                            $candidatos[] = ['fecha' => $fechaDia, 'carga' => $carga];
                        }
                    }

                    if (empty($candidatos)) {
                        for ($d = 0; $d <= 6; $d++) {
                            $ts = strtotime($semana['lunes']) + $d * 86400;
                            $dow = (int)date('N', $ts);
                            $fechaDia = date('Y-m-d', $ts);
                            if ($dow > 5) continue;
                            if ((int)date('n', $ts) != (int)$mes) continue;
                            $carga = $cargaPorFecha[$fechaDia] ?? 0;
                            if ($carga < $MAX_LIBRES_DIA) {
                                $candidatos[] = ['fecha' => $fechaDia, 'carga' => $carga];
                            }
                        }
                    }

                    if (empty($candidatos)) {
                        for ($d = 0; $d <= 6; $d++) {
                            $ts = strtotime($semana['lunes']) + $d * 86400;
                            $dow = (int)date('N', $ts);
                            $fechaDia = date('Y-m-d', $ts);
                            if ($dow > 5) continue;
                            if ((int)date('n', $ts) != (int)$mes) continue;
                            $carga = $cargaPorFecha[$fechaDia] ?? 0;
                            $candidatos[] = ['fecha' => $fechaDia, 'carga' => $carga];
                        }
                    }

                    if (empty($candidatos)) {
                        $libresErrores[] = ['trabajador' => $trab['nombre'], 'semana' => $semana['lunes'], 'error' => 'Sin día entre semana disponible'];
                        continue;
                    }

                    usort($candidatos, function($a, $b) use ($patronLibresMesAnterior, $trab) {
                        $diaPreferido = $patronLibresMesAnterior[$trab['id']] ?? null;
                        $dowA = (int)date('N', strtotime($a['fecha']));
                        $dowB = (int)date('N', strtotime($b['fecha']));
                        if ($diaPreferido) {
                            $prefA = ($dowA == $diaPreferido) ? 1 : 0;
                            $prefB = ($dowB == $diaPreferido) ? 1 : 0;
                            if ($prefA != $prefB) return $prefB - $prefA; // Preferido primero
                        }
                        return $a['carga'] - $b['carga'];
                    });
                    $mejorDia = $candidatos[0]['fecha'];

                    try {
                        $stmtInsLibre->execute([$trab['id'], $mejorDia]);
                        $libresAsignados[] = ['trabajador' => $trab['nombre'], 'fecha' => $mejorDia];
                        $libresPorTrabajador[$trab['id']][] = ['fecha_inicio' => $mejorDia, 'fecha_fin' => null];
                        $cargaPorFecha[$mejorDia] = ($cargaPorFecha[$mejorDia] ?? 0) + 1;
                    } catch (Exception $eL) {
                        $libresErrores[] = ['trabajador' => $trab['nombre'], 'semana' => $semana['lunes'], 'error' => $eL->getMessage()];
                    }
                }
            }

            // ════════════════════════════════════════════════════
            // PASO 2 — TURNOS L4
            // ════════════════════════════════════════════════════

            $turnosAsignadosPrefetch = $this->prefetchTurnosAsignados($mes, $anio);
            $turnosPorTrabajadorSemana = $turnosAsignadosPrefetch['turnosPorTrabajadorSemana'];
            $turnosPorPuestoFecha = $turnosAsignadosPrefetch['turnosPorPuestoFecha'];

            foreach ($semanas as $semana) {
                foreach ($trabajadoresActivos as $trab) {
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
                            $turnoIdL4 = $puestosL4Map[$puesto['codigo']] ?? 9;
                            $numeroTurnoL4 = $numeroPorTurnoId[$turnoIdL4] ?? 4;

                            if ($this->estaPuestoOcupado($puesto['id'], $numeroTurnoL4, $fechaL4, $turnosPorPuestoFecha)) {
                                continue;
                            }

                            $disponibles = $this->trabajadores->obtenerDisponiblesL4(
                                $puesto['id'], $turnoIdL4, $fechaL4
                            );
                            $disponible = array_filter($disponibles, function($t) use ($trab) {
                                return $t['id'] == $trab['id'];
                            });
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
                                $turnosPorPuestoFecha[$puesto['id'] . '|' . $numeroTurnoL4 . '|' . $fechaL4] = true;
                                if (!isset($turnosPorTrabajadorSemana[$trab['id']][$semana['lunes']])) {
                                    $turnosPorTrabajadorSemana[$trab['id']][$semana['lunes']] = [];
                                }
                                $turnosPorTrabajadorSemana[$trab['id']][$semana['lunes']][] = $numeroTurnoL4;
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

            // ════════════════════════════════════════════════════
            // PASO 3 — TURNOS NORMALES (T1, T2, T3)
            // ════════════════════════════════════════════════════

            for ($dia = 1; $dia <= $diasMes; $dia++) {
                $fecha    = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

                foreach ($puestos as $puesto) {
                    foreach ($turnos as $turno) {
                        if ($turno == 3 && !in_array(strtoupper($puesto['codigo']), $puestosNocturnos)) continue;

                        $turnoIdReal  = $turnoIdPorNumero[$turno] ?? $turno;
                        $codigoPuesto = strtoupper($puesto['codigo']);

                        if (isset($puestosL4Turno[$codigoPuesto]) && $puestosL4Turno[$codigoPuesto] == $turno) {
                            if ($this->tieneTurnoL4EnFecha($puesto['id'], $fecha, $turnosPorPuestoFecha)) {
                                continue;
                            }
                        }

                        if ($this->estaPuestoOcupado($puesto['id'], $turno, $fecha, $turnosPorPuestoFecha)) {
                            continue;
                        }

                        $disponibles = $this->trabajadores->obtenerDisponibles($puesto['id'], $turnoIdReal, $fecha);

                        if (count($disponibles) > 0) {
                            if ($turno == 3) {
                                $prioritarios = array_values(array_filter($disponibles, function($t) use ($turnosNochePorTrabajador) {
                                    return ($turnosNochePorTrabajador[$t['id']] ?? 0) < 5;
                                }));
                                $candidatos = !empty($prioritarios) ? $prioritarios : $disponibles;
                                $sel = $candidatos[array_rand($candidatos)];
                            } else {
                                $sel = $disponibles[array_rand($disponibles)];
                            }

                            $resultado = $this->turnosAsignados->asignarDirecto([
                                'trabajador_id'     => $sel['id'],
                                'puesto_trabajo_id' => $puesto['id'],
                                'turno_id'          => $turnoIdReal,
                                'fecha'             => $fecha,
                                'observaciones'     => 'Asignacion automatica'
                            ]);
                            if ($resultado['success']) {
                                if ($turno == 3) {
                                    $turnosNochePorTrabajador[$sel['id']] = ($turnosNochePorTrabajador[$sel['id']] ?? 0) + 1;
                                }
                                $turnosPorPuestoFecha[$puesto['id'] . '|' . $turno . '|' . $fecha] = true;
                                $asignaciones[] = ['fecha'=>$fecha,'puesto'=>$puesto['codigo'],'turno'=>$turno,'trabajador'=>$sel['nombre']];
                            } else {
                                $errores[] = ['fecha'=>$fecha,'puesto'=>$puesto['codigo'],'turno'=>$turno,'error'=>$resultado['message']];
                            }
                        } else {
                            $errores[] = ['fecha'=>$fecha,'puesto'=>$puesto['codigo'],'turno'=>$turno,'error'=>'Sin trabajadores disponibles'];
                        }
                    }
                }
            }

            return [
                'success'              => true,
                'asignaciones'         => count($asignaciones),
                'errores'              => count($errores),
                'libres_asignados'     => count($libresAsignados),
                'libres_errores'       => count($libresErrores),
                'detalle_asignaciones' => $asignaciones,
                'detalle_errores'      => $errores,
                'detalle_libres'       => $libresAsignados
            ];

        } catch (Throwable $e) {
            return ['success'=>false,'message'=>'Error en asignacion automatica: '.$e->getMessage()];
        }
    }

    private function calcularSemanas($mes, $anio) {
        $diasMes = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $semanas = [];
        for ($dia = 1; $dia <= $diasMes; $dia++) {
            $ts  = mktime(0,0,0,$mes,$dia,$anio);
            $dow = (int)date('N', $ts);
            $lunesTs  = $ts - ($dow - 1) * 86400;
            $lunesStr = date('Y-m-d', $lunesTs);
            $encontrado = false;
            foreach ($semanas as $sem) {
                if ($sem['lunes'] === $lunesStr) {
                    $encontrado = true;
                    break;
                }
            }
            if (!$encontrado) {
                $semanas[] = [
                    'lunes'   => $lunesStr,
                    'domingo' => date('Y-m-d', $lunesTs + 6 * 86400)
                ];
            }
        }
        return $semanas;
    }

    private function obtenerPuestos() {
        $sql  = "SELECT id, codigo, nombre, area FROM puestos_trabajo WHERE activo = TRUE ORDER BY area, codigo";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function prefetchDiasEspeciales($mes, $anio) {
        $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin = date('Y-m-t', strtotime($fechaInicio));
        $fechaInicioPrev = date('Y-m-d', strtotime($fechaInicio . ' -90 days'));

        $stmt = $this->db->prepare(
            "SELECT trabajador_id, fecha_inicio FROM dias_especiales
             WHERE tipo IN ('L','L8','LC','VAC','SUS')
             AND fecha_inicio BETWEEN ? AND ?
             AND estado IN ('programado','activo')"
        );
        $stmt->execute([$fechaInicioPrev, $fechaFin]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $libresPorTrabajador = [];
        $cargaPorFecha = [];

        foreach ($result as $row) {
            $libresPorTrabajador[$row['trabajador_id']][] = ['fecha_inicio' => $row['fecha_inicio'], 'fecha_fin' => null];
            $cargaPorFecha[$row['fecha_inicio']] = ($cargaPorFecha[$row['fecha_inicio']] ?? 0) + 1;
        }

        foreach ($libresPorTrabajador as &$fechas) {
            sort($fechas);
        }
        unset($fechas);

        return [
            'libresPorTrabajador' => $libresPorTrabajador,
            'cargaPorFecha' => $cargaPorFecha
        ];
    }

    private function tieneLibreEnRango($trabajador_id, $inicio, $fin, $libresPorTrabajador) {
        if (empty($libresPorTrabajador[$trabajador_id])) {
            return false;
        }

        foreach ($libresPorTrabajador[$trabajador_id] as $fecha) {
            if ($fecha >= $inicio && $fecha <= $fin) {
                return true;
            }
            if ($fecha > $fin) {
                break;
            }
        }

        return false;
    }

    private function obtenerUltimoLibreAntes($trabajador_id, $fecha, $libresPorTrabajador) {
        if (empty($libresPorTrabajador[$trabajador_id])) {
            return null;
        }

        $ultimo = null;
        foreach ($libresPorTrabajador[$trabajador_id] as $libre) {
            $fechaLibre = $libre['fecha_inicio'];
            if ($fechaLibre >= $fecha) {
                break;
            }
            $ultimo = $fechaLibre;
        }

        return $ultimo ? strtotime($ultimo) : null;
    }

    private function prefetchTurnosAsignados($mes, $anio) {
        $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin = date('Y-m-t', strtotime($fechaInicio));

        $stmt = $this->db->prepare(
            "SELECT ta.trabajador_id, ta.puesto_trabajo_id, ta.fecha, ct.numero_turno
             FROM turnos_asignados ta
             INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
             WHERE ta.fecha BETWEEN ? AND ?
             AND ta.estado IN ('programado','activo')"
        );
        $stmt->execute([$fechaInicio, $fechaFin]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $turnosPorTrabajadorSemana = [];
        $turnosPorPuestoFecha = [];

        foreach ($result as $row) {
            // Para L4 por semana
            $semanaKey = $this->getSemanaKey($row['fecha']);
            if (!isset($turnosPorTrabajadorSemana[$row['trabajador_id']])) {
                $turnosPorTrabajadorSemana[$row['trabajador_id']] = [];
            }
            if (!isset($turnosPorTrabajadorSemana[$row['trabajador_id']][$semanaKey])) {
                $turnosPorTrabajadorSemana[$row['trabajador_id']][$semanaKey] = [];
            }
            $turnosPorTrabajadorSemana[$row['trabajador_id']][$semanaKey][] = $row['numero_turno'];

            // Para ocupación por puesto/fecha/turno
            $key = $row['puesto_trabajo_id'] . '|' . $row['numero_turno'] . '|' . $row['fecha'];
            $turnosPorPuestoFecha[$key] = true;
        }

        return [
            'turnosPorTrabajadorSemana' => $turnosPorTrabajadorSemana,
            'turnosPorPuestoFecha' => $turnosPorPuestoFecha
        ];
    }

    private function getSemanaKey($fecha) {
        $ts = strtotime($fecha);
        $dow = (int)date('N', $ts);
        $lunesTs = $ts - ($dow - 1) * 86400;
        return date('Y-m-d', $lunesTs);
    }

    private function tieneTurnoL4EnSemana($trabajador_id, $lunes, $domingo, $turnosPorTrabajadorSemana) {
        if (!isset($turnosPorTrabajadorSemana[$trabajador_id][$lunes])) {
            return false;
        }
        $turnos = $turnosPorTrabajadorSemana[$trabajador_id][$lunes];
        return in_array(4, $turnos) || in_array(5, $turnos);
    }

    private function tieneTurnoL4EnFecha($puesto_id, $fecha, $turnosPorPuestoFecha) {
        $key4 = $puesto_id . '|4|' . $fecha;
        $key5 = $puesto_id . '|5|' . $fecha;
        return isset($turnosPorPuestoFecha[$key4]) || isset($turnosPorPuestoFecha[$key5]);
    }

    private function estaPuestoOcupado($puesto_id, $numero_turno, $fecha, $turnosPorPuestoFecha) {
        $key = $puesto_id . '|' . $numero_turno . '|' . $fecha;
        return isset($turnosPorPuestoFecha[$key]);
    }
        $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin = date('Y-m-t', strtotime($fechaInicio));
        // Última semana: del último lunes al domingo del mes anterior
        $ultimoDiaMes = strtotime($fechaFin);
        $dowUltimo = (int)date('N', $ultimoDiaMes);
        $ultimoLunes = $ultimoDiaMes - ($dowUltimo - 1) * 86400;
        $fechaInicioUltimaSemana = date('Y-m-d', $ultimoLunes);
        $fechaFinUltimaSemana = date('Y-m-d', $ultimoLunes + 6 * 86400);

        $patron = [];

        $stmt = $this->db->prepare(
            "SELECT trabajador_id, fecha_inicio FROM dias_especiales
             WHERE tipo IN ('L','L8','LC','VAC','SUS')
             AND fecha_inicio BETWEEN ? AND ?
             AND estado IN ('programado','activo')"
        );
        $stmt->execute([$fechaInicioUltimaSemana, $fechaFinUltimaSemana]);
        $libres = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($trabajadores as $trab) {
            $diasLibres = array_filter($libres, function($l) use ($trab) {
                return $l['trabajador_id'] == $trab['id'];
            });
            $diasSemana = [];
            foreach ($diasLibres as $libre) {
                $dow = (int)date('N', strtotime($libre['fecha_inicio']));
                $diasSemana[] = $dow; // 1=lunes, 7=domingo
            }
            if (!empty($diasSemana)) {
                $conteo = array_count_values($diasSemana);
                arsort($conteo);
                $diaPreferido = key($conteo); // Día más común en la última semana
                $patron[$trab['id']] = $diaPreferido;
            } else {
                $patron[$trab['id']] = null; // Sin patrón
            }
        }
        return $patron;
    }
}
?>