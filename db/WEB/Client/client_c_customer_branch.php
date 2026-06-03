<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

$sql = "
  SELECT
    cb.id AS customer_branch_id,
    cb.customer_access_id,
    cb.organization_id,
    u.Nombre AS branch_name,
    bp.public_name,
    bp.description,
    bp.logo_url,
    bp.address,
    bp.phone,
    bp.email,
    cb.current_level_id,
    bl.level_name,
    bl.color_hex,
    cb.level_points,
    cb.reward_points_balance,
    cb.total_purchases,
    cb.total_amount,
    cb.relation_status,
    cb.linked_by,
    cb.linked_at,
    cb.updated_at
  FROM moon_customer_branch cb
  INNER JOIN moon_user u
    ON u.id = cb.organization_id
  INNER JOIN moon_branch_profile bp
    ON bp.organization_id = cb.organization_id
  LEFT JOIN moon_branch_level bl
    ON bl.id = cb.current_level_id
  WHERE cb.customer_access_id = ?
    AND cb.relation_status = 1
    AND u.Status = 1
    AND bp.is_active = 1
  ORDER BY bp.public_name ASC
";

$stmt = $con->prepare($sql);
$stmt->bind_param("i", $customer_access_id);

if (!$stmt->execute()) {
  $error = $stmt->error;
  $stmt->close();
  $con->close();
  client_response(["success" => false, "error" => "Error al consultar sucursales del cliente: " . $error], 500);
}

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
  $data[] = $row;
}

$stmt->close();
$con->close();

client_response([
  "success" => true,
  "total" => count($data),
  "data" => $data
]);