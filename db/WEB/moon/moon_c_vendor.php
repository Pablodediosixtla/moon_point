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
  echo json_encode(["success" => false, "error" => "Falta organization_id"]);
  exit;
}

$organization_id = (int)$in['organization_id'];
$vendor_id       = isset($in['vendor_id']) ? (int)$in['vendor_id'] : 0;
$vendor_type     = isset($in['vendor_type']) ? (int)$in['vendor_type'] : 0;
$only_active     = isset($in['only_active']) ? (int)$in['only_active'] : null; // 1 = solo activos
$search          = isset($in['search']) ? trim((string)$in['search']) : '';
$limit           = isset($in['limit']) ? max(1, min(200, (int)$in['limit'])) : 100;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$sql = "SELECT id, organization_id, vendor_type, vendor_name, contact_name,
               phone, email, tax_id, is_active, attributes,
               created_at, updated_at
        FROM `moon_point`.`moon_vendor`
        WHERE organization_id = ?";
$types = "i";
$params = [$organization_id];

if ($vendor_id > 0) {
    $sql .= " AND id = ?";
    $types .= "i";
    $params[] = $vendor_id;
}

if ($vendor_type > 0) {
    $sql .= " AND vendor_type = ?";
    $types .= "i";
    $params[] = $vendor_type;
}

if ($only_active !== null) {
    $sql .= " AND is_active = ?";
    $types .= "i";
    $params[] = $only_active;
}

if ($search !== '') {
    $sql .= " AND (vendor_name LIKE CONCAT('%', ?, '%')
               OR contact_name LIKE CONCAT('%', ?, '%')
               OR phone LIKE CONCAT('%', ?, '%')
               OR email LIKE CONCAT('%', ?, '%'))";
    $types .= "ssss";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$sql .= " ORDER BY vendor_name ASC LIMIT ?";
$types .= "i";
$params[] = $limit;

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success"=>false,"error"=>"Error al preparar consulta: ".$con->error]);
  $con->close(); exit;
}

$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
  echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]);
  $stmt->close(); $con->close(); exit;
}

$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
    if (isset($row['attributes']) && $row['attributes'] !== null && $row['attributes'] !== '') {
        $row['attributes'] = json_decode($row['attributes'], true);
    }
    $row['id']              = (int)$row['id'];
    $row['organization_id'] = (int)$row['organization_id'];
    $row['vendor_type']     = (int)$row['vendor_type'];
    $row['is_active']       = (int)$row['is_active'];
    $out[] = $row;
}

echo json_encode(["success"=>true, "data"=>$out]);
$stmt->close();
$con->close();
