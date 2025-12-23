<?php
// login.php - Complete Login API in Single File
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-DB-Host, X-DB-User, X-DB-Pass, X-DB-Name, X-DB-Port');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only accept POST requests for login
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit();
}

try {
    // =================================================================
    // 1. GET DATABASE CONFIGURATION FROM HEADERS
    // =================================================================
    
    // Function to get all headers (works with both Apache and Nginx)
    function getAllHeaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
    
    $headers = getAllHeaders();
    
    // Extract database configuration from headers
    $dbConfig = [
        'host' => $headers['X-DB-Host'] ?? $_SERVER['HTTP_X_DB_HOST'] ?? 'localhost',
        'username' => $headers['X-DB-User'] ?? $_SERVER['HTTP_X_DB_USER'] ?? '',
        'password' => $headers['X-DB-Pass'] ?? $_SERVER['HTTP_X_DB_PASS'] ?? '',
        'database' => $headers['X-DB-Name'] ?? $_SERVER['HTTP_X_DB_NAME'] ?? '',
        'port' => $headers['X-DB-Port'] ?? $_SERVER['HTTP_X_DB_PORT'] ?? 3306
    ];
    
    // Validate required database configuration
    if (empty($dbConfig['username']) || empty($dbConfig['database'])) {
        throw new Exception('Database configuration is incomplete. Please provide X-DB-User and X-DB-Name headers.', 400);
    }
    
    // =================================================================
    // 2. CONNECT TO DATABASE
    // =================================================================
    
    $mysqli = new mysqli(
        $dbConfig['host'],
        $dbConfig['username'],
        $dbConfig['password'],
        $dbConfig['database'],
        $dbConfig['port']
    );
    
    if ($mysqli->connect_error) {
        throw new Exception("Database connection failed: " . $mysqli->connect_error, 500);
    }
    
    // Set charset to utf8mb4 (same as Laravel)
    $mysqli->set_charset("utf8mb4");
    
    // =================================================================
    // 3. GET LOGIN CREDENTIALS FROM POST DATA
    // =================================================================
    
    // Get raw POST data
    $rawInput = file_get_contents('php://input');
    
    // Try to decode as JSON first
    $postData = json_decode($rawInput, true);
    
    // If JSON decoding fails, use $_POST (form data)
    if (json_last_error() !== JSON_ERROR_NONE) {
        $postData = $_POST;
    }
    
    // Validate required fields
    if (empty($postData['email']) || empty($postData['password'])) {
        throw new Exception('Email and password are required fields.', 400);
    }
    
    $email = trim($postData['email']);
    $password = $postData['password'];
    
    // Additional validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format.', 400);
    }
    
    if (strlen($password) < 1) {
        throw new Exception('Password cannot be empty.', 400);
    }
    
    // =================================================================
    // 4. VERIFY PASSWORD (LARAVEL BCRYPT COMPATIBLE)
    // =================================================================
    
    // Laravel-compatible password verification function
    function verifyPassword($plainPassword, $hashedPassword) {
        if (empty($hashedPassword)) {
            return false;
        }
        
        // Laravel uses PHP's password_verify() for bcrypt
        return password_verify($plainPassword, $hashedPassword);
    }
    
    // =================================================================
    // 5. CHECK USER IN DATABASE
    // =================================================================
    
    // Prepare SQL query to prevent SQL injection
    $stmt = $mysqli->prepare("
        SELECT 
            id, 
            name, 
            email, 
            password, 
            role, 
            email_verified_at,
            created_at, 
            updated_at
        FROM users 
        WHERE email = ? 
        LIMIT 1
    ");
    
    if (!$stmt) {
        throw new Exception("Failed to prepare SQL statement: " . $mysqli->error, 500);
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Check if user exists
    if ($result->num_rows === 0) {
        throw new Exception('Invalid email or password.', 401);
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // =================================================================
    // 6. VERIFY PASSWORD
    // =================================================================
    
    if (!verifyPassword($password, $user['password'])) {
        throw new Exception('Invalid email or password.', 401);
    }
    
    // Optional: Check if email is verified
    if (is_null($user['email_verified_at'])) {
        // Uncomment if you want to require email verification
        // throw new Exception('Please verify your email address before logging in.', 403);
    }
    
    // =================================================================
    // 7. PREPARE SUCCESS RESPONSE
    // =================================================================
    
    // Define role names based on your database
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
            ],
            'session' => [
                'token' => bin2hex(random_bytes(32)), // Generate a simple session token
                'expires_in' => 3600, // 1 hour in seconds
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ]
    ];
    
    // Optional: Update last login timestamp (if you have this column)
    // $updateStmt = $mysqli->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    // $updateStmt->bind_param("i", $user['id']);
    // $updateStmt->execute();
    // $updateStmt->close();
    
    // =================================================================
    // 8. SEND SUCCESS RESPONSE
    // =================================================================
    
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    // =================================================================
    // 9. CLEAN UP
    // =================================================================
    
    $mysqli->close();
    
} catch (Exception $e) {
    // =================================================================
    // ERROR HANDLING
    // =================================================================
    
    // Determine HTTP status code
    $statusCode = $e->getCode();
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }
    
    // For security, don't expose detailed error messages for 500 errors in production
    $errorMessage = $e->getMessage();
    if ($statusCode === 500) {
        // In production, you might want to log the actual error and show a generic message
        error_log("Login API Error: " . $errorMessage);
        $errorMessage = 'An internal server error occurred. Please try again later.';
    }
    
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $errorMessage,
        'code' => $statusCode,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    // Close database connection if it exists
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->close();
    }
}