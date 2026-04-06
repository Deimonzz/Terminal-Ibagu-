<?php
$config = require 'config/database.php';
$conn = new mysqli($config['host'], $config['user'], $config['pass'], $config['db']);
if ($conn->connect_error) die('Error: ' . $conn->connect_error);
$result = $conn->query('SELECT hora_inicio, hora_fin FROM supervisores_turno LIMIT 5');
while ($row = $result->fetch_assoc()) {
    echo 'hora_inicio: ' . $row['hora_inicio'] . ', hora_fin: ' . $row['hora_fin'] . PHP_EOL;
}
$conn->close();
?>