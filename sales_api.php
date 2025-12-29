<?php
// sales_api.php - Sales listing API with optional returns inclusion
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
    $invoice_no = isset($_GET['invoice_no']) && $_GET['invoice_no'] !== '' ? trim($_GET['invoice_no']) : null;
    $customer_id = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int)$_GET['customer_id'] : null;
    $user_id = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
    $type = isset($_GET['type']) && $_GET['type'] !== '' ? (int)$_GET['type'] : null;
    $date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
    $date_to = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
    if ($per_page < 1 || $per_page > 500) $per_page = 50;

    $where = [];
    $params = [];
    $types = '';

    if ($id !== null) { $where[] = 's.id = ?'; $params[] = $id; $types .= 'i'; }
    if ($invoice_no !== null) { $where[] = 's.invoice_no = ?'; $params[] = $invoice_no; $types .= 's'; }
    if ($customer_id !== null) { $where[] = 's.customer_id = ?'; $params[] = $customer_id; $types .= 'i'; }
    if ($user_id !== null) { $where[] = 's.user_id = ?'; $params[] = $user_id; $types .= 'i'; }
    if ($type !== null) { $where[] = 's.type = ?'; $params[] = $type; $types .= 'i'; }
    if ($date_from !== null) { $where[] = 's.sale_date >= ?'; $params[] = $date_from; $types .= 's'; }
    if ($date_to !== null) { $where[] = 's.sale_date <= ?'; $params[] = $date_to; $types .= 's'; }

    $where_clause = '';
    if (!empty($where)) $where_clause = 'WHERE ' . implode(' AND ', $where);

    // Count total
    $count_sql = "SELECT COUNT(*) as total FROM sales s $where_clause";
    $count_stmt = $mysqli->prepare($count_sql);
    if (!$count_stmt) throw new Exception('Failed to prepare count query: ' . $mysqli->error, 500);
    if (!empty($params)) $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_res = $count_stmt->get_result()->fetch_assoc();
    $total = $count_res ? (int)$count_res['total'] : 0;
    $count_stmt->close();

    $offset = ($page - 1) * $per_page;

    $fields = [
        's.id','s.invoice_no','s.customer_id','s.user_id','s.total_amount','s.discount','s.net_amount','s.balance','s.has_return','s.sale_date','s.type','s.created_at','s.updated_at'
    ];

    $select_sql = 'SELECT ' . implode(', ', $fields) . " FROM sales s $where_clause ORDER BY s.id ASC LIMIT ? OFFSET ?";
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
    // Candidate return table names to check
    $candidate_return_tables = ['sale_returns','sale_return','sales_returns','returns','returns_items','sale_return_items','sales_return'];

    while ($row = $res->fetch_assoc()) {
        $sale = [
            'id' => (int)$row['id'],
            'invoice_no' => $row['invoice_no'],
            'customer_id' => $row['customer_id'] !== null ? (int)$row['customer_id'] : null,
            'user_id' => (int)$row['user_id'],
            'total_amount' => isset($row['total_amount']) ? (float)$row['total_amount'] : null,
            'discount' => isset($row['discount']) ? (float)$row['discount'] : null,
            'net_amount' => isset($row['net_amount']) ? (float)$row['net_amount'] : null,
            'balance' => isset($row['balance']) ? (float)$row['balance'] : null,
            'has_return' => (int)$row['has_return'],
            'sale_date' => $row['sale_date'],
            'type' => isset($row['type']) ? (int)$row['type'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];

        $sale['returns'] = [];
        if ((int)$row['has_return'] === 1) {
            foreach ($candidate_return_tables as $tbl) {
                $tbl_esc = $mysqli->real_escape_string($tbl);
                $check = $mysqli->query("SHOW TABLES LIKE '" . $tbl_esc . "'");
                if ($check && $check->num_rows > 0) {
                    // try to fetch return records by sale id or invoice
                    $ret_sql = "SELECT * FROM `" . $tbl_esc . "` WHERE `sale_id` = ? OR `invoice_no` = ? LIMIT 200";
                    $ret_stmt = $mysqli->prepare($ret_sql);
                    if ($ret_stmt) {
                        $ret_stmt->bind_param('is', $row['id'], $row['invoice_no']);
                        if ($ret_stmt->execute()) {
                            $rres = $ret_stmt->get_result();
                            while ($rrow = $rres->fetch_assoc()) {
                                $sale['returns'][] = $rrow;
                            }
                        }
                        $ret_stmt->close();
                    } else {
                        // fallback: attempt simple select without params
                        $simple_q = "SELECT * FROM `" . $tbl_esc . "` WHERE sale_id = " . (int)$row['id'] . " OR invoice_no = '" . $mysqli->real_escape_string($row['invoice_no']) . "' LIMIT 200";
                        $qres = $mysqli->query($simple_q);
                        if ($qres) {
                            while ($rrow = $qres->fetch_assoc()) $sale['returns'][] = $rrow;
                        }
                    }
                    // if found any returns, stop checking other candidate tables
                    if (!empty($sale['returns'])) break;
                }
            }
        }

        $items[] = $sale;
    }

    $stmt->close();
    $mysqli->close();

    $response = [
        'success' => true,
        'message' => 'Sales retrieved',
        'data' => [
            'filters' => ['id' => $id, 'invoice_no' => $invoice_no, 'customer_id' => $customer_id, 'user_id' => $user_id, 'type' => $type, 'date_from' => $date_from, 'date_to' => $date_to],
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
