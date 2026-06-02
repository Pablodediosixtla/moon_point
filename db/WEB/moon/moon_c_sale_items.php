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

if (!isset($in['sale_id']) || $in['sale_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta sale_id"]);
  exit;
}

$sale_id = (int)$in['sale_id'];

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$sql = "SELECT id, sale_id, product_id, name, image_name, qty, unit_price, line_subtotal, note,
               created_at, updated_at
        FROM `moon_point`.`moon_sale_item`
        WHERE sale_id = ?
        ORDER BY id ASC";

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error al preparar consulta: ".$con->error]); $con->close(); exit; }

$stmt->bind_param("i", $sale_id);
if (!$stmt->execute()) {
  echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]);
  $stmt->close(); $con->close(); exit;
}

$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
  $row['id']            = (int)$row['id'];
  $row['sale_id']       = (int)$row['sale_id'];
  $row['product_id']    = (int)$row['product_id'];
  $row['qty']           = (int)$row['qty'];
  $row['unit_price']    = (float)$row['unit_price'];
  $row['line_subtotal'] = (float)$row['line_subtotal'];
  $out[] = $row;
}

echo json_encode(["success"=>true, "data"=>$out]);
$stmt->close();
$con->close();


