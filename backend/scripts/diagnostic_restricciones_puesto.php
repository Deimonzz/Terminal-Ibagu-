<?php
/**
 * Diagnóstico: Verifica si la columna puesto_trabajo_id existe
 * y si las restricciones de puesto específico se están guardando correctamente
 */

require_once dirname(dirname(dirname(__FILE__))) . '/config/database.php';

echo "<h1>🔍 Diagnóstico: Restricciones de Puesto Específico</h1>";

try {
    $db = Database::getInstance()->getConnection();
    
    // 1. Verificar la existencia de la columna
    echo "<h2>1. Existencia de columnas en restricciones_trabajador</h2>";
    
    $result = $db->query("DESCRIBE restricciones_trabajador")->fetchAll(PDO::FETCH_ASSOC);
    $columnas = array_column($result, 'Field');
    
    echo "<pre>";
    echo "Columnas existentes: " . implode(", ", $columnas) . "\n";
    echo "</pre>";
    
    $tieneColPuesto = in_array('puesto_trabajo_id', $columnas);
    $tieneColPuestoAlt = in_array('puesto_id', $columnas);
    
    echo "<p><strong>puesto_trabajo_id existe:</strong> " . ($tieneColPuesto ? "✅ SÍ" : "❌ NO") . "</p>";
    echo "<p><strong>puesto_id existe:</strong> " . ($tieneColPuestoAlt ? "✅ SÍ" : "❌ NO") . "</p>";
    
    // 2. Verificar restricciones de puesto específico guardadas
    echo "<h2>2. Restricciones de tipo 'puesto_especifico' en BD</h2>";
    
    $stmtPuesto = $db->prepare(
        "SELECT r.id, r.trabajador_id, r.tipo_restriccion, 
                r.fecha_inicio, r.fecha_fin,
                COALESCE(r.puesto_trabajo_id, r.puesto_id) as puesto_id,
                pt.codigo, pt.nombre
         FROM restricciones_trabajador r
         LEFT JOIN puestos_trabajo pt ON COALESCE(r.puesto_trabajo_id, r.puesto_id) = pt.id
         WHERE r.tipo_restriccion = 'puesto_especifico'
         LIMIT 10"
    );
    $stmtPuesto->execute();
    $restriccionesPuesto = $stmtPuesto->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Total restricciones puesto específico:</strong> " . count($restriccionesPuesto) . "</p>";
    
    if (count($restriccionesPuesto) > 0) {
        echo "<table border='1' style='border-collapse:collapse; width:100%; margin:10px 0;'>";
        echo "<tr style='background:#f0f0f0;'><th>ID</th><th>Trabajador</th><th>Puesto ID</th><th>Código Puesto</th><th>Nombre Puesto</th><th>Vigencia</th></tr>";
        foreach ($restriccionesPuesto as $r) {
            echo "<tr>";
            echo "<td>" . $r['id'] . "</td>";
            echo "<td>" . $r['trabajador_id'] . "</td>";
            echo "<td>" . ($r['puesto_id'] ?? "NULL") . "</td>";
            echo "<td>" . ($r['codigo'] ?? "NULL") . "</td>";
            echo "<td>" . ($r['nombre'] ?? "NULL") . "</td>";
            echo "<td>" . $r['fecha_inicio'] . (isset($r['fecha_fin']) ? " al " . $r['fecha_fin'] : " (indefinida)") . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Verificar si hay NULL en puesto_id
        $conNull = array_filter($restriccionesPuesto, function($r) { return is_null($r['puesto_id']); });
        if (count($conNull) > 0) {
            echo "<p style='color:red;'><strong>⚠️ PROBLEMA ENCONTRADO:</strong> " . count($conNull) . " restricción(es) tiene puesto_id = NULL</p>";
            echo "<p>Esto significa que el puesto NO se está guardando correctamente.</p>";
        }
    } else {
        echo "<p style='color:orange;'>⚠️ No hay restricciones de puesto específico registradas aún.</p>";
    }
    
    // 3. Verificar el método de detección en la clase
    echo "<h2>3. Detección de columna en la clase</h2>";
    $columnName = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
    echo "<p><strong>Resultado de Database::getColumnName():</strong> " . ($columnName ? "'$columnName'" : "NULL") . "</p>";
    
    if ($columnName) {
        echo "<p style='color:green;'>✅ La columna se detectó correctamente. Las nuevas restricciones debería guardarse OK.</p>";
    } else {
        echo "<p style='color:red;'><strong>❌ ERROR:</strong> La columna NO fue detectada. Las nuevas restricciones NO se guardarán con puesto_id.</p>";
        echo "<p>Solución: Ejecutar migración para agregar la columna.</p>";
    }
    
    // 4. Estadísticas generales
    echo "<h2>4. Estadísticas de restricciones</h2>";
    
    $stmtStats = $db->query(
        "SELECT tipo_restriccion, COUNT(*) as cantidad 
         FROM restricciones_trabajador 
         WHERE activa = true
         GROUP BY tipo_restriccion"
    );
    $stats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse:collapse;'>";
    echo "<tr style='background:#f0f0f0;'><th>Tipo Restricción</th><th>Activas</th></tr>";
    foreach ($stats as $s) {
        echo "<tr><td>" . $s['tipo_restriccion'] . "</td><td>" . $s['cantidad'] . "</td></tr>";
    }
    echo "</table>";
    
    // 5. Test de asignación automática
    echo "<h2>5. Test de Validación en Asignación</h2>";
    
    echo "<p>Para verificar que la asignación automática respeta las restricciones, prueba lo siguiente:</p>";
    echo "<ol>";
    echo "<li>Crea una restricción de puesto específico para un trabajador</li>";
    echo "<li>Corre la asignación automática</li>";
    echo "<li>Verifica que el trabajador NO tenga turnos en ese puesto</li>";
    echo "</ol>";
    
    echo "<p><strong>Acceso a debug:</strong></p>";
    echo "<ul>";
    echo "<li><a href='../scripts/debug_asignacion_prefetch.php' target='_blank'>Debug del Prefetch</a></li>";
    echo "<li><a href='../scripts/debug_restriccion_trabajador.php' target='_blank'>Debug de Restricciones por Trabajador</a></li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}
?>
