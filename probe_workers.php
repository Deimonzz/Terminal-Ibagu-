<?php
require 'config/database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->query('SELECT id, nombre, activo FROM trabajadores ORDER BY id LIMIT 20');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
