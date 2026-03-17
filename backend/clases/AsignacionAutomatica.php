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
        $this->trabajadores = new Trabajadores();
    }

    public function asignarMesCompleto($mes, $anio, $opciones = []) {
        $diasMes = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
        $asignaciones  = [];
        $errores       = [];
        $libresAsignados = [];
        $libresErrores   = [];

        // ── Cargar datos base ──────────────────────────────────────────────
        $trabajadoresActivos = $this->db->query(
            "SELECT id, nombre FROM trabajadores WHERE activo = true ORDER BY nombre"
        )->fetchAll();

        $puestos = $this->obtenerPuestos();

        $turnosConfig = $this->db->query(
            "SELECT id, numero_turno FROM configuracion_turnos ORDER BY numero_turno"
        )->fetchAll();

        $turnoIdPorNumero = [];
        foreach ($turnosConfig as $tc) {
            if (in_array($tc['numero_turno'], [1, 2, 3])) {
                $turnoIdPorNumero[$tc['numero_turno']] = $tc['id'];
            }
        }
        $turnos = !empty($turnoIdPorNumero) ? array_keys($turnoIdPorNumero) : [1, 2, 3];

        // Calcular semanas del mes
        $semanas = [];
        for ($dia = 1; $dia <= $diasMes; $dia++) {
            $ts  = mktime(0,0,0, $mes, $dia, $anio);
            $dow = (int)date('N', $ts);
            $lunesTs  = $ts - ($dow - 1) * 86400;
            $lunesStr = date('Y-m-d', $lunesTs);
            if (!in_array($lunesStr, array_column($semanas, 'lunes'))) {
                $semanas[] = [
                    'lunes'   => $lunesStr,
                    'domingo' => date('Y-m-d', $lunesTs + 6 * 86400)
                ];
            }
        }

        try {

            // ══════════════════════════════════════════════════════════════
            // PASO 1: DÍAS LIBRES — primero para que los turnos los respeten
            // ══════════════════════════════════════════════════════════════
            foreach ($semanas as $semana) {
                foreach ($trabajadoresActivos as $trab) {
                    // ¿Ya tiene libre esta semana?
                    $chkL = $this->db->prepare(
                        "SELECT COUNT(*) as cnt FROM dias_especiales
                         WHERE trabajador_id = ? AND tipo IN ('L','L8','LC','VAC','SUS')
                         AND fecha_inicio BETWEEN ? AND ? AND estado IN ('programado','activo')"
                    );
                    $chkL->execute([$trab['id'], $semana['lunes'], $semana['domingo']]);
                    if ((int)$chkL->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) continue;

                    // Último libre (para secuencia fluida entre meses)
                    $chkUltimo = $this->db->prepare(
                        "SELECT fecha_inicio FROM dias_especiales
                         WHERE trabajador_id = ? AND tipo IN ('L','L8','LC','VAC','SUS')
                         AND fecha_inicio < ? AND estado IN ('programado','activo')
                         ORDER BY fecha_inicio DESC LIMIT 1"
                    );
                    $chkUltimo->execute([$trab['id'], $semana['lunes']]);
                    $ultimoLibre   = $chkUltimo->fetch(PDO::FETCH_ASSOC);
                    $tsUltimoLibre = $ultimoLibre ? strtotime($ultimoLibre['fecha_inicio']) : null;

                    // Límite: máx 4 libres por día para garantizar cobertura mínima
                    // Con ~42 trabajadores necesitamos al menos 34 disponibles cada día
                    $MAX_LIBRES_DIA = 4;

                    // Elegir el día con menos libres acumulados, respetando el límite
                    $mejorDia   = null;
                    $menorCarga = PHP_INT_MAX;

                    for ($d = 0; $d <= 6; $d++) {
                        $ts2 = strtotime($semana['lunes']) + $d * 86400;
                        if ((int)date('n', $ts2) != (int)$mes) continue;
                        if ($tsUltimoLibre && ($ts2 - $tsUltimoLibre) < (5 * 86400)) continue;
                        $fechaLibre = date('Y-m-d', $ts2);

                        $chkC = $this->db->prepare(
                            "SELECT COUNT(*) as cnt FROM dias_especiales
                             WHERE tipo IN ('L','L8','LC','VAC','SUS')
                             AND fecha_inicio = ? AND estado IN ('programado','activo')"
                        );
                        $chkC->execute([$fechaLibre]);
                        $carga = (int)$chkC->fetch(PDO::FETCH_ASSOC)['cnt'];

                        // Descartar días que ya tienen demasiados libres
                        if ($carga >= $MAX_LIBRES_DIA) continue;

                        if ($carga < $menorCarga) {
                            $menorCarga = $carga;
                            $mejorDia   = $fechaLibre;
                        }
                    }

                    // Fallback 1: ignorar límite de 5 días pero respetar límite de carga
                    if (!$mejorDia) {
                        for ($d = 0; $d <= 6; $d++) {
                            $ts2 = strtotime($semana['lunes']) + $d * 86400;
                            if ((int)date('n', $ts2) != (int)$mes) continue;
                            $fechaLibre = date('Y-m-d', $ts2);
                            $chkC = $this->db->prepare(
                                "SELECT COUNT(*) as cnt FROM dias_especiales
                                 WHERE tipo IN ('L','L8','LC','VAC','SUS')
                                 AND fecha_inicio = ? AND estado IN ('programado','activo')"
                            );
                            $chkC->execute([$fechaLibre]);
                            $carga = (int)$chkC->fetch(PDO::FETCH_ASSOC)['cnt'];
                            if ($carga >= $MAX_LIBRES_DIA) continue;
                            if ($carga < $menorCarga) { $menorCarga = $carga; $mejorDia = $fechaLibre; }
                        }
                    }

                    // Fallback 2: el día con menos carga aunque supere el límite
                    if (!$mejorDia) {
                        $menorCarga = PHP_INT_MAX;
                        for ($d = 0; $d <= 6; $d++) {
                            $ts2 = strtotime($semana['lunes']) + $d * 86400;
                            if ((int)date('n', $ts2) != (int)$mes) continue;
                            $fechaLibre = date('Y-m-d', $ts2);
                            $chkC = $this->db->prepare(
                                "SELECT COUNT(*) as cnt FROM dias_especiales
                                 WHERE tipo IN ('L','L8','LC','VAC','SUS')
                                 AND fecha_inicio = ? AND estado IN ('programado','activo')"
                            );
                            $chkC->execute([$fechaLibre]);
                            $carga = (int)$chkC->fetch(PDO::FETCH_ASSOC)['cnt'];
                            if ($carga < $menorCarga) { $menorCarga = $carga; $mejorDia = $fechaLibre; }
                        }
                    }

                    if ($mejorDia) {
                        try {
                            $ins = $this->db->prepare(
                                "INSERT INTO dias_especiales
                                 (trabajador_id, tipo, fecha_inicio, fecha_fin, descripcion, estado)
                                 VALUES (?, 'L', ?, NULL, 'AUTO: generado automáticamente — puede eliminarse si no es conveniente', 'programado')"
                            );
                            $ins->execute([$trab['id'], $mejorDia]);

                            $libresAsignados[] = [
                                'trabajador' => $trab['nombre'],
                                'fecha'      => $mejorDia,
                                'semana'     => $semana['lunes'] . ' al ' . $semana['domingo']
                            ];
                        } catch (Exception $eL) {
                            $libresErrores[] = [
                                'trabajador' => $trab['nombre'],
                                'semana'     => $semana['lunes'],
                                'error'      => $eL->getMessage()
                            ];
                        }
                    }
                }
            }

            // ══════════════════════════════════════════════════════════════
            // PASO 2: TURNOS L4 (4 horas, 1 por trabajador por semana)
            // Se asignan antes que los turnos normales para reservar esos días
            // ══════════════════════════════════════════════════════════════
            $puestosL4Map   = ['F5' => 9, 'F15' => 9, 'D2' => 10, 'D1' => 10, 'F11' => 9];
            $puestosL4Turno = ['F5' => 1, 'F15' => 1, 'D2' => 2, 'D1' => 2, 'F11' => 1];

            $stmtPuestosL4 = $this->db->prepare(
                "SELECT id, codigo FROM puestos_trabajo
                 WHERE codigo IN ('F5','F15','D2','D1','F11') AND activo = TRUE"
            );
            $stmtPuestosL4->execute();
            $puestosL4Info = $stmtPuestosL4->fetchAll();

            $stmtChkL4Trab = $this->db->prepare(
                "SELECT COUNT(*) as cnt FROM turnos_asignados ta
                 INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                 WHERE ta.trabajador_id = ?
                 AND ct.numero_turno IN (4, 5)
                 AND ta.fecha BETWEEN ? AND ?
                 AND ta.estado IN ('programado','activo')"
            );

            $stmtChkL4Puesto = $this->db->prepare(
                "SELECT COUNT(*) as cnt FROM turnos_asignados
                 WHERE puesto_trabajo_id = ? AND turno_id = ? AND fecha = ?
                 AND estado IN ('programado','activo')"
            );

            foreach ($semanas as $semana) {
                foreach ($trabajadoresActivos as $trab) {
                    $stmtChkL4Trab->execute([$trab['id'], $semana['lunes'], $semana['domingo']]);
                    if ($stmtChkL4Trab->fetch()['cnt'] > 0) continue;

                    $diasSemana = [];
                    for ($d = 0; $d <= 6; $d++) {
                        $ts = strtotime($semana['lunes']) + $d * 86400;
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
                            if ($stmtChkL4Puesto->fetch()['cnt'] > 0) continue;

                            $disponibles = $this->trabajadores->obtenerDisponibles(
                                $puesto['id'], $turnoIdL4, $fechaL4
                            );
                            $estaDisponible = array_filter($disponibles, fn($t) => $t['id'] == $trab['id']);
                            if (empty($estaDisponible)) continue;

                            $resultado = $this->turnosAsignados->asignar([
                                'trabajador_id'     => $trab['id'],
                                'puesto_trabajo_id' => $puesto['id'],
                                'turno_id'          => $turnoIdL4,
                                'fecha'             => $fechaL4,
                                'observaciones'     => 'Asignacion automatica L4'
                            ]);

                            if ($resultado['success']) {
                                $asignaciones[] = [
                                    'fecha'      => $fechaL4,
                                    'puesto'     => $puesto['codigo'],
                                    'turno'      => 'L4',
                                    'trabajador' => $trab['nombre']
                                ];
                                $asignado = true;
                                break;
                            }
                        }
                    }

                    if (!$asignado) {
                        $errores[] = [
                            'fecha'  => $semana['lunes'] . ' al ' . $semana['domingo'],
                            'puesto' => 'L4',
                            'turno'  => 'L4',
                            'error'  => 'Sin puesto L4 disponible para ' . $trab['nombre']
                        ];
                    }
                }
            }

            // ══════════════════════════════════════════════════════════════
            // PASO 3: TURNOS NORMALES (T1, T2, T3)
            // Los días libres ya están en BD → obtenerDisponibles los respeta
            // ══════════════════════════════════════════════════════════════
            $stmtOcupPuesto = $this->db->prepare(
                "SELECT COUNT(*) as cnt FROM turnos_asignados ta
                 INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                 WHERE ta.puesto_trabajo_id = :pid
                 AND ct.numero_turno = :nturno
                 AND ta.fecha = :fecha
                 AND ta.estado IN ('programado','activo')"
            );

            $stmtTieneL4 = $this->db->prepare(
                "SELECT COUNT(*) as cnt FROM turnos_asignados ta
                 INNER JOIN configuracion_turnos ct ON ta.turno_id = ct.id
                 WHERE ta.puesto_trabajo_id = :pid
                 AND ct.numero_turno IN (4, 5)
                 AND ta.fecha = :fecha
                 AND ta.estado IN ('programado','activo')"
            );

            for ($dia = 1; $dia <= $diasMes; $dia++) {
                $fecha     = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

                foreach ($puestos as $puesto) {
                    foreach ($turnos as $turno) {
                        // T3 solo en puestos nocturnos
                        $puestosNocturnos = ['V1','V2','C','D3','F6','F11'];
                        if ($turno == 3 && !in_array(strtoupper($puesto['codigo']), $puestosNocturnos)) continue;

                        $turnoIdReal  = $turnoIdPorNumero[$turno] ?? $turno;
                        $codigoPuesto = strtoupper($puesto['codigo']);

                        // Si el puesto tiene L4 ese día, no asignar turno normal
                        if (isset($puestosL4Turno[$codigoPuesto]) && $puestosL4Turno[$codigoPuesto] == $turno) {
                            $stmtTieneL4->execute([':pid' => $puesto['id'], ':fecha' => $fecha]);
                            if ($stmtTieneL4->fetch()['cnt'] > 0) continue;
                        }

                        // Puesto ya ocupado en este turno ese día
                        $stmtOcupPuesto->execute([':pid' => $puesto['id'], ':nturno' => $turno, ':fecha' => $fecha]);
                        if ($stmtOcupPuesto->fetch()['cnt'] > 0) continue;

                        // obtenerDisponibles ya excluye: turno ese día, incapacidades, días libres
                        $disponibles = $this->trabajadores->obtenerDisponibles(
                            $puesto['id'], $turnoIdReal, $fecha
                        );

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
                                $asignaciones[] = [
                                    'fecha'      => $fecha,
                                    'puesto'     => $puesto['codigo'],
                                    'turno'      => $turno,
                                    'trabajador' => $sel['nombre']
                                ];
                            } else {
                                $errores[] = [
                                    'fecha'  => $fecha,
                                    'puesto' => $puesto['codigo'],
                                    'turno'  => $turno,
                                    'error'  => $resultado['message']
                                ];
                            }
                        } else {
                            $errores[] = [
                                'fecha'  => $fecha,
                                'puesto' => $puesto['codigo'],
                                'turno'  => $turno,
                                'error'  => 'Sin trabajadores disponibles'
                            ];
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
            return [
                'success' => false,
                'message' => 'Error en asignacion automatica: ' . $e->getMessage()
            ];
        }
    }

    private function obtenerPuestos() {
        $sql = "SELECT id, codigo, nombre, area FROM puestos_trabajo WHERE activo = TRUE ORDER BY area, codigo";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
?>