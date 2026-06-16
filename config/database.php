<?php
// database.php - Configuración para LOCAL y PRODUCCIÓN (MySQL)

// Detectar automáticamente el entorno
// Usa la variable de entorno APP_ENV. Por defecto usa LOCAL
$appEnv = getenv('APP_ENV') ?: 'ENV';
$isProduction = strtoupper($appEnv) === 'PRODUCTION';

if ($isProduction) {
    // ========== CONFIGURACIÓN PARA PRODUCCIÓN (MySQL) ==========
    define('DB_HOST', getenv('DB_HOST') ?: 'sql300.infinityfree.com');
    define('DB_NAME', getenv('DB_NAME') ?: 'if0_42018119_gestion_turnos');
    define('DB_USER', getenv('DB_USER') ?: 'if0_42018119');
    define('DB_PASS', getenv('DB_PASS') ?: 'UObmwULK5bhn');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
} else {
    // ========== CONFIGURACIÓN PARA LOCAL (XAMPP - MySQL) ==========
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_NAME', getenv('DB_NAME') ?: 'gestion_turnos_db');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: '');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
}

// Driver (MySQL para ambos ambientes)
define('DB_DRIVER', 'mysql');

define('DB_CHARSET', 'utf8mb4');

date_default_timezone_set('America/Bogota');

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            // Conectar a MySQL
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            $isProduction = strtoupper(getenv('APP_ENV') ?: 'LOCAL') === 'PRODUCTION';
            $errorMsg = $isProduction ? 'Error de conexión con la base de datos' : 'Error de conexión: ' . $e->getMessage();
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

    public static function hasColumn($table, $column) {
        try {
            $db = self::getInstance()->getConnection();
            $sql = "SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = :table 
                    AND column_name = :column";
            $stmt = $db->prepare($sql);
            $stmt->execute([':table' => $table, ':column' => $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function getColumnName($table, $preferred, $fallback = null) {
        if (self::hasColumn($table, $preferred)) {
            return $preferred;
        }
        if ($fallback && self::hasColumn($table, $fallback)) {
            return $fallback;
        }
        return null;
    }

    private function __clone() {}
    public function __wakeup() {}

    public static function groupConcat($column, $separator = ', ') {
        $distinct = false;
        if (stripos($column, 'DISTINCT ') === 0) {
            $distinct = true;
            $column = substr($column, 9);
        }

        return "GROUP_CONCAT(" . ($distinct ? 'DISTINCT ' : '') . "$column SEPARATOR '$separator')";
    }

    public static function currentDate() {
        return 'CURDATE()';
    }

    public static function year($column) {
        return "YEAR($column)";
    }
    
    public static function month($column) {
        return "MONTH($column)";
    }

    public static function day($column) {
        return "DAY($column)";
    }

    public static function dateFormat($column, $format) {
        return "DATE_FORMAT($column, '$format')";
    }
    
    public static function dateDiff($date1, $date2) {
        return "DATEDIFF($date1, $date2)";
    }

    public static function ifNull($column, $default) {
        return "IFNULL($column, '$default')";
    }

    public static function concat(...$parts) {
        return "CONCAT(" . implode(", ", $parts) . ")";
    }

    public static function now() {
        return 'NOW()';
    }
}

// Headers para API REST
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