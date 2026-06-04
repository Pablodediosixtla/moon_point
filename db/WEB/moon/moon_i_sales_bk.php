<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if (!$path || !file_exists($path)) {
  echo json_encode(["success" => false, "error" => "No se encontró Conexion.php en $path"]);
  exit;
}
include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (!isset($in['organization_id']) || $in['organization_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta organization_id"]);
  exit;
}
if (!isset($in['payment_method']) || $in['payment_method'] === "") {
  echo json_encode(["success" => false, "error" => "Falta payment_method"]);
  exit;
}
if (!isset($in['items']) || !is_array($in['items']) || count($in['items']) === 0) {
  echo json_encode(["success" => false, "error" => "Faltan items de la venta"]);
  exit;
}

$organization_id  = (int)$in['organization_id'];
$pending_order_id = isset($in['pending_order_id']) ? (int)$in['pending_order_id'] : 0;
$customer_id      = isset($in['customer_id']) ? (int)$in['customer_id'] : 0;
$customer_name    = isset($in['customer_name']) ? trim((string)$in['customer_name']) : '';

$source           = isset($in['source']) ? (int)$in['source'] : 1;            // 1=POS
$channel          = isset($in['channel']) ? trim((string)$in['channel']) : 'POS';
$status           = isset($in['status']) ? (int)$in['status'] : 1;            // 1=pagada

// payment_method: acepta 1/2 o 'cash'/'card'
$pmIn = $in['payment_method'];
if (is_string($pmIn)) {
  $pmLower = strtolower($pmIn);
  $payment_method = ($pmLower === 'card') ? 2 : 1;
} else {
  $payment_method = (int)$pmIn;
  if ($payment_method !== 1 && $payment_method !== 2) { $payment_method = 1; }
}

$note        = isset($in['note']) ? trim((string)$in['note']) : '';
$attributes  = isset($in['attributes']) ? json_encode($in['attributes']) : '';

$items = $in['items'];

// Calcular subtotal por servidor
$items_subtotal = 0.0;
foreach ($items as $it) {
  $q  = isset($it['qty']) ? (int)$it['qty'] : 0;
  $up = isset($it['unit_price']) ? (float)$it['unit_price'] : 0.0;
  if ($q <= 0) { echo json_encode(["success"=>false,"error"=>"Item con qty inválido"]); exit; }
  $items_subtotal += ($q * $up);
}

// Descuentos / impuestos
$discount_amount  = isset($in['discount_amount']) ? (float)$in['discount_amount'] : 0.0;
$discount_percent = isset($in['discount_percent']) ? (float)$in['discount_percent'] : null;
if ($discount_percent !== null && $discount_amount <= 0) {
  $discount_amount = round($items_subtotal * max(0.0, min($discount_percent, 100.0)) / 100.0, 2);
}
$tax_amount = isset($in['tax_amount']) ? (float)$in['tax_amount'] : 0.0;

$subtotal = isset($in['subtotal']) ? (float)$in['subtotal'] : $items_subtotal;
// Normalizamos por seguridad al cálculo del servidor
$subtotal = round($items_subtotal, 2);

$total = isset($in['total']) ? (float)$in['total'] : ($subtotal - $discount_amount + $tax_amount);
$total = round(max(0.0, $total), 2);

$cash_received = isset($in['cash_received']) ? (float)$in['cash_received'] : 0.0;
$change_amount = 0.0;
if ($payment_method === 1) { // efectivo
  $change_amount = round(max(0.0, $cash_received - $total), 2);
} else {
  // tarjeta: asumimos pago exacto
  $cash_received = $total;
  $change_amount = 0.0;
}

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

/* ====== AJUSTE DE ZONA HORARIA POR SESIÓN ======
   Hereda la global (-06:00 en tu servidor). Si por alguna razón falla,
   forzamos el offset explícito (no requiere tablas de zonas). */
if (!$con->query("SET time_zone = @@global.time_zone")) {
  $con->query("SET time_zone = '-06:00'");
}

try {
  $con->begin_transaction();

  $sqlH = "INSERT INTO `moon_point`.`moon_sale`
    (organization_id, pending_order_id, customer_id, customer_name, source, channel, status, payment_method,
     subtotal, discount_amount, discount_percent, tax_amount, total, cash_received, change_amount, note, attributes)
    VALUES (
      ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,''), ?, NULLIF(?,''), ?, ?, ?, ?, NULLIF(?,NULL), ?, ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,''), NULLIF(?, '')
    )";

  $stmtH = $con->prepare($sqlH);
  if (!$stmtH) { throw new Exception("Error al preparar encabezado: ".$con->error); }

  // types: iiisisiidddddddss
  $stmtH->bind_param(
    "iiisisiidddddddss",
    $organization_id,
    $pending_order_id,
    $customer_id,
    $customer_name,
    $source,
    $channel,
    $status,
    $payment_method,
    $subtotal,
    $discount_amount,
    $discount_percent,
    $tax_amount,
    $total,
    $cash_received,
    $change_amount,
    $note,
    $attributes
  );

  if (!$stmtH->execute()) { throw new Exception("Error al insertar venta: ".$stmtH->error); }
  $sale_id = (int)$stmtH->insert_id;
  $stmtH->close();

  // Items
  $sqlI = "INSERT INTO `moon_point`.`moon_sale_item`
           (sale_id, product_id, name, image_name, qty, unit_price, line_subtotal, note)
           VALUES (?, ?, ?, NULLIF(?,''), ?, ?, ?, NULLIF(?,''))";
  $stmtI = $con->prepare($sqlI);
  if (!$stmtI) { throw new Exception("Error al preparar items: ".$con->error); }

  foreach ($items as $it) {
    $pid   = (int)$it['product_id'];
    $name  = trim((string)$it['name']);
    $img   = isset($it['image_name']) ? trim((string)$it['image_name']) : '';
    $qty   = (int)$it['qty'];
    $price = (float)$it['unit_price'];
    $n     = isset($it['note']) ? trim((string)$it['note']) : '';
    $line  = round($qty * $price, 2);

    $stmtI->bind_param("iissidds", $sale_id, $pid, $name, $img, $qty, $price, $line, $n);
    if (!$stmtI->execute()) {
      throw new Exception("Error al insertar item: ".$stmtI->error);
    }
  }
  $stmtI->close();

  // Cerrar la orden pendiente (opcional)
  $closePO = isset($in['close_pending_order']) ? (int)$in['close_pending_order'] : 0;
  if ($closePO === 1 && $pending_order_id > 0) {
    $sqlPO = "UPDATE `moon_point`.`moon_pending_order`
              SET status=3, total=?, updated_at=NOW()
              WHERE id=? LIMIT 1";
    $stmtPO = $con->prepare($sqlPO);
    if ($stmtPO) {
      $stmtPO->bind_param("di", $total, $pending_order_id);
      $stmtPO->execute();
      $stmtPO->close();
    }
  }

  $con->commit();
  echo json_encode([
    "success" => true,
    "message" => "Venta registrada",
    "id"      => $sale_id
  ]);

} catch (Throwable $e) {
  $con->rollback();
  echo json_encode(["success"=>false,"error"=>$e->getMessage()]);
}

$con->close();
