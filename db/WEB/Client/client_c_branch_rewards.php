<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$customer_branch_id = isset($in['customer_branch_id']) ? (int)$in['customer_branch_id'] : 0;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

if ($customer_branch_id <= 0) {
  client_response(["success" => false, "error" => "El customer_branch_id es obligatorio."], 400);
}

try {
  $sqlBranch = "
    SELECT
      cb.id AS customer_branch_id,
      cb.organization_id,
      cb.reward_points_balance,
      cb.level_points,
      COALESCE(NULLIF(bp.public_name, ''), NULLIF(u.Nombre, ''), 'Sucursal') AS branch_name
    FROM moon_customer_branch cb
    INNER JOIN moon_user u
      ON u.id = cb.organization_id
    INNER JOIN moon_branch_profile bp
      ON bp.organization_id = cb.organization_id
    WHERE cb.id = ?
      AND cb.customer_access_id = ?
      AND cb.relation_status = 1
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
    throw new Exception("Error al consultar sucursal del cliente: " . $error);
  }

  $branch = $stmtBranch->get_result()->fetch_assoc();
  $stmtBranch->close();

  if (!$branch) {
    $con->close();
    client_response(["success" => false, "error" => "No se encontró la sucursal enlazada al cliente."], 404);
  }

  $organization_id = (int)$branch['organization_id'];
  $reward_points_balance = (int)$branch['reward_points_balance'];
  $level_points = (int)$branch['level_points'];

  $sqlRewards = "
    SELECT
      r.id,
      r.organization_id,
      r.reward_name,
      r.description,
      r.image_name,
      r.image_url,
      r.points_cost,
      r.required_level_id,
      req_level.level_name AS required_level_name,
      req_level.min_level_points AS required_min_level_points,
      r.stock_available,
      r.is_active,
      r.start_at,
      r.end_at
    FROM moon_branch_reward r
    LEFT JOIN moon_branch_level req_level
      ON req_level.id = r.required_level_id
    WHERE r.organization_id = ?
      AND r.is_active = 1
      AND r.stock_available > 0
      AND (r.start_at IS NULL OR r.start_at <= NOW())
      AND (r.end_at IS NULL OR r.end_at >= NOW())
    ORDER BY r.points_cost ASC, r.reward_name ASC
  ";

  $stmtRewards = $con->prepare($sqlRewards);

  if (!$stmtRewards) {
    throw new Exception("Error al preparar recompensas: " . $con->error);
  }

  $stmtRewards->bind_param("i", $organization_id);

  if (!$stmtRewards->execute()) {
    $error = $stmtRewards->error;
    $stmtRewards->close();
    throw new Exception("Error al consultar recompensas: " . $error);
  }

  $result = $stmtRewards->get_result();
  $rewards = [];

  while ($row = $result->fetch_assoc()) {
    $points_cost = (int)$row['points_cost'];
    $required_min = $row['required_min_level_points'] !== null ? (int)$row['required_min_level_points'] : 0;

    $has_points = $reward_points_balance >= $points_cost;
    $has_level = $row['required_level_id'] === null || $level_points >= $required_min;

    $row['id'] = (int)$row['id'];
    $row['organization_id'] = (int)$row['organization_id'];
    $row['points_cost'] = $points_cost;
    $row['required_level_id'] = $row['required_level_id'] !== null ? (int)$row['required_level_id'] : null;
    $row['required_min_level_points'] = $required_min;
    $row['stock_available'] = (int)$row['stock_available'];
    $row['is_active'] = (int)$row['is_active'];
    $row['can_redeem'] = $has_points && $has_level;
    $row['blocked_reason'] = null;

    if (!$has_points) {
      $row['blocked_reason'] = "No tienes puntos suficientes.";
    } elseif (!$has_level) {
      $row['blocked_reason'] = "Necesitas el nivel " . ($row['required_level_name'] ?? "requerido") . ".";
    }

    $rewards[] = $row;
  }

  $stmtRewards->close();
  $con->close();

  client_response([
    "success" => true,
    "branch" => [
      "customer_branch_id" => $customer_branch_id,
      "organization_id" => $organization_id,
      "branch_name" => $branch['branch_name'],
      "reward_points_balance" => $reward_points_balance,
      "level_points" => $level_points
    ],
    "total" => count($rewards),
    "data" => $rewards
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