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

if (empty($in['nombre']) || empty($in['promo_type']) || empty($in['Id_company'])) {
  echo json_encode(["success" => false, "error" => "Faltan parámetros obligatorios: nombre, promo_type, Id_company"]);
  exit;
}

$nombre            = trim((string)$in['nombre']);
$promo_type        = (int)$in['promo_type'];
$Id_company        = (int)$in['Id_company'];
$status            = isset($in['status']) && $in['status'] !== '' ? (int)$in['status'] : 1;
$formato_descuento = isset($in['formato_descuento']) ? (int)$in['formato_descuento'] : 1; // 1 = %, 2 = $

// Permitiendo NULL reales
$porcentaje = isset($in['porcentaje']) ? (int)$in['porcentaje'] : null;
$cantidad   = isset($in['cantidad'])   ? (int)$in['cantidad']   : null;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sql = "INSERT INTO `moon_promo` (`nombre`, `porcentaje`, `cantidad`, `formato_descuento`, `promo_type`, `status`, `Id_company`) VALUES (?, ?, ?, ?, ?, ?, ?)";

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close();
  exit;
}

// s = string, i = int (7 parametros)
$stmt->bind_param("siiiiii", $nombre, $porcentaje, $cantidad, $formato_descuento, $promo_type, $status, $Id_company);

if ($stmt->execute()) {
  echo json_encode(["success" => true, "id" => $stmt->insert_id]);
} else {
  echo json_encode(["success" => false, "error" => "Error al insertar: " . $stmt->error]);
}

$stmt->close();
$con->close();