<?php
// test_conexion.php - Diagnóstico completo

// Copia tu configuración aquí temporalmente
$host = 'dpg-d7keg17avr4c73c8au2g-a';  // Tu host de Render
$port = '5432';
$dbname = 'gestion_turnos_vxle';
$user = 'gestion_turnos_vxle_user';
$password = 'mB1w3VK1c8NHwr500RzPOlr34sw1cb4g';  // ¡Cámbiala!

echo "<h2>Diagnóstico de conexión a PostgreSQL en Render</h2>";

// Mostrar datos (ocultar contraseña parcialmente)
echo "Host: " . $host . "<br>";
echo "Port: " . $port . "<br>";
echo "Database: " . $dbname . "<br>";
echo "User: " . $user . "<br>";
echo "Password: " . substr($password, 0, 3) . "..." . "<br><br>";

try {
    // Intentar conexión directa con PDO
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ];
    
    $conn = new PDO($dsn, $user, $password, $options);
    echo "✅ <strong style='color:green'>Conexión exitosa!</strong><br>";
    
    // Probar consulta
    $stmt = $conn->query("SELECT version()");
    $version = $stmt->fetch();
    echo "📦 Versión de PostgreSQL: " . $version['version'] . "<br>";
    
    // Ver tablas existentes
    $stmt = $conn->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
    $tables = $stmt->fetchAll();
    
    if (count($tables) > 0) {
        echo "<br>📋 Tablas encontradas:<br><ul>";
        foreach ($tables as $table) {
            echo "<li>" . $table['tablename'] . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<br>⚠️ No hay tablas en la base de datos. Necesitas crearlas.";
    }
    
} catch (PDOException $e) {
    echo "❌ <strong style='color:red'>Error de conexión:</strong><br>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
?>