<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$customer_branch_id = isset($in['customer_branch_id']) ? (int)$in['customer_branch_id'] : 0;
$reward_id = isset($in['reward_id']) ? (int)$in['reward_id'] : 0;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

if ($customer_branch_id <= 0) {
  client_response(["success" => false, "error" => "El customer_branch_id es obligatorio."], 400);
}

if ($reward_id <= 0) {
  client_response(["success" => false, "error" => "El reward_id es obligatorio."], 400);
}

function mp_generate_redemption_code($con) {
  $chars = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";

  for ($attempt = 0; $attempt < 20; $attempt++) {
    $code = "MP-";

    for ($i = 0; $i < 8; $i++) {
      $code .= $chars[random_int(0, strlen($chars) - 1)];
    }

    $sql = "SELECT id FROM moon_customer_reward_redemption WHERE redemption_code = ? LIMIT 1";
    $stmt = $con->prepare($sql);
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$exists) {
      return $code;
    }
  }

  return "MP-" . strtoupper(bin2hex(random_bytes(4)));
}

try {
  $con->begin_transaction();

  $sqlBranch = "
    SELECT
      cb.id,
      cb.customer_access_id,
      cb.organization_id,
      cb.moon_customer_id,
      cb.reward_points_balance,
      cb.level_points
    FROM moon_customer_branch cb
    WHERE cb.id = ?
      AND cb.customer_access_id = ?
      AND cb.relation_status = 1
    LIMIT 1
    FOR UPDATE
  ";

  $stmtBranch = $con->prepare($sqlBranch);

  if (!$stmtBranch) {
    throw new Exception("Error al preparar relación cliente/sucursal: " . $con->error);
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
    throw new Exception("No se encontró relación cliente/sucursal activa.");
  }

  $organization_id = (int)$branch['organization_id'];
  $moon_customer_id = $branch['moon_customer_id'] !== null ? (int)$branch['moon_customer_id'] : null;
  $reward_points_balance = (int)$branch['reward_points_balance'];
  $level_points = (int)$branch['level_points'];

  $sqlReward = "
    SELECT
      r.id,
      r.organization_id,
      r.reward_name,
      r.points_cost,
      r.required_level_id,
      r.stock_available,
      req_level.level_name AS required_level_name,
      req_level.min_level_points AS required_min_level_points
    FROM moon_branch_reward r
    LEFT JOIN moon_branch_level req_level
      ON req_level.id = r.required_level_id
    WHERE r.id = ?
      AND r.organization_id = ?
      AND r.is_active = 1
      AND r.stock_available > 0
      AND (r.start_at IS NULL OR r.start_at <= NOW())
      AND (r.end_at IS NULL OR r.end_at >= NOW())
    LIMIT 1
    FOR UPDATE
  ";

  $stmtReward = $con->prepare($sqlReward);

  if (!$stmtReward) {
    throw new Exception("Error al preparar recompensa: " . $con->error);
  }

  $stmtReward->bind_param("ii", $reward_id, $organization_id);

  if (!$stmtReward->execute()) {
    $error = $stmtReward->error;
    $stmtReward->close();
    throw new Exception("Error al consultar recompensa: " . $error);
  }

  $reward = $stmtReward->get_result()->fetch_assoc();
  $stmtReward->close();

  if (!$reward) {
    throw new Exception("La recompensa no existe, no está activa o no tiene stock disponible.");
  }

  $points_cost = (int)$reward['points_cost'];
  $required_min = $reward['required_min_level_points'] !== null ? (int)$reward['required_min_level_points'] : 0;

  if ($reward['required_level_id'] !== null && $level_points < $required_min) {
    throw new Exception("No tienes el nivel requerido para canjear esta recompensa.");
  }

  if ($reward_points_balance < $points_cost) {
    throw new Exception("No tienes puntos suficientes para canjear esta recompensa.");
  }

  $redemption_code = mp_generate_redemption_code($con);

  $sqlInsertRedemption = "
    INSERT INTO moon_customer_reward_redemption (
      redemption_code,
      customer_access_id,
      customer_branch_id,
      organization_id,
      moon_customer_id,
      reward_id,
      points_cost,
      status,
      expires_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, DATE_ADD(NOW(), INTERVAL 7 DAY))
  ";

  $stmtInsert = $con->prepare($sqlInsertRedemption);

  if (!$stmtInsert) {
    throw new Exception("Error al preparar canje: " . $con->error);
  }

  $stmtInsert->bind_param(
    "siiiiii",
    $redemption_code,
    $customer_access_id,
    $customer_branch_id,
    $organization_id,
    $moon_customer_id,
    $reward_id,
    $points_cost
  );

  if (!$stmtInsert->execute()) {
    $error = $stmtInsert->error;
    $stmtInsert->close();
    throw new Exception("Error al generar canje: " . $error);
  }

  $redemption_id = (int)$stmtInsert->insert_id;
  $stmtInsert->close();

  $sqlUpdateBranch = "
    UPDATE moon_customer_branch
    SET
      reward_points_balance = reward_points_balance - ?,
      updated_at = NOW()
    WHERE id = ?
      AND reward_points_balance >= ?
    LIMIT 1
  ";

  $stmtUpdateBranch = $con->prepare($sqlUpdateBranch);

  if (!$stmtUpdateBranch) {
    throw new Exception("Error al preparar descuento de puntos: " . $con->error);
  }

  $stmtUpdateBranch->bind_param("iii", $points_cost, $customer_branch_id, $points_cost);

  if (!$stmtUpdateBranch->execute()) {
    $error = $stmtUpdateBranch->error;
    $stmtUpdateBranch->close();
    throw new Exception("Error al descontar puntos: " . $error);
  }

  if ($stmtUpdateBranch->affected_rows <= 0) {
    $stmtUpdateBranch->close();
    throw new Exception("Saldo insuficiente de puntos.");
  }

  $stmtUpdateBranch->close();

  $sqlUpdateStock = "
    UPDATE moon_branch_reward
    SET
      stock_available = stock_available - 1,
      updated_at = NOW()
    WHERE id = ?
      AND stock_available > 0
    LIMIT 1
  ";

  $stmtStock = $con->prepare($sqlUpdateStock);

  if (!$stmtStock) {
    throw new Exception("Error al preparar actualización de stock: " . $con->error);
  }

  $stmtStock->bind_param("i", $reward_id);

  if (!$stmtStock->execute()) {
    $error = $stmtStock->error;
    $stmtStock->close();
    throw new Exception("Error al actualizar stock: " . $error);
  }

  if ($stmtStock->affected_rows <= 0) {
    $stmtStock->close();
    throw new Exception("La recompensa ya no tiene stock disponible.");
  }

  $stmtStock->close();

  $negative_points = $points_cost * -1;
  $movement_type = 2;
  $source = "reward_redeem";
  $notes = "Canje de recompensa: " . $reward['reward_name'];

  $sqlLog = "
    INSERT INTO moon_customer_branch_points_log (
      customer_branch_id,
      movement_type,
      points,
      source,
      reference_id,
      notes
    ) VALUES (?, ?, ?, ?, ?, ?)
  ";

  $stmtLog = $con->prepare($sqlLog);

  if (!$stmtLog) {
    throw new Exception("Error al preparar log de puntos: " . $con->error);
  }

  $stmtLog->bind_param(
    "iiisis",
    $customer_branch_id,
    $movement_type,
    $negative_points,
    $source,
    $redemption_id,
    $notes
  );

  if (!$stmtLog->execute()) {
    $error = $stmtLog->error;
    $stmtLog->close();
    throw new Exception("Error al registrar movimiento de puntos: " . $error);
  }

  $stmtLog->close();

  $con->commit();
  $con->close();

  client_response([
    "success" => true,
    "message" => "Canje generado correctamente.",
    "data" => [
      "redemption_id" => $redemption_id,
      "redemption_code" => $redemption_code,
      "reward_id" => $reward_id,
      "reward_name" => $reward['reward_name'],
      "points_cost" => $points_cost,
      "expires_in_days" => 7
    ]
  ]);

} catch (Throwable $e) {
  if ($con) {
    $con->rollback();
    $con->close();
  }

  client_response([
    "success" => false,
    "error" => $e->getMessage()
  ], 500);
}