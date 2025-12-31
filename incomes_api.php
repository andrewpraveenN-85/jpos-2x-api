<?php
// incomes_api.php - Incomes listing API
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
    $sale_id = isset($_GET['sale_id']) && $_GET['sale_id'] !== '' ? (int)$_GET['sale_id'] : null;
    $source = isset($_GET['source']) && $_GET['source'] !== '' ? trim($_GET['source']) : null;
    $payment_type = isset($_GET['payment_type']) && $_GET['payment_type'] !== '' ? (int)$_GET['payment_type'] : null;
    $transaction_type = isset($_GET['transaction_type']) && $_GET['transaction_type'] !== '' ? trim($_GET['transaction_type']) : null;
    $date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
    $date_to = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null;
    $amount_min = isset($_GET['amount_min']) && $_GET['amount_min'] !== '' ? (float)$_GET['amount_min'] : null;
    $amount_max = isset($_GET['amount_max']) && $_GET['amount_max'] !== '' ? (float)$_GET['amount_max'] : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
    if ($per_page < 1 || $per_page > 500) $per_page = 50;

    $where = [];
    $params = [];
    $types = '';

    if ($id !== null) { $where[] = 'i.id = ?'; $params[] = $id; $types .= 'i'; }
    if ($sale_id !== null) { $where[] = 'i.sale_id = ?'; $params[] = $sale_id; $types .= 'i'; }
    if ($source !== null) { $where[] = 'i.source = ?'; $params[] = $source; $types .= 's'; }
    if ($payment_type !== null) { $where[] = 'i.payment_type = ?'; $params[] = $payment_type; $types .= 'i'; }
    if ($transaction_type !== null) { $where[] = 'i.transaction_type = ?'; $params[] = $transaction_type; $types .= 's'; }
    if ($date_from !== null) { $where[] = 'i.income_date >= ?'; $params[] = $date_from; $types .= 's'; }
    if ($date_to !== null) { $where[] = 'i.income_date <= ?'; $params[] = $date_to; $types .= 's'; }
    if ($amount_min !== null) { $where[] = 'i.amount >= ?'; $params[] = $amount_min; $types .= 'd'; }
    if ($amount_max !== null) { $where[] = 'i.amount <= ?'; $params[] = $amount_max; $types .= 'd'; }

    $where_clause = '';
    if (!empty($where)) $where_clause = 'WHERE ' . implode(' AND ', $where);

    // Count total
    $count_sql = "SELECT COUNT(*) as total FROM incomes i LEFT JOIN sales s ON i.sale_id = s.id $where_clause";
    $count_stmt = $mysqli->prepare($count_sql);
    if (!$count_stmt) throw new Exception('Failed to prepare count query: ' . $mysqli->error, 500);
    if (!empty($params)) $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_res = $count_stmt->get_result()->fetch_assoc();
    $total = $count_res ? (int)$count_res['total'] : 0;
    $count_stmt->close();

    $offset = ($page - 1) * $per_page;

    $fields = [
        'i.id','i.source','i.sale_id','i.amount','i.income_date','i.payment_type','i.transaction_type','i.created_at','i.updated_at','s.invoice_no'
    ];

    $select_sql = 'SELECT ' . implode(', ', $fields) . " FROM incomes i LEFT JOIN sales s ON i.sale_id = s.id $where_clause ORDER BY i.id ASC LIMIT ? OFFSET ?";
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
        $income = [
            'id' => (int)$row['id'],
            'source' => $row['source'],
            'sale_id' => $row['sale_id'] !== null ? (int)$row['sale_id'] : null,
            'invoice_no' => $row['invoice_no'] ?? null,
            'amount' => (float)$row['amount'],
            'income_date' => $row['income_date'],
            'payment_type' => (int)$row['payment_type'],
            'transaction_type' => $row['transaction_type'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
        $items[] = $income;
    }

    $stmt->close();
    $mysqli->close();

    $response = [
        'success' => true,
        'message' => 'Incomes retrieved',
        'data' => [
            'filters' => [
                'id' => $id,
                'sale_id' => $sale_id,
                'source' => $source,
                'payment_type' => $payment_type,
                'transaction_type' => $transaction_type,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'amount_min' => $amount_min,
                'amount_max' => $amount_max
            ],
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
