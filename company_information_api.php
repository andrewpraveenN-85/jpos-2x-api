<?php
// company_information_api.php - Company Information API
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);

function getAllHeadersSimple() {
    if (function_exists('getallheaders')) return getallheaders();
    $headers = [];
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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Only GET method is allowed', 405);
    }

    $headers = getAllHeadersSimple();

    $db_host = $headers['X-DB-Host'] ?? $_SERVER['HTTP_X_DB_HOST'] ?? ($headers['x-db-host'] ?? 'localhost');
    $db_user = $headers['X-DB-User'] ?? $_SERVER['HTTP_X_DB_USER'] ?? ($headers['x-db-user'] ?? '');
    $db_pass = $headers['X-DB-Pass'] ?? $_SERVER['HTTP_X_DB_PASS'] ?? ($headers['x-db-pass'] ?? '');
    $db_name = $headers['X-DB-Name'] ?? $_SERVER['HTTP_X_DB_NAME'] ?? ($headers['x-db-name'] ?? '');
    $db_port = $headers['X-DB-Port'] ?? $_SERVER['HTTP_X_DB_PORT'] ?? ($headers['x-db-port'] ?? 3306);

    if (empty($db_user) || empty($db_name)) {
        throw new Exception('Database configuration incomplete. Required: X-DB-User and X-DB-Name', 400);
    }

    $mysqli = @new mysqli($db_host, $db_user, $db_pass, $db_name, (int)$db_port);
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error, 500);
    }
    $mysqli->set_charset('utf8mb4');

    // Filters & pagination
    $id = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;
    $company_name = isset($_GET['company_name']) && $_GET['company_name'] !== '' ? trim($_GET['company_name']) : null;
    $email = isset($_GET['email']) && $_GET['email'] !== '' ? trim($_GET['email']) : null;
    $phone = isset($_GET['phone']) && $_GET['phone'] !== '' ? trim($_GET['phone']) : null;
    $currency = isset($_GET['currency']) && $_GET['currency'] !== '' ? trim($_GET['currency']) : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
    if ($per_page < 1 || $per_page > 500) $per_page = 50;

    $where = [];
    $params = [];
    $types = '';

    if ($id !== null) { $where[] = 'ci.id = ?'; $params[] = $id; $types .= 'i'; }
    if ($company_name !== null) { $where[] = 'ci.company_name LIKE ?'; $params[] = '%' . $company_name . '%'; $types .= 's'; }
    if ($email !== null) { $where[] = 'ci.email LIKE ?'; $params[] = '%' . $email . '%'; $types .= 's'; }
    if ($phone !== null) { $where[] = 'ci.phone LIKE ?'; $params[] = '%' . $phone . '%'; $types .= 's'; }
    if ($currency !== null) { $where[] = 'ci.currency = ?'; $params[] = $currency; $types .= 's'; }

    $where_clause = '';
    if (!empty($where)) $where_clause = 'WHERE ' . implode(' AND ', $where);

    // Count total
    $count_sql = "SELECT COUNT(*) as total FROM company_information ci $where_clause";
    $count_stmt = $mysqli->prepare($count_sql);
    if (!$count_stmt) throw new Exception('Failed to prepare count query: ' . $mysqli->error, 500);
    if (!empty($params)) $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_res = $count_stmt->get_result()->fetch_assoc();
    $total = $count_res ? (int)$count_res['total'] : 0;
    $count_stmt->close();

    $offset = ($page - 1) * $per_page;

    $fields = [
        'ci.id','ci.company_name','ci.address','ci.phone','ci.email','ci.website','ci.logo','ci.currency','ci.created_at','ci.updated_at'
    ];

    $select_sql = 'SELECT ' . implode(', ', $fields) . " FROM company_information ci $where_clause ORDER BY ci.id ASC LIMIT ? OFFSET ?";
    $stmt = $mysqli->prepare($select_sql);
    if (!$stmt) throw new Exception('Failed to prepare select query: ' . $mysqli->error, 500);

    $all_params = $params;
    $all_types = $types . 'ii';
    $all_params[] = $per_page;
    $all_params[] = $offset;
    if (!empty($all_params)) $stmt->bind_param($all_types, ...$all_params);

    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'company_name' => $row['company_name'],
            'address' => $row['address'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'website' => $row['website'],
            'logo' => $row['logo'],
            'currency' => $row['currency'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    $stmt->close();
    $mysqli->close();

    $response = [
        'success' => true,
        'message' => 'Company information retrieved',
        'data' => [
            'filters' => ['id' => $id, 'company_name' => $company_name, 'email' => $email, 'phone' => $phone, 'currency' => $currency],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total,
                'total_pages' => $per_page > 0 ? ceil($total / $per_page) : 0
            ],
            'items' => $items
        ]
    ];

    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;

} catch (Exception $e) {
    $status_code = $e->getCode();
    if ($status_code < 100 || $status_code > 599) $status_code = 500;
    $error = [
        'error' => true,
        'status_code' => $status_code,
        'message' => $e->getMessage()
    ];
    http_response_code($status_code);
    echo json_encode($error, JSON_PRETTY_PRINT);
    exit;
}
