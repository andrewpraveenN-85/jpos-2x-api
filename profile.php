<?php
// profile.php - User Profile Update API
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
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'PATCH') {
        throw new Exception('Only PUT or PATCH methods are allowed', 405);
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
    
    // ==============================================
    // STEP 4: Get user ID from authentication/request
    // ==============================================
    // In a real app, you'd get this from JWT or session
    // For this example, we'll get it from request headers or body
    
    $raw_input = file_get_contents('php://input');
    $put_data = [];
    
    if (!empty($raw_input)) {
        // Try JSON first
        $json_data = json_decode($raw_input, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $put_data = $json_data;
        } else {
            // Try form data
            parse_str($raw_input, $put_data);
        }
    }
    
    // Get user ID - could come from authentication token or request
    $user_id = $headers['X-User-ID'] ?? 
               $_SERVER['HTTP_X_USER_ID'] ?? 
               ($headers['x-user-id'] ?? ($put_data['user_id'] ?? null));
    
    if (empty($user_id)) {
        throw new Exception('User ID is required for profile update', 400);
    }
    
    // ==============================================
    // STEP 5: Validate update data
    // ==============================================
    $name = trim($put_data['name'] ?? '');
    $email = trim($put_data['email'] ?? '');
    
    // Check if at least one field is provided
    if (empty($name) && empty($email)) {
        throw new Exception('At least one field (name or email) is required for update', 400);
    }
    
    // Validate email format if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format', 400);
    }
    
    // ==============================================
    // STEP 6: Check if new email already exists (if email is being updated)
    // ==============================================
    if (!empty($email)) {
        $check_email_query = "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1";
        $check_stmt = $mysqli->prepare($check_email_query);
        
        if (!$check_stmt) {
            throw new Exception("Failed to prepare email check query: " . $mysqli->error, 500);
        }
        
        $check_stmt->bind_param("si", $email, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $check_stmt->close();
            throw new Exception('Email already exists. Please use a different email.', 409);
        }
        
        $check_stmt->close();
    }
    
    // ==============================================
    // STEP 7: Build dynamic update query
    // ==============================================
    $update_fields = [];
    $params = [];
    $param_types = "";
    
    if (!empty($name)) {
        $update_fields[] = "name = ?";
        $params[] = $name;
        $param_types .= "s";
    }
    
    if (!empty($email)) {
        $update_fields[] = "email = ?";
        $params[] = $email;
        $param_types .= "s";
        
        // If email is changed, you might want to mark it as unverified
        // $update_fields[] = "email_verified_at = NULL";
    }
    
    // Always update the timestamp
    $update_fields[] = "updated_at = NOW()";
    
    // Add user_id parameter at the end for WHERE clause
    $params[] = $user_id;
    $param_types .= "i";
    
    // Build the final query
    $update_query = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ? LIMIT 1";
    
    // ==============================================
    // STEP 8: Execute update
    // ==============================================
    $update_stmt = $mysqli->prepare($update_query);
    if (!$update_stmt) {
        throw new Exception("Failed to prepare update query: " . $mysqli->error, 500);
    }
    
    // Bind parameters dynamically
    $update_stmt->bind_param($param_types, ...$params);
    $update_stmt->execute();
    
    // Check if any row was affected
    if ($update_stmt->affected_rows === 0) {
        // No rows updated - user might not exist
        $update_stmt->close();
        
        // Verify user exists
        $verify_query = "SELECT id FROM users WHERE id = ? LIMIT 1";
        $verify_stmt = $mysqli->prepare($verify_query);
        $verify_stmt->bind_param("i", $user_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows === 0) {
            $verify_stmt->close();
            throw new Exception('User not found', 404);
        }
        
        $verify_stmt->close();
        throw new Exception('No changes were made to the profile', 200);
    }
    
    $update_stmt->close();
    
    // ==============================================
    // STEP 9: Fetch updated user data
    // ==============================================
    $fetch_query = "SELECT id, name, email, role, email_verified_at, created_at, updated_at 
                    FROM users WHERE id = ? LIMIT 1";
    
    $fetch_stmt = $mysqli->prepare($fetch_query);
    if (!$fetch_stmt) {
        throw new Exception("Failed to prepare fetch query: " . $mysqli->error, 500);
    }
    
    $fetch_stmt->bind_param("i", $user_id);
    $fetch_stmt->execute();
    $fetch_result = $fetch_stmt->get_result();
    $updated_user = $fetch_result->fetch_assoc();
    $fetch_stmt->close();
    
    // ==============================================
    // STEP 10: Prepare role mapping
    // ==============================================
    $role_names = [
        0 => 'Admin',
        1 => 'Manager',
        2 => 'Cashier',
        3 => 'Stock Keeper'
    ];
    
    // ==============================================
    // STEP 11: Prepare success response
    // ==============================================
    $response = [
        'success' => true,
        'message' => 'Profile updated successfully',
        'data' => [
            'user' => [
                'id' => (int)$updated_user['id'],
                'name' => $updated_user['name'],
                'email' => $updated_user['email'],
                'role' => [
                    'id' => (int)$updated_user['role'],
                    'name' => $role_names[$updated_user['role']] ?? 'Unknown'
                ],
                'email_verified' => !empty($updated_user['email_verified_at']),
                'created_at' => $updated_user['created_at'],
                'updated_at' => $updated_user['updated_at']
            ],
            'updated_fields' => [
                'name_updated' => !empty($name),
                'email_updated' => !empty($email)
            ]
        ],
    ];
    
    // ==============================================
    // STEP 12: Send success response
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
    ];
    
    http_response_code($status_code);
    echo json_encode($error_response, JSON_PRETTY_PRINT);
    
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        @$mysqli->close();
    }
}