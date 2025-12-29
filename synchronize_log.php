<?php
// synchronize_log.php - Synchronization Logs API
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

// Helper function to get available tables
function getAvailableTables($mysqli) {
    $query = "SELECT DISTINCT table_name FROM syn_logs WHERE table_name IS NOT NULL ORDER BY table_name";
    $result = $mysqli->query($query);
    $tables = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $tables[] = $row['table_name'];
        }
        $result->free();
    }
    
    return $tables;
}

// Helper function to get available modules
function getAvailableModules($mysqli) {
    $query = "SELECT DISTINCT module FROM syn_logs WHERE module IS NOT NULL ORDER BY module";
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
    $query = "SELECT DISTINCT action FROM syn_logs ORDER BY action";
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

// Helper function to get sync status summary
function getSyncStatusSummary($mysqli, $where_clause, $params, $param_types) {
    $summary_query = "SELECT 
                        COUNT(CASE WHEN synced_at IS NOT NULL THEN 1 END) as synced_count,
                        COUNT(CASE WHEN synced_at IS NULL THEN 1 END) as pending_count,
                        MIN(created_at) as first_sync_record,
                        MAX(created_at) as last_sync_record,
                        MIN(synced_at) as first_synced_at,
                        MAX(synced_at) as last_synced_at
                     FROM syn_logs sl
                     $where_clause";
    
    $summary_stmt = $mysqli->prepare($summary_query);
    if (!$summary_stmt) {
        return null;
    }
    
    if (!empty($params)) {
        $summary_stmt->bind_param($param_types, ...$params);
    }
    
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    $summary = $summary_result->fetch_assoc();
    $summary_stmt->close();
    
    return $summary ?: [
        'synced_count' => 0,
        'pending_count' => 0,
        'first_sync_record' => null,
        'last_sync_record' => null,
        'first_synced_at' => null,
        'last_synced_at' => null
    ];
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
    
    // ==============================================
    // STEP 4: Get filter parameters from query string
    // ==============================================
    $filters = [
        'user_id' => isset($_GET['user_id']) && $_GET['user_id'] !== '' ? $_GET['user_id'] : null,
        'table_name' => isset($_GET['table_name']) && $_GET['table_name'] !== '' ? $_GET['table_name'] : null,
        'module' => isset($_GET['module']) && $_GET['module'] !== '' ? $_GET['module'] : null,
        'action' => isset($_GET['action']) && $_GET['action'] !== '' ? $_GET['action'] : null,
        'sync_status' => isset($_GET['sync_status']) && $_GET['sync_status'] !== '' ? $_GET['sync_status'] : null, // 'synced' or 'pending'
        'start_date' => isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : null,
        'end_date' => isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : null,
        'synced_start_date' => isset($_GET['synced_start_date']) && $_GET['synced_start_date'] !== '' ? $_GET['synced_start_date'] : null,
        'synced_end_date' => isset($_GET['synced_end_date']) && $_GET['synced_end_date'] !== '' ? $_GET['synced_end_date'] : null,
        'page' => isset($_GET['page']) ? (int)$_GET['page'] : 1,
        'per_page' => isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50,
        'search' => isset($_GET['search']) && $_GET['search'] !== '' ? $_GET['search'] : null
    ];
    
    // Validate and sanitize parameters
    if ($filters['page'] < 1) $filters['page'] = 1;
    if ($filters['per_page'] < 1 || $filters['per_page'] > 100) $filters['per_page'] = 50;
    
    // Validate date formats
    $date_fields = ['start_date', 'end_date', 'synced_start_date', 'synced_end_date'];
    foreach ($date_fields as $field) {
        if (!empty($filters[$field]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$field])) {
            throw new Exception('Invalid ' . str_replace('_', ' ', $field) . ' format. Use YYYY-MM-DD', 400);
        }
    }
    
    // Validate sync status
    if (!empty($filters['sync_status']) && !in_array($filters['sync_status'], ['synced', 'pending'])) {
        throw new Exception('Invalid sync_status. Use "synced" or "pending"', 400);
    }
    
    // ==============================================
    // STEP 5: Build dynamic WHERE clause
    // ==============================================
    $where_conditions = [];
    $params = [];
    $param_types = "";
    
    // User ID filter
    if (!empty($filters['user_id'])) {
        $where_conditions[] = "sl.user_id = ?";
        $params[] = $filters['user_id'];
        $param_types .= "i";
    }
    
    // Table name filter
    if (!empty($filters['table_name'])) {
        $where_conditions[] = "sl.table_name = ?";
        $params[] = $filters['table_name'];
        $param_types .= "s";
    }
    
    // Module filter
    if (!empty($filters['module'])) {
        $where_conditions[] = "sl.module = ?";
        $params[] = $filters['module'];
        $param_types .= "s";
    }
    
    // Action filter
    if (!empty($filters['action'])) {
        $where_conditions[] = "sl.action = ?";
        $params[] = $filters['action'];
        $param_types .= "s";
    }
    
    // Sync status filter
    if (!empty($filters['sync_status'])) {
        if ($filters['sync_status'] === 'synced') {
            $where_conditions[] = "sl.synced_at IS NOT NULL";
        } elseif ($filters['sync_status'] === 'pending') {
            $where_conditions[] = "sl.synced_at IS NULL";
        }
    }
    
    // Created date filters
    if (!empty($filters['start_date'])) {
        $where_conditions[] = "DATE(sl.created_at) >= ?";
        $params[] = $filters['start_date'];
        $param_types .= "s";
    }
    
    if (!empty($filters['end_date'])) {
        $where_conditions[] = "DATE(sl.created_at) <= ?";
        $params[] = $filters['end_date'];
        $param_types .= "s";
    }
    
    // Synced date filters
    if (!empty($filters['synced_start_date'])) {
        $where_conditions[] = "DATE(sl.synced_at) >= ?";
        $params[] = $filters['synced_start_date'];
        $param_types .= "s";
    }
    
    if (!empty($filters['synced_end_date'])) {
        $where_conditions[] = "DATE(sl.synced_at) <= ?";
        $params[] = $filters['synced_end_date'];
        $param_types .= "s";
    }
    
    // Search filter
    if (!empty($filters['search'])) {
        $where_conditions[] = "(sl.table_name LIKE ? OR sl.module LIKE ? OR sl.action LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
        $search_term = "%" . $filters['search'] . "%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $param_types .= "sssss";
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
                    FROM syn_logs sl
                    LEFT JOIN users u ON sl.user_id = u.id 
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
    // STEP 8: Fetch sync logs with pagination
    // ==============================================
    $sync_logs = [];
    
    // Only fetch logs if per_page > 0
    if ($filters['per_page'] > 0) {
        $logs_query = "SELECT 
                        sl.id,
                        sl.user_id,
                        u.name as user_name,
                        u.email as user_email,
                        sl.table_name,
                        sl.module,
                        sl.action,
                        sl.synced_at,
                        sl.created_at,
                        sl.updated_at
                       FROM syn_logs sl
                       LEFT JOIN users u ON sl.user_id = u.id 
                       $where_clause
                       ORDER BY sl.created_at DESC
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
        
        while ($row = $logs_result->fetch_assoc()) {
            $sync_logs[] = [
                'id' => (int)$row['id'],
                'user' => $row['user_id'] ? [
                    'id' => (int)$row['user_id'],
                    'name' => $row['user_name'],
                    'email' => $row['user_email']
                ] : null,
                'table_name' => $row['table_name'],
                'module' => $row['module'],
                'action' => $row['action'],
                'sync_status' => !empty($row['synced_at']) ? 'synced' : 'pending',
                'synced_at' => $row['synced_at'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'formatted_created_at' => date('Y-m-d H:i:s', strtotime($row['created_at'])),
                'formatted_synced_at' => $row['synced_at'] ? date('Y-m-d H:i:s', strtotime($row['synced_at'])) : null,
                'sync_duration' => $row['synced_at'] ? 
                    strtotime($row['synced_at']) - strtotime($row['created_at']) : null
            ];
        }
        
        $logs_stmt->close();
    }
    
    // ==============================================
    // STEP 9: Get summary statistics
    // ==============================================
    $summary = getSyncStatusSummary($mysqli, $where_clause, $params, $param_types);
    
    // ==============================================
    // STEP 10: Get top tables and modules
    // ==============================================
    $top_tables = [];
    $top_modules = [];
    $top_users = [];
    
    // Only get top data if we have logs
    if ($total_count > 0) {
        // Top tables
        $top_tables_query = "SELECT 
                             table_name,
                             COUNT(*) as count
                             FROM syn_logs sl
                             $where_clause
                             GROUP BY table_name
                             ORDER BY count DESC
                             LIMIT 5";
        
        $top_tables_stmt = $mysqli->prepare($top_tables_query);
        if ($top_tables_stmt) {
            if (!empty($params)) {
                $top_tables_stmt->bind_param($param_types, ...$params);
            }
            
            $top_tables_stmt->execute();
            $top_tables_result = $top_tables_stmt->get_result();
            
            while ($row = $top_tables_result->fetch_assoc()) {
                $top_tables[] = [
                    'table_name' => $row['table_name'],
                    'count' => (int)$row['count']
                ];
            }
            $top_tables_stmt->close();
        }
        
        // Top modules
        $top_modules_query = "SELECT 
                             module,
                             COUNT(*) as count
                             FROM syn_logs sl
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
        
        // Top users by sync activity
        $top_users_query = "SELECT 
                           u.id,
                           u.name,
                           u.email,
                           COUNT(*) as sync_count
                           FROM syn_logs sl
                           LEFT JOIN users u ON sl.user_id = u.id
                           $where_clause
                           GROUP BY sl.user_id, u.name, u.email
                           ORDER BY sync_count DESC
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
                    'sync_count' => (int)$row['sync_count']
                ];
            }
            $top_users_stmt->close();
        }
    }
    
    // ==============================================
    // STEP 11: Get meta information
    // ==============================================
    $available_tables = getAvailableTables($mysqli);
    $available_modules = getAvailableModules($mysqli);
    $available_actions = getAvailableActions($mysqli);
    $all_users = getAllUsers($mysqli);
    
    // ==============================================
    // STEP 12: Prepare final response
    // ==============================================
    $response = [
        'success' => true,
        'message' => 'Synchronization logs retrieved successfully',
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
            'summary' => [
                'total_records' => (int)$total_count,
                'synced_count' => (int)$summary['synced_count'],
                'pending_count' => (int)$summary['pending_count'],
                'sync_rate' => $total_count > 0 ? round(($summary['synced_count'] / $total_count) * 100, 2) : 0,
                'date_range' => [
                    'first_record' => $summary['first_sync_record'],
                    'last_record' => $summary['last_sync_record'],
                    'first_synced' => $summary['first_synced_at'],
                    'last_synced' => $summary['last_synced_at']
                ]
            ],
            'top_tables' => $top_tables,
            'top_modules' => $top_modules,
            'top_users' => $top_users,
            'sync_logs' => $sync_logs
        ],
        'meta' => [
            'available_tables' => $available_tables,
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