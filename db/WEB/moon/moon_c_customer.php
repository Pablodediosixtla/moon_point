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

if (!isset($in['organization_id']) || $in['organization_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: organization_id"]);
  exit;
}

$organization_id = (int)$in['organization_id'];
$search          = isset($in['search']) ? trim((string)$in['search']) : '';
$is_active       = isset($in['is_active']) && $in['is_active'] !== '' ? (int)$in['is_active'] : null;
$updated_after   = isset($in['updated_after']) ? trim((string)$in['updated_after']) : '';
$limit           = isset($in['limit']) ? max(1, min(200, (int)$in['limit'])) : 50;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sql = "SELECT id,
               organization_id,
               customer_name,
               full_name,
               birth_date,
               phone,
               email,
               address,
               is_active,
               created_at,
               updated_at
        FROM `moon_point`.`moon_customer`
        WHERE organization_id = ?";
$types = "i";
$params = [$organization_id];

if ($is_active !== null) {
  $sql .= " AND is_active = ?";
  $types .= "i";
  $params[] = $is_active;
}

if ($updated_after !== '') {
  $sql .= " AND updated_at > ?";
  $types .= "s";
  $params[] = $updated_after;
}

if ($search !== '') {
  // wildcard para LIKE
  $like = "%" . $search . "%";
  $sql .= " AND (customer_name LIKE ? OR full_name LIKE ? OR phone LIKE ? OR email LIKE ?)";
  $types .= "ssss";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$sql .= " ORDER BY updated_at DESC LIMIT ?";
$types .= "i";
$params[] = $limit;

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close();
  exit;
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
  echo json_encode(["success" => false, "error" => "Error al ejecutar: " . $stmt->error]);
  $stmt->close();
  $con->close();
  exit;
}

$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
  $row['id']              = (int)$row['id'];
  $row['organization_id'] = (int)$row['organization_id'];
  $row['is_active']       = (int)$row['is_active'];

  // birth_date se va como string 'YYYY-MM-DD' o null
  // phone/email/address ya vienen como string o null

  $out[] = $row;
}

echo json_encode(["success" => true, "data" => $out]);

$stmt->close();
$con->close();
