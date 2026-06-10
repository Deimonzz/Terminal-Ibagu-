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
    http_response_code(500);
    echo json_encode(['error' => 'API key no configurada. Revisa el archivo .env']);
    exit();
}

// ─── Leer body ───────────────────────────────────────────────────────────────
$input = file_get_contents('php://input');
$datos = json_decode($input, true);

if (!$datos || !isset($datos['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos']);
    exit();
}

$systemPrompt = $datos['system'] ?? '';
$mensajes     = $datos['messages'];

// ─── Truncar system prompt si es muy largo (límite seguro Groq ~24k chars) ───
$MAX_SYSTEM = 24000;
if (strlen($systemPrompt) > $MAX_SYSTEM) {
    $systemPrompt = substr($systemPrompt, 0, $MAX_SYSTEM) . "\n... (contexto truncado)";
}

// ─── Construir mensajes ───────────────────────────────────────────────────────
$messages = [];
if ($systemPrompt) {
    $messages[] = ['role' => 'system', 'content' => $systemPrompt];
}
foreach ($mensajes as $m) {
    $messages[] = ['role' => $m['role'], 'content' => $m['content']];
}

// ─── Llamar a Groq ────────────────────────────────────────────────────────────
$payload = json_encode([
    'model'       => 'llama-3.3-70b-versatile',
    'messages'    => $messages,
    'max_tokens'  => 1024,
    'temperature' => 0.6,
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
    echo json_encode(['error' => 'Error de conexión: ' . $curlError]);
    exit();
}

$groqData = json_decode($response, true);

// ─── Respuesta exitosa ────────────────────────────────────────────────────────
if (isset($groqData['choices'][0]['message']['content'])) {
    echo json_encode([
        'content' => [['type' => 'text', 'text' => $groqData['choices'][0]['message']['content']]]
    ]);
    exit();
}

// ─── Error de Groq — devolver mensaje legible sin 500 ────────────────────────
// Retornamos 200 con mensaje de error para que el JS lo muestre en el chat
$errorMsg = $groqData['error']['message'] ?? 'Error desconocido de Groq';

// Detectar error de tokens
if (strpos($errorMsg, 'token') !== false || $httpCode === 413) {
    $errorMsg = 'El contexto enviado es demasiado largo. Intenta una pregunta más específica.';
}
// Detectar rate limit
if ($httpCode === 429) {
    $errorMsg = 'Límite de solicitudes alcanzado. Espera unos segundos e intenta de nuevo.';
}
// Detectar API key inválida
if ($httpCode === 401) {
    $errorMsg = 'API key de Groq inválida o expirada.';
}

echo json_encode([
    'content' => [['type' => 'text', 'text' => '⚠️ ' . $errorMsg]]
]);