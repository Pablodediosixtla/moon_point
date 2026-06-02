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

if (!isset($in['customer_id']) || $in['customer_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: customer_id"]);
  exit;
}

$customer_id   = (int)$in['customer_id'];
$customer_name = array_key_exists('customer_name', $in) ? trim((string)$in['customer_name']) : null;
$full_name     = array_key_exists('full_name', $in)     ? trim((string)$in['full_name'])     : null;
$birth_date    = array_key_exists('birth_date', $in)    ? trim((string)$in['birth_date'])    : null;
$phone         = array_key_exists('phone', $in)         ? trim((string)$in['phone'])         : null;
$email         = array_key_exists('email', $in)         ? trim((string)$in['email'])         : null;
$address       = array_key_exists('address', $in)       ? trim((string)$in['address'])       : null;
$is_active     = array_key_exists('is_active', $in) && $in['is_active'] !== '' ? (int)$in['is_active'] : null;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

// Build dinámico
$sets  = [];
$types = "";
$args  = [];

if ($customer_name !== null) {
  $sets[]  = "customer_name = ?";
  $types  .= "s";
  $args[]  = $customer_name;
}
if ($full_name !== null) {
  // usamos NULLIF para permitir "" -> NULL
  $sets[]  = "full_name = NULLIF(?, '')";
  $types  .= "s";
  $args[]  = $full_name;
}
if ($birth_date !== null) {
  $sets[]  = "birth_date = NULLIF(?, '')";
  $types  .= "s";
  $args[]  = $birth_date; // 'YYYY-MM-DD'
}
if ($phone !== null) {
  $sets[]  = "phone = NULLIF(?, '')";
  $types  .= "s";
  $args[]  = $phone;
}
if ($email !== null) {
  $sets[]  = "email = NULLIF(?, '')";
  $types  .= "s";
  $args[]  = $email;
}
if ($address !== null) {
  $sets[]  = "address = NULLIF(?, '')";
  $types  .= "s";
  $args[]  = $address;
}
if ($is_active !== null) {
  $sets[]  = "is_active = ?";
  $types  .= "i";
  $args[]  = $is_active;
}

// siempre tocamos updated_at
$sets[] = "updated_at = NOW()";

$sql = "UPDATE `moon_point`.`moon_customer` SET " . implode(", ", $sets) . " WHERE id = ?";
$types .= "i";
$args[]  = $customer_id;

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode([
    "success" => false,
    "error"   => "Error al preparar consulta: " . $con->error
  ]);
  $con->close();
  exit;
}

$stmt->bind_param($types, ...$args);

if ($stmt->execute()) {
  echo json_encode(["success" => true]);
} else {
  echo json_encode([
    "success" => false,
    "error"   => "Error al actualizar: " . $stmt->error
  ]);
}

$stmt->close();
$con->close();
