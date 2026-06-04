<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$limit = isset($in['limit']) ? (int)$in['limit'] : 200;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

$limit = max(1, min($limit, 500));

try {
  $sqlTotal = "
    SELECT
      COALESCE(SUM(cb.reward_points_balance), 0) AS total_points
    FROM moon_customer_branch cb
    INNER JOIN moon_user u
      ON u.id = cb.organization_id
    INNER JOIN moon_branch_profile bp
      ON bp.organization_id = cb.organization_id
    WHERE cb.customer_access_id = ?
      AND cb.relation_status = 1
      AND u.Status = 1
      AND bp.is_active = 1
  ";

  $stmtTotal = $con->prepare($sqlTotal);

  if (!$stmtTotal) {
    throw new Exception("Error al preparar total de puntos: " . $con->error);
  }

  $stmtTotal->bind_param("i", $customer_access_id);

  if (!$stmtTotal->execute()) {
    $error = $stmtTotal->error;
    $stmtTotal->close();
    throw new Exception("Error al consultar total de puntos: " . $error);
  }

  $totalRow = $stmtTotal->get_result()->fetch_assoc();
  $stmtTotal->close();

  $total_points = isset($totalRow['total_points']) ? (int)$totalRow['total_points'] : 0;

  $sqlActivity = "
    SELECT
      pl.id,
      pl.customer_branch_id,
      cb.organization_id,
      COALESCE(NULLIF(bp.public_name, ''), NULLIF(u.Nombre, ''), 'Sucursal') AS branch_name,
      pl.movement_type,
      CASE pl.movement_type
        WHEN 1 THEN 'Entrada de puntos'
        WHEN 2 THEN 'Canje de puntos'
        WHEN 3 THEN 'Ajuste de puntos'
        WHEN 4 THEN 'Expiración de puntos'
        ELSE 'Movimiento de puntos'
      END AS movement_name,
      pl.points,
      ABS(pl.points) AS abs_points,
      pl.source,
      pl.reference_id,
      pl.notes,
      pl.created_at
    FROM moon_customer_branch_points_log pl
    INNER JOIN moon_customer_branch cb
      ON cb.id = pl.customer_branch_id
    INNER JOIN moon_user u
      ON u.id = cb.organization_id
    INNER JOIN moon_branch_profile bp
      ON bp.organization_id = cb.organization_id
    WHERE cb.customer_access_id = ?
      AND cb.relation_status = 1
      AND u.Status = 1
      AND bp.is_active = 1
    ORDER BY pl.created_at DESC, pl.id DESC
    LIMIT ?
  ";

  $stmtActivity = $con->prepare($sqlActivity);

  if (!$stmtActivity) {
    throw new Exception("Error al preparar actividad de puntos: " . $con->error);
  }

  $stmtActivity->bind_param("ii", $customer_access_id, $limit);

  if (!$stmtActivity->execute()) {
    $error = $stmtActivity->error;
    $stmtActivity->close();
    throw new Exception("Error al consultar actividad de puntos: " . $error);
  }

  $result = $stmtActivity->get_result();

  $all_activity = [];
  $recent_activity = [];

  while ($row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['customer_branch_id'] = (int)$row['customer_branch_id'];
    $row['organization_id'] = (int)$row['organization_id'];
    $row['movement_type'] = (int)$row['movement_type'];
    $row['points'] = (int)$row['points'];
    $row['abs_points'] = (int)$row['abs_points'];
    $row['reference_id'] = $row['reference_id'] !== null ? (int)$row['reference_id'] : null;

    $all_activity[] = $row;

    if (count($recent_activity) < 3) {
      $recent_activity[] = $row;
    }
  }

  $stmtActivity->close();
  $con->close();

  client_response([
    "success" => true,
    "total_points" => $total_points,
    "recent_activity" => $recent_activity,
    "all_activity" => $all_activity
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