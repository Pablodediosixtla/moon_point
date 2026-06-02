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

if (empty($in['id_compuesto']) || empty($in['item']) || empty($in['Id_company'])) {
  echo json_encode(["success" => false, "error" => "Faltan parámetros obligatorios: id_compuesto, item, Id_company"]);
  exit;
}

$id_compuesto = trim((string)$in['id_compuesto']);
$item         = (int)$in['item'];
$Id_company   = (int)$in['Id_company'];

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sql = "INSERT INTO `moon_producto_compuesto` (`id_compuesto`, `item`, `Id_company`) VALUES (?, ?, ?)";

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close();
  exit;
}

// s = string, i = int, i = int
$stmt->bind_param("sii", $id_compuesto, $item, $Id_company);

if ($stmt->execute()) {
  echo json_encode(["success" => true, "inserted_id" => $stmt->insert_id]);
} else {
  echo json_encode(["success" => false, "error" => "Error al insertar: " . $stmt->error]);
}

$stmt->close();
$con->close();