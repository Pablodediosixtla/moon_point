<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$customer_branch_id = isset($in['customer_branch_id']) ? (int)$in['customer_branch_id'] : 0;
$limit = isset($in['limit']) ? (int)$in['limit'] : 50;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

if ($customer_branch_id <= 0) {
  client_response(["success" => false, "error" => "El customer_branch_id es obligatorio."], 400);
}

$limit = max(1, min($limit, 100));

try {
  $sqlBranch = "
    SELECT
      cb.id AS customer_branch_id,
      cb.customer_access_id,
      cb.organization_id,
      COALESCE(NULLIF(bp.public_name, ''), NULLIF(u.Nombre, ''), 'Sucursal') AS branch_name
    FROM moon_customer_branch cb
    INNER JOIN moon_user u
      ON u.id = cb.organization_id
    INNER JOIN moon_branch_profile bp
      ON bp.organization_id = cb.organization_id
    INNER JOIN moon_customer_access ca
      ON ca.id = cb.customer_access_id
    WHERE cb.id = ?
      AND cb.customer_access_id = ?
      AND cb.relation_status = 1
      AND ca.deleted_at IS NULL
      AND ca.access_status = 1
      AND u.Status = 1
      AND bp.is_active = 1
    LIMIT 1
  ";

  $stmtBranch = $con->prepare($sqlBranch);

  if (!$stmtBranch) {
    throw new Exception("Error al preparar consulta de sucursal: " . $con->error);
  }

  $stmtBranch->bind_param("ii", $customer_branch_id, $customer_access_id);

  if (!$stmtBranch->execute()) {
    $error = $stmtBranch->error;
    $stmtBranch->close();
    throw new Exception("Error al consultar relación cliente/sucursal: " . $error);
  }

  $branch = $stmtBranch->get_result()->fetch_assoc();
  $stmtBranch->close();

  if (!$branch) {
    $con->close();
    client_response([
      "success" => false,
      "error" => "No se encontró la relación activa entre cliente y sucursal."
    ], 404);
  }

  $organization_id = (int)$branch['organization_id'];

  $sqlEvents = "
    SELECT
      id,
      organization_id,
      event_title,
      event_description,
      event_start_at,
      event_end_at,
      event_location,
      image_name,
      image_url,
      is_active,
      created_at,
      updated_at
    FROM moon_branch_event
    WHERE organization_id = ?
      AND is_active = 1
      AND event_start_at >= NOW()
    ORDER BY event_start_at ASC
    LIMIT ?
  ";

  $stmtEvents = $con->prepare($sqlEvents);

  if (!$stmtEvents) {
    throw new Exception("Error al preparar eventos: " . $con->error);
  }

  $stmtEvents->bind_param("ii", $organization_id, $limit);

  if (!$stmtEvents->execute()) {
    $error = $stmtEvents->error;
    $stmtEvents->close();
    throw new Exception("Error al consultar eventos: " . $error);
  }

  $result = $stmtEvents->get_result();
  $data = [];

  while ($row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['organization_id'] = (int)$row['organization_id'];
    $row['is_active'] = (int)$row['is_active'];
    $data[] = $row;
  }

  $stmtEvents->close();
  $con->close();

  client_response([
    "success" => true,
    "branch" => [
      "customer_branch_id" => $customer_branch_id,
      "organization_id" => $organization_id,
      "branch_name" => $branch['branch_name']
    ],
    "total" => count($data),
    "data" => $data
  ]);

} catch (Throwable $e) {
  if ($con) {
    $con->close();
  }

  client_response([
    "success" => false,
    "error" => $e->getMessage()
  ], 500);
}