<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$organization_id = isset($in['organization_id']) ? (int)$in['organization_id'] : 0;
$qr_token = isset($in['qr_token']) ? trim((string)$in['qr_token']) : "";
$linked_by = isset($in['linked_by']) ? trim((string)$in['linked_by']) : "manual";

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

if ($organization_id <= 0 && $qr_token === "") {
  client_response(["success" => false, "error" => "Debes enviar organization_id o qr_token."], 400);
}

if (!in_array($linked_by, ["manual", "qr", "search"], true)) {
  $linked_by = "manual";
}

$sqlCustomer = "
  SELECT id
  FROM moon_customer_access
  WHERE id = ?
    AND deleted_at IS NULL
    AND access_status = 1
  LIMIT 1
";

$stmt = $con->prepare($sqlCustomer);
$stmt->bind_param("i", $customer_access_id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
  $con->close();
  client_response(["success" => false, "error" => "No se encontró el cliente o no está activo."], 404);
}

if ($organization_id <= 0 && $qr_token !== "") {
  $sqlOrgByQR = "
    SELECT organization_id
    FROM moon_branch_profile
    WHERE qr_token = ?
      AND qr_enabled = 1
      AND is_active = 1
    LIMIT 1
  ";

  $stmt = $con->prepare($sqlOrgByQR);
  $stmt->bind_param("s", $qr_token);
  $stmt->execute();
  $branchQR = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$branchQR) {
    $con->close();
    client_response(["success" => false, "error" => "QR inválido o sucursal inactiva."], 404);
  }

  $organization_id = (int)$branchQR['organization_id'];
}

$sqlBranch = "
  SELECT
    u.id,
    u.Nombre,
    bp.public_name
  FROM moon_user u
  INNER JOIN moon_branch_profile bp
    ON bp.organization_id = u.id
  WHERE u.id = ?
    AND u.Status = 1
    AND bp.is_active = 1
  LIMIT 1
";

$stmt = $con->prepare($sqlBranch);
$stmt->bind_param("i", $organization_id);
$stmt->execute();
$branch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$branch) {
  $con->close();
  client_response(["success" => false, "error" => "No se encontró la sucursal o no está activa."], 404);
}

$sqlExisting = "
  SELECT id
  FROM moon_customer_branch
  WHERE customer_access_id = ?
    AND organization_id = ?
  LIMIT 1
";

$stmt = $con->prepare($sqlExisting);
$stmt->bind_param("ii", $customer_access_id, $organization_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
  $sqlReactivate = "
    UPDATE moon_customer_branch
    SET relation_status = 1,
        linked_by = ?,
        updated_at = NOW()
    WHERE id = ?
  ";

  $stmt = $con->prepare($sqlReactivate);
  $stmt->bind_param("si", $linked_by, $existing['id']);
  $stmt->execute();
  $stmt->close();

  $con->close();

  client_response([
    "success" => true,
    "message" => "La sucursal ya estaba enlazada.",
    "already_linked" => true,
    "id" => (int)$existing['id']
  ]);
}

$current_level_id = null;

$sqlLevel = "
  SELECT id
  FROM moon_branch_level
  WHERE organization_id = ?
    AND is_active = 1
  ORDER BY min_level_points ASC
  LIMIT 1
";

$stmt = $con->prepare($sqlLevel);
$stmt->bind_param("i", $organization_id);
$stmt->execute();
$level = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($level) {
  $current_level_id = (int)$level['id'];
}

$sqlInsert = "
  INSERT INTO moon_customer_branch (
    customer_access_id,
    organization_id,
    current_level_id,
    level_points,
    reward_points_balance,
    total_purchases,
    total_amount,
    relation_status,
    linked_by
  ) VALUES (?, ?, ?, 0, 0, 0, 0.00, 1, ?)
";

$stmt = $con->prepare($sqlInsert);
$stmt->bind_param("iiis", $customer_access_id, $organization_id, $current_level_id, $linked_by);

if (!$stmt->execute()) {
  $error = $stmt->error;
  $stmt->close();
  $con->close();
  client_response(["success" => false, "error" => "Error al enlazar sucursal: " . $error], 500);
}

$new_id = $stmt->insert_id;
$stmt->close();
$con->close();

client_response([
  "success" => true,
  "message" => "Sucursal agregada correctamente.",
  "id" => $new_id,
  "data" => [
    "id" => $new_id,
    "customer_access_id" => $customer_access_id,
    "organization_id" => $organization_id,
    "branch_name" => $branch['public_name'] ?: $branch['Nombre'],
    "linked_by" => $linked_by
  ]
]);