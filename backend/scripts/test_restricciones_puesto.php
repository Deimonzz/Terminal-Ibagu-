<?php
/**
 * Test: Validar que las restricciones de puesto_especifico funcionan
 */

require_once dirname(dirname(dirname(__FILE__))) . '/config/database.php';
require_once dirname(dirname(__FILE__)) . '/clases/AsignacionAutomatica.php';

echo "<h1>🧪 Test: Restricciones de Puesto Específico</h1>";

try {
    $db = Database::getInstance()->getConnection();
    $asignacion = new AsignacionAutomatica();
    
    // 1. Obtener un trabajador activo
    echo "<h2>1. Búsqueda de Trabajador Activo</h2>";
    $stmt = $db->query("SELECT id, nombre FROM trabajadores WHERE activo = true LIMIT 1");
    $trabajador = $stmt->fetch();
    
    if (!$trabajador) {
        echo "<p style='color:red;'>❌ No hay trabajadores activos</p>";
        exit;
    }
    
    echo "<p>Trabajador: <strong>" . $trabajador['nombre'] . "</strong> (ID: " . $trabajador['id'] . ")</p>";
    $trabajadorId = $trabajador['id'];
    
    // 2. Obtener un puesto
    echo "<h2>2. Búsqueda de Puesto</h2>";
    $stmt = $db->query("SELECT id, codigo, nombre FROM puestos_trabajo WHERE activo = true LIMIT 1");
    $puesto = $stmt->fetch();
    
    if (!$puesto) {
        echo "<p style='color:red;'>❌ No hay puestos activos</p>";
        exit;
    }
    
    echo "<p>Puesto: <strong>" . $puesto['codigo'] . "</strong> (ID: " . $puesto['id'] . ")</p>";
    $puestoId = $puesto['id'];
    
    // 3. Verificar si ya tiene restricción
    echo "<h2>3. Verificar Restricciones Existentes</h2>";
    $stmt = $db->prepare(
        "SELECT * FROM restricciones_trabajador 
         WHERE trabajador_id = ? AND tipo_restriccion = 'puesto_especifico' 
         AND activa = true LIMIT 1"
    );
    $stmt->execute([$trabajadorId]);
    $restriccionExistente = $stmt->fetch();
    
    if ($restriccionExistente) {
        echo "<p style='color:orange;'>⚠️ Ya tiene restricción puesto_especifico: puesto_id = " . 
             ($restriccionExistente['puesto_trabajo_id'] ?? $restriccionExistente['puesto_id'] ?? 'NULL') . "</p>";
        $puestoId = $restriccionExistente['puesto_trabajo_id'] ?? $restriccionExistente['puesto_id'];
    } else {
        // 4. Crear una restricción
        echo "<h2>4. Crear Restricción Puesto Específico</h2>";
        
        $columnName = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
        echo "<p>Columna detectada: <strong>" . ($columnName ? $columnName : "NINGUNA (NULL)") . "</strong></p>";
        
        if ($columnName) {
            $sql = "INSERT INTO restricciones_trabajador (trabajador_id, tipo_restriccion, $columnName, fecha_inicio, activa)
                    VALUES (?, 'puesto_especifico', ?, ?, 1)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$trabajadorId, $puestoId, date('Y-m-d')]);
            echo "<p style='color:green;'>✅ Restricción creada para trabajador=" . $trabajadorId . ", puesto=" . $puestoId . "</p>";
        } else {
            echo "<p style='color:red;'>❌ No se pudo detectar la columna para guardar puesto_id</p>";
        }
    }
    
    // 5. Test del prefetch y getDisponibles
    echo "<h2>5. Test: Prefetch y Validación en Memoria</h2>";
    
    $mes = (int)date('m');
    $anio = (int)date('Y');
    $fecha = date('Y-m-d');
    
    // Usar reflexión para acceder a método privado
    $reflection = new ReflectionClass($asignacion);
    $prefetchMethod = $reflection->getMethod('prefetchDisponibilidadMes');
    $prefetchMethod->setAccessible(true);
    
    $ctx = $prefetchMethod->invoke($asignacion, $mes, $anio);
    
    echo "<p>Restricciones cargadas en prefetch: " . count($ctx['restricciones']) . "</p>";
    
    // Buscar si nuestra restricción está en el prefetch
    $restriccionEnPrefetch = array_filter($ctx['restricciones'], function($r) use ($trabajadorId, $puestoId) {
        return $r['trabajador_id'] == $trabajadorId && $r['tipo_restriccion'] === 'puesto_especifico';
    });
    
    if (count($restriccionEnPrefetch) > 0) {
        $r = array_values($restriccionEnPrefetch)[0];
        echo "<p style='color:green;'>✅ Restricción ENCONTRADA en prefetch</p>";
        echo "<pre>";
        echo "  trabajador_id: " . $r['trabajador_id'] . "\n";
        echo "  tipo_restriccion: " . $r['tipo_restriccion'] . "\n";
        echo "  puesto_trabajo_id: " . ($r['puesto_trabajo_id'] ?? 'NULL') . "\n";
        echo "</pre>";
        
        if ($r['puesto_trabajo_id'] === null || $r['puesto_trabajo_id'] === '') {
            echo "<p style='color:red;'>❌ ERROR: puesto_trabajo_id es NULL en prefetch</p>";
        }
    } else {
        echo "<p style='color:orange;'>⚠️ Restricción NO encontrada en prefetch (podría no estar en vigencia)</p>";
    }
    
    // 6. Test getDisponibles
    echo "<h2>6. Test: Validación getDisponibles</h2>";
    
    $getDisponiblesMethod = $reflection->getMethod('getDisponibles');
    $getDisponiblesMethod->setAccessible(true);
    
    $conteoTurnos = [];
    $disponibles = $getDisponiblesMethod->invoke($asignacion, $puestoId, 1, $ctx, $conteoTurnos);
    
    $trabajadorDisponible = array_filter($disponibles, function($t) use ($trabajadorId) {
        return $t['id'] == $trabajadorId;
    });
    
    if (count($trabajadorDisponible) > 0) {
        echo "<p style='color:red;'>❌ ERROR: Trabajador con restricción aparece como DISPONIBLE</p>";
        echo "<p>El fix NO está funcionando correctamente</p>";
    } else {
        echo "<p style='color:green;'>✅ OK: Trabajador con restricción aparece como BLOQUEADO</p>";
        echo "<p>El fix está funcionando correctamente</p>";
    }
    
    echo "<h2>7. Conclusión</h2>";
    echo "<p>Si en el paso 6 viste ✅ OK, el problema está RESUELTO.</p>";
    echo "<p>Si viste ❌ ERROR, aún hay un problema que investigar.</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
