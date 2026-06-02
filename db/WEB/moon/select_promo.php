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
$status     = isset($in['status']) && $in['status'] !== '' ? (int)$in['status'] : null;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

// Seleccionamos la promo y hacemos el JOIN solo con el tipo de promoción
$sql = "SELECT p.*, pt.nombre as nombre_tipo_promo 
        FROM `moon_promo` p
        LEFT JOIN `moon_promo_type` pt ON p.promo_type = pt.id
        WHERE p.Id_company = ?";
        
$types = "i";
$args = [$Id_company];

if ($status !== null) {
    $sql .= " AND p.status = ?";
    $types .= "i";
    $args[] = $status;
}

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close();
  exit;
}

$stmt->bind_param($types, ...$args);
$stmt->execute();
$result = $stmt->get_result();

$promos = [];
while ($row = $result->fetch_assoc()) {
    // Casteamos los valores numéricos para que Swift no tenga problemas al decodificar
    $row['id']                = (int)$row['id'];
    $row['porcentaje']        = $row['porcentaje'] !== null ? (int)$row['porcentaje'] : null;
    $row['cantidad']          = $row['cantidad'] !== null ? (int)$row['cantidad'] : null;
    $row['formato_descuento'] = (int)$row['formato_descuento'];
    $row['promo_type']        = (int)$row['promo_type'];
    $row['status']            = (int)$row['status'];
    $row['Id_company']        = (int)$row['Id_company'];
    
    $promos[] = $row;
}

echo json_encode(["success" => true, "data" => $promos]);

$stmt->close();
$con->close();