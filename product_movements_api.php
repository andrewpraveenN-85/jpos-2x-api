<?php
// product_movements_api.php - Product Movements API with product details
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
    $product_id = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int)$_GET['product_id'] : null;
    $movement_type = isset($_GET['movement_type']) && $_GET['movement_type'] !== '' ? (int)$_GET['movement_type'] : null;
    $reference = isset($_GET['reference']) && $_GET['reference'] !== '' ? trim($_GET['reference']) : null;
    $date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
    $date_to = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
    if ($per_page < 1 || $per_page > 500) $per_page = 50;

    $where = [];
    $params = [];
    $types = '';

    if ($id !== null) { $where[] = 'pm.id = ?'; $params[] = $id; $types .= 'i'; }
    if ($product_id !== null) { $where[] = 'pm.product_id = ?'; $params[] = $product_id; $types .= 'i'; }
    if ($movement_type !== null) { $where[] = 'pm.movement_type = ?'; $params[] = $movement_type; $types .= 'i'; }
    if ($reference !== null) { $where[] = 'pm.reference = ?'; $params[] = $reference; $types .= 's'; }
    if ($date_from !== null) { $where[] = 'pm.created_at >= ?'; $params[] = $date_from; $types .= 's'; }
    if ($date_to !== null) { $where[] = 'pm.created_at <= ?'; $params[] = $date_to; $types .= 's'; }

    $where_clause = '';
    if (!empty($where)) $where_clause = 'WHERE ' . implode(' AND ', $where);

    // Count total
    $count_sql = "SELECT COUNT(*) as total FROM product_movements pm $where_clause";
    $count_stmt = $mysqli->prepare($count_sql);
    if (!$count_stmt) throw new Exception('Failed to prepare count query: ' . $mysqli->error, 500);
    if (!empty($params)) $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_res = $count_stmt->get_result()->fetch_assoc();
    $total = $count_res ? (int)$count_res['total'] : 0;
    $count_stmt->close();

    $offset = ($page - 1) * $per_page;

    $fields = [
        'pm.id','pm.product_id','pm.movement_type','pm.quantity','pm.reference','pm.created_at','pm.updated_at',
        'p.name as product_name','p.barcode as product_barcode','p.retail_price as product_retail_price','p.wholesale_price as product_wholesale_price',
        'p.purchase_unit_id','p.sales_unit_id','p.transfer_unit_id',
        'pu.name AS purchase_unit_name','pu.symbol AS purchase_unit_symbol',
        'su.name AS sales_unit_name','su.symbol AS sales_unit_symbol',
        'tu.name AS transfer_unit_name','tu.symbol AS transfer_unit_symbol'
    ];

    $select_sql = 'SELECT ' . implode(', ', $fields) . " FROM product_movements pm 
        LEFT JOIN products p ON pm.product_id = p.id 
        LEFT JOIN measurement_units pu ON p.purchase_unit_id = pu.id 
        LEFT JOIN measurement_units su ON p.sales_unit_id = su.id 
        LEFT JOIN measurement_units tu ON p.transfer_unit_id = tu.id 
        $where_clause ORDER BY pm.id DESC LIMIT ? OFFSET ?";
    $stmt = $mysqli->prepare($select_sql);
    if (!$stmt) throw new Exception('Failed to prepare select query: ' . $mysqli->error, 500);

    $all_params = $params;
    $all_types = $types . 'ii';
    $all_params[] = $per_page;
    $all_params[] = $offset;
    if (!empty($all_params)) $stmt->bind_param($all_types, ...$all_params);

    $stmt->execute();
    $res = $stmt->get_result();

    // Movement type mapping
    $movement_types = [
        0 => 'Purchase',
        1 => 'Purchase Return',
        2 => 'Transfer',
        3 => 'Sale',
        4 => 'Sale Return',
        5 => 'BRN Return'
    ];

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $product = null;
        if ($row['product_id'] !== null) {
            // Determine unit symbol based on movement type
            $movement_type_value = (int)$row['movement_type'];
            if ($movement_type_value === 0) {
                $unit_symbol = $row['purchase_unit_symbol'] ?? null;
            } elseif ($movement_type_value === 2) {
                $unit_symbol = $row['transfer_unit_symbol'] ?? null;
            } else {
                $unit_symbol = $row['sales_unit_symbol'] ?? null;
            }
            
            $product = [
                'id' => (int)$row['product_id'],
                'name' => $row['product_name'],
                'barcode' => $row['product_barcode'],
                'retail_price' => $row['product_retail_price'] !== null ? (float)$row['product_retail_price'] : null,
                'wholesale_price' => $row['product_wholesale_price'] !== null ? (float)$row['product_wholesale_price'] : null,
                'purchase_unit_id' => isset($row['purchase_unit_id']) ? (int)$row['purchase_unit_id'] : null,
                'purchase_unit' => [
                    'id' => isset($row['purchase_unit_id']) ? (int)$row['purchase_unit_id'] : null,
                    'name' => $row['purchase_unit_name'] ?? null,
                    'symbol' => $row['purchase_unit_symbol'] ?? null
                ],
                'sales_unit_id' => isset($row['sales_unit_id']) ? (int)$row['sales_unit_id'] : null,
                'sales_unit' => [
                    'id' => isset($row['sales_unit_id']) ? (int)$row['sales_unit_id'] : null,
                    'name' => $row['sales_unit_name'] ?? null,
                    'symbol' => $row['sales_unit_symbol'] ?? null
                ],
                'transfer_unit_id' => isset($row['transfer_unit_id']) ? (int)$row['transfer_unit_id'] : null,
                'transfer_unit' => [
                    'id' => isset($row['transfer_unit_id']) ? (int)$row['transfer_unit_id'] : null,
                    'name' => $row['transfer_unit_name'] ?? null,
                    'symbol' => $row['transfer_unit_symbol'] ?? null
                ],
                'unit_symbol' => $unit_symbol
            ];
        }

        $movement_type_value = (int)$row['movement_type'];
        $movement_type_label = $movement_types[$movement_type_value] ?? 'Unknown';

        $items[] = [
            'id' => (int)$row['id'],
            'product_id' => (int)$row['product_id'],
            'product' => $product,
            'movement_type' => $movement_type_value,
            'movement_type_label' => $movement_type_label,
            'quantity' => isset($row['quantity']) ? (float)$row['quantity'] : null,
            'reference' => $row['reference'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    $stmt->close();
    $mysqli->close();

    $response = [
        'success' => true,
        'message' => 'Product movements retrieved',
        'data' => [
            'filters' => ['id' => $id, 'product_id' => $product_id, 'movement_type' => $movement_type, 'reference' => $reference, 'date_from' => $date_from, 'date_to' => $date_to],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total,
                'total_pages' => $per_page > 0 ? ceil($total / $per_page) : 0
            ],
            'items' => $items,
            'movement_types' => $movement_types
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
