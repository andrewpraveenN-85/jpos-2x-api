<?php
// activity_log.php - Activity Logs Report API
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

// Helper function to get available modules
function getAvailableModules($mysqli) {
    $query = "SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL ORDER BY module";
    $result = $mysqli->query($query);
    $modules = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $modules[] = $row['module'];
        }
        $result->free();
    }
    
    return $modules;
}

// Helper function to get available actions
function getAvailableActions($mysqli) {
    $query = "SELECT DISTINCT action FROM activity_logs ORDER BY action";
    $result = $mysqli->query($query);
    $actions = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $actions[] = $row['action'];
        }
        $result->free();
    }
    
    return $actions;
}

// Helper function to get all users
function getAllUsers($mysqli) {
    $query = "SELECT id, name, email, role FROM users ORDER BY name";
    $result = $mysqli->query($query);
    $users = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'role' => (int)$row['role']
            ];
        }
        $result->free();
    }
    
    return $users;
}

try { 
    // ==============================================
    // STEP 1: Check request method
    // ==============================================
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Only GET method is allowed', 405);
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
    
    // Debug: Log extracted headers
    $debug_info = [
        'extracted_headers' => [
            'host' => $db_host,
            'user' => $db_user,
            'pass' => !empty($db_pass) ? '***SET***' : 'EMPTY',
            'name' => $db_name,
            'port' => $db_port
        ]
    ];
    
    // Validate required fields
    if (empty($db_user) || empty($db_name)) {
        $error_msg = 'Database configuration incomplete. Required: X-DB-User and X-DB-Name. ';
        $error_msg .= 'Received: user=' . ($db_user ?: 'empty') . ', name=' . ($db_name ?: 'empty');
        throw new Exception($error_msg, 400);
    }
    
    // ==============================================
    // STEP 3: Connect to database
    // ==============================================
    $mysqli = @new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
    
    if ($mysqli->connect_error) {
        throw new Exception("Database connection failed: " . $mysqli->connect_error, 500);
    }
    
    $mysqli->set_charset("utf8mb4");
    $debug_info['db_connection'] = 'SUCCESS';
    
    // ==============================================
    // STEP 4: Get filter parameters from query string
    // ==============================================
    $filters = [
        'user_id' => isset($_GET['user_id']) && $_GET['user_id'] !== '' ? $_GET['user_id'] : null,
        'start_date' => isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : null,
        'end_date' => isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : null,
        'module' => isset($_GET['module']) && $_GET['module'] !== '' ? $_GET['module'] : null,
        'action' => isset($_GET['action']) && $_GET['action'] !== '' ? $_GET['action'] : null,
        'page' => isset($_GET['page']) ? (int)$_GET['page'] : 1,
        'per_page' => isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50,
        'search' => isset($_GET['search']) && $_GET['search'] !== '' ? $_GET['search'] : null
    ];
    
    // Validate and sanitize parameters
    if ($filters['page'] < 1) $filters['page'] = 1;
    if ($filters['per_page'] < 1 || $filters['per_page'] > 100) $filters['per_page'] = 50;
    
    // Validate date formats
    if ($filters['start_date'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['start_date'])) {
        throw new Exception('Invalid start date format. Use YYYY-MM-DD', 400);
    }
    
    if ($filters['end_date'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['end_date'])) {
        throw new Exception('Invalid end date format. Use YYYY-MM-DD', 400);
    }
    
    // ==============================================
    // STEP 5: Build dynamic WHERE clause
    // ==============================================
    $where_conditions = [];
    $params = [];
    $param_types = "";
    
    // User ID filter
    if (!empty($filters['user_id'])) {
        $where_conditions[] = "al.user_id = ?";
        $params[] = $filters['user_id'];
        $param_types .= "i";
    }
    
    // Start date filter
    if (!empty($filters['start_date'])) {
        $where_conditions[] = "DATE(al.created_at) >= ?";
        $params[] = $filters['start_date'];
        $param_types .= "s";
    }
    
    // End date filter
    if (!empty($filters['end_date'])) {
        $where_conditions[] = "DATE(al.created_at) <= ?";
        $params[] = $filters['end_date'];
        $param_types .= "s";
    }
    
    // Module filter
    if (!empty($filters['module'])) {
        $where_conditions[] = "al.module = ?";
        $params[] = $filters['module'];
        $param_types .= "s";
    }
    
    // Action filter
    if (!empty($filters['action'])) {
        $where_conditions[] = "al.action = ?";
        $params[] = $filters['action'];
        $param_types .= "s";
    }
    
    // Search filter (search in details or user name)
    if (!empty($filters['search'])) {
        $where_conditions[] = "(al.details LIKE ? OR u.name LIKE ? OR al.module LIKE ? OR al.action LIKE ?)";
        $search_term = "%" . $filters['search'] . "%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $param_types .= "ssss";
    }
    
    // Build WHERE clause
    $where_clause = "";
    if (!empty($where_conditions)) {
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    }
    
    // ==============================================
    // STEP 6: Get total count for pagination
    // ==============================================
    $count_query = "SELECT COUNT(*) as total 
                    FROM activity_logs al
                    LEFT JOIN users u ON al.user_id = u.id 
                    $where_clause";
    
    $count_stmt = $mysqli->prepare($count_query);
    if (!$count_stmt) {
        throw new Exception("Failed to prepare count query: " . $mysqli->error, 500);
    }
    
    // Bind parameters if any
    if (!empty($params)) {
        $count_stmt->bind_param($param_types, ...$params);
    }
    
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_row = $count_result->fetch_assoc();
    $total_count = $total_row ? $total_row['total'] : 0;
    $count_stmt->close();
    
    // ==============================================
    // STEP 7: Calculate pagination
    // ==============================================
    $offset = ($filters['page'] - 1) * $filters['per_page'];
    $total_pages = $filters['per_page'] > 0 ? ceil($total_count / $filters['per_page']) : 0;
    
    // ==============================================
    // STEP 8: Fetch activity logs with pagination
    // ==============================================
    $activity_logs = [];
    
    // Only fetch logs if per_page > 0
    if ($filters['per_page'] > 0) {
        $logs_query = "SELECT 
                        al.id,
                        al.user_id,
                        u.name as user_name,
                        u.email as user_email,
                        al.action,
                        al.module,
                        al.details,
                        al.created_at,
                        al.updated_at
                       FROM activity_logs al
                       LEFT JOIN users u ON al.user_id = u.id 
                       $where_clause
                       ORDER BY al.created_at DESC
                       LIMIT ? OFFSET ?";
        
        $logs_stmt = $mysqli->prepare($logs_query);
        if (!$logs_stmt) {
            throw new Exception("Failed to prepare logs query: " . $mysqli->error, 500);
        }
        
        // Add pagination parameters
        $all_params = $params;
        $all_param_types = $param_types . "ii";
        $all_params[] = $filters['per_page'];
        $all_params[] = $offset;
        
        // Bind parameters if any
        if (!empty($all_params)) {
            $logs_stmt->bind_param($all_param_types, ...$all_params);
        } else {
            // If no filters, bind just pagination params
            $logs_stmt->bind_param("ii", $filters['per_page'], $offset);
        }
        
        $logs_stmt->execute();
        $logs_result = $logs_stmt->get_result();
        
        // Parse JSON details field
        while ($row = $logs_result->fetch_assoc()) {
            $details = [];
            if (!empty($row['details'])) {
                $decoded_details = @json_decode($row['details'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $details = $decoded_details;
                } else {
                    $details = ['raw' => $row['details']];
                }
            }
            
            $activity_logs[] = [
                'id' => (int)$row['id'],
                'user' => $row['user_id'] ? [
                    'id' => (int)$row['user_id'],
                    'name' => $row['user_name'],
                    'email' => $row['user_email']
                ] : null,
                'action' => $row['action'],
                'module' => $row['module'],
                'details' => $details,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'formatted_date' => date('Y-m-d H:i:s', strtotime($row['created_at']))
            ];
        }
        
        $logs_stmt->close();
    }
    
    // ==============================================
    // STEP 9: Get summary statistics
    // ==============================================
    $stats_query = "SELECT 
                    COUNT(DISTINCT al.user_id) as unique_users,
                    COUNT(DISTINCT al.module) as unique_modules,
                    COUNT(DISTINCT al.action) as unique_actions,
                    MIN(DATE(al.created_at)) as first_log_date,
                    MAX(DATE(al.created_at)) as last_log_date
                   FROM activity_logs al
                   $where_clause";
    
    $stats_stmt = $mysqli->prepare($stats_query);
    if (!$stats_stmt) {
        throw new Exception("Failed to prepare stats query: " . $mysqli->error, 500);
    }
    
    // Bind parameters if any
    if (!empty($params)) {
        $stats_stmt->bind_param($param_types, ...$params);
    }
    
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $statistics = $stats_result->fetch_assoc();
    $stats_stmt->close();
    
    // Handle null values
    $statistics = $statistics ?: [
        'unique_users' => 0,
        'unique_modules' => 0,
        'unique_actions' => 0,
        'first_log_date' => null,
        'last_log_date' => null
    ];
    
    // ==============================================
    // STEP 10: Get top modules and actions
    // ==============================================
    $top_modules = [];
    $top_users = [];
    
    // Only get top data if we have logs
    if ($total_count > 0) {
        // Top modules
        $top_modules_query = "SELECT 
                             module,
                             COUNT(*) as count
                             FROM activity_logs al
                             $where_clause
                             GROUP BY module
                             ORDER BY count DESC
                             LIMIT 5";
        
        $top_modules_stmt = $mysqli->prepare($top_modules_query);
        if ($top_modules_stmt) {
            if (!empty($params)) {
                $top_modules_stmt->bind_param($param_types, ...$params);
            }
            
            $top_modules_stmt->execute();
            $top_modules_result = $top_modules_stmt->get_result();
            
            while ($row = $top_modules_result->fetch_assoc()) {
                $top_modules[] = [
                    'module' => $row['module'],
                    'count' => (int)$row['count']
                ];
            }
            $top_modules_stmt->close();
        }
        
        // Top users
        $top_users_query = "SELECT 
                           u.id,
                           u.name,
                           u.email,
                           COUNT(*) as activity_count
                           FROM activity_logs al
                           LEFT JOIN users u ON al.user_id = u.id
                           $where_clause
                           GROUP BY al.user_id, u.name, u.email
                           ORDER BY activity_count DESC
                           LIMIT 10";
        
        $top_users_stmt = $mysqli->prepare($top_users_query);
        if ($top_users_stmt) {
            if (!empty($params)) {
                $top_users_stmt->bind_param($param_types, ...$params);
            }
            
            $top_users_stmt->execute();
            $top_users_result = $top_users_stmt->get_result();
            
            while ($row = $top_users_result->fetch_assoc()) {
                $top_users[] = [
                    'user' => [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'email' => $row['email']
                    ],
                    'activity_count' => (int)$row['activity_count']
                ];
            }
            $top_users_stmt->close();
        }
    }
    
    // ==============================================
    // STEP 11: Get meta information
    // ==============================================
    $available_modules = getAvailableModules($mysqli);
    $available_actions = getAvailableActions($mysqli);
    $all_users = getAllUsers($mysqli);
    
    // ==============================================
    // STEP 12: Prepare final response
    // ==============================================
    $response = [
        'success' => true,
        'message' => 'Activity logs retrieved successfully',
        'data' => [
            'filters_applied' => $filters,
            'pagination' => [
                'current_page' => $filters['page'],
                'per_page' => $filters['per_page'],
                'total_items' => (int)$total_count,
                'total_pages' => $total_pages,
                'has_next_page' => $filters['page'] < $total_pages,
                'has_prev_page' => $filters['page'] > 1
            ],
            'statistics' => [
                'total_logs' => (int)$total_count,
                'unique_users' => (int)$statistics['unique_users'],
                'unique_modules' => (int)$statistics['unique_modules'],
                'unique_actions' => (int)$statistics['unique_actions'],
                'date_range' => [
                    'start' => $statistics['first_log_date'],
                    'end' => $statistics['last_log_date']
                ]
            ],
            'top_modules' => $top_modules,
            'top_users' => $top_users,
            'activity_logs' => $activity_logs
        ],
        'meta' => [
            'available_modules' => $available_modules,
            'available_actions' => $available_actions,
            'all_users' => $all_users
        ]
    ];
    
    // ==============================================
    // STEP 13: Send success response
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