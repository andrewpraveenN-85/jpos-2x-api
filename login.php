<?php
// login.php - Professional Login API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-DB-Host, X-DB-User, X-DB-Pass, X-DB-Name, X-DB-Port');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // ==============================================
    // VALIDATE REQUEST METHOD
    // ==============================================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // ==============================================
    // EXTRACT DATABASE CONFIGURATION FROM HEADERS
    // ==============================================
    $headers = [];
    
    // Get all headers (compatible with all servers)
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$header] = $value;
        }
    }

    // Extract database configuration
    $dbConfig = [
        'host' => $headers['X-DB-Host'] ?? 'localhost',
        'username' => $headers['X-DB-User'] ?? '',
        'password' => $headers['X-DB-Pass'] ?? '',
        'database' => $headers['X-DB-Name'] ?? '',
        'port' => isset($headers['X-DB-Port']) ? (int)$headers['X-DB-Port'] : 3306
    ];

    // Validate required database configuration
    if (empty($dbConfig['username']) || empty($dbConfig['database'])) {
        throw new Exception('Database configuration is incomplete. Please provide X-DB-User and X-DB-Name headers.', 400);
    }

    // ==============================================
    // ESTABLISH DATABASE CONNECTION
    // ==============================================
    $mysqli = new mysqli(
        $dbConfig['host'],
        $dbConfig['username'],
        $dbConfig['password'],
        $dbConfig['database'],
        $dbConfig['port']
    );

    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed. Please check your database configuration.', 500);
    }

    // Set charset to utf8mb4
    $mysqli->set_charset("utf8mb4");

    // ==============================================
    // PARSE AND VALIDATE REQUEST DATA
    // ==============================================
    $inputData = json_decode(file_get_contents('php://input'), true);
    
    // Fallback to $_POST if JSON parsing fails
    if (json_last_error() !== JSON_ERROR_NONE || empty($inputData)) {
        $inputData = $_POST;
    }

    // Validate required fields
    if (empty($inputData['email']) || empty($inputData['password'])) {
        throw new Exception('Email and password are required fields.', 400);
    }

    $email = trim($inputData['email']);
    $password = $inputData['password'];

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format.', 400);
    }

    // ==============================================
    // AUTHENTICATE USER
    // ==============================================
    $stmt = $mysqli->prepare("
        SELECT id, name, email, password, role, email_verified_at, created_at, updated_at 
        FROM users 
        WHERE email = ? 
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Authentication service temporarily unavailable.', 500);
    }

    $stmt->bind_param("s", $email);
    
    if (!$stmt->execute()) {
        throw new Exception('Authentication failed. Please try again.', 500);
    }

    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Invalid email or password.', 401);
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // ==============================================
    // VERIFY PASSWORD (Laravel bcrypt compatible)
    // ==============================================
    if (!password_verify($password, $user['password'])) {
        throw new Exception('Invalid email or password.', 401);
    }

    // ==============================================
    // PREPARE SUCCESS RESPONSE
    // ==============================================
    $roleNames = [
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
                    'name' => $roleNames[$user['role']] ?? 'Unknown'
                ],
                'email_verified' => !is_null($user['email_verified_at']),
                'email_verified_at' => $user['email_verified_at'],
                'created_at' => $user['created_at'],
                'updated_at' => $user['updated_at']
            ]
        ],
        'timestamp' => date('c')
    ];

    // ==============================================
    // SEND SUCCESS RESPONSE
    // ==============================================
    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    // Clean up
    $mysqli->close();

} catch (Exception $e) {
    // ==============================================
    // ERROR HANDLING
    // ==============================================
    $statusCode = $e->getCode();
    
    // Ensure valid HTTP status code
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }

    // Security: Generic message for server errors
    $errorMessage = $statusCode === 500 
        ? 'An internal server error occurred. Please try again later.' 
        : $e->getMessage();

    $errorResponse = [
        'error' => true,
        'status_code' => $statusCode,
        'message' => $errorMessage,
        'timestamp' => date('c')
    ];

    http_response_code($statusCode);
    echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    // Clean up database connection if exists
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        @$mysqli->close();
    }
}