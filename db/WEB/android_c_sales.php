<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

$path1 = realpath("/home/site/wwwroot/db/conn/Conn_android.php");
$path2 = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if ($path1 && file_exists($path1)) {
    include $path1;
} elseif ($path2 && file_exists($path2)) {
    include $path2;
} else {
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"CONN_FILE_NOT_FOUND"]);
    exit;
}

$con = function_exists('conectar_android') ? conectar_android()
     : (function_exists('conectar') ? conectar() : null);

if (!$con) { http_response_code(500); echo json_encode(["ok"=>false,"error"=>"DB_CONNECT_FAILED"]); exit; }
mysqli_set_charset($con, "utf8mb4");

$in = json_decode(file_get_contents("php://input"), true) ?: [];

/*
Modos:
1) Detalle:
   { "sale_id": 123 }

2) Lista:
   {
     "date_from": "YYYY-MM-DD",
     "date_to":   "YYYY-MM-DD",
     "limit": 50,
     "offset": 0
   }
*/

// ====== Offset horario (servidor adelantado) ======
$OFFSET_HOURS = 6; // restar 6 horas
$INTERVAL_SQL = (int)$OFFSET_HOURS; // sanity cast

$sale_id  = isset($in['sale_id']) ? (int)$in['sale_id'] : 0;

if ($sale_id > 0) {
    // === Detalle ===
    // Ajuste: created_at corregido ya con -6h y devuelto como 'created_at'
    $sqlH = "
        SELECT
            id, payment_method, discount_mode, discount_value,
            subtotal, total, received, change_amount,
            client_id, client_name, status,
            DATE_FORMAT(DATE_SUB(created_at, INTERVAL $INTERVAL_SQL HOUR), '%Y-%m-%d %H:%i:%s') AS created_at
        FROM sales
        WHERE id = ?
        LIMIT 1";
    $stmt = mysqli_prepare($con, $sqlH);
    mysqli_stmt_bind_param($stmt, "i", $sale_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $head = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$head) { echo json_encode(["ok"=>false,"error"=>"SALE_NOT_FOUND"]); exit; }

    $sqlD = "
        SELECT si.id, si.product_id, p.name AS product_name, si.qty, si.unit_price, si.line_total
        FROM sale_items si
        LEFT JOIN products p ON p.id = si.product_id
        WHERE si.sale_id = ?
        ORDER BY si.id ASC";
    $stmt = mysqli_prepare($con, $sqlD);
    mysqli_stmt_bind_param($stmt, "i", $sale_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $items = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row['id']         = (int)$row['id'];
        $row['product_id'] = (int)$row['product_id'];
        $row['qty']        = (int)$row['qty'];
        $row['unit_price'] = (float)$row['unit_price'];
        $row['line_total'] = (float)$row['line_total'];
        $items[] = $row;
    }
    mysqli_stmt_close($stmt);

    // Formatear encabezado numéricos
    $head['id']             = (int)$head['id'];
    $head['discount_value'] = (float)$head['discount_value'];
    $head['subtotal']       = (float)$head['subtotal'];
    $head['total']          = (float)$head['total'];
    $head['received']       = (float)$head['received'];
    $head['change_amount']  = (float)$head['change_amount'];
    $head['client_id']      = is_null($head['client_id']) ? null : (int)$head['client_id'];
    $head['status']         = (int)$head['status'];

    echo json_encode(["ok"=>true, "data"=> ["header"=>$head, "items"=>$items]]);
    mysqli_close($con);
    exit;
}

// === Lista ===
$date_from = isset($in['date_from']) ? $in['date_from'] : null;
$date_to   = isset($in['date_to'])   ? $in['date_to']   : null;
$limit     = isset($in['limit'])     ? max(1, min(200, (int)$in['limit'])) : 50;
$offset    = isset($in['offset'])    ? max(0, (int)$in['offset']) : 0;

// IMPORTANTE: los filtros por fecha se hacen sobre la fecha/hora ajustada (-6h)
$where = " WHERE 1=1 ";
$params = [];
$types  = "";

if ($date_from && $date_to) {
    $where .= " AND DATE(DATE_SUB(created_at, INTERVAL $INTERVAL_SQL HOUR)) BETWEEN ? AND ? ";
    $params[] = $date_from; $types .= "s";
    $params[] = $date_to;   $types .= "s";
} elseif ($date_from) {
    $where .= " AND DATE(DATE_SUB(created_at, INTERVAL $INTERVAL_SQL HOUR)) >= ? ";
    $params[] = $date_from; $types .= "s";
} elseif ($date_to) {
    $where .= " AND DATE(DATE_SUB(created_at, INTERVAL $INTERVAL_SQL HOUR)) <= ? ";
    $params[] = $date_to;   $types .= "s";
}

$sql = "
    SELECT SQL_CALC_FOUND_ROWS
        id, payment_method, subtotal, total, received, change_amount, client_name, status,
        DATE_FORMAT(DATE_SUB(created_at, INTERVAL $INTERVAL_SQL HOUR), '%Y-%m-%d %H:%i:%s') AS created_at
    FROM sales
    $where
    ORDER BY id DESC
    LIMIT ? OFFSET ?";

$params[] = $limit;  $types .= "i";
$params[] = $offset; $types .= "i";

$stmt = mysqli_prepare($con, $sql);
if ($types !== "") {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
} else {
    mysqli_stmt_bind_param($stmt, "ii", $limit, $offset);
}
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$list = [];
while ($row = mysqli_fetch_assoc($res)) {
    $row['id']            = (int)$row['id'];
    $row['subtotal']      = (float)$row['subtotal'];
    $row['total']         = (float)$row['total'];
    $row['received']      = (float)$row['received'];
    $row['change_amount'] = (float)$row['change_amount'];
    $row['status']        = (int)$row['status'];
    // created_at ya viene con -6h en formato 'Y-m-d H:i:s'
    $list[] = $row;
}
mysqli_stmt_close($stmt);

$totRes    = mysqli_query($con, "SELECT FOUND_ROWS() AS total");
$totalRows = (int)mysqli_fetch_assoc($totRes)['total'];

echo json_encode([
    "ok"=>true,
    "data"=>[
        "items"=>$list,
        "total"=>$totalRows,
        "limit"=>$limit,
        "offset"=>$offset
    ]
]);
mysqli_close($con);
