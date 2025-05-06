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

// Check if the message is related to demographic or assistance data
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

// Check if the message is related to demographic or assistance data
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
    $is_detailed = stripos($message, 'in detailed') !== false;

    if ($is_related) {
        if ($is_in_need) {
            // Summarize pindata for "in need" questions
            $data_summary = "Relevant data for populations requiring assistance:\n";
            if (empty($data['pindata'])) {
                $data_summary .= "No specific data found for the query.\n";
            } else {
                // Sum numerical columns for non-detailed queries
                $sums = [
                    'IDP_Girls' => 0,
                    'IDP_Boys' => 0,
                    'IDP_Women' => 0,
                    'IDP_Men' => 0,
                    'IDP_Elderly_Women' => 0,
                    'IDP_Elderly_Men' => 0,
                    'Returnee_Girls' => 0,
                    'Returnee_Boys' => 0,
                    'Returnee_Women' => 0,
                    'Returnee_Men' => 0,
                    'Returnee_Elderly_Women' => 0,
                    'Returnee_Elderly_Men' => 0,
                    'Host_Community_Girls' => 0,
                    'Host_Community_Boys' => 0,
                    'Host_Community_Women' => 0,
                    'Host_Community_Men' => 0,
                    'Host_Community_Elderly_Women' => 0,
                    'Host_Community_Elderly_Men' => 0
                ];
                $sectors = [];
                $locations = [];
                $years = [];

                foreach ($data['pindata'] as $row) {
                    foreach ($sums as $key => &$value) {
                        $value += $row[$key] ?? 0;
                    }
                    if (!empty($row['Sector'])) {
                        $sectors[$row['Sector']] = true;
                    }
                    if (!empty($row['State']) || !empty($row['LGA'])) {
                        $locations[] = ($row['LGA'] ?? $row['State'] ?? 'Unknown');
                    }
                    if (!empty($row['Response_Year'])) {
                        $years[$row['Response_Year']] = true;
                    }
                }

                if (!$is_detailed) {
                    // Provide summed totals for queried groups
                    if (strpos(strtolower($message), 'women') !== false) {
                        $total_women = $sums['IDP_Women'] + $sums['Returnee_Women'] + $sums['Host_Community_Women'] +
                            $sums['IDP_Elderly_Women'] + $sums['Returnee_Elderly_Women'] + $sums['Host_Community_Elderly_Women'];
                        $data_summary .= sprintf("Total women requiring assistance: %d\n", $total_women);
                    } elseif (strpos(strtolower($message), 'men') !== false) {
                        $total_men = $sums['IDP_Men'] + $sums['Returnee_Men'] + $sums['Host_Community_Men'] +
                            $sums['IDP_Elderly_Men'] + $sums['Returnee_Elderly_Men'] + $sums['Host_Community_Elderly_Men'];
                        $data_summary .= sprintf("Total men requiring assistance: %d\n", $total_men);
                    } elseif (strpos(strtolower($message), 'girls') !== false) {
                        $total_girls = $sums['IDP_Girls'] + $sums['Returnee_Girls'] + $sums['Host_Community_Girls'];
                        $data_summary .= sprintf("Total girls requiring assistance: %d\n", $total_girls);
                    } elseif (strpos(strtolower($message), 'boys') !== false) {
                        $total_boys = $sums['IDP_Boys'] + $sums['Returnee_Boys'] + $sums['Host_Community_Boys'];
                        $data_summary .= sprintf("Total boys requiring assistance: %d\n", $total_boys);
                    } else {
                        foreach ($sums as $key => $value) {
                            if (strpos(strtolower($message), strtolower(str_replace('_', ' ', $key))) !== false && $value > 0) {
                                $data_summary .= sprintf("Total %s requiring assistance: %d\n", str_replace('_', ' ', $key), $value);
                            }
                        }
                    }
                    if (!empty($sectors)) {
                        $data_summary .= sprintf("Sectors: %s\n", implode(', ', array_keys($sectors)));
                    }
                    if (!empty($locations)) {
                        $data_summary .= sprintf("Locations: %s\n", implode(', ', array_unique($locations)));
                    }
                    if (!empty($years)) {
                        $data_summary .= sprintf("Years: %s\n", implode(', ', array_keys($years)));
                    }
                } else {
                    // Detailed breakdown
                    $data_summary .= "Detailed data for populations requiring assistance:\n";
                    foreach ($data['pindata'] as $row) {
                        $data_summary .= sprintf(
                            "- Year: %s, Sector: %s, Location: %s, " .
                                "IDP Girls: %d, IDP Boys: %d, IDP Women: %d, IDP Men: %d, " .
                                "IDP Elderly Women: %d, IDP Elderly Men: %d, " .
                                "Returnee Girls: %d, Returnee Boys: %d, Returnee Women: %d, Returnee Men: %d, " .
                                "Returnee Elderly Women: %d, Returnee Elderly Men: %d, " .
                                "Host Community Girls: %d, Host Community Boys: %d, Host Community Women: %d, Host Community Men: %d, " .
                                "Host Community Elderly Women: %d, Host Community Elderly Men: %d\n",
                            $row['Response_Year'] ?? 'N/A',
                            $row['Sector'] ?? 'N/A',
                            ($row['LGA'] ?? $row['State'] ?? 'N/A'),
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
            }
            $prompt = "You are FREDOMETER ASSISTANT, an expert on demographic and assistance data. User asked: '$message'\n\n$data_summary\nSince the question mentions 'in need,' focus exclusively on data about populations requiring assistance (e.g., women, men, girls, boys in need). Provide a precise, conversational answer with Markdown formatting (e.g., **bold**, *italic*, [links](url)) where appropriate, using only the provided data to answer accurately, focusing on specific values mentioned (e.g., locations like Fufore, sectors like Health). For numerical queries (e.g., 'number of women'), sum the relevant totals unless 'in detailed' is specified, in which case provide a detailed breakdown. Avoid multiplication unless explicitly requested (e.g., 'multiply'). If no data is relevant, explain clearly and suggest related questions, such as 'People requiring assistance in Fufore' or 'Women in need in Health sector.'";
        } else {
            // Summarize data for other related questions
            $data_summary = "Relevant demographic and assistance data:\n";
            $has_data = false;

            // Handle duplicate values across sources
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
                        $tables_with_value[] = sprintf("The value '%s' in column '%s' appears in multiple data sources.", $message, $col_key);
                    }
                }
                if (!empty($tables_with_value)) {
                    $value_note = "Note: " . implode(" ", $tables_with_value) . " Some data represents general demographics, some details populations requiring assistance, and some tracks severity metrics.\n\n";
                    $data_summary .= $value_note;
                }
            }

            // Process baselinedata
            if (!empty($data['baselinedata'])) {
                $has_data = true;
                $sums = [
                    'IDP_Girls' => 0,
                    'IDP_Boys' => 0,
                    'IDP_Women' => 0,
                    'IDP_Men' => 0,
                    'IDP_Elderly_Women' => 0,
                    'IDP_Elderly_Men' => 0,
                    'Returnee_Girls' => 0,
                    'Returnee_Boys' => 0,
                    'Returnee_Women' => 0,
                    'Returnee_Men' => 0,
                    'Returnee_Elderly_Women' => 0,
                    'Returnee_Elderly_Men' => 0,
                    'Host_Community_Girls' => 0,
                    'Host_Community_Boys' => 0,
                    'Host_Community_Women' => 0,
                    'Host_Community_Men' => 0,
                    'Host_Community_Elderly_Women' => 0,
                    'Host_Community_Elderly_Men' => 0
                ];
                $locations = [];
                $years = [];

                foreach ($data['baselinedata'] as $row) {
                    foreach ($sums as $key => &$value) {
                        $value += $row[$key] ?? 0;
                    }
                    if (!empty($row['State']) || !empty($row['LGA'])) {
                        $locations[] = ($row['LGA'] ?? $row['State'] ?? 'Unknown');
                    }
                    if (!empty($row['Response_Year'])) {
                        $years[$row['Response_Year']] = true;
                    }
                }

                if (!$is_detailed) {
                    if (strpos(strtolower($message), 'women') !== false) {
                        $total_women = $sums['IDP_Women'] + $sums['Returnee_Women'] + $sums['Host_Community_Women'] +
                            $sums['IDP_Elderly_Women'] + $sums['Returnee_Elderly_Women'] + $sums['Host_Community_Elderly_Women'];
                        $data_summary .= sprintf("Total women: %d\n", $total_women);
                    } elseif (strpos(strtolower($message), 'men') !== false) {
                        $total_men = $sums['IDP_Men'] + $sums['Returnee_Men'] + $sums['Host_Community_Men'] +
                            $sums['IDP_Elderly_Men'] + $sums['Returnee_Elderly_Men'] + $sums['Host_Community_Elderly_Men'];
                        $data_summary .= sprintf("Total men: %d\n", $total_men);
                    } elseif (strpos(strtolower($message), 'girls') !== false) {
                        $total_girls = $sums['IDP_Girls'] + $sums['Returnee_Girls'] + $sums['Host_Community_Girls'];
                        $data_summary .= sprintf("Total girls: %d\n", $total_girls);
                    } elseif (strpos(strtolower($message), 'boys') !== false) {
                        $total_boys = $sums['IDP_Boys'] + $sums['Returnee_Boys'] + $sums['Host_Community_Boys'];
                        $data_summary .= sprintf("Total boys: %d\n", $total_boys);
                    } else {
                        foreach ($sums as $key => $value) {
                            if (strpos(strtolower($message), strtolower(str_replace('_', ' ', $key))) !== false && $value > 0) {
                                $data_summary .= sprintf("Total %s: %d\n", str_replace('_', ' ', $key), $value);
                            }
                        }
                    }
                    if (!empty($locations)) {
                        $data_summary .= sprintf("Locations: %s\n", implode(', ', array_unique($locations)));
                    }
                    if (!empty($years)) {
                        $data_summary .= sprintf("Years: %s\n", implode(', ', array_keys($years)));
                    }
                } else {
                    $data_summary .= "Detailed demographic data:\n";
                    foreach ($data['baselinedata'] as $row) {
                        $data_summary .= sprintf(
                            "- Year: %s, Location: %s, " .
                                "IDP Girls: %d, IDP Boys: %d, IDP Women: %d, IDP Men: %d, " .
                                "IDP Elderly Women: %d, IDP Elderly Men: %d, " .
                                "Returnee Girls: %d, Returnee Boys: %d, Returnee Women: %d, Returnee Men: %d, " .
                                "Returnee Elderly Women: %d, Returnee Elderly Men: %d, " .
                                "Host Community Girls: %d, Host Community Boys: %d, Host Community Women: %d, Host Community Men: %d, " .
                                "Host Community Elderly Women: %d, Host Community Elderly Men: %d\n",
                            $row['Response_Year'] ?? 'N/A',
                            ($row['LGA'] ?? $row['State'] ?? 'N/A'),
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
            }

            // Process pindata
            if (!empty($data['pindata'])) {
                $has_data = true;
                $sums = [
                    'IDP_Girls' => 0,
                    'IDP_Boys' => 0,
                    'IDP_Women' => 0,
                    'IDP_Men' => 0,
                    'IDP_Elderly_Women' => 0,
                    'IDP_Elderly_Men' => 0,
                    'Returnee_Girls' => 0,
                    'Returnee_Boys' => 0,
                    'Returnee_Women' => 0,
                    'Returnee_Men' => 0,
                    'Returnee_Elderly_Women' => 0,
                    'Returnee_Elderly_Men' => 0,
                    'Host_Community_Girls' => 0,
                    'Host_Community_Boys' => 0,
                    'Host_Community_Women' => 0,
                    'Host_Community_Men' => 0,
                    'Host_Community_Elderly_Women' => 0,
                    'Host_Community_Elderly_Men' => 0
                ];
                $sectors = [];
                $locations = [];
                $years = [];

                foreach ($data['pindata'] as $row) {
                    foreach ($sums as $key => &$value) {
                        $value += $row[$key] ?? 0;
                    }
                    if (!empty($row['Sector'])) {
                        $sectors[$row['Sector']] = true;
                    }
                    if (!empty($row['State']) || !empty($row['LGA'])) {
                        $locations[] = ($row['LGA'] ?? $row['State'] ?? 'Unknown');
                    }
                    if (!empty($row['Response_Year'])) {
                        $years[$row['Response_Year']] = true;
                    }
                }

                if (!$is_detailed) {
                    if (strpos(strtolower($message), 'women') !== false) {
                        $total_women = $sums['IDP_Women'] + $sums['Returnee_Women'] + $sums['Host_Community_Women'] +
                            $sums['IDP_Elderly_Women'] + $sums['Returnee_Elderly_Women'] + $sums['Host_Community_Elderly_Women'];
                        $data_summary .= sprintf("Total women requiring assistance: %d\n", $total_women);
                    } elseif (strpos(strtolower($message), 'men') !== false) {
                        $total_men = $sums['IDP_Men'] + $sums['Returnee_Men'] + $sums['Host_Community_Men'] +
                            $sums['IDP_Elderly_Men'] + $sums['Returnee_Elderly_Men'] + $sums['Host_Community_Elderly_Men'];
                        $data_summary .= sprintf("Total men requiring assistance: %d\n", $total_men);
                    } elseif (strpos(strtolower($message), 'girls') !== false) {
                        $total_girls = $sums['IDP_Girls'] + $sums['Returnee_Girls'] + $sums['Host_Community_Girls'];
                        $data_summary .= sprintf("Total girls requiring assistance: %d\n", $total_girls);
                    } elseif (strpos(strtolower($message), 'boys') !== false) {
                        $total_boys = $sums['IDP_Boys'] + $sums['Returnee_Boys'] + $sums['Host_Community_Boys'];
                        $data_summary .= sprintf("Total boys requiring assistance: %d\n", $total_boys);
                    } else {
                        foreach ($sums as $key => $value) {
                            if (strpos(strtolower($message), strtolower(str_replace('_', ' ', $key))) !== false && $value > 0) {
                                $data_summary .= sprintf("Total %s requiring assistance: %d\n", str_replace('_', ' ', $key), $value);
                            }
                        }
                    }
                    if (!empty($sectors)) {
                        $data_summary .= sprintf("Sectors: %s\n", implode(', ', array_keys($sectors)));
                    }
                    if (!empty($locations)) {
                        $data_summary .= sprintf("Locations: %s\n", implode(', ', array_unique($locations)));
                    }
                    if (!empty($years)) {
                        $data_summary .= sprintf("Years: %s\n", implode(', ', array_keys($years)));
                    }
                } else {
                    $data_summary .= "Detailed data for populations requiring assistance:\n";
                    foreach ($data['pindata'] as $row) {
                        $data_summary .= sprintf(
                            "- Year: %s, Sector: %s, Location: %s, " .
                                "IDP Girls: %d, IDP Boys: %d, IDP Women: %d, IDP Men: %d, " .
                                "IDP Elderly Women: %d, IDP Elderly Men: %d, " .
                                "Returnee Girls: %d, Returnee Boys: %d, Returnee Women: %d, Returnee Men: %d, " .
                                "Returnee Elderly Women: %d, Returnee Elderly Men: %d, " .
                                "Host Community Girls: %d, Host Community Boys: %d, Host Community Women: %d, Host Community Men: %d, " .
                                "Host Community Elderly Women: %d, Host Community Elderly Men: %d\n",
                            $row['Response_Year'] ?? 'N/A',
                            $row['Sector'] ?? 'N/A',
                            ($row['LGA'] ?? $row['State'] ?? 'N/A'),
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
            }

            // Process severitydata
            if (!empty($data['severitydata'])) {
                $has_data = true;
                $sums = [
                    'IDP' => 0,
                    'Returnees' => 0,
                    'Host_Community' => 0,
                    'Final' => 0
                ];
                $sectors = [];
                $locations = [];
                $years = [];

                foreach ($data['severitydata'] as $row) {
                    foreach ($sums as $key => &$value) {
                        $value += $row[$key] ?? 0;
                    }
                    if (!empty($row['Sector'])) {
                        $sectors[$row['Sector']] = true;
                    }
                    if (!empty($row['State']) || !empty($row['LGA'])) {
                        $locations[] = ($row['LGA'] ?? $row['State'] ?? 'Unknown');
                    }
                    if (!empty($row['Response_Year'])) {
                        $years[$row['Response_Year']] = true;
                    }
                }

                if (!$is_detailed) {
                    foreach ($sums as $key => $value) {
                        if (strpos(strtolower($message), strtolower(str_replace('_', ' ', $key))) !== false && $value > 0) {
                            $data_summary .= sprintf("Total %s: %d\n", str_replace('_', ' ', $key), $value);
                        }
                    }
                    if (!empty($sectors)) {
                        $data_summary .= sprintf("Sectors: %s\n", implode(', ', array_keys($sectors)));
                    }
                    if (!empty($locations)) {
                        $data_summary .= sprintf("Locations: %s\n", implode(', ', array_unique($locations)));
                    }
                    if (!empty($years)) {
                        $data_summary .= sprintf("Years: %s\n", implode(', ', array_keys($years)));
                    }
                } else {
                    $data_summary .= "Detailed severity data:\n";
                    foreach ($data['severitydata'] as $row) {
                        $data_summary .= sprintf(
                            "- Year: %s, Sector: %s, Location: %s, " .
                                "IDP: %d, Returnees: %d, Host Community: %d, Final: %d\n",
                            $row['Response_Year'] ?? 'N/A',
                            $row['Sector'] ?? 'N/A',
                            ($row['LGA'] ?? $row['State'] ?? 'N/A'),
                            $row['IDP'] ?? 0,
                            $row['Returnees'] ?? 0,
                            $row['Host_Community'] ?? 0,
                            $row['Final'] ?? 0
                        );
                    }
                }
            }

            if (!$has_data) {
                $data_summary .= "No specific data found for the query.\n";
            }

            $prompt = "You are FREDOMETER ASSISTANT, an expert on demographic and assistance data. User asked: '$message'\n\n$data_summary\nProvide a precise, conversational answer with Markdown formatting (e.g., **bold**, *italic*, [links](url)) where appropriate, using the provided data to answer accurately, focusing on specific values mentioned (e.g., locations like Fufore, sectors like Health, metrics like Final). For numerical queries (e.g., 'number of IDP Girls'), sum the relevant totals unless 'in detailed' is specified, in which case provide a detailed breakdown. Avoid multiplication unless explicitly requested (e.g., 'multiply'). If a value appears in multiple data sources, note this as shown in the data summary and explain the context (e.g., demographics vs. assistance needs vs. severity metrics). If no data is relevant, explain clearly and suggest related questions, such as 'IDP Girls in Fufore' or 'Final metric in Health sector.'";
        }
    } else {
        $prompt = "You are FREDOMETER ASSISTANT, a knowledgeable AI. User asked: '$message'\n\nThis question does not appear to be related to demographic or assistance data. Provide a concise, accurate, and conversational answer to the user's question using Markdown formatting (e.g., **bold**, *italic*, [links](url)) where appropriate. If the question is vague, offer a clear response and suggest asking about demographic or assistance data (e.g., **IDP Girls** in Fufore, **Women requiring assistance** in Health sector, or **Final** metrics) for more specific insights.";
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
