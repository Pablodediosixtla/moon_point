<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if (!$path || !file_exists($path)) {
  echo json_encode([
    "success" => false,
    "error"   => "No se encontró Conexion.php en $path"
  ]);
  exit;
}
include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (!isset($in['pending_order_id']) || $in['pending_order_id'] === "") {
  echo json_encode([
    "success" => false,
    "error"   => "Falta parámetro obligatorio: pending_order_id"
  ]);
  exit;
}

$pending_order_id = (int)$in['pending_order_id'];

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sql = "SELECT 
          id,
          pending_order_id,
          product_id,
          name,
          image_name,
          qty,
          unit_price,
          note,
          created_at,
          updated_at
        FROM `moon_point`.`moon_pending_order_item`
        WHERE pending_order_id = ?
        ORDER BY id ASC";

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close();
  exit;
}

$stmt->bind_param("i", $pending_order_id);

if (!$stmt->execute()) {
  echo json_encode(["success" => false, "error" => "Error al ejecutar: " . $stmt->error]);
  $stmt->close();
  $con->close();
  exit;
}

$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
  // casteos numéricos para que Swift no truene
  $row['id']               = (int)$row['id'];
  $row['pending_order_id'] = (int)$row['pending_order_id'];
  $row['product_id']       = (int)$row['product_id'];
  $row['qty']              = (int)$row['qty'];
  $row['unit_price']       = (float)$row['unit_price'];
  // name, image_name, note ya son strings
  $out[] = $row;
}

echo json_encode(["success" => true, "data" => $out]);

$stmt->close();
$con->close();
