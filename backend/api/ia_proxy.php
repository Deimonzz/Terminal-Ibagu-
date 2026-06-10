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

// ─── API KEY ─────────────────────────────────────────────────────────────────
$envFile = dirname(dirname(__DIR__)) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $_ENV[trim($parts[0])] = trim($parts[1]);
    }
}
$GROQ_API_KEY = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?? '';
if (!$GROQ_API_KEY) {
    echo json_encode(['content' => [['type' => 'text', 'text' => '⚠️ API key de Groq no configurada.']]]);
    exit();
}

// ─── Leer body ────────────────────────────────────────────────────────────────
$input = file_get_contents('php://input');
$datos = json_decode($input, true);

if (!$datos || !isset($datos['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos']);
    exit();
}

// ─── Truncar system prompt (límite seguro ~24k chars) ─────────────────────────
$systemPrompt = $datos['system'] ?? '';
if (strlen($systemPrompt) > 24000) {
    $systemPrompt = substr($systemPrompt, 0, 24000) . "\n... (contexto truncado)";
}

// ─── Construir mensajes ───────────────────────────────────────────────────────
$messages = [];
if ($systemPrompt) {
    $messages[] = ['role' => 'system', 'content' => $systemPrompt];
}
foreach (($datos['messages'] ?? []) as $m) {
    $messages[] = ['role' => $m['role'], 'content' => $m['content']];
}

$payload = json_encode([
    'model'       => 'llama-3.3-70b-versatile',
    'messages'    => $messages,
    'max_tokens'  => 1024,
    'temperature' => 0.6,
]);

$groqUrl = 'https://api.groq.com/openai/v1/chat/completions';
$headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $GROQ_API_KEY,
];

// ─── Intentar con curl primero, luego file_get_contents ───────────────────────
$response  = false;
$httpCode  = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($groqUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $response === false) {
        $response = false; // fallback a file_get_contents
    }
}

// Fallback: file_get_contents con stream_context
if ($response === false && ini_get('allow_url_fopen')) {
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 60,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ]
    ]);
    $response = @file_get_contents($groqUrl, false, $context);
    // Obtener HTTP code de los headers de respuesta
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $h, $m)) {
                $httpCode = (int)$m[1];
            }
        }
    }
}

// Si ninguno funcionó
if ($response === false || $response === '') {
    echo json_encode(['content' => [['type' => 'text', 'text' => '⚠️ No se pudo conectar con Groq. Este hosting puede tener bloqueadas las conexiones externas. Considera mover el proxy a Render.']]]);
    exit();
}

// ─── Procesar respuesta ───────────────────────────────────────────────────────
$groqData = json_decode($response, true);

if (isset($groqData['choices'][0]['message']['content'])) {
    echo json_encode([
        'content' => [['type' => 'text', 'text' => $groqData['choices'][0]['message']['content']]]
    ]);
    exit();
}

// Error de Groq — mensaje legible
$errorMsg = $groqData['error']['message'] ?? 'Error desconocido';
if ($httpCode === 429)       $errorMsg = 'Límite de solicitudes alcanzado. Espera unos segundos.';
if ($httpCode === 401)       $errorMsg = 'API key de Groq inválida o expirada.';
if ($httpCode === 413 || strpos($errorMsg, 'token') !== false)
                             $errorMsg = 'Contexto demasiado largo. Intenta una pregunta más específica.';

echo json_encode([
    'content' => [['type' => 'text', 'text' => '⚠️ ' . $errorMsg]]
]);