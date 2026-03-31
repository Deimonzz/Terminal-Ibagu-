<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit();
}

// ─── API KEY desde .env (nunca hardcodear aquí) ──────────────────────────────
$envFile = dirname(dirname(__DIR__)) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        $_ENV[$key] = $val;
    }
}
$GROQ_API_KEY = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?? '';
if (!$GROQ_API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'API key no configurada. Revisa el archivo .env']);
    exit();
}
// ─────────────────────────────────────────────────────────────────────────────

$input = file_get_contents('php://input');
$datos = json_decode($input, true);

if (!$datos || !isset($datos['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos']);
    exit();
}

$systemPrompt = $datos['system'] ?? '';
$mensajes     = $datos['messages'];

// Construir mensajes en formato OpenAI (compatible con Groq)
$messages = [];

if ($systemPrompt) {
    $messages[] = ['role' => 'system', 'content' => $systemPrompt];
}

foreach ($mensajes as $m) {
    $messages[] = [
        'role'    => $m['role'], // 'user' o 'assistant'
        'content' => $m['content']
    ];
}

$payload = json_encode([
    'model'       => 'llama-3.3-70b-versatile', // Modelo gratuito de Groq, muy capaz
    'messages'    => $messages,
    'max_tokens'  => 2048,
    'temperature' => 0.7,
]);

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $GROQ_API_KEY
    ],
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'curl error: ' . $curlError]);
    exit();
}

$groqData = json_decode($response, true);

if (isset($groqData['choices'][0]['message']['content'])) {
    $texto = $groqData['choices'][0]['message']['content'];
    // Devolver en el mismo formato que espera ia-asistente.js
    echo json_encode([
        'content' => [['type' => 'text', 'text' => $texto]]
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'error'    => 'Error de Groq',
        'httpCode' => $httpCode,
        'respuesta'=> $groqData
    ]);
}
?>