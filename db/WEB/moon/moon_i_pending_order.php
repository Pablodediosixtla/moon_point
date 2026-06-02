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

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) { $input = []; }

// obligatorios
if (!isset($input['organization_id']) || $input['organization_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: organization_id"]);
  exit;
}

// acepta label o name
$label = null;
if (isset($input['label']) && $input['label'] !== '') {
  $label = trim((string)$input['label']);
} elseif (isset($input['name']) && $input['name'] !== '') {
  $label = trim((string)$input['name']);
} else {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: label/name"]);
  exit;
}

$organization_id   = (int)$input['organization_id'];
$source            = isset($input['source']) ? (int)$input['source'] : 1;     // 1=POS, 2=otro canal
$channel           = isset($input['channel']) ? trim((string)$input['channel']) : '';
$customer_name     = isset($input['customer_name']) ? trim((string)$input['customer_name']) : '';
$status            = isset($input['status']) ? (int)$input['status'] : 0;     // 0=pending
$total             = isset($input['total']) ? (float)$input['total'] : 0.0;
$external_ref      = isset($input['external_ref']) ? trim((string)$input['external_ref']) : '';
$created_by_user_id= isset($input['created_by_user_id']) && $input['created_by_user_id'] !== ''
                        ? (int)$input['created_by_user_id'] : 0; // 0 -> NULL
$attributes        = isset($input['attributes']) ? json_encode($input['attributes']) : '';

// conexión
$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sql = "INSERT INTO `moon_point`.`moon_pending_order`
  (organization_id, source, channel, label, customer_name, status, total, external_ref, created_by_user_id, attributes)
  VALUES (?, ?, NULLIF(?,''), ?, NULLIF(?,''), ?, ?, NULLIF(?,''), NULLIF(?,0), NULLIF(?,''))";

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close(); exit;
}

$stmt->bind_param(
  // ii s  s  s  i  d  s  i  s
  "iisssidsis",
  $organization_id,
  $source,
  $channel,
  $label,
  $customer_name,
  $status,
  $total,
  $external_ref,
  $created_by_user_id,
  $attributes
);

if ($stmt->execute()) {
  echo json_encode([
    "success" => true,
    "message" => "Orden pendiente creada",
    "id"      => (int)$stmt->insert_id
  ]);
} else {
  echo json_encode(["success" => false, "error" => "Error al insertar: " . $stmt->error]);
}

$stmt->close();
$con->close();
