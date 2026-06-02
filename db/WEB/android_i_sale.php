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

$input = json_decode(file_get_contents("php://input"), true) ?: [];

/*
Esperado (ejemplo):
{
  "payment_method": "CASH",          // o "CARD"
  "discount_mode": "AMOUNT",         // o "PERCENT"
  "discount_value": 10.0,            // MXN o % según discount_mode
  "received": 100.0,                 // si CARD se ignora y se toma = total
  "client_id": null,
  "client_name": "Cliente mostrador",
  "items": [
    {"product_id": 5, "qty": 2, "unit_price": 60.00},
    {"product_id": 3, "qty": 1, "unit_price": 55.00}
  ]
}
*/

$pm   = strtoupper(trim($input['payment_method'] ?? 'CASH'));
$dm   = strtoupper(trim($input['discount_mode']  ?? 'AMOUNT'));
$dval = floatval($input['discount_value'] ?? 0.0);
$recv = floatval($input['received'] ?? 0.0);
$cid  = isset($input['client_id']) ? $input['client_id'] : null;
$cname= isset($input['client_name']) ? trim($input['client_name']) : null;
$items= $input['items'] ?? [];

if (!in_array($pm, ['CASH','CARD']))  { echo json_encode(["ok"=>false,"error"=>"BAD_PAYMENT_METHOD"]); exit; }
if (!in_array($dm, ['AMOUNT','PERCENT'])) { echo json_encode(["ok"=>false,"error"=>"BAD_DISCOUNT_MODE"]); exit; }
if (!is_array($items) || count($items) === 0) { echo json_encode(["ok"=>false,"error"=>"NO_ITEMS"]); exit; }

/* Calcular totales del lado servidor */
$subtotal = 0.0;
foreach ($items as $it) {
    $qty = intval($it['qty'] ?? 0);
    $price = floatval($it['unit_price'] ?? 0);
    if ($qty <= 0 || $price < 0) { echo json_encode(["ok"=>false,"error"=>"BAD_ITEM"]); exit; }
    $subtotal += ($qty * $price);
}

$discount_amount = ($dm === 'PERCENT') ? $subtotal * (max(0,min(100,$dval))/100.0) : max(0.0, $dval);
$discount_amount = min($discount_amount, $subtotal);
$total = max(0.0, $subtotal - $discount_amount);

if ($pm === 'CARD') $recv = $total;
$change = max(0.0, $recv - $total);

mysqli_begin_transaction($con);
try {
    /* Insertar encabezado */
    $sqlSale = "INSERT INTO sales
        (payment_method, discount_mode, discount_value, subtotal, total, received, change_amount, client_id, client_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($con, $sqlSale);
    mysqli_stmt_bind_param($stmt, "ssdddddis",
        $pm, $dm, $dval, $subtotal, $total, $recv, $change,
        $cid, $cname
    );
    if (!mysqli_stmt_execute($stmt)) { throw new Exception("INSERT_SALE_FAILED"); }
    $sale_id = mysqli_insert_id($con);
    mysqli_stmt_close($stmt);

    /* Insertar detalle */
    $sqlItem = "INSERT INTO sale_items (sale_id, product_id, qty, unit_price, line_total) VALUES (?, ?, ?, ?, ?)";
    $stmtItem = mysqli_prepare($con, $sqlItem);

    // Para afectar stock después:
    // $sqlStock = "UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?";
    // $stmtStock = mysqli_prepare($con, $sqlStock);

    foreach ($items as $it) {
        $pid = intval($it['product_id']);
        $qty = intval($it['qty']);
        $price = floatval($it['unit_price']);
        $line = $qty * $price;

        mysqli_stmt_bind_param($stmtItem, "iiidd", $sale_id, $pid, $qty, $price, $line);
        if (!mysqli_stmt_execute($stmtItem)) { throw new Exception("INSERT_ITEM_FAILED"); }

        // Afectar stock (opcional):
        // mysqli_stmt_bind_param($stmtStock, "iii", $qty, $pid, $qty);
        // if (!mysqli_stmt_execute($stmtStock) || mysqli_stmt_affected_rows($stmtStock) === 0) {
        //     throw new Exception("STOCK_NOT_AVAILABLE");
        // }
    }
    mysqli_stmt_close($stmtItem);
    // if (isset($stmtStock)) mysqli_stmt_close($stmtStock);

    mysqli_commit($con);
    echo json_encode([
        "ok" => true,
        "sale_id" => (int)$sale_id,
        "totals" => [
            "subtotal" => round($subtotal,2),
            "discount" => round($discount_amount,2),
            "total"    => round($total,2),
            "received" => round($recv,2),
            "change"   => round($change,2)
        ]
    ]);
} catch (Exception $e) {
    mysqli_rollback($con);
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>$e->getMessage()]);
} finally {
    mysqli_close($con);
}
