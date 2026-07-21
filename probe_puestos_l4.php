<?php
require 'config/database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT id, codigo, activo FROM puestos_trabajo WHERE codigo IN ('D2','D1','F11','F5','F15') ORDER BY codigo");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
