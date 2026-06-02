<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if (!$path || !file_exists($path)) { echo json_encode(["success" => false, "error" => "No se encontró Conexion.php en $path"]); exit; }
include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

$req = ["pending_order_id","product_id","name","qty","unit_price"];
foreach ($req as $k) {
  if (!isset($in[$k]) || $in[$k] === "") { echo json_encode(["success"=>false,"error"=>"Falta $k"]); exit; }
}

$pending_order_id = (int)$in['pending_order_id'];
$product_id       = (int)$in['product_id'];
$name             = trim((string)$in['name']);
$image_name       = isset($in['image_name']) ? trim((string)$in['image_name']) : '';
$qty              = (int)$in['qty'];
$unit_price       = (float)$in['unit_price'];
$note             = isset($in['note']) ? trim((string)$in['note']) : '';

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$sql = "INSERT INTO `moon_point`.`moon_pending_order_item`
        (pending_order_id, product_id, name, image_name, qty, unit_price, note)
        VALUES (?, ?, ?, NULLIF(?,''), ?, ?, NULLIF(?,''))";

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error al preparar consulta: ".$con->error]); $con->close(); exit; }

$stmt->bind_param("iissids", $pending_order_id, $product_id, $name, $image_name, $qty, $unit_price, $note);

if ($stmt->execute()) {
  echo json_encode(["success"=>true,"message"=>"Item agregado","id"=>(int)$stmt->insert_id]);
} else {
  echo json_encode(["success"=>false,"error"=>"Error al insertar: ".$stmt->error]);
}

$stmt->close();
$con->close();
