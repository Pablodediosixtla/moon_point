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

if (!isset($in['pending_order_id']) || $in['pending_order_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: pending_order_id"]);
  exit;
}

$pending_order_id = (int)$in['pending_order_id'];

$label  = isset($in['label'])  ? trim((string)$in['label'])   : null;
$total  = isset($in['total'])  ? (float)$in['total']          : null;
$status = isset($in['status']) ? (int)$in['status']           : null;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sets  = [];
$types = "";
$args  = [];

if ($label !== null)  { $sets[] = "label = ?";       $types .= "s"; $args[] = $label; }
if ($total !== null)  { $sets[] = "total = ?";       $types .= "d"; $args[] = $total; }
if ($status !== null) { $sets[] = "status = ?";      $types .= "i"; $args[] = $status; }

$sets[] = "updated_at = NOW()";

$sql = "UPDATE `moon_point`.`moon_pending_order` SET " . implode(", ", $sets) . " WHERE id = ?";
$types .= "i";
$args[]  = $pending_order_id;

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: ".$con->error]);
  $con->close();
  exit;
}

$stmt->bind_param($types, ...$args);

if ($stmt->execute()) {
  echo json_encode(["success" => true]);
} else {
  echo json_encode(["success" => false, "error" => "Error al actualizar: ".$stmt->error]);
}

$stmt->close();
$con->close();
