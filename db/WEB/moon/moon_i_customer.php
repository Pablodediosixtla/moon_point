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

// obligatorios
if (!isset($in['organization_id']) || $in['organization_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: organization_id"]);
  exit;
}
if (!isset($in['customer_name']) || trim($in['customer_name']) === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: customer_name"]);
  exit;
}

$organization_id = (int)$in['organization_id'];
$customer_name   = trim((string)$in['customer_name']);

$full_name  = isset($in['full_name'])  ? trim((string)$in['full_name'])  : '';
$birth_date = isset($in['birth_date']) ? trim((string)$in['birth_date']) : ''; // 'YYYY-MM-DD'
$phone      = isset($in['phone'])      ? trim((string)$in['phone'])      : '';
$email      = isset($in['email'])      ? trim((string)$in['email'])      : '';
$address    = isset($in['address'])    ? trim((string)$in['address'])    : '';

// conectar
$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

// insert
$sql = "INSERT INTO `moon_point`.`moon_customer`
        (organization_id, customer_name, full_name, birth_date, phone, email, address, is_active)
        VALUES (?, ?, NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), 1)";

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode([
    "success" => false,
    "error"   => "Error al preparar consulta: " . $con->error
  ]);
  $con->close();
  exit;
}

$stmt->bind_param(
  "issssss",
  $organization_id,
  $customer_name,
  $full_name,
  $birth_date,
  $phone,
  $email,
  $address
);

if ($stmt->execute()) {
  echo json_encode([
    "success" => true,
    "message" => "Cliente creado",
    "id"      => (int)$stmt->insert_id
  ]);
} else {
  // Nota: si rompe UNIQUE (organization_id, customer_name) MySQL va a marcar error de duplicado
  echo json_encode([
    "success" => false,
    "error"   => "Error al insertar: " . $stmt->error
  ]);
}

$stmt->close();
$con->close();
