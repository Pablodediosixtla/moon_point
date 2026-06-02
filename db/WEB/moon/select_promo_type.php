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

if (empty($in['Id_company'])) {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: Id_company"]);
  exit;
}

$Id_company = (int)$in['Id_company'];

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sql = "SELECT * FROM `moon_promo_type` WHERE Id_company = ?";

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close();
  exit;
}

$stmt->bind_param("i", $Id_company);
$stmt->execute();
$result = $stmt->get_result();

$tipos_promo = [];
while ($row = $result->fetch_assoc()) {
    $tipos_promo[] = $row;
}

echo json_encode(["success" => true, "data" => $tipos_promo]);

$stmt->close();
$con->close();