<?php
$config = [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'visitas_db',
        'user' => 'root',
        'pass' => '',
        'port' => 3306
    ]
];

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['name']};charset=utf8",
        $config['db']['user'],
        $config['db']['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("❌ Error de conexión: " . $e->getMessage());
}


