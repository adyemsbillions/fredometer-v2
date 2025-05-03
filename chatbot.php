<?php
//good day sir i clearly commented it so you can see how it works i also use this same file for my db processing you can use another if you wanter a cleaner work
// Allow frontend apps (from any domain) to access this endpoint
header("Access-Control-Allow-Origin: *");

// Response will always be in JSON format
header("Content-Type: application/json");

// Allow POST and OPTIONS HTTP methods
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Only allow Content-Type in request headers
header("Access-Control-Allow-Headers: Content-Type");

// Database connection config
$servername = "localhost";
$username = "unimaid9_chatbot";
$password = "#adyems123AD";
$dbname = "unimaid9_chatbot";

// Connect to MySQL database
$conn = new mysqli($servername, $username, $password, $dbname);

// If connection fails, return an error and stop
if ($conn->connect_error) respondWithError(500, 'DB connection failed');

// Handle preflight (OPTIONS) requests and exit immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(http_response_code(200));

// Gemini API key – this is used to authorize the request
$api_key = 'AIzaSyDwxxzhjFQnOT0BH8VoIc31htO6kJJv3h4';
if (!$api_key) respondWithError(500, 'API key missing');

// Read raw JSON from the request body
$input = json_decode(file_get_contents("php://input"), true);

// If there's no 'message' key in the request, throw a 400 error
if (empty($input['message'])) respondWithError(400, 'Missing "message"');

// Clean the user message
$user_message = trim($input['message']);

// Use session ID as conversation ID, or generate a unique one
$conversation_id = session_id() ?: uniqid();

// Save the user message in the DB
storeMessage($conversation_id, 'user', $user_message);

// Build the payload Gemini expects
$payload = buildPayload($user_message);

// Make the request to Gemini API
$response = callGeminiAPI($api_key, $payload);

// Parse the response body
$parsed = json_decode($response['body'], true);

// If something went wrong with the API or the response is empty, return an error
if ($response['status_code'] !== 200 || empty($parsed['candidates'][0]['content']['parts'][0]['text'])) {
    respondWithError($response['status_code'], 'Gemini API error', ['raw' => $response['body']]);
}

// Extract the AI's response from the API result
$ai_response = trim($parsed['candidates'][0]['content']['parts'][0]['text']);

// Save the AI's message to the DB
storeMessage($conversation_id, 'ai', $ai_response);

// Fetch full chat history for this conversation
$history = getHistory($conversation_id);

// Return the AI response and history to the frontend
echo json_encode([
    'response' => $ai_response,
    'history' => $history
]);


// === Helpers === //

// Send a proper HTTP error response in JSON and stop execution
function respondWithError($code, $msg, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(['error' => $msg], $extra));
    exit;
}

// Structure the user message the way Gemini expects it
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

// Send a POST request to Gemini using curl
function callGeminiAPI($key, $data) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=$key";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,            // Get the response as a string
        CURLOPT_POST => true,                      // Use POST method
        CURLOPT_POSTFIELDS => json_encode($data),  // JSON-encoded body
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,                     // Timeout in 30 seconds
    ]);
    $body = curl_exec($ch);                        // Execute request
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Get HTTP status
    curl_close($ch);                               // Close the connection
    return ['body' => $body, 'status_code' => $status];
}

// Store a message in the conversation_history table
function storeMessage($id, $role, $msg) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO conversation_history (conversation_id, role, message) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $id, $role, $msg);
    $stmt->execute();
    $stmt->close();
}

// Get full message history for a given conversation ID
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
