<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

try {
  $sql = "
    SELECT
      cb.id AS customer_branch_id,
      cb.customer_access_id,
      cb.organization_id,
      cb.moon_customer_id,
      cb.reward_points_balance,
      cb.level_points,
      COALESCE(NULLIF(bp.public_name, ''), NULLIF(u.Nombre, ''), 'Sucursal') AS branch_name,
      COUNT(r.id) AS available_rewards_count,
      COALESCE(SUM(
        CASE
          WHEN r.id IS NOT NULL
           AND cb.reward_points_balance >= r.points_cost
           AND (
                r.required_level_id IS NULL
                OR cb.level_points >= COALESCE(req_level.min_level_points, 0)
           )
          THEN 1
          ELSE 0
        END
      ), 0) AS redeemable_rewards_count
    FROM moon_customer_branch cb
    INNER JOIN moon_user u
      ON u.id = cb.organization_id
    INNER JOIN moon_branch_profile bp
      ON bp.organization_id = cb.organization_id
    LEFT JOIN moon_branch_reward r
      ON r.organization_id = cb.organization_id
     AND r.is_active = 1
     AND r.stock_available > 0
     AND (r.start_at IS NULL OR r.start_at <= NOW())
     AND (r.end_at IS NULL OR r.end_at >= NOW())
    LEFT JOIN moon_branch_level req_level
      ON req_level.id = r.required_level_id
    WHERE cb.customer_access_id = ?
      AND cb.relation_status = 1
      AND u.Status = 1
      AND bp.is_active = 1
    GROUP BY
      cb.id,
      cb.customer_access_id,
      cb.organization_id,
      cb.moon_customer_id,
      cb.reward_points_balance,
      cb.level_points,
      branch_name
    ORDER BY branch_name ASC
  ";

  $stmt = $con->prepare($sql);

  if (!$stmt) {
    throw new Exception("Error al preparar resumen de recompensas: " . $con->error);
  }

  $stmt->bind_param("i", $customer_access_id);

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al consultar resumen de recompensas: " . $error);
  }

  $result = $stmt->get_result();
  $data = [];

  while ($row = $result->fetch_assoc()) {
    $row['customer_branch_id'] = (int)$row['customer_branch_id'];
    $row['customer_access_id'] = (int)$row['customer_access_id'];
    $row['organization_id'] = (int)$row['organization_id'];
    $row['moon_customer_id'] = $row['moon_customer_id'] !== null ? (int)$row['moon_customer_id'] : null;
    $row['reward_points_balance'] = (int)$row['reward_points_balance'];
    $row['level_points'] = (int)$row['level_points'];
    $row['available_rewards_count'] = (int)$row['available_rewards_count'];
    $row['redeemable_rewards_count'] = (int)$row['redeemable_rewards_count'];

    $data[] = $row;
  }

  $stmt->close();
  $con->close();

  client_response([
    "success" => true,
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