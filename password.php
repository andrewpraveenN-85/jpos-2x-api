<?php
// password.php - User Password Update API
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
    // STEP 4: Get request data
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
    
    // ==============================================
    // STEP 5: Get user ID and validate input
    // ==============================================
    // Get user ID - could come from authentication token or request
    $user_id = $headers['X-User-ID'] ?? 
               $_SERVER['HTTP_X_USER_ID'] ?? 
               ($headers['x-user-id'] ?? ($post_data['user_id'] ?? null));
    
    if (empty($user_id)) {
        throw new Exception('User ID is required for password update', 400);
    }
    
    // Validate required fields
    $required_fields = ['current_password', 'new_password', 'retype_new_password'];
    foreach ($required_fields as $field) {
        if (empty($post_data[$field])) {
            throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required', 400);
        }
    }
    
    $current_password = $post_data['current_password'];
    $new_password = $post_data['new_password'];
    $retype_new_password = $post_data['retype_new_password'];
    
    // ==============================================
    // STEP 6: Validate password requirements
    // ==============================================
    // Check if new passwords match
    if ($new_password !== $retype_new_password) {
        throw new Exception('New password and retype password do not match', 400);
    }
    
    // Check if new password is different from current password
    if ($new_password === $current_password) {
        throw new Exception('New password must be different from current password', 400);
    }
    
    // Validate password strength (customize as needed)
    $password_min_length = 8;
    if (strlen($new_password) < $password_min_length) {
        throw new Exception('New password must be at least ' . $password_min_length . ' characters long', 400);
    }
    
    // Optional: Add more password complexity rules
    if (!preg_match('/[A-Z]/', $new_password)) {
        throw new Exception('New password must contain at least one uppercase letter', 400);
    }
    
    if (!preg_match('/[a-z]/', $new_password)) {
        throw new Exception('New password must contain at least one lowercase letter', 400);
    }
    
    if (!preg_match('/[0-9]/', $new_password)) {
        throw new Exception('New password must contain at least one number', 400);
    }
    
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new_password)) {
        throw new Exception('New password must contain at least one special character', 400);
    }
    
    // ==============================================
    // STEP 7: Fetch user and verify current password
    // ==============================================
    $query = "SELECT id, password FROM users WHERE id = ? LIMIT 1";
    $stmt = $mysqli->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $mysqli->error, 500);
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        throw new Exception('User not found', 404);
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Verify current password
    if (!password_verify($current_password, $user['password'])) {
        throw new Exception('Current password is incorrect', 401);
    }
    
    // ==============================================
    // STEP 8: Check password history (optional)
    // ==============================================
    // You could implement password history check here
    // Query a password_history table or check against last N passwords
    
    // ==============================================
    // STEP 9: Hash new password and update database
    // ==============================================
    $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    $update_query = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ? LIMIT 1";
    $update_stmt = $mysqli->prepare($update_query);
    
    if (!$update_stmt) {
        throw new Exception("Failed to prepare update query: " . $mysqli->error, 500);
    }
    
    $update_stmt->bind_param("si", $hashed_new_password, $user_id);
    $update_stmt->execute();
    
    // Check if update was successful
    if ($update_stmt->affected_rows === 0) {
        $update_stmt->close();
        throw new Exception('Failed to update password', 500);
    }
    
    $update_stmt->close();
    
    // ==============================================
    // STEP 10: Optional - Log password change
    // ==============================================
    // You could log this action in an audit_log table
    // $log_query = "INSERT INTO password_history (user_id, changed_at) VALUES (?, NOW())";
    // $log_stmt = $mysqli->prepare($log_query);
    // if ($log_stmt) {
    //     $log_stmt->bind_param("i", $user_id);
    //     $log_stmt->execute();
    //     $log_stmt->close();
    // }
    
    // ==============================================
    // STEP 11: Prepare success response
    // ==============================================
    $response = [
        'success' => true,
        'message' => 'Password updated successfully',
        'data' => [
            'password_updated' => true,
            'password_changed_at' => date('Y-m-d H:i:s'),
            'password_requirements_met' => [
                'min_length' => $password_min_length,
                'has_uppercase' => true,
                'has_lowercase' => true,
                'has_number' => true,
                'has_special_char' => true
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