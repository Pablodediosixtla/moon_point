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

$Id_company   = (int)$in['Id_company'];
// Filtro opcional para buscar los items de un compuesto en específico
$id_compuesto = !empty($in['id_compuesto']) ? trim((string)$in['id_compuesto']) : null;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sql = "SELECT pc.*, p.name AS product_name, p.sku AS product_sku 
        FROM `moon_producto_compuesto` pc
        INNER JOIN `moon_product` p ON pc.item = p.id
        WHERE pc.Id_company = ?";

$types = "i";
$args = [$Id_company];

if ($id_compuesto !== null) {
    $sql .= " AND pc.id_compuesto = ?";
    $types .= "s";
    $args[] = $id_compuesto;
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

$compuestos = [];
while ($row = $result->fetch_assoc()) {
    $compuestos[] = $row;
}

echo json_encode(["success" => true, "data" => $compuestos]);

$stmt->close();
$con->close();