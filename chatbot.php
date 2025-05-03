<?php
// Allow frontend apps (from any domain) to access this endpoint
header("Access-Control-Allow-Origin: *");

// Response will always be in JSON format
header("Content-Type: application/json");

// Allow POST and OPTIONS HTTP methods
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Only allow Content-Type in request headers
header("Access-Control-Allow-Headers: Content-Type");

// Log errors to a file for debugging
function logError($message)
{
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents('error.log', "[$timestamp] $message\n", FILE_APPEND);
}

// Send a proper HTTP error response in JSON and stop execution
function respondWithError($code, $msg, $extra = [])
{
    http_response_code($code);
    echo json_encode(array_merge(['error' => $msg], $extra));
    exit;
}

// Handle preflight (OPTIONS) requests and exit immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(http_response_code(200));
}

// Gemini API key
$api_key = 'AIzaSyDwxxzhjFQnOT0BH8VoIc31htO6kJJv3h4';
if (!$api_key) {
    logError("API key missing");
    respondWithError(500, 'API key missing');
}

// Read raw JSON from the request body
$input = json_decode(file_get_contents("php://input"), true);
if ($input === null) {
    logError("Failed to parse JSON input: " . json_last_error_msg());
    respondWithError(400, 'Invalid JSON input');
}

// If there's no 'message' key in the request, throw a 400 error
if (empty($input['message'])) {
    logError("Missing 'message' in request");
    respondWithError(400, 'Missing "message"');
}

// Clean the user message
$user_message = trim($input['message']);

// Build the payload for Gemini API with user message
$payload = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => "You are Unimaid Resources AI, providing helpful answers. User asked: '$user_message'\nRespond in a smooth, conversational tone with Markdown formatting (e.g., **bold**, *italic*, [links](url)) where appropriate."]
            ]
        ]
    ]
];

// Make the request to Gemini API
$ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=$api_key");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30,
]);
$body = curl_exec($ch);
if ($body === false) {
    logError("cURL error: " . curl_error($ch));
    respondWithError(500, 'cURL error');
}
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Parse the response body
$parsed = json_decode($body, true);

// If something went wrong with the API or the response is empty, return an error
if ($status !== 200 || empty($parsed['candidates'][0]['content']['parts'][0]['text'])) {
    logError("Gemini API error: Status $status, Body: $body");
    respondWithError($status, 'Gemini API error', ['raw' => $body]);
}

// Extract the AI's response from the API result
$ai_response = trim($parsed['candidates'][0]['content']['parts'][0]['text']);

// Return the AI response to the frontend
echo json_encode([
    'response' => $ai_response
]);