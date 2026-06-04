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

$organization_id = isset($in['organization_id']) ? (int)$in['organization_id'] : 0;
$redemption_code = isset($in['redemption_code']) ? strtoupper(trim((string)$in['redemption_code'])) : "";

if ($organization_id <= 0) {
  echo json_encode(["success" => false, "error" => "Falta organization_id"]);
  exit;
}

if ($redemption_code === "") {
  echo json_encode(["success" => false, "error" => "Falta redemption_code"]);
  exit;
}

$con = conectar();

if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}

$con->set_charset('utf8mb4');

$sql = "
  SELECT
    rd.id,
    rd.redemption_code,
    rd.customer_access_id,
    rd.customer_branch_id,
    rd.organization_id,
    rd.moon_customer_id,
    rd.reward_id,
    r.reward_name,
    r.description,
    r.image_name,
    r.image_url,
    rd.points_cost,
    rd.status,
    CASE rd.status
      WHEN 1 THEN 'Pendiente'
      WHEN 2 THEN 'Canjeado'
      WHEN 3 THEN 'Cancelado'
      WHEN 4 THEN 'Expirado'
      ELSE 'Desconocido'
    END AS status_name,
    rd.expires_at,
    rd.redeemed_at,
    rd.created_at,
    ca.full_name,
    ca.phone_normalized,
    ca.email
  FROM moon_customer_reward_redemption rd
  INNER JOIN moon_branch_reward r
    ON r.id = rd.reward_id
  INNER JOIN moon_customer_access ca
    ON ca.id = rd.customer_access_id
  WHERE rd.organization_id = ?
    AND rd.redemption_code = ?
  LIMIT 1
";

$stmt = $con->prepare($sql);

if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close();
  exit;
}

$stmt->bind_param("is", $organization_id, $redemption_code);

if (!$stmt->execute()) {
  echo json_encode(["success" => false, "error" => "Error al consultar código: " . $stmt->error]);
  $stmt->close();
  $con->close();
  exit;
}

$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  $con->close();
  echo json_encode(["success" => false, "error" => "Código no encontrado para esta sucursal."]);
  exit;
}

$is_valid = ((int)$row['status'] === 1) && ($row['expires_at'] === null || strtotime($row['expires_at']) >= time());

$row['id'] = (int)$row['id'];
$row['customer_access_id'] = (int)$row['customer_access_id'];
$row['customer_branch_id'] = (int)$row['customer_branch_id'];
$row['organization_id'] = (int)$row['organization_id'];
$row['moon_customer_id'] = $row['moon_customer_id'] !== null ? (int)$row['moon_customer_id'] : null;
$row['reward_id'] = (int)$row['reward_id'];
$row['points_cost'] = (int)$row['points_cost'];
$row['status'] = (int)$row['status'];
$row['is_valid'] = $is_valid;

$con->close();

echo json_encode([
  "success" => true,
  "is_valid" => $is_valid,
  "data" => $row
]);