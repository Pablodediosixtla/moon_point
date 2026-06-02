<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$path = realpath("/home/site/wwwroot/db/conn/Conn_android.php");
if ($path && file_exists($path)) {
    include $path;
} else {
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"CONN_FILE_NOT_FOUND","path"=>$path]);
    exit;
}

$con = function_exists('conectar_android') ? conectar_android()
     : (function_exists('conectar') ? conectar() : null);

if (!$con) {
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"DB_CONNECT_FAILED"]);
    exit;
}
mysqli_set_charset($con, "utf8mb4");

// Lee params de GET o POST JSON
$input = json_decode(file_get_contents("php://input"), true) ?: [];

function param($key, $default = null) {
    global $input;
    return isset($_GET[$key]) ? $_GET[$key] : (isset($input[$key]) ? $input[$key] : $default);
}

$q          = trim((string) param('q', ''));
$categoryId = param('category_id', null);
$onlyActive = intval(param('only_active', 1));
$limit      = max(1, min(200, intval(param('limit', 50))));
$offset     = max(0, intval(param('offset', 0)));

$where = [];
if ($onlyActive) $where[] = "p.is_active = 1";
if ($categoryId !== null && $categoryId !== '') $where[] = "p.category_id = ".intval($categoryId);
if ($q !== '') {
    $qEsc = mysqli_real_escape_string($con, $q);
    $like = "'%$qEsc%'";
    $where[] = "(p.name LIKE $like OR p.sku LIKE $like OR p.barcode LIKE $like)";
}
$whereSql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

// Conteo total (para paginación)
$sqlCount = "SELECT COUNT(*) AS total FROM products p $whereSql";
$cntRes = mysqli_query($con, $sqlCount);
$total = 0;
if ($cntRes && ($row = mysqli_fetch_assoc($cntRes))) $total = intval($row['total']);

// Datos
$sql = "SELECT
          p.id, p.sku, p.barcode, p.name, p.description,
          p.category_id, c.name AS category_name,
          p.price, p.cost, p.stock, p.image_url,
          p.is_active, p.created_at, p.updated_at
        FROM products p
        JOIN categories c ON c.id = p.category_id
        $whereSql
        ORDER BY p.name ASC
        LIMIT $limit OFFSET $offset";

$res = mysqli_query($con, $sql);
$items = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        // Cast mínimos
        $row['id'] = (int)$row['id'];
        $row['category_id'] = (int)$row['category_id'];
        $row['is_active'] = (int)$row['is_active'];
        $row['stock'] = (int)$row['stock'];
        $row['price'] = (float)$row['price'];
        $row['cost'] = (float)$row['cost'];
        $items[] = $row;
    }
    echo json_encode([
        "ok"=>true,
        "data"=>[
            "items"=>$items,
            "total"=>$total,
            "limit"=>$limit,
            "offset"=>$offset
        ]
    ], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(["ok"=>false, "error"=>"QUERY_FAILED", "message"=>mysqli_error($con)]);
}
mysqli_close($con);
