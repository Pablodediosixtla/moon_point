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

$organization_id = (int)$in['organization_id'];
$status          = isset($in['status']) ? (int)$in['status'] : null; // 1=pagada, 2=anulada
$sale_id         = isset($in['sale_id']) ? (int)$in['sale_id'] : 0;
$pending_order_id= isset($in['pending_order_id']) ? (int)$in['pending_order_id'] : 0;
$date_from       = isset($in['date_from']) ? trim((string)$in['date_from']) : ''; // 'YYYY-MM-DD'
$date_to         = isset($in['date_to'])   ? trim((string)$in['date_to'])   : ''; // 'YYYY-MM-DD'
$limit           = isset($in['limit']) ? max(1, min(100000, (int)$in['limit'])) : 100;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$sql = "SELECT id, organization_id, pending_order_id, customer_id, customer_name, source, channel,
               status, payment_method, subtotal, discount_amount, discount_percent, tax_amount, total,
               cash_received, change_amount, note, attributes, created_at, updated_at
        FROM `moon_point`.`moon_sale`
        WHERE organization_id = ?";
$types = "i";
$params = [$organization_id];

if ($status !== null) { $sql .= " AND status = ?";            $types .= "i"; $params[] = $status; }
if ($sale_id > 0)      { $sql .= " AND id = ?";               $types .= "i"; $params[] = $sale_id; }
if ($pending_order_id > 0) { $sql .= " AND pending_order_id = ?"; $types .= "i"; $params[] = $pending_order_id; }
if ($date_from !== '') { $sql .= " AND DATE(created_at) >= ?"; $types .= "s"; $params[] = $date_from; }
if ($date_to !== '')   { $sql .= " AND DATE(created_at) <= ?"; $types .= "s"; $params[] = $date_to; }

$sql .= " ORDER BY created_at DESC LIMIT ?"; $types .= "i"; $params[] = $limit;

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error al preparar consulta: ".$con->error]); $con->close(); exit; }

$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
  echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]);
  $stmt->close(); $con->close(); exit;
}

$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
  if (isset($row['attributes']) && $row['attributes'] !== null && $row['attributes'] !== '') {
    $row['attributes'] = json_decode($row['attributes'], true);
  }
  // cast numéricos
  $row['id']                = (int)$row['id'];
  $row['organization_id']   = (int)$row['organization_id'];
  $row['pending_order_id']  = isset($row['pending_order_id']) ? (int)$row['pending_order_id'] : null;
  $row['customer_id']       = isset($row['customer_id']) ? (int)$row['customer_id'] : null;
  $row['source']            = (int)$row['source'];
  $row['status']            = (int)$row['status'];
  $row['payment_method']    = (int)$row['payment_method'];
  $row['subtotal']          = (float)$row['subtotal'];
  $row['discount_amount']   = (float)$row['discount_amount'];
  $row['discount_percent']  = isset($row['discount_percent']) ? (float)$row['discount_percent'] : null;
  $row['tax_amount']        = (float)$row['tax_amount'];
  $row['total']             = (float)$row['total'];
  $row['cash_received']     = isset($row['cash_received']) ? (float)$row['cash_received'] : null;
  $row['change_amount']     = isset($row['change_amount']) ? (float)$row['change_amount'] : null;
  $out[] = $row;
}

echo json_encode(["success"=>true, "data"=>$out]);
$stmt->close();
$con->close();
