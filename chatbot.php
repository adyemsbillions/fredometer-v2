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

// Sanitize user input to prevent SQL injection
function sanitizeInput($input)
{
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
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

// Clean and sanitize the user message
$user_message = sanitizeInput($input['message']);

// Check if the message is related to baselinedata, pindata, or severitydata
$is_related = isRelatedToData($user_message, $conn);

// Check if the question is specifically about "in need"
$is_in_need = stripos($user_message, 'in need') !== false;

// Build the payload for Gemini API
if ($is_related) {
    if ($is_in_need) {
        // Fetch only pindata for "in need" questions
        $data = fetchPinDataOnly($user_message, $conn);
        $payload = buildPayload($user_message, $data, true, true);
    } else {
        // Fetch data from all tables for other related questions
        $data = fetchData($user_message, $conn);
        $payload = buildPayload($user_message, $data, true, false);
    }
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

// Check if the message is related to baselinedata, pindata, or severitydata
function isRelatedToData($message, $conn)
{
    // Column names from all tables
    $columns = [
        'Response_Year',
        'State',
        'State_Pcode',
        'LGA',
        'LGA_Pcode',
        'LGA_pCode',
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
        'Sector',
        'IDP',
        'Returnees',
        'Host_Community',
        'Final'
    ];
    // Additional keywords
    $keywords = [
        'IDP',
        'internally displaced',
        'displaced person',
        'Returnee',
        'Returnees',
        'Host Community',
        'Girls',
        'Boys',
        'Women',
        'Men',
        'Elderly',
        'population',
        'demographic',
        'severity',
        'final',
        'Pcode',
        'pCode',
        'Borno',
        'Lagos',
        'Adamawa',
        'Yobe',
        'Kano',
        'Maiduguri',
        'Jigawa',
        'Kaduna',
        'Fufore',
        'data',
        'statistics',
        'count',
        'number of',
        'how many',
        'report',
        'survey',
        'total',
        'sector',
        'health',
        'education',
        'protection',
        'people in need',
        'in need'
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

    // Check if the message matches any data in varchar columns of all tables
    $varchar_columns = ['State', 'State_Pcode', 'LGA', 'LGA_Pcode', 'LGA_pCode', 'Sector'];
    $tables = ['baselinedata', 'pindata', 'severitydata'];
    foreach ($tables as $table) {
        foreach ($varchar_columns as $column) {
            // Skip columns not in the table
            if ($table === 'baselinedata' && $column === 'Sector') continue;
            if ($table === 'baselinedata' && $column === 'LGA_pCode') continue;
            if (($table === 'pindata' || $table === 'severitydata') && $column === 'LGA_Pcode') continue;
            $stmt = $conn->prepare("SELECT 1 FROM $table WHERE $column = ? LIMIT 1");
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
                logError("Failed to prepare statement for checking $column in $table: " . $conn->error);
            }
        }
    }

    return false;
}

// Structure the user message and data for Gemini
function buildPayload($message, $data, $is_related, $is_in_need = false)
{
    // Table structures for context
    $table_structure = "The fredometer database has three tables: baselinedata, pindata, and severitydata.\n\n";
    $table_structure .= "**baselinedata table** (general demographic data):\n";
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
    $table_structure .= "- Host_Community_Elderly_Men (int): Number of host community elderly men\n\n";
    $table_structure .= "**pindata table** (People in Need Data, detailing populations requiring assistance across sectors like Health, Education, Protection):\n";
    $table_structure .= "- Response_Year (int): Year of the data\n";
    $table_structure .= "- Sector (varchar): Sector of need (e.g., Health, Education, Protection)\n";
    $table_structure .= "- State (varchar): State name (e.g., Borno, Adamawa)\n";
    $table_structure .= "- State_Pcode (varchar): State postal code (e.g., NG002)\n";
    $table_structure .= "- LGA (varchar): Local Government Area (e.g., Fufore, Maiduguri)\n";
    $table_structure .= "- LGA_pCode (varchar): LGA postal code (e.g., NGM001)\n";
    $table_structure .= "- IDP_Girls (int): Number of internally displaced girls in need\n";
    $table_structure .= "- IDP_Boys (int): Number of internally displaced boys in need\n";
    $table_structure .= "- IDP_Women (int): Number of internally displaced women in need\n";
    $table_structure .= "- IDP_Men (int): Number of internally displaced men in need\n";
    $table_structure .= "- IDP_Elderly_Women (int): Number of internally displaced elderly women in need\n";
    $table_structure .= "- IDP_Elderly_Men (int): Number of internally displaced elderly men in need\n";
    $table_structure .= "- Returnee_Girls (int): Number of returnee girls in need\n";
    $table_structure .= "- Returnee_Boys (int): Number of returnee boys in need\n";
    $table_structure .= "- Returnee_Women (int): Number of returnee women in need\n";
    $table_structure .= "- Returnee_Men (int): Number of returnee men in need\n";
    $table_structure .= "- Returnee_Elderly_Women (int): Number of returnee elderly women in need\n";
    $table_structure .= "- Returnee_Elderly_Men (int): Number of returnee elderly men in need\n";
    $table_structure .= "- Host_Community_Girls (int): Number of host community girls in need\n";
    $table_structure .= "- Host_Community_Boys (int): Number of host community boys in need\n";
    $table_structure .= "- Host_Community_Women (int): Number of host community women in need\n";
    $table_structure .= "- Host_Community_Men (int): Number of host community men in need\n";
    $table_structure .= "- Host_Community_Elderly_Women (int): Number of host community elderly women in need\n";
    $table_structure .= "- Host_Community_Elderly_Men (int): Number of host community elderly men in need\n\n";
    $table_structure .= "**severitydata table** (severity metrics):\n";
    $table_structure .= "- Response_Year (int): Year of the data\n";
    $table_structure .= "- Sector (varchar): Sector of intervention (e.g., Health, Education, Protection)\n";
    $table_structure .= "- State (varchar): State name (e.g., Borno, Adamawa)\n";
    $table_structure .= "- State_Pcode (varchar): State postal code (e.g., NG002)\n";
    $table_structure .= "- LGA (varchar): Local Government Area (e.g., Fufore, Maiduguri)\n";
    $table_structure .= "- LGA_pCode (varchar): LGA postal code (e.g., NGM001)\n";
    $table_structure .= "- IDP (int): Number of internally displaced persons\n";
    $table_structure .= "- Returnees (int): Number of returnees\n";
    $table_structure .= "- Host_Community (int): Number of host community members\n";
    $table_structure .= "- Final (int): Final severity score or count\n";

    if ($is_related) {
        if ($is_in_need) {
            // Summarize only pindata for "in need" questions
            $data_summary = "Relevant data from pindata (People in Need Data):\n";
            if (empty($data['pindata'])) {
                $data_summary .= "No specific data found for the query in pindata.\n";
            } else {
                $data_summary .= "**From pindata**:\n";
                foreach ($data['pindata'] as $row) {
                    $data_summary .= sprintf(
                        "- Year: %s, Sector: %s, State: %s, State_Pcode: %s, LGA: %s, LGA_pCode: %s, " .
                            "IDP Girls: %d, IDP Boys: %d, IDP Women: %d, IDP Men: %d, " .
                            "IDP Elderly Women: %d, IDP Elderly Men: %d, " .
                            "Returnee Girls: %d, Returnee Boys: %d, Returnee Women: %d, Returnee Men: %d, " .
                            "Returnee Elderly Women: %d, Returnee Elderly Men: %d, " .
                            "Host Community Girls: %d, Host Community Boys: %d, Host Community Women: %d, Host Community Men: %d, " .
                            "Host Community Elderly Women: %d, Host Community Elderly Men: %d\n",
                        $row['Response_Year'] ?? 'N/A',
                        $row['Sector'] ?? 'N/A',
                        $row['State'] ?? 'N/A',
                        $row['State_Pcode'] ?? 'N/A',
                        $row['LGA'] ?? 'N/A',
                        $row['LGA_pCode'] ?? 'N/A',
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
            $prompt = "You are FREDOMETER ASSISTANT, an expert on the pindata table (People in Need Data) in the fredometer database. User asked: '$message'\n\nTable structures:\n$table_structure\n\n$data_summary\nSince the question mentions 'in need,' focus exclusively on the pindata table, which details populations requiring assistance (e.g., IDP_Women, Returnee_Women, Host_Community_Women in need). Provide a precise, conversational answer with Markdown formatting (e.g., **bold**, *italic*, [links](url)) where appropriate, using only pindata to answer accurately, focusing on specific values or columns mentioned (e.g., LGA like Fufore, Sector like Health, IDP_Women). If no data is relevant in pindata, explain clearly and suggest related questions about pindata, such as 'People in need in Fufore' or 'IDP Women in need in Health sector.'";
        } else {
            // Summarize data from all tables for other related questions
            $value_note = "";
            $tables_with_value = [];
            if (count(array_filter($data, fn($rows) => !empty($rows))) > 1) {
                foreach (['State', 'State_Pcode', 'LGA', 'LGA_Pcode', 'LGA_pCode', 'Sector'] as $column) {
                    $col_key = ($column === 'LGA_Pcode' || $column === 'LGA_pCode') ? 'LGA_Pcode/LGA_pCode' : $column;
                    if (strtolower($message) === strtolower($col_key) || strpos(strtolower($message), strtolower($col_key)) !== false) {
                        continue;
                    }
                    $matches = [];
                    foreach (['baselinedata', 'pindata', 'severitydata'] as $table) {
                        if ($table === 'baselinedata' && $column === 'Sector') continue;
                        if ($table === 'baselinedata' && $column === 'LGA_pCode') continue;
                        if (($table === 'pindata' || $table === 'severitydata') && $column === 'LGA_Pcode') continue;
                        foreach ($data[$table] as $row) {
                            if (isset($row[$column]) && strtolower($row[$column]) === strtolower($message)) {
                                $matches[] = $table;
                                break;
                            }
                        }
                    }
                    if (count($matches) > 1) {
                        $tables_with_value[] = sprintf("The value '%s' in column '%s' appears in %s.", $message, $col_key, implode(", ", $matches));
                    }
                }
                if (!empty($tables_with_value)) {
                    $value_note = "Note: " . implode(" ", $tables_with_value) . " baselinedata provides general demographic data, pindata details People in Need Data (populations requiring assistance), and severitydata tracks severity metrics.\n\n";
                }
            }

            $data_summary = $value_note . "Relevant data from baselinedata, pindata, and severitydata:\n";
            if (empty($data['baselinedata']) && empty($data['pindata']) && empty($data['severitydata'])) {
                $data_summary .= "No specific data found for the query.\n";
            } else {
                if (!empty($data['baselinedata'])) {
                    $data_summary .= "**From baselinedata** (general demographic data):\n";
                    foreach ($data['baselinedata'] as $row) {
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
                if (!empty($data['pindata'])) {
                    $data_summary .= "**From pindata** (People in Need Data):\n";
                    foreach ($data['pindata'] as $row) {
                        $data_summary .= sprintf(
                            "- Year: %s, Sector: %s, State: %s, State_Pcode: %s, LGA: %s, LGA_pCode: %s, " .
                                "IDP Girls: %d, IDP Boys: %d, IDP Women: %d, IDP Men: %d, " .
                                "IDP Elderly Women: %d, IDP Elderly Men: %d, " .
                                "Returnee Girls: %d, Returnee Boys: %d, Returnee Women: %d, Returnee Men: %d, " .
                                "Returnee Elderly Women: %d, Returnee Elderly Men: %d, " .
                                "Host Community Girls: %d, Host Community Boys: %d, Host Community Women: %d, Host Community Men: %d, " .
                                "Host Community Elderly Women: %d, Host Community Elderly Men: %d\n",
                            $row['Response_Year'] ?? 'N/A',
                            $row['Sector'] ?? 'N/A',
                            $row['State'] ?? 'N/A',
                            $row['State_Pcode'] ?? 'N/A',
                            $row['LGA'] ?? 'N/A',
                            $row['LGA_pCode'] ?? 'N/A',
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
                if (!empty($data['severitydata'])) {
                    $data_summary .= "**From severitydata** (severity metrics):\n";
                    foreach ($data['severitydata'] as $row) {
                        $data_summary .= sprintf(
                            "- Year: %s, Sector: %s, State: %s, State_Pcode: %s, LGA: %s, LGA_pCode: %s, " .
                                "IDP: %d, Returnees: %d, Host Community: %d, Final: %d\n",
                            $row['Response_Year'] ?? 'N/A',
                            $row['Sector'] ?? 'N/A',
                            $row['State'] ?? 'N/A',
                            $row['State_Pcode'] ?? 'N/A',
                            $row['LGA'] ?? 'N/A',
                            $row['LGA_pCode'] ?? 'N/A',
                            $row['IDP'] ?? 0,
                            $row['Returnees'] ?? 0,
                            $row['Host_Community'] ?? 0,
                            $row['Final'] ?? 0
                        );
                    }
                }
            }
            $prompt = "You are FREDOMETER ASSISTANT, an expert on the baselinedata, pindata, and severitydata tables in the fredometer database. User asked: '$message'\n\nTable structures:\n$table_structure\n\n$data_summary\nProvide a precise, conversational answer with Markdown formatting (e.g., **bold**, *italic*, [links](url)) where appropriate. Use the table structures and data to answer accurately, focusing on the specific values or columns mentioned (e.g., LGA like Fufore, State_Pcode like NG002, Sector like Health, IDP, Final). Note that pindata represents People in Need Data, detailing populations requiring assistance. If a value appears in multiple tables, note this as shown in the data summary and explain the context of each table (baselinedata: demographics, pindata: People in Need Data, severitydata: severity metrics). If no data is relevant, explain clearly and suggest related questions about any of the three tables.";
        }
    } else {
        $prompt = "You are FREDOMETER ASSISTANT, a knowledgeable AI specialized in the baselinedata, pindata, and severitydata tables in the fredometer database. User asked: '$message'\n\nThis question does not appear to be related to the fredometer database. Provide a concise, accurate, and conversational answer to the user's question using Markdown formatting (e.g., **bold**, *italic*, [links](url)) where appropriate. If the question is vague, offer a clear response and suggest asking about the fredometer database (e.g., **IDP_Girls**, **LGA** like Fufore, **Sector** like Health, or **People in Need** in pindata) for more specific insights.";
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

// Fetch relevant data from baselinedata, pindata, and severitydata tables based on user message
function fetchData($message, $conn)
{
    $data = ['baselinedata' => [], 'pindata' => [], 'severitydata' => []];

    // Define columns for each table
    $baselinedata_columns = [
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
    $pindata_columns = array_merge(['Sector'], $baselinedata_columns);
    $pindata_columns = str_replace('LGA_Pcode', 'LGA_pCode', $pindata_columns);
    $severitydata_columns = [
        'Response_Year',
        'Sector',
        'State',
        'State_Pcode',
        'LGA',
        'LGA_pCode',
        'IDP',
        'Returnees',
        'Host_Community',
        'Final'
    ];

    // Process all tables
    $tables = [
        'baselinedata' => $baselinedata_columns,
        'pindata' => $pindata_columns,
        'severitydata' => $severitydata_columns
    ];

    foreach ($tables as $table => $table_columns) {
        $query = "SELECT " . implode(', ', $table_columns) . " FROM $table";
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
        foreach ($table_columns as $column) {
            if ($column === 'Sector' && $table === 'baselinedata') continue;
            if (strpos($message_lower, strtolower(str_replace('_', ' ', $column))) !== false) {
                $conditions[] = "$column IS NOT NULL AND $column != 0";
            }
        }

        // Filter by specific data values in varchar columns
        $varchar_columns = ($table === 'severitydata' || $table === 'pindata') ?
            ['State', 'State_Pcode', 'LGA', 'LGA_pCode', 'Sector'] :
            ['State', 'State_Pcode', 'LGA', 'LGA_Pcode'];
        foreach ($varchar_columns as $column) {
            if ($column === 'Sector' && $table === 'baselinedata') continue;
            if ($column === 'LGA_pCode' && $table === 'baselinedata') continue;
            if ($column === 'LGA_Pcode' && ($table === 'pindata' || $table === 'severitydata')) continue;
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
            logError("Failed to prepare statement for fetching data from $table: " . $conn->error);
            respondWithError(500, 'Failed to prepare statement');
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            logError("Failed to fetch data from $table: " . $stmt->error);
            respondWithError(500, 'Failed to fetch data');
        }

        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $data[$table][] = $row;
        }

        $stmt->close();
    }

    return $data;
}

// Fetch relevant data only from pindata table for "in need" questions
function fetchPinDataOnly($message, $conn)
{
    $data = ['pindata' => []];

    // Define columns for pindata
    $pindata_columns = [
        'Response_Year',
        'Sector',
        'State',
        'State_Pcode',
        'LGA',
        'LGA_pCode',
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

    $query = "SELECT " . implode(', ', $pindata_columns) . " FROM pindata";
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
    foreach ($pindata_columns as $column) {
        if (strpos($message_lower, strtolower(str_replace('_', ' ', $column))) !== false) {
            $conditions[] = "$column IS NOT NULL AND $column != 0";
        }
    }

    // Filter by specific data values in varchar columns
    $varchar_columns = ['State', 'State_Pcode', 'LGA', 'LGA_pCode', 'Sector'];
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
        logError("Failed to prepare statement for fetching data from pindata: " . $conn->error);
        respondWithError(500, 'Failed to prepare statement');
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        logError("Failed to fetch data from pindata: " . $stmt->error);
        respondWithError(500, 'Failed to fetch data');
    }

    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $data['pindata'][] = $row;
    }

    $stmt->close();

    return $data;
}
