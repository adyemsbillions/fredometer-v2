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

// Database connection config
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "fredometer";

// Connect to MySQL database
$conn = new mysqli($servername, $username, $password, $dbname);

// If connection fails, log and return an error
if ($conn->connect_error) {
    logError("DB connection failed: " . $conn->connect_error);
    respondWithError(500, 'DB connection failed');
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

// Check if the message is related to baselinedata
$is_related = isRelatedToBaselineData($user_message);

// Build the payload for Gemini API
if ($is_related) {
    // Fetch relevant data from baselinedata for related questions
    $data = fetchBaselineData($user_message);
    $payload = buildPayload($user_message, $data, true);
} else {
    // For unrelated questions, let Gemini provide a smart response
    $payload = buildPayload($user_message, [], false);
}

// Make the request to Gemini API
$response = callGeminiAPI($api_key, $payload);

// Parse the response body
$parsed = json_decode($response['body'], true);

// If something went wrong with the API or the response is empty, return an error
if ($response['status_code'] !== 200 || empty($parsed['candidates'][0]['content']['parts'][0]['text'])) {
    logError("Gemini API error: Status {$response['status_code']}, Body: {$response['body']}");
    respondWithError($response['status_code'], 'Gemini API error', ['raw' => $response['body']]);
}

// Extract the AI's response
$ai_response = trim($parsed['candidates'][0]['content']['parts'][0]['text']);

// Return the AI response to the frontend
echo json_encode([
    'response' => $ai_response
]);

// === Helpers === //

// Check if the message is related to baselinedata
function isRelatedToBaselineData($message)
{
    // Expanded keywords for better detection
    $keywords = [
        'IDP',
        'internally displaced',
        'displaced person',
        'Returnee',
        'Host Community',
        'Girls',
        'Boys',
        'Women',
        'Men',
        'Elderly',
        'population',
        'demographic',
        'State',
        'LGA',
        'Year',
        'Response Year',
        'Pcode',
        'Borno',
        'Lagos',
        'Adamawa',
        'Yobe',
        'Kano',
        'Maiduguri',
        'Jigawa',
        'Kaduna',
        'data',
        'statistics',
        'count',
        'number of',
        'how many',
        'report',
        'survey'
    ];
    $message_lower = strtolower($message);
    foreach ($keywords as $keyword) {
        if (strpos($message_lower, strtolower($keyword)) !== false) {
            return true;
        }
    }
    // Check for numerical queries or patterns like "in [location]"
    if (
        preg_match('/\b(how many|count|number|total)\b/i', $message) ||
        preg_match('/\b(in|at|from)\s+[a-zA-Z\s]+\b/i', $message)
    ) {
        return true;
    }
    return false;
}

// Structure the user message and data for Gemini
function buildPayload($message, $data, $is_related)
{
    if ($is_related) {
        // Summarize data for related questions
        $data_summary = "Baseline data from fredometer database (fields: Response_Year, State, LGA, IDP_Girls, IDP_Boys, Returnee_Women, Host_Community_Men, etc.):\n";
        if (empty($data)) {
            $data_summary .= "No specific data found for the query, but you can provide general insights.\n";
        } else {
            foreach ($data as $row) {
                $data_summary .= sprintf(
                    "- Year: %s, State: %s, LGA: %s, IDP Girls: %d, IDP Boys: %d, Returnee Women: %d, Host Community Men: %d\n",
                    $row['Response_Year'] ?? 'N/A',
                    $row['State'] ?? 'N/A',
                    $row['LGA'] ?? 'N/A',
                    $row['IDP_Girls'] ?? 0,
                    $row['IDP_Boys'] ?? 0,
                    $row['Returnee_Women'] ?? 0,
                    $row['Host_Community_Men'] ?? 0
                );
            }
        }
        $prompt = "You are Unimaid Resources AI, an expert on baseline data about IDP, Returnee, and Host Community populations. User asked: '$message'\n$data_summary\nProvide a clear, conversational answer with Markdown formatting (e.g., **bold**, *italic*, [links](url)) where appropriate. If no data is relevant, explain clearly and suggest related questions.";
    } else {
        // Smart response for unrelated questions
        $prompt = "You are Unimaid Resources AI, specialized in baseline data about IDP, Returnee, and Host Community populations (e.g., IDP_Girls, Returnee_Women, State, LGA). User asked: '$message'\nThis question seems unrelated to the baseline data. Respond in a friendly, conversational tone with Markdown formatting, suggesting the user ask about baseline data or offering to clarify their question. Do not offer to browse the internet.";
    }
    return [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];
}

// Send a POST request to Gemini using curl
function callGeminiAPI($key, $data)
{
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
    if ($body === false) {
        logError("cURL error: " . curl_error($ch));
        respondWithError(500, 'cURL error');
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'status_code' => $status];
}

// Fetch relevant data from baselinedata table based on user message
function fetchBaselineData($message)
{
    global $conn;
    $query = "SELECT Response_Year, State, LGA, IDP_Girls, IDP_Boys, Returnee_Women, Host_Community_Men FROM baselinedata";
    $params = [];
    $types = "";

    // Enhanced keyword extraction
    $message_lower = strtolower($message);
    if (preg_match('/\b(Borno|Lagos|Adamawa|Yobe|Kano|Maiduguri|Jigawa|Kaduna)\b/i', $message, $matches)) {
        $state_or_lga = $matches[1];
        $query .= " WHERE State = ? OR LGA = ?";
        $params[] = $state_or_lga;
        $params[] = $state_or_lga;
        $types .= "ss";
    } elseif (preg_match('/\b(\d{4})\b/', $message, $matches)) {
        // Filter by year if mentioned
        $year = $matches[1];
        $query .= " WHERE Response_Year = ?";
        $params[] = $year;
        $types .= "i";
    }

    $query .= " LIMIT 5";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logError("Failed to prepare statement for fetching data: " . $conn->error);
        respondWithError(500, 'Failed to prepare statement');
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        logError("Failed to fetch data: " . $stmt->error);
        respondWithError(500, 'Failed to fetch data');
    }

    $res = $stmt->get_result();
    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }

    $stmt->close();
    return $data;
}