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

    public function asignarMesCompleto($mes, $anio, $opciones = []) {
        $diasMes       = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
        $asignaciones  = [];
        $errores       = [];
        $libresAsignados = [];
        $libresErrores   = [];

        // ── Datos base ──────────────────────────────────────────
        $trabajadoresActivos = $this->db->query(
            "SELECT id, nombre FROM trabajadores WHERE activo = true ORDER BY nombre"
        )->fetchAll();

        $puestos = $this->obtenerPuestos();

        $turnosConfig = $this->db->query(
            "SELECT id, numero_turno FROM configuracion_turnos ORDER BY numero_turno"
        )->fetchAll();
        $turnoIdPorNumero = [];
        foreach ($turnosConfig as $tc) {
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

        // Máximo libres permitidos el mismo día para garantizar cobertura
        $MAX_LIBRES_DIA = 4;

        try {
            // ════════════════════════════════════════════════════
            // PASO 1 — DÍAS LIBRES
            // Reglas:
            //   · 1 libre por trabajador por semana
            //   · Solo lunes a viernes (NO sábado/domingo)
            //   · Mínimo 6 días de diferencia con su libre anterior
            //   · Máximo MAX_LIBRES_DIA trabajadores libres el mismo día
            // ════════════════════════════════════════════════════

            // Calcular semanas del mes
            $semanas = $this->calcularSemanas($mes, $anio);

            $stmtChkLibreSemana = $this->db->prepare(
                "SELECT COUNT(*) as cnt FROM dias_especiales
                 WHERE trabajador_id = ? AND tipo IN ('L','L8','LC','VAC','SUS')
                 AND fecha_inicio BETWEEN ? AND ? AND estado IN ('programado','activo')"
            );
            $stmtUltimoLibre = $this->db->prepare(
                "SELECT fecha_inicio FROM dias_especiales
                 WHERE trabajador_id = ? AND tipo IN ('L','L8','LC','VAC','SUS')
                 AND fecha_inicio < ? AND estado IN ('programado','activo')
                 ORDER BY fecha_inicio DESC LIMIT 1"
            );
            $stmtCargaDia = $this->db->prepare(
                "SELECT COUNT(*) as cnt FROM dias_especiales
                 WHERE tipo IN ('L','L8','LC','VAC','SUS')
                 AND fecha_inicio = ? AND estado IN ('programado','activo')"
            );
            $stmtInsLibre = $this->db->prepare(
                "INSERT INTO dias_especiales
                 (trabajador_id, tipo, fecha_inicio, fecha_fin, descripcion, estado)
                 VALUES (?, 'L', ?, NULL, 'AUTO: generado automáticamente', 'programado')"
            );

            foreach ($semanas as $semana) {
                foreach ($trabajadoresActivos as $trab) {
                    // ¿Ya tiene libre esta semana?
                    $stmtChkLibreSemana->execute([$trab['id'], $semana['lunes'], $semana['domingo']]);
                    if ((int)$stmtChkLibreSemana->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) continue;

                    // Último libre (para calcular separación mínima de 6 días)
                    $stmtUltimoLibre->execute([$trab['id'], $semana['lunes']]);
                    $rowUltimo     = $stmtUltimoLibre->fetch(PDO::FETCH_ASSOC);
                    $tsUltimoLibre = $rowUltimo ? strtotime($rowUltimo['fecha_inicio']) : null;

                    // Candidatos: lunes a viernes de la semana dentro del mes
                    // Ordenados por carga ascendente (el día con menos libres primero)
                    $candidatos = [];
                    for ($d = 0; $d <= 6; $d++) {
                        $ts       = strtotime($semana['lunes']) + $d * 86400;
                        $dow      = (int)date('N', $ts); // 1=lun, 7=dom
                        $fechaDia = date('Y-m-d', $ts);

                        // Solo lunes a viernes
                        if ($dow > 5) continue;
                        // Solo dentro del mes
                        if ((int)date('n', $ts) != (int)$mes) continue;
                        // Respetar separación mínima de 6 días
                        if ($tsUltimoLibre && ($ts - $tsUltimoLibre) < (6 * 86400)) continue;

                        $stmtCargaDia->execute([$fechaDia]);
                        $carga = (int)$stmtCargaDia->fetch(PDO::FETCH_ASSOC)['cnt'];
                        if ($carga < $MAX_LIBRES_DIA) {
                            $candidatos[] = ['fecha' => $fechaDia, 'carga' => $carga];
                        }
                    }

                    // Fallback 1: ignorar separación mínima pero respetar límite de carga
                    if (empty($candidatos)) {
                        for ($d = 0; $d <= 6; $d++) {
                            $ts       = strtotime($semana['lunes']) + $d * 86400;
                            $dow      = (int)date('N', $ts);
                            $fechaDia = date('Y-m-d', $ts);
                            if ($dow > 5) continue;
                            if ((int)date('n', $ts) != (int)$mes) continue;
                            $stmtCargaDia->execute([$fechaDia]);
                            $carga = (int)$stmtCargaDia->fetch(PDO::FETCH_ASSOC)['cnt'];
                            if ($carga < $MAX_LIBRES_DIA) {
                                $candidatos[] = ['fecha' => $fechaDia, 'carga' => $carga];
                            }
                        }
                    }

                    // Fallback 2: cualquier día entre semana aunque supere el límite
                    if (empty($candidatos)) {
                        for ($d = 0; $d <= 6; $d++) {
                            $ts       = strtotime($semana['lunes']) + $d * 86400;
                            $dow      = (int)date('N', $ts);
                            $fechaDia = date('Y-m-d', $ts);
                            if ($dow > 5) continue;
                            if ((int)date('n', $ts) != (int)$mes) continue;
                            $stmtCargaDia->execute([$fechaDia]);
                            $carga = (int)$stmtCargaDia->fetch(PDO::FETCH_ASSOC)['cnt'];
                            $candidatos[] = ['fecha' => $fechaDia, 'carga' => $carga];
                        }
                    }

                    if (empty($candidatos)) {
                        $libresErrores[] = ['trabajador' => $trab['nombre'], 'semana' => $semana['lunes'], 'error' => 'Sin día entre semana disponible'];
                        continue;
                    }

                    // Elegir el de menor carga
                    usort($candidatos, fn($a,$b) => $a['carga'] - $b['carga']);
                    $mejorDia = $candidatos[0]['fecha'];

                    try {
                        $stmtInsLibre->execute([$trab['id'], $mejorDia]);
                        $libresAsignados[] = ['trabajador' => $trab['nombre'], 'fecha' => $mejorDia];
                    } catch (Exception $eL) {
                        $libresErrores[] = ['trabajador' => $trab['nombre'], 'semana' => $semana['lunes'], 'error' => $eL->getMessage()];
                    }
                }
            }

            // ════════════════════════════════════════════════════
            // PASO 2 — TURNOS L4
            // Reglas:
            //   · 1 L4 por trabajador por semana
            //   · Solo lunes a viernes
            //   · El trabajador debe estar disponible ese día
            // ════════════════════════════════════════════════════

            $stmtChkL4Trab = $this->db->prepare(
                "SELECT COUNT(*) as cnt FROM turnos_asignados ta
                 INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                 WHERE ta.trabajador_id = ? AND ct.numero_turno IN (4,5)
                 AND ta.fecha BETWEEN ? AND ? AND ta.estado IN ('programado','activo')"
            );
            $stmtChkL4Puesto = $this->db->prepare(
                "SELECT COUNT(*) as cnt FROM turnos_asignados
                 WHERE puesto_trabajo_id = ? AND turno_id = ? AND fecha = ?
                 AND estado IN ('programado','activo')"
            );

            foreach ($semanas as $semana) {
                foreach ($trabajadoresActivos as $trab) {
                    $stmtChkL4Trab->execute([$trab['id'], $semana['lunes'], $semana['domingo']]);
                    if ((int)$stmtChkL4Trab->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) continue;

                    // Días disponibles entre semana
                    $diasSemana = [];
                    for ($d = 0; $d <= 6; $d++) {
                        $ts  = strtotime($semana['lunes']) + $d * 86400;
                        $dow = (int)date('N', $ts);
                        if ($dow > 5) continue; // solo lun-vie
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

                            $stmtChkL4Puesto->execute([$puesto['id'], $turnoIdL4, $fechaL4]);
                            if ((int)$stmtChkL4Puesto->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) continue;

                            $disponibles = $this->trabajadores->obtenerDisponibles(
                                $puesto['id'], $turnoIdL4, $fechaL4
                            );
                            $disponible = array_filter($disponibles, fn($t) => $t['id'] == $trab['id']);
                            if (empty($disponible)) continue;

                            $resultado = $this->turnosAsignados->asignar([
                                'trabajador_id'     => $trab['id'],
                                'puesto_trabajo_id' => $puesto['id'],
                                'turno_id'          => $turnoIdL4,
                                'fecha'             => $fechaL4,
                                'observaciones'     => 'Asignacion automatica L4'
                            ]);

                            if ($resultado['success']) {
                                $asignaciones[] = ['fecha'=>$fechaL4,'puesto'=>$puesto['codigo'],'turno'=>'L4','trabajador'=>$trab['nombre']];
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
            // Los días libres ya están en BD → obtenerDisponibles los respeta
            // ════════════════════════════════════════════════════

            $stmtOcupPuesto = $this->db->prepare(
                "SELECT COUNT(*) as cnt FROM turnos_asignados ta
                 INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                 WHERE ta.puesto_trabajo_id = :pid AND ct.numero_turno = :nturno
                 AND ta.fecha = :fecha AND ta.estado IN ('programado','activo')"
            );
            $stmtTieneL4 = $this->db->prepare(
                "SELECT COUNT(*) as cnt FROM turnos_asignados ta
                 INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                 WHERE ta.puesto_trabajo_id = :pid AND ct.numero_turno IN (4,5)
                 AND ta.fecha = :fecha AND ta.estado IN ('programado','activo')"
            );

            for ($dia = 1; $dia <= $diasMes; $dia++) {
                $fecha    = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

                foreach ($puestos as $puesto) {
                    foreach ($turnos as $turno) {
                        if ($turno == 3 && !in_array(strtoupper($puesto['codigo']), $puestosNocturnos)) continue;

                        $turnoIdReal  = $turnoIdPorNumero[$turno] ?? $turno;
                        $codigoPuesto = strtoupper($puesto['codigo']);

                        // Si el puesto tiene L4 ese día, no asignar el turno normal que sustituye
                        if (isset($puestosL4Turno[$codigoPuesto]) && $puestosL4Turno[$codigoPuesto] == $turno) {
                            $stmtTieneL4->execute([':pid'=>$puesto['id'],':fecha'=>$fecha]);
                            if ((int)$stmtTieneL4->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) continue;
                        }

                        $stmtOcupPuesto->execute([':pid'=>$puesto['id'],':nturno'=>$turno,':fecha'=>$fecha]);
                        if ((int)$stmtOcupPuesto->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) continue;

                        $disponibles = $this->trabajadores->obtenerDisponibles($puesto['id'], $turnoIdReal, $fecha);

                        if (count($disponibles) > 0) {
                            $sel = $disponibles[array_rand($disponibles)];
                            $resultado = $this->turnosAsignados->asignar([
                                'trabajador_id'     => $sel['id'],
                                'puesto_trabajo_id' => $puesto['id'],
                                'turno_id'          => $turnoIdReal,
                                'fecha'             => $fecha,
                                'observaciones'     => 'Asignacion automatica'
                            ]);
                            if ($resultado['success']) {
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

        } catch (Exception $e) {
            return ['success'=>false,'message'=>'Error en asignacion automatica: '.$e->getMessage()];
        }
    }

    private function calcularSemanas($mes, $anio) {
        $diasMes = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
        $semanas = [];
        for ($dia = 1; $dia <= $diasMes; $dia++) {
            $ts  = mktime(0,0,0,$mes,$dia,$anio);
            $dow = (int)date('N', $ts);
            $lunesTs  = $ts - ($dow - 1) * 86400;
            $lunesStr = date('Y-m-d', $lunesTs);
            if (!in_array($lunesStr, array_column($semanas,'lunes'))) {
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
}
?>