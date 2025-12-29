<?php
// products_partial.php - Partial product texts API
header('Content-Type: application/json');

// Enable error reporting for debugging (do not expose in production)
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

    // Filters and pagination
    $q = isset($_GET['q']) && $_GET['q'] !== '' ? trim($_GET['q']) : null; // partial text search on name or barcode
    $id = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;
    $brand_id = isset($_GET['brand_id']) && $_GET['brand_id'] !== '' ? (int)$_GET['brand_id'] : null;
    $category_id = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
    $status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null; // allow 0,1,2
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
    if ($per_page < 1 || $per_page > 200) $per_page = 50;

    $where = [];
    $params = [];
    $types = '';

    if ($id !== null) {
        $where[] = 'p.id = ?';
        $params[] = $id; $types .= 'i';
    }
    if ($brand_id !== null) {
        $where[] = 'p.brand_id = ?';
        $params[] = $brand_id; $types .= 'i';
    }
    if ($category_id !== null) {
        $where[] = 'p.category_id = ?';
        $params[] = $category_id; $types .= 'i';
    }
    if ($status !== null && $status !== '') {
        if (!in_array($status, ['0','1','2','0',0,1,2], true)) {
            throw new Exception('Invalid status value', 400);
        }
        $where[] = 'p.status = ?';
        $params[] = (int)$status; $types .= 'i';
    }
    if ($q !== null) {
        $where[] = '(p.name LIKE ? OR p.barcode LIKE ? OR p.image LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= 'sss';
    }

    $where_clause = '';
    if (!empty($where)) $where_clause = 'WHERE ' . implode(' AND ', $where);

    // Total count
    $count_sql = "SELECT COUNT(*) as total FROM products p $where_clause";
    $count_stmt = $mysqli->prepare($count_sql);
    if (!$count_stmt) throw new Exception('Failed to prepare count query: ' . $mysqli->error, 500);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_res = $count_stmt->get_result()->fetch_assoc();
    $total = $count_res ? (int)$count_res['total'] : 0;
    $count_stmt->close();

    $offset = ($page - 1) * $per_page;

    // Select desired fields
    $fields = [
        'p.id','p.name','p.barcode','p.brand_id','p.category_id','p.type_id','p.discount_id','p.tax_id',
        'p.shop_quantity','p.shop_low_stock_margin','p.store_quantity','p.store_low_stock_margin',
        'p.purchase_price','p.wholesale_price','p.retail_price','p.return_product',
        'p.purchase_unit_id','p.sales_unit_id','p.transfer_unit_id','p.purchase_to_transfer_rate','p.transfer_to_sales_rate',
        'p.status','p.image','p.created_at','p.updated_at','p.deleted_at'
    ];

    $select_sql = 'SELECT ' . implode(', ', $fields) . ' FROM products p ' . $where_clause . ' ORDER BY p.id ASC LIMIT ? OFFSET ?';
    $stmt = $mysqli->prepare($select_sql);
    if (!$stmt) throw new Exception('Failed to prepare select query: ' . $mysqli->error, 500);

    // Bind parameters + pagination
    $all_params = $params;
    $all_types = $types . 'ii';
    $all_params[] = $per_page;
    $all_params[] = $offset;
    if (!empty($all_params)) {
        $stmt->bind_param($all_types, ...$all_params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'brand_id' => isset($row['brand_id']) ? (int)$row['brand_id'] : null,
            'category_id' => isset($row['category_id']) ? (int)$row['category_id'] : null,
            'type_id' => isset($row['type_id']) ? (int)$row['type_id'] : null,
            'discount_id' => isset($row['discount_id']) ? (int)$row['discount_id'] : null,
            'tax_id' => isset($row['tax_id']) ? (int)$row['tax_id'] : null,
            'shop_quantity' => is_numeric($row['shop_quantity']) ? (float)$row['shop_quantity'] : $row['shop_quantity'],
            'shop_low_stock_margin' => is_numeric($row['shop_low_stock_margin']) ? (float)$row['shop_low_stock_margin'] : $row['shop_low_stock_margin'],
            'store_quantity' => is_numeric($row['store_quantity']) ? (float)$row['store_quantity'] : $row['store_quantity'],
            'store_low_stock_margin' => is_numeric($row['store_low_stock_margin']) ? (float)$row['store_low_stock_margin'] : $row['store_low_stock_margin'],
            'purchase_price' => isset($row['purchase_price']) ? (float)$row['purchase_price'] : null,
            'wholesale_price' => isset($row['wholesale_price']) ? (float)$row['wholesale_price'] : null,
            'retail_price' => isset($row['retail_price']) ? (float)$row['retail_price'] : null,
            'return_product' => isset($row['return_product']) ? (int)$row['return_product'] : null,
            'purchase_unit_id' => isset($row['purchase_unit_id']) ? (int)$row['purchase_unit_id'] : null,
            'sales_unit_id' => isset($row['sales_unit_id']) ? (int)$row['sales_unit_id'] : null,
            'transfer_unit_id' => isset($row['transfer_unit_id']) ? (int)$row['transfer_unit_id'] : null,
            'purchase_to_transfer_rate' => isset($row['purchase_to_transfer_rate']) ? (float)$row['purchase_to_transfer_rate'] : null,
            'transfer_to_sales_rate' => isset($row['transfer_to_sales_rate']) ? (float)$row['transfer_to_sales_rate'] : null,
            'status' => isset($row['status']) ? (int)$row['status'] : null,
            'image' => $row['image'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'deleted_at' => $row['deleted_at']
        ];
    }

    $stmt->close();
    $mysqli->close();

    $response = [
        'success' => true,
        'message' => 'Products retrieved',
        'data' => [
            'filters' => [ 'q' => $q, 'id' => $id, 'brand_id' => $brand_id, 'category_id' => $category_id, 'status' => $status ],
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
