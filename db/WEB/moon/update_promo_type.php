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

$id     = (int)$in['id'];
$nombre = array_key_exists('nombre', $in) ? trim((string)$in['nombre']) : null;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sets  = [];
$types = "";
$args  = [];

if ($nombre !== null) {
  $sets[]  = "nombre = ?";
  $types  .= "s";
  $args[]  = $nombre;
}

if (count($sets) === 0) {
    echo json_encode(["success" => false, "error" => "No hay campos para actualizar"]);
    exit;
}

$sets[] = "updated_at = NOW()";

$sql = "UPDATE `moon_promo_type` SET " . implode(", ", $sets) . " WHERE id = ?";
$types .= "i";
$args[]  = $id;

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close();
  exit;
}

$stmt->bind_param($types, ...$args);

if ($stmt->execute()) {
  echo json_encode(["success" => true]);
} else {
  echo json_encode(["success" => false, "error" => "Error al actualizar: " . $stmt->error]);
}

$stmt->close();
$con->close();