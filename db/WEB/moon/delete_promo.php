<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if (!$path || !file_exists($path)) {
  echo json_encode(["success" => false, "error" => "No se encontró Conexion.php"]);
  exit;
}
include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (!isset($in['id']) || $in['id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: id"]);
  exit;
}

$id = (int)$in['id'];

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}

$sql = "DELETE FROM `moon_promo` WHERE id = ?";
$stmt = $con->prepare($sql);

if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close();
  exit;
}

$stmt->bind_param("i", $id);

if ($stmt->execute()) {
  echo json_encode(["success" => true]);
} else {
  echo json_encode(["success" => false, "error" => "Error al eliminar: " . $stmt->error]);
}

$stmt->close();
$con->close();