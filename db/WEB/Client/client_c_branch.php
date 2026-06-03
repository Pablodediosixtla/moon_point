<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$organization_id = isset($in['organization_id']) ? (int)$in['organization_id'] : 0;
$qr_token = isset($in['qr_token']) ? trim((string)$in['qr_token']) : "";
$search = isset($in['search']) ? trim((string)$in['search']) : "";
$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;

$params = [];
$types = "";

$sql = "
  SELECT
    u.id AS organization_id,
    u.Nombre AS branch_name,
    u.Status AS branch_status,
    bp.public_name,
    bp.description,
    bp.logo_url,
    bp.address,
    bp.phone,
    bp.email,
    bp.qr_token,
    bp.qr_enabled,
    bp.is_active,
    cb.id AS customer_branch_id,
    cb.relation_status,
    cb.level_points,
    cb.reward_points_balance,
    cb.total_purchases,
    cb.total_amount,
    bl.level_name,
    bl.color_hex
  FROM moon_user u
  INNER JOIN moon_branch_profile bp
    ON bp.organization_id = u.id
  LEFT JOIN moon_customer_branch cb
    ON cb.organization_id = u.id
    AND cb.customer_access_id = ?
  LEFT JOIN moon_branch_level bl
    ON bl.id = cb.current_level_id
  WHERE u.Status = 1
    AND bp.is_active = 1
";

$params[] = $customer_access_id;
$types .= "i";

if ($organization_id > 0) {
  $sql .= " AND u.id = ? ";
  $params[] = $organization_id;
  $types .= "i";
}

if ($qr_token !== "") {
  $sql .= " AND bp.qr_token = ? AND bp.qr_enabled = 1 ";
  $params[] = $qr_token;
  $types .= "s";
}

if ($search !== "") {
  $like = "%" . $search . "%";
  $sql .= " AND (u.Nombre LIKE ? OR bp.public_name LIKE ?) ";
  $params[] = $like;
  $params[] = $like;
  $types .= "ss";
}

$sql .= " ORDER BY bp.public_name ASC LIMIT 30 ";

$stmt = $con->prepare($sql);

if (!$stmt) {
  $con->close();
  client_response(["success" => false, "error" => "Error al preparar consulta: " . $con->error], 500);
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
  $error = $stmt->error;
  $stmt->close();
  $con->close();
  client_response(["success" => false, "error" => "Error al consultar sucursales: " . $error], 500);
}

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
  $row['is_linked'] = !empty($row['customer_branch_id']);
  $data[] = $row;
}

$stmt->close();
$con->close();

client_response([
  "success" => true,
  "total" => count($data),
  "data" => $data
]);