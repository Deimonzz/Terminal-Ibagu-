<?php

// ============================================
// CONFIGURACIÓN AUTOMÁTICA (Local vs Render)
// ============================================

// Detectar si estamos en Render (entorno de producción)
$isRender = getenv('RENDER') !== false || getenv('DB_HOST_RENDER') !== false;

if ($isRender) {
    // ========== CONFIGURACIÓN PARA RENDER (PostgreSQL) ==========
    define('DB_HOST', getenv('DB_HOST_RENDER') ?: 'dpg-xxxxx.oregon-postgres.render.com');  // Cambiar por el Hostname de Render
    define('DB_NAME', getenv('DB_NAME_RENDER') ?: 'gestion_turnos_vxle');                    // El nombre que te dio Render
    define('DB_USER', getenv('DB_USER_RENDER') ?: 'gestion_turnos_vxle_user');               // El usuario de Render
    define('DB_PASS', getenv('DB_PASS_RENDER') ?: 'tu_contraseña_aqui');                     // La contraseña de Render
    define('DB_DRIVER', 'pgsql');  // PostgreSQL driver
    define('DB_PORT', getenv('DB_PORT_RENDER') ?: '5432');
} else {
    // ========== CONFIGURACIÓN PARA LOCAL (XAMPP - MySQL) ==========
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'gestion_turnos_db');     // Tu BD local con XAMPP
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_DRIVER', 'mysql');               // MySQL driver
    define('DB_PORT', '3306');
}

define('DB_CHARSET', 'utf8mb4');

date_default_timezone_set('America/Bogota');

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            // Construir el DSN según el driver (MySQL o PostgreSQL)
            $dsn = DB_DRIVER . ":host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
            
            // Para MySQL también incluimos charset
            if (DB_DRIVER === 'mysql') {
                $dsn .= ";charset=" . DB_CHARSET;
            }
            
            // Opciones comunes para PDO
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
        } catch (PDOException $e) {
            $isRender = getenv('RENDER') !== false;
            $errorMsg = $isRender ? 'Error de conexión con la base de datos' : 'Error de conexión: ' . $e->getMessage();
            die(json_encode(['success' => false, 'message' => $errorMsg]));
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }

    private function __clone() {}
    public function __wakeup() {}
}

// Headers para API REST (solo si no se han enviado)
if (!headers_sent()) {
    header('Content-type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}
?>