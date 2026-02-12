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
        $asignaciones = [];
        $errores = [];

        $trabajadoresActivos = $this->trabajadores->obtenerTodos();

        $puestos = $this->obtenerPuestos();

        $turnos = [1, 2, 3];

        try {
            $this->db->beginTransaction();

            for ($dia = 1; $dia <= $diasMes; $dia++) {
                $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
                $diaSemana = date('N', strtotime($fecha));

                if ($diaSemana == 7 && !empty($opciones['saltar_domingos'])) {
                    continue;
                }

                foreach ($puestos as $puesto) {
                    foreach ($turnos as $turno) {
                        $disponibles = $this->trabajadores->obtenerDisponibles(
                            $puesto['id'],
                            $turno,
                            $fecha
                        );

                        if (count($disponibles) > 0) {
                            $trabajadorSeleccionado = $disponibles[array_rand($disponibles)];

                            $resultado = $this->turnosAsignados->asignar([
                                'trabajador_id' => $trabajadorSeleccionado['id'],
                                'puesto_trabajo_id' => $puesto['id'],
                                'turno_id' => $turno,
                                'fecha' => $fecha,
                                'observaciones' => 'Asignacion automatica'
                            ]);

                            if ($resultado['success']) {
                                $asignaciones[] = [
                                    'fecha' => $fecha,
                                    'puesto' => $puesto['codigo'],
                                    'turno' => $turno,
                                    'trabajador' => $trabajadorSeleccionado['nombre']
                                ];
                            } else {
                                $errores[] = [
                                    'fecha' => $fecha,
                                    'puesto' => $puesto['codigo'],
                                    'turno' => $turno,
                                    'error' => $resultado['message']
                                ];
                            }
                        } else {
                            $errores[] = [
                                'fecha' => $fecha,
                                'puesto' => $puesto['codigo'],
                                'turno' => $turno,
                                'error' => 'Sin trabajadores disponibles'
                            ];
                        }
                    }
                }
            }

            $this->db->commit();

            return [
                'success' => true,
                'asignaciones' => count($asignaciones),
                'errores' => count($errores),
                'detalle_asignaciones' => $asignaciones,
                'detalle_errores' => $errores
            ];
        } catch (Exception $e) {
            $this->db->rollback();
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