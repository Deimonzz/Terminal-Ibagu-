<?php
/**
 * Debug: Verificar si las restricciones de puesto específico se guardan CORRECTAMENTE
 */

require_once dirname(dirname(dirname(__FILE__))) . '/config/database.php';

echo "<h1>🔍 Debug: Verificar Restricciones de Puesto Específico</h1>";

try {
    $db = Database::getInstance()->getConnection();
    
    // 1. Verificar estructura de BD
    echo "<h2>1. Estructura de Tabla restricciones_trabajador</h2>";
    if (DB_DRIVER === 'pgsql') {
        $result = $db->query("SELECT column_name, data_type FROM information_schema.columns 
                             WHERE table_name = 'restricciones_trabajador' 
                             ORDER BY ordinal_position")->fetchAll();
    } else {
        $result = $db->query("DESCRIBE restricciones_trabajador")->fetchAll();
    }
    
    echo "<pre>";
    foreach ($result as $col) {
        if (DB_DRIVER === 'pgsql') {
            echo $col['column_name'] . " (" . $col['data_type'] . ")\n";
        } else {
            echo $col['Field'] . " (" . $col['Type'] . ")\n";
        }
    }
    echo "</pre>";
    
    // 2. Verificar si hay restricciones puesto_especifico
    echo "<h2>2. Restricciones de Tipo 'puesto_especifico' en BD</h2>";
    
    $stmt = $db->query(
        "SELECT r.id, r.trabajador_id, r.tipo_restriccion, 
                r.puesto_trabajo_id, r.puesto_id, r.fecha_inicio, r.fecha_fin,
                t.nombre as trabajador, 
                pt.codigo as puesto_codigo, pt.nombre as puesto_nombre
         FROM restricciones_trabajador r
         LEFT JOIN trabajadores t ON r.trabajador_id = t.id
         LEFT JOIN puestos_trabajo pt ON COALESCE(r.puesto_trabajo_id, r.puesto_id) = pt.id
         WHERE r.tipo_restriccion = 'puesto_especifico'
         LIMIT 10"
    );
    
    $restricciones = $stmt->fetchAll();
    
    if (count($restricciones) === 0) {
        echo "<p style='color:orange;'>⚠️ NO HAY restricciones de puesto_especifico registradas</p>";
    } else {
        echo "<p>Total: " . count($restricciones) . " restricción(es)</p>";
        echo "<table border='1' style='border-collapse:collapse; width:100%;'>";
        echo "<tr style='background:#f0f0f0;'>";
        echo "<th>ID</th><th>Trabajador</th><th>puesto_trabajo_id</th><th>puesto_id</th>";
        echo "<th>Puesto (Código)</th><th>Vigencia</th><th>Estado</th>";
        echo "</tr>";
        
        foreach ($restricciones as $r) {
            echo "<tr>";
            echo "<td>" . $r['id'] . "</td>";
            echo "<td>" . $r['trabajador'] . "</td>";
            echo "<td>" . ($r['puesto_trabajo_id'] ?? 'NULL') . "</td>";
            echo "<td>" . ($r['puesto_id'] ?? 'NULL') . "</td>";
            echo "<td>" . ($r['puesto_codigo'] ?? 'NULL') . "</td>";
            echo "<td>" . $r['fecha_inicio'] . (isset($r['fecha_fin']) ? " al " . $r['fecha_fin'] : " (indefinida)") . "</td>";
            
            // Verificar si está guardado correctamente
            $puestoId = $r['puesto_trabajo_id'] ?? $r['puesto_id'];
            if (!$puestoId) {
                echo "<td style='color:red;'>❌ ERROR: Sin puesto_id</td>";
            } elseif (!$r['puesto_codigo']) {
                echo "<td style='color:red;'>❌ ERROR: Puesto no existe</td>";
            } else {
                echo "<td style='color:green;'>✅ OK</td>";
            }
            
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Verificar si hay turnos asignados a trabajadores restringidos
    echo "<h2>3. Verificar Turnos Asignados a Trabajadores Restringidos</h2>";
    
    $stmt = $db->prepare(
        "SELECT 
            t.nombre as trabajador,
            pt_rest.codigo as puesto_restringido,
            pt_asig.codigo as puesto_asignado,
            COUNT(ta.id) as cantidad_turnos
         FROM restricciones_trabajador r
         INNER JOIN trabajadores t ON r.trabajador_id = t.id
         LEFT JOIN puestos_trabajo pt_rest ON COALESCE(r.puesto_trabajo_id, r.puesto_id) = pt_rest.id
         INNER JOIN turnos_asignados ta ON ta.trabajador_id = r.trabajador_id
         INNER JOIN puestos_trabajo pt_asig ON COALESCE(ta.puesto_trabajo_id, ta.puesto_id) = pt_asig.id
         WHERE r.tipo_restriccion = 'puesto_especifico'
         AND r.activa = true
         AND COALESCE(r.puesto_trabajo_id, r.puesto_id) = COALESCE(ta.puesto_trabajo_id, ta.puesto_id)
         AND ta.estado IN ('programado', 'activo')
         AND ta.fecha >= r.fecha_inicio
         AND (r.fecha_fin IS NULL OR ta.fecha <= r.fecha_fin)
         GROUP BY t.id, pt_rest.codigo, pt_asig.codigo"
    );
    
    $stmt->execute();
    $conflictos = $stmt->fetchAll();
    
    if (count($conflictos) === 0) {
        echo "<p style='color:green;'>✅ NO HAY CONFLICTOS: Ningún trabajador tiene turnos en puesto restringido</p>";
    } else {
        echo "<p style='color:red;'><strong>❌ PROBLEMA ENCONTRADO:</strong> " . count($conflictos) . " trabajador(es) tiene(n) turnos en puesto restringido</p>";
        echo "<table border='1' style='border-collapse:collapse; width:100%;'>";
        echo "<tr style='background:#f8d7da;'>";
        echo "<th>Trabajador</th><th>Puesto Restringido</th><th>Puesto Asignado</th><th>Cantidad Turnos</th>";
        echo "</tr>";
        
        foreach ($conflictos as $c) {
            echo "<tr>";
            echo "<td>" . $c['trabajador'] . "</td>";
            echo "<td>" . $c['puesto_restringido'] . "</td>";
            echo "<td>" . $c['puesto_asignado'] . "</td>";
            echo "<td><strong>" . $c['cantidad_turnos'] . "</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 4. Verificar la BD en general
    echo "<h2>4. Diagnóstico de Columna Detectada</h2>";
    
    $columnName = Database::getColumnName('restricciones_trabajador', 'puesto_trabajo_id', 'puesto_id');
    echo "<p><strong>Columna detectada:</strong> " . ($columnName ? "'" . $columnName . "'" : "NINGUNA (NULL)") . "</p>";
    
    if (!$columnName) {
        echo "<p style='color:red;'><strong>⚠️ PROBLEMA CRÍTICO:</strong> No se puede detectar la columna puesto_trabajo_id o puesto_id</p>";
        echo "<p>Intenta ejecutar: <code>/Terminal-Ibagu-/backend/scripts/migrate_add_puesto_column.php</code></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
