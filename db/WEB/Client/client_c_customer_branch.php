<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$refresh = isset($in['refresh']) ? (int)$in['refresh'] : 0;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

function mp_clear_results($con) {
  while ($con->more_results()) {
    $con->next_result();

    if ($res = $con->use_result()) {
      $res->free();
    }
  }
}

function mp_sync_customer_profile_to_branches($con, $customer_access_id) {
  $sqlCustomer = "
    SELECT
      full_name,
      email
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
    throw new Exception("No se encontró el cliente global activo.");
  }

  $full_name = $customer['full_name'];
  $email = $customer['email'];

  $sqlSync = "CALL sp_moon_sync_customer_profile_to_branches(?, ?, ?)";
  $stmtSync = $con->prepare($sqlSync);

  if (!$stmtSync) {
    throw new Exception("Error al preparar sincronización de perfil: " . $con->error);
  }

  $stmtSync->bind_param("iss", $customer_access_id, $full_name, $email);

  if (!$stmtSync->execute()) {
    $error = $stmtSync->error;
    $stmtSync->close();
    throw new Exception("Error al sincronizar perfil: " . $error);
  }

  if ($res = $stmtSync->get_result()) {
    $res->free();
  }

  $stmtSync->close();
  mp_clear_results($con);
}

function mp_recalculate_customer_branch_levels($con, $customer_access_id) {
  $sqlBranches = "
    SELECT id
    FROM moon_customer_branch
    WHERE customer_access_id = ?
      AND relation_status = 1
  ";

  $stmt = $con->prepare($sqlBranches);
  $stmt->bind_param("i", $customer_access_id);
  $stmt->execute();
  $result = $stmt->get_result();

  $branchIds = [];

  while ($row = $result->fetch_assoc()) {
    $branchIds[] = (int)$row['id'];
  }

  $stmt->close();

  foreach ($branchIds as $customer_branch_id) {
    $sqlRecalc = "CALL sp_moon_recalculate_customer_branch_level(?)";
    $stmtRecalc = $con->prepare($sqlRecalc);

    if (!$stmtRecalc) {
      throw new Exception("Error al preparar recálculo de nivel: " . $con->error);
    }

    $stmtRecalc->bind_param("i", $customer_branch_id);

    if (!$stmtRecalc->execute()) {
      $error = $stmtRecalc->error;
      $stmtRecalc->close();
      throw new Exception("Error al recalcular nivel: " . $error);
    }

    if ($res = $stmtRecalc->get_result()) {
      $res->free();
    }

    $stmtRecalc->close();
    mp_clear_results($con);
  }
}

try {
  if ($refresh === 1) {
    mp_sync_customer_profile_to_branches($con, $customer_access_id);
    mp_recalculate_customer_branch_levels($con, $customer_access_id);
  }

  $sql = "
    SELECT
      cb.id AS customer_branch_id,
      cb.customer_access_id,
      cb.organization_id,
      cb.moon_customer_id,
      u.Nombre AS branch_name,
      bp.public_name,
      bp.description,
      bp.logo_url,
      bp.address,
      bp.phone,
      bp.email,
      cb.current_level_id,

      (
        SELECT l.level_name
        FROM moon_branch_level l
        WHERE l.organization_id = cb.organization_id
          AND l.is_active = 1
          AND cb.level_points >= l.min_level_points
          AND (l.max_level_points IS NULL OR cb.level_points <= l.max_level_points)
        ORDER BY l.min_level_points DESC
        LIMIT 1
      ) AS level_name,

      (
        SELECT l.color_hex
        FROM moon_branch_level l
        WHERE l.organization_id = cb.organization_id
          AND l.is_active = 1
          AND cb.level_points >= l.min_level_points
          AND (l.max_level_points IS NULL OR cb.level_points <= l.max_level_points)
        ORDER BY l.min_level_points DESC
        LIMIT 1
      ) AS color_hex,

      (
        SELECT l.min_level_points
        FROM moon_branch_level l
        WHERE l.organization_id = cb.organization_id
          AND l.is_active = 1
          AND cb.level_points >= l.min_level_points
          AND (l.max_level_points IS NULL OR cb.level_points <= l.max_level_points)
        ORDER BY l.min_level_points DESC
        LIMIT 1
      ) AS level_min_points,

      (
        SELECT l.max_level_points
        FROM moon_branch_level l
        WHERE l.organization_id = cb.organization_id
          AND l.is_active = 1
          AND cb.level_points >= l.min_level_points
          AND (l.max_level_points IS NULL OR cb.level_points <= l.max_level_points)
        ORDER BY l.min_level_points DESC
        LIMIT 1
      ) AS level_max_points,

      (
        SELECT l.level_name
        FROM moon_branch_level l
        WHERE l.organization_id = cb.organization_id
          AND l.is_active = 1
          AND l.min_level_points > cb.level_points
        ORDER BY l.min_level_points ASC
        LIMIT 1
      ) AS next_level_name,

      (
        SELECT l.min_level_points
        FROM moon_branch_level l
        WHERE l.organization_id = cb.organization_id
          AND l.is_active = 1
          AND l.min_level_points > cb.level_points
        ORDER BY l.min_level_points ASC
        LIMIT 1
      ) AS next_level_min_points,

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
    $level_points = isset($row['level_points']) ? (int)$row['level_points'] : 0;
    $level_min_points = isset($row['level_min_points']) && $row['level_min_points'] !== null ? (int)$row['level_min_points'] : 0;
    $level_max_points = isset($row['level_max_points']) && $row['level_max_points'] !== null ? (int)$row['level_max_points'] : null;
    $next_level_min_points = isset($row['next_level_min_points']) && $row['next_level_min_points'] !== null ? (int)$row['next_level_min_points'] : null;

    if ($next_level_min_points === null) {
      $progress = 100;
      $points_to_next = 0;
    } else {
      $range = max($next_level_min_points - $level_min_points, 1);
      $progress = (($level_points - $level_min_points) / $range) * 100;
      $progress = max(0, min(100, $progress));
      $points_to_next = max($next_level_min_points - $level_points, 0);
    }

    $row['level_points'] = $level_points;
    $row['reward_points_balance'] = isset($row['reward_points_balance']) ? (int)$row['reward_points_balance'] : 0;
    $row['total_purchases'] = isset($row['total_purchases']) ? (int)$row['total_purchases'] : 0;
    $row['level_min_points'] = $level_min_points;
    $row['level_max_points'] = $level_max_points;
    $row['next_level_min_points'] = $next_level_min_points;
    $row['level_progress_percent'] = round($progress, 2);
    $row['points_to_next_level'] = $points_to_next;

    if (empty($row['level_name'])) {
      $row['level_name'] = 'Inicial';
    }

    if (empty($row['color_hex'])) {
      $row['color_hex'] = '#0D1B2A';
    }

    $data[] = $row;
  }

  $stmt->close();
  $con->close();

  client_response([
    "success" => true,
    "refreshed" => $refresh === 1,
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