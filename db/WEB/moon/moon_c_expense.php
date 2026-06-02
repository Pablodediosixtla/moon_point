<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

include realpath("/home/site/wwwroot/db/conn/Conexion.php");

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (!isset($in['organization_id']) || $in['organization_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta organization_id"]);
  exit;
}

$organization_id = (int)$in['organization_id'];
$vendor_id       = isset($in['vendor_id']) ? (int)$in['vendor_id'] : 0;
$status          = isset($in['status']) ? (int)$in['status'] : null; // 1=registrado,2=cancelado
$date_from       = isset($in['date_from']) ? trim((string)$in['date_from']) : ''; // YYYY-MM-DD
$date_to         = isset($in['date_to'])   ? trim((string)$in['date_to'])   : ''; // YYYY-MM-DD
$limit           = isset($in['limit']) ? max(1, min(100000, (int)$in['limit'])) : 100;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$sql = "SELECT id, organization_id, vendor_id, description, expense_date, amount,
               payment_method, status, note, attributes,
               created_at, updated_at
        FROM `moon_point`.`moon_expense`
        WHERE organization_id = ?";
$types = "i";
$params = [$organization_id];

if ($vendor_id > 0) {
    $sql .= " AND vendor_id = ?";
    $types .= "i";
    $params[] = $vendor_id;
}
if ($status !== null) {
    $sql .= " AND status = ?";
    $types .= "i";
    $params[] = $status;
}
if ($date_from !== '') {
    $sql .= " AND expense_date >= ?";
    $types .= "s";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $sql .= " AND expense_date <= ?";
    $types .= "s";
    $params[] = $date_to;
}

$sql .= " ORDER BY expense_date DESC, id DESC LIMIT ?";
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
    $row['vendor_id']       = isset($row['vendor_id']) ? (int)$row['vendor_id'] : null;
    $row['amount']          = (float)$row['amount'];
    $row['payment_method']  = isset($row['payment_method']) ? (int)$row['payment_method'] : null;
    $row['status']          = (int)$row['status'];
    $out[] = $row;
}

echo json_encode(["success"=>true,"data"=>$out]);
$stmt->close();
$con->close();
