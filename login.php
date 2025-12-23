<?php
// login.php - Debug Version
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Function to get all headers properly
function getAllHeadersSimple() {
    $headers = [];
    if (function_exists('getallheaders')) {
        return getallheaders();
    }
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        } elseif ($name == 'CONTENT_TYPE') {
            $headers['Content-Type'] = $value;
        } elseif ($name == 'CONTENT_LENGTH') {
            $headers['Content-Length'] = $value;
        }
    }
    return $headers;
}

try { 
    // ==============================================
    // STEP 1: Check request method
    // ==============================================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed', 405);
    }
    
    // ==============================================
    // STEP 2: Get database config from headers
    // ==============================================
    $headers = getAllHeadersSimple();
    
    $db_host = '';
    $db_user = '';
    $db_pass = '';
    $db_name = '';
    $db_port = 3306;
    
    // Try different header formats
    $db_host = $headers['X-DB-Host'] ?? 
               $_SERVER['HTTP_X_DB_HOST'] ?? 
               ($headers['x-db-host'] ?? 'localhost');
    
    $db_user = $headers['X-DB-User'] ?? 
               $_SERVER['HTTP_X_DB_USER'] ?? 
               ($headers['x-db-user'] ?? '');
    
    $db_pass = $headers['X-DB-Pass'] ?? 
               $_SERVER['HTTP_X_DB_PASS'] ?? 
               ($headers['x-db-pass'] ?? '');
    
    $db_name = $headers['X-DB-Name'] ?? 
               $_SERVER['HTTP_X_DB_NAME'] ?? 
               ($headers['x-db-name'] ?? '');
    
    $db_port = $headers['X-DB-Port'] ?? 
               $_SERVER['HTTP_X_DB_PORT'] ?? 
               ($headers['x-db-port'] ?? 3306);
    
    // Log extracted values
    $debug['extracted_db_config'] = [
        'host' => $db_host,
        'user' => $db_user,
        'pass' => strlen($db_pass) > 0 ? '***SET***' : 'EMPTY',
        'name' => $db_name,
        'port' => $db_port
    ];
    
    // Validate required fields
    if (empty($db_user) || empty($db_name)) {
        throw new Exception('Database configuration incomplete. Required: X-DB-User and X-DB-Name', 400);
    }
    
    // ==============================================
    // STEP 3: Connect to database
    // ==============================================
    $mysqli = @new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
    
    if ($mysqli->connect_error) {
        throw new Exception("Database connection failed: " . $mysqli->connect_error, 500);
    }
    
    $mysqli->set_charset("utf8mb4");
    $debug['db_connection'] = 'SUCCESS';
    
    // ==============================================
    // STEP 4: Get login data
    // ==============================================
    $raw_input = file_get_contents('php://input');
    $post_data = [];
    
    if (!empty($raw_input)) {
        // Try JSON first
        $json_data = json_decode($raw_input, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $post_data = $json_data;
        } else {
            // Try form data
            parse_str($raw_input, $post_data);
        }
    }
    
    // If still empty, use $_POST
    if (empty($post_data) && !empty($_POST)) {
        $post_data = $_POST;
    }
    
    $debug['parsed_post_data'] = $post_data;
    
    // Validate required fields
    if (empty($post_data['email']) || empty($post_data['password'])) {
        throw new Exception('Email and password are required', 400);
    }
    
    $email = trim($post_data['email']);
    $password = $post_data['password'];
    
    // ==============================================
    // STEP 5: Query database for user
    // ==============================================
    $query = "SELECT id, name, email, password, role, email_verified_at, created_at, updated_at 
              FROM users WHERE email = ? LIMIT 1";
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $mysqli->error, 500);
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Invalid email or password', 401);
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // ==============================================
    // STEP 6: Verify password
    // ==============================================
    if (!password_verify($password, $user['password'])) {
        throw new Exception('Invalid email or password', 401);
    }
    
    // ==============================================
    // STEP 7: Prepare response
    // ==============================================
    $role_names = [
        0 => 'Admin',
        1 => 'Manager',
        2 => 'Cashier',
        3 => 'Stock Keeper'
    ];
    
    $response = [
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => [
                    'id' => (int)$user['role'],
                    'name' => $role_names[$user['role']] ?? 'Unknown'
                ],
                'email_verified' => !empty($user['email_verified_at']),
                'created_at' => $user['created_at'],
                'updated_at' => $user['updated_at']
            ]
        ],
    ];
    
    // ==============================================
    // STEP 8: Send success response
    // ==============================================
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT);
    
    $mysqli->close();
    
} catch (Exception $e) {
    // ==============================================
    // ERROR HANDLING
    // ==============================================
    $status_code = $e->getCode();
    if ($status_code < 100 || $status_code > 599) {
        $status_code = 500;
    }
    
    $error_response = [
        'error' => true,
        'status_code' => $status_code,
        'message' => $e->getMessage(),
        'response' => '',
        // Include debug info for troubleshooting
    ];
    
    http_response_code($status_code);
    echo json_encode($error_response, JSON_PRETTY_PRINT);
    
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        @$mysqli->close();
    }
}