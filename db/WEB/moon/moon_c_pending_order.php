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
$status          = isset($in['status']) ? (int)$in['status'] : null;     // uno solo
$source          = isset($in['source']) ? (int)$in['source'] : null;
$channel         = isset($in['channel']) ? trim((string)$in['channel']) : '';
$updated_after   = isset($in['updated_after']) ? trim((string)$in['updated_after']) : ''; // 'YYYY-MM-DD HH:MM:SS'
$limit           = isset($in['limit']) ? max(1, min(200, (int)$in['limit'])) : 50;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sql = "SELECT id, organization_id, source, channel, label, customer_name, status, total, external_ref,
               created_by_user_id, attributes, created_at, updated_at
        FROM `moon_point`.`moon_pending_order`
        WHERE organization_id = ?";
$types = "i";
$params = [$organization_id];

if ($status !== null) { $sql .= " AND status = ?"; $types .= "i"; $params[] = $status; }
if ($source !== null) { $sql .= " AND source = ?"; $types .= "i"; $params[] = $source; }
if ($channel !== '') { $sql .= " AND channel = ?"; $types .= "s"; $params[] = $channel; }
if ($updated_after !== '') { $sql .= " AND updated_at > ?"; $types .= "s"; $params[] = $updated_after; }

$sql .= " ORDER BY updated_at DESC LIMIT ?"; $types .= "i"; $params[] = $limit;

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success" => false, "error" => "Error al preparar consulta: ".$con->error]); $con->close(); exit; }

$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
  echo json_encode(["success" => false, "error" => "Error al ejecutar: " . $stmt->error]);
  $stmt->close(); $con->close(); exit;
}

$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
  // decodifica JSON si viene
  if (isset($row['attributes']) && $row['attributes'] !== null && $row['attributes'] !== '') {
    $row['attributes'] = json_decode($row['attributes'], true);
  }
  // castea numéricos
  $row['id'] = (int)$row['id'];
  $row['organization_id'] = (int)$row['organization_id'];
  $row['source'] = (int)$row['source'];
  $row['status'] = (int)$row['status'];
  $row['total'] = (float)$row['total'];
  $row['created_by_user_id'] = isset($row['created_by_user_id']) ? (int)$row['created_by_user_id'] : null;
  $out[] = $row;
}

echo json_encode(["success" => true, "data" => $out]);
$stmt->close();
$con->close();
