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
$is_related = isRelatedToBaselineData($user_message, $conn);

// Build the payload for Gemini API
if ($is_related) {
    // Fetch relevant data from baselinedata for related questions
    $data = fetchBaselineData($user_message, $conn);
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
function isRelatedToBaselineData($message, $conn)
{
    // Column names from baselinedata
    $columns = [
        'Response_Year',
        'State',
        'State_Pcode',
        'LGA',
        'LGA_Pcode',
        'IDP_Girls',
        'IDP_Boys',
        'IDP_Women',
        'IDP_Men',
        'IDP_Elderly_Women',
        'IDP_Elderly_Men',
        'Returnee_Girls',
        'Returnee_Boys',
        'Returnee_Women',
        'Returnee_Men',
        'Returnee_Elderly_Women',
        'Returnee_Elderly_Men',
        'Host_Community_Girls',
        'Host_Community_Boys',
        'Host_Community_Women',
        'Host_Community_Men',
        'Host_Community_Elderly_Women',
        'Host_Community_Elderly_Men'
    ];
    // Additional keywords
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
        'survey',
        'total'
    ];
    $message_lower = strtolower($message);

    // Check for column names
    foreach ($columns as $column) {
        if (strpos($message_lower, strtolower(str_replace('_', ' ', $column))) !== false) {
            return true;
        }
    }

    // Check for keywords
    foreach ($keywords as $keyword) {
        if (strpos($message_lower, strtolower($keyword)) !== false) {
            return true;
        }
    }

    // Check for numerical queries, location patterns, or data-related phrases
    if (
        preg_match('/\b(how many|count|number|total|data for)\b/i', $message) ||
        preg_match('/\b(in|at|from)\s+[a-zA-Z\s]+\b/i', $message) ||
        preg_match('/\b(\d{4})\b/', $message)
    ) {
        return true;
    }

    // Check if the message matches any data in varchar columns (State, State_Pcode, LGA, LGA_Pcode)
    $varchar_columns = ['State', 'State_Pcode', 'LGA', 'LGA_Pcode'];
    foreach ($varchar_columns as $column) {
        $stmt = $conn->prepare("SELECT 1 FROM baselinedata WHERE $column = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $message);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows > 0) {
                $stmt->close();
                return true;
            }
            $stmt->close();
        } else {
            logError("Failed to prepare statement for checking $column: " . $conn->error);
        }
    }

    return false;
}

// Structure the user message and data for Gemini
function buildPayload($message, $data, $is_related)
{
    // Table structure for context
    $table_structure = "The baselinedata table in the fredometer database has the following columns:\n";
    $table_structure .= "- Response_Year (int): Year of the data\n";
    $table_structure .= "- State (varchar): State name (e.g., Borno, Adamawa)\n";
    $table_structure .= "- State_Pcode (varchar): State postal code (e.g., NG002)\n";
    $table_structure .= "- LGA (varchar): Local Government Area (e.g., Fufore, Maiduguri)\n";
    $table_structure .= "- LGA_Pcode (varchar): LGA postal code (e.g., NGM001)\n";
    $table_structure .= "- IDP_Girls (int): Number of internally displaced girls\n";
    $table_structure .= "- IDP_Boys (int): Number of internally displaced boys\n";
    $table_structure .= "- IDP_Women (int): Number of internally displaced women\n";
    $table_structure .= "- IDP_Men (int): Number of internally displaced men\n";
    $table_structure .= "- IDP_Elderly_Women (int): Number of internally displaced elderly women\n";
    $table_structure .= "- IDP_Elderly_Men (int): Number of internally displaced elderly men\n";
    $table_structure .= "- Returnee_Girls (int): Number of returnee girls\n";
    $table_structure .= "- Returnee_Boys (int): Number of returnee boys\n";
    $table_structure .= "- Returnee_Women (int): Number of returnee women\n";
    $table_structure .= "- Returnee_Men (int): Number of returnee men\n";
    $table_structure .= "- Returnee_Elderly_Women (int): Number of returnee elderly women\n";
    $table_structure .= "- Returnee_Elderly_Men (int): Number of returnee elderly men\n";
    $table_structure .= "- Host_Community_Girls (int): Number of host community girls\n";
    $table_structure .= "- Host_Community_Boys (int): Number of host community boys\n";
    $table_structure .= "- Host_Community_Women (int): Number of host community women\n";
    $table_structure .= "- Host_Community_Men (int): Number of host community men\n";
    $table_structure .= "- Host_Community_Elderly_Women (int): Number of host community elderly women\n";
    $table_structure .= "- Host_Community_Elderly_Men (int): Number of host community elderly men\n";

    if ($is_related) {
        // Summarize data for related questions
        $data_summary = "Relevant data from baselinedata:\n";
        if (empty($data)) {
            $data_summary .= "No specific data found for the query.\n";
        } else {
            foreach ($data as $row) {
                $data_summary .= sprintf(
                    "- Year: %s, State: %s, State_Pcode: %s, LGA: %s, LGA_Pcode: %s, " .
                        "IDP Girls: %d, IDP Boys: %d, IDP Women: %d, IDP Men: %d, " .
                        "IDP Elderly Women: %d, IDP Elderly Men: %d, " .
                        "Returnee Girls: %d, Returnee Boys: %d, Returnee Women: %d, Returnee Men: %d, " .
                        "Returnee Elderly Women: %d, Returnee Elderly Men: %d, " .
                        "Host Community Girls: %d, Host Community Boys: %d, Host Community Women: %d, Host Community Men: %d, " .
                        "Host Community Elderly Women: %d, Host Community Elderly Men: %d\n",
                    $row['Response_Year'] ?? 'N/A',
                    $row['State'] ?? 'N/A',
                    $row['State_Pcode'] ?? 'N/A',
                    $row['LGA'] ?? 'N/A',
                    $row['LGA_Pcode'] ?? 'N/A',
                    $row['IDP_Girls'] ?? 0,
                    $row['IDP_Boys'] ?? 0,
                    $row['IDP_Women'] ?? 0,
                    $row['IDP_Men'] ?? 0,
                    $row['IDP_Elderly_Women'] ?? 0,
                    $row['IDP_Elderly_Men'] ?? 0,
                    $row['Returnee_Girls'] ?? 0,
                    $row['Returnee_Boys'] ?? 0,
                    $row['Returnee_Women'] ?? 0,
                    $row['Returnee_Men'] ?? 0,
                    $row['Returnee_Elderly_Women'] ?? 0,
                    $row['Returnee_Elderly_Men'] ?? 0,
                    $row['Host_Community_Girls'] ?? 0,
                    $row['Host_Community_Boys'] ?? 0,
                    $row['Host_Community_Women'] ?? 0,
                    $row['Host_Community_Men'] ?? 0,
                    $row['Host_Community_Elderly_Women'] ?? 0,
                    $row['Host_Community_Elderly_Men'] ?? 0
                );
            }
        }
        $prompt = "You are FREDOMETER ASSISTANT, an expert on the baselinedata table in the fredometer database. User asked: '$message'\n\nTable structure:\n$table_structure\n\n$data_summary\nProvide a precise, conversational answer with Markdown formatting (e.g., **bold**, *italic*, [links](url)) where appropriate. Use the table structure and data to answer accurately, focusing on the specific values or columns mentioned (e.g., LGA like Fufore, State_Pcode like NG002). If no data is relevant, explain clearly and suggest related questions about the baselinedata table.";
    } else {
        // Smart response for unrelated questions
        $prompt = "You are FREDOMETER ASSISTANT, specialized in the baselinedata table in the fredometer database. User asked: '$message'\n\nTable structure:\n$table_structure\n\nThis question seems unrelated to the baselinedata table. Respond in a friendly, conversational tone with Markdown formatting, explaining that you focus on baselinedata (e.g., IDP_Girls, Returnee_Women, LGA like Fufore, State_Pcode like NG002) and suggesting the user ask a relevant question about the table's data or structure. Do not offer to browse the internet.";
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
function fetchBaselineData($message, $conn)
{
    $query = "SELECT Response_Year, State, State_Pcode, LGA, LGA_Pcode, " .
        "IDP_Girls, IDP_Boys, IDP_Women, IDP_Men, IDP_Elderly_Women, IDP_Elderly_Men, " .
        "Returnee_Girls, Returnee_Boys, Returnee_Women, Returnee_Men, Returnee_Elderly_Women, Returnee_Elderly_Men, " .
        "Host_Community_Girls, Host_Community_Boys, Host_Community_Women, Host_Community_Men, " .
        "Host_Community_Elderly_Women, Host_Community_Elderly_Men FROM baselinedata";
    $params = [];
    $types = "";
    $conditions = [];

    $message_lower = strtolower($message);

    // Filter by State or LGA
    if (preg_match('/\b(Borno|Lagos|Adamawa|Yobe|Kano|Maiduguri|Jigawa|Kaduna|Fufore)\b/i', $message, $matches)) {
        $state_or_lga = $matches[1];
        $conditions[] = "(State = ? OR LGA = ?)";
        $params[] = $state_or_lga;
        $params[] = $state_or_lga;
        $types .= "ss";
    }

    // Filter by year
    if (preg_match('/\b(\d{4})\b/', $message, $matches)) {
        $year = $matches[1];
        $conditions[] = "Response_Year = ?";
        $params[] = $year;
        $types .= "i";
    }

    // Filter by specific column mentions
    $columns = [
        'IDP_Girls',
        'IDP_Boys',
        'IDP_Women',
        'IDP_Men',
        'IDP_Elderly_Women',
        'IDP_Elderly_Men',
        'Returnee_Girls',
        'Returnee_Boys',
        'Returnee_Women',
        'Returnee_Men',
        'Returnee_Elderly_Women',
        'Returnee_Elderly_Men',
        'Host_Community_Girls',
        'Host_Community_Boys',
        'Host_Community_Women',
        'Host_Community_Men',
        'Host_Community_Elderly_Women',
        'Host_Community_Elderly_Men',
        'State_Pcode',
        'LGA_Pcode'
    ];
    foreach ($columns as $column) {
        if (strpos($message_lower, strtolower(str_replace('_', ' ', $column))) !== false) {
            $conditions[] = "$column IS NOT NULL AND $column != 0";
        }
    }

    // Filter by specific data values in varchar columns (State, State_Pcode, LGA, LGA_Pcode)
    $varchar_columns = ['State', 'State_Pcode', 'LGA', 'LGA_Pcode'];
    foreach ($varchar_columns as $column) {
        $conditions[] = "$column = ?";
        $params[] = $message;
        $types .= "s";
    }

    // Combine conditions with OR for data value search
    if (!empty($conditions)) {
        $query .= " WHERE (" . implode(" OR ", $conditions) . ")";
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