<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$servername = "localhost";
$username = "unimaid9_chatbot";
$password = "#adyems123AD";
$dbname = "unimaid9_chatbot";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) respondWithError(500, 'DB connection failed');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(http_response_code(200));

$api_key = 'AIzaSyDwxxzhjFQnOT0BH8VoIc31htO6kJJv3h4';
if (!$api_key) respondWithError(500, 'API key missing');

$input = json_decode(file_get_contents("php://input"), true);
if (empty($input['message'])) respondWithError(400, 'Missing "message"');

$user_message = trim($input['message']);
$conversation_id = session_id() ?: uniqid();
storeMessage($conversation_id, 'user', $user_message);

$payload = buildPayload($user_message);
$response = callGeminiAPI($api_key, $payload);

$parsed = json_decode($response['body'], true);
if ($response['status_code'] !== 200 || empty($parsed['candidates'][0]['content']['parts'][0]['text'])) {
    respondWithError($response['status_code'], 'Gemini API error', ['raw' => $response['body']]);
}

$ai_response = trim($parsed['candidates'][0]['content']['parts'][0]['text']);
storeMessage($conversation_id, 'ai', $ai_response);
$history = getHistory($conversation_id);

echo json_encode([
    'response' => $ai_response,
    'history' => $history
]);

// === Helpers === //
function respondWithError($code, $msg, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(['error' => $msg], $extra));
    exit;
}

function buildPayload($message) {
    return [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => "You are Unimaid Resources AI.\nUser: $message"]
                ]
            ]
        ]
    ];
}

function callGeminiAPI($key, $data) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=$key";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'status_code' => $status];
}

function storeMessage($id, $role, $msg) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO conversation_history (conversation_id, role, message) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $id, $role, $msg);
    $stmt->execute();
    $stmt->close();
}

function getHistory($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT role, message FROM conversation_history WHERE conversation_id = ? ORDER BY timestamp ASC");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $history = [];
    while ($row = $res->fetch_assoc()) $history[] = $row;
    $stmt->close();
    return $history;
}
?>
