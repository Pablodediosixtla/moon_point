<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$customer_branch_id = isset($in['customer_branch_id']) ? (int)$in['customer_branch_id'] : 0;
$relation_status = isset($in['relation_status']) ? (int)$in['relation_status'] : 0;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

if ($customer_branch_id <= 0) {
  client_response(["success" => false, "error" => "El customer_branch_id es obligatorio."], 400);
}

if (!in_array($relation_status, [0, 1], true)) {
  client_response(["success" => false, "error" => "relation_status inválido. Usa 0 o 1."], 400);
}

try {
  $sqlCheck = "
    SELECT
      cb.id,
      cb.customer_access_id,
      cb.organization_id,
      cb.relation_status
    FROM moon_customer_branch cb
    WHERE cb.id = ?
      AND cb.customer_access_id = ?
    LIMIT 1
  ";

  $stmtCheck = $con->prepare($sqlCheck);

  if (!$stmtCheck) {
    throw new Exception("Error al preparar validación: " . $con->error);
  }

  $stmtCheck->bind_param("ii", $customer_branch_id, $customer_access_id);

  if (!$stmtCheck->execute()) {
    $error = $stmtCheck->error;
    $stmtCheck->close();
    throw new Exception("Error al validar relación cliente/sucursal: " . $error);
  }

  $branch = $stmtCheck->get_result()->fetch_assoc();
  $stmtCheck->close();

  if (!$branch) {
    $con->close();
    client_response(["success" => false, "error" => "No se encontró la relación cliente/sucursal."], 404);
  }

  $sqlUpdate = "
    UPDATE moon_customer_branch
    SET
      relation_status = ?,
      updated_at = NOW()
    WHERE id = ?
      AND customer_access_id = ?
    LIMIT 1
  ";

  $stmtUpdate = $con->prepare($sqlUpdate);

  if (!$stmtUpdate) {
    throw new Exception("Error al preparar actualización: " . $con->error);
  }

  $stmtUpdate->bind_param("iii", $relation_status, $customer_branch_id, $customer_access_id);

  if (!$stmtUpdate->execute()) {
    $error = $stmtUpdate->error;
    $stmtUpdate->close();
    throw new Exception("Error al actualizar estatus de sucursal: " . $error);
  }

  $stmtUpdate->close();
  $con->close();

  client_response([
    "success" => true,
    "message" => $relation_status === 1 ? "Sucursal reactivada." : "Sucursal ocultada del perfil.",
    "data" => [
      "customer_branch_id" => $customer_branch_id,
      "customer_access_id" => $customer_access_id,
      "organization_id" => (int)$branch['organization_id'],
      "relation_status" => $relation_status
    ]
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