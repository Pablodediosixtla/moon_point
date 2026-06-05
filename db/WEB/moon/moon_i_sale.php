<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if (!$path || !file_exists($path)) {
  echo json_encode(["success" => false, "error" => "No se encontró Conexion.php en $path"]);
  exit;
}
include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (!isset($in['organization_id']) || $in['organization_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta organization_id"]);
  exit;
}
if (!isset($in['payment_method']) || $in['payment_method'] === "") {
  echo json_encode(["success" => false, "error" => "Falta payment_method"]);
  exit;
}
if (!isset($in['items']) || !is_array($in['items']) || count($in['items']) === 0) {
  echo json_encode(["success" => false, "error" => "Faltan items de la venta"]);
  exit;
}

$organization_id  = (int)$in['organization_id'];
$pending_order_id = isset($in['pending_order_id']) ? (int)$in['pending_order_id'] : 0;
$customer_id      = isset($in['customer_id']) ? (int)$in['customer_id'] : 0;
$customer_name    = isset($in['customer_name']) ? trim((string)$in['customer_name']) : '';

$source           = isset($in['source']) ? (int)$in['source'] : 1;
$channel          = isset($in['channel']) ? trim((string)$in['channel']) : 'POS';
$status           = isset($in['status']) ? (int)$in['status'] : 1;

$pmIn = $in['payment_method'];
if (is_string($pmIn)) {
  $pmLower = strtolower($pmIn);
  $payment_method = ($pmLower === 'card') ? 2 : 1;
} else {
  $payment_method = (int)$pmIn;
  if ($payment_method !== 1 && $payment_method !== 2) { $payment_method = 1; }
}

$note        = isset($in['note']) ? trim((string)$in['note']) : '';
$attributes  = isset($in['attributes']) ? json_encode($in['attributes']) : '';

$items = $in['items'];

$items_subtotal = 0.0;
$total_qty_for_rewards = 0;

foreach ($items as $it) {
  $q  = isset($it['qty']) ? (int)$it['qty'] : 0;
  $up = isset($it['unit_price']) ? (float)$it['unit_price'] : 0.0;

  if ($q <= 0) {
    echo json_encode(["success" => false, "error" => "Item con qty inválido"]);
    exit;
  }

  $items_subtotal += ($q * $up);
  $total_qty_for_rewards += $q;
}

$discount_amount  = isset($in['discount_amount']) ? (float)$in['discount_amount'] : 0.0;
$discount_percent = isset($in['discount_percent']) ? (float)$in['discount_percent'] : null;

if ($discount_percent !== null && $discount_amount <= 0) {
  $discount_amount = round($items_subtotal * max(0.0, min($discount_percent, 100.0)) / 100.0, 2);
}

$tax_amount = isset($in['tax_amount']) ? (float)$in['tax_amount'] : 0.0;

$subtotal = isset($in['subtotal']) ? (float)$in['subtotal'] : $items_subtotal;
$subtotal = round($items_subtotal, 2);

$total = isset($in['total']) ? (float)$in['total'] : ($subtotal - $discount_amount + $tax_amount);
$total = round(max(0.0, $total), 2);

$cash_received = isset($in['cash_received']) ? (float)$in['cash_received'] : 0.0;
$change_amount = 0.0;

if ($payment_method === 1) {
  $change_amount = round(max(0.0, $cash_received - $total), 2);
} else {
  $cash_received = $total;
  $change_amount = 0.0;
}

$con = conectar();

if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}

$con->set_charset('utf8mb4');

if (!$con->query("SET time_zone = @@global.time_zone")) {
  $con->query("SET time_zone = '-06:00'");
}

function mp_clear_results($con) {
  while ($con->more_results()) {
    $con->next_result();

    if ($res = $con->use_result()) {
      $res->free();
    }
  }
}

function mp_get_customer_branch_info_for_points($con, $organization_id, $customer_id) {
  if ($organization_id <= 0 || $customer_id <= 0) {
    return null;
  }

  $sql = "
    SELECT
      cb.id AS customer_branch_id,
      cb.customer_access_id,
      cb.organization_id,
      COALESCE(NULLIF(bp.public_name, ''), NULLIF(u.Nombre, ''), 'Sucursal') AS branch_name
    FROM moon_customer_branch cb
    INNER JOIN moon_customer_access ca
      ON ca.id = cb.customer_access_id
    INNER JOIN moon_user u
      ON u.id = cb.organization_id
    LEFT JOIN moon_branch_profile bp
      ON bp.organization_id = cb.organization_id
    WHERE cb.organization_id = ?
      AND cb.moon_customer_id = ?
      AND cb.relation_status = 1
      AND ca.deleted_at IS NULL
      AND ca.access_status = 1
      AND u.Status = 1
    LIMIT 1
  ";

  $stmt = $con->prepare($sql);

  if (!$stmt) {
    throw new Exception("Error al preparar búsqueda de relación cliente/sucursal: " . $con->error);
  }

  $stmt->bind_param("ii", $organization_id, $customer_id);

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al buscar relación cliente/sucursal: " . $error);
  }

  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    return null;
  }

  return [
    "customer_branch_id" => (int)$row["customer_branch_id"],
    "customer_access_id" => (int)$row["customer_access_id"],
    "organization_id" => (int)$row["organization_id"],
    "branch_name" => trim((string)$row["branch_name"])
  ];
}

function mp_call_level_points_sp($con, $customer_branch_id, $sale_id) {
  $level_points = 25;
  $source = "purchase";
  $reference_id = $sale_id;
  $notes = "Puntaje de nivel generado por venta";

  $sql = "CALL sp_moon_add_customer_branch_level_points(?, ?, ?, ?, ?)";
  $stmt = $con->prepare($sql);

  if (!$stmt) {
    throw new Exception("Error al preparar SP de puntaje de nivel: " . $con->error);
  }

  $stmt->bind_param(
    "iisis",
    $customer_branch_id,
    $level_points,
    $source,
    $reference_id,
    $notes
  );

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al sumar puntaje de nivel: " . $error);
  }

  if ($res = $stmt->get_result()) {
    $res->free();
  }

  $stmt->close();
  mp_clear_results($con);

  return $level_points;
}

function mp_call_reward_points_sp($con, $customer_branch_id, $sale_id, $reward_points) {
  if ($reward_points <= 0) {
    return 0;
  }

  $movement_type = 1;
  $source = "purchase";
  $reference_id = $sale_id;
  $notes = "Puntos de recompensa generados por productos";

  $sql = "CALL sp_moon_add_customer_branch_reward_points(?, ?, ?, ?, ?, ?)";
  $stmt = $con->prepare($sql);

  if (!$stmt) {
    throw new Exception("Error al preparar SP de puntos de recompensa: " . $con->error);
  }

  $stmt->bind_param(
    "iiisis",
    $customer_branch_id,
    $reward_points,
    $movement_type,
    $source,
    $reference_id,
    $notes
  );

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al sumar puntos de recompensa: " . $error);
  }

  if ($res = $stmt->get_result()) {
    $res->free();
  }

  $stmt->close();
  mp_clear_results($con);

  return $reward_points;
}

function mp_create_points_notification(
  $con,
  $customer_access_id,
  $customer_branch_id,
  $organization_id,
  $branch_name,
  $level_points_added,
  $reward_points_added,
  $sale_id
) {
  if ($customer_access_id <= 0 || $customer_branch_id <= 0 || $organization_id <= 0) {
    return [
      "notification_created" => false,
      "notification_reason" => "Datos insuficientes para crear notificación."
    ];
  }

  if ($level_points_added <= 0 && $reward_points_added <= 0) {
    return [
      "notification_created" => false,
      "notification_reason" => "No se generaron puntos."
    ];
  }

  $branch_name = trim((string)$branch_name);
  if ($branch_name === "") {
    $branch_name = "tu sucursal";
  }

  $title = "¡Gracias por tu compra!";

  if ($level_points_added > 0 && $reward_points_added > 0) {
    $body = "Gracias por tu compra en " . $branch_name . ". Sumaste "
          . (int)$level_points_added . " puntos de nivel y "
          . (int)$reward_points_added . " puntos disponibles.";
  } elseif ($reward_points_added > 0) {
    $body = "Gracias por tu compra en " . $branch_name . ". Sumaste "
          . (int)$reward_points_added . " puntos disponibles.";
  } else {
    $body = "Gracias por tu compra en " . $branch_name . ". Sumaste "
          . (int)$level_points_added . " puntos de nivel.";
  }

  $payload = [
    "screen" => "points",
    "notification_type" => "points_earned",
    "customer_branch_id" => (int)$customer_branch_id,
    "organization_id" => (int)$organization_id,
    "level_points_added" => (int)$level_points_added,
    "reward_points_added" => (int)$reward_points_added,
    "sale_id" => (int)$sale_id,
    "source" => "purchase"
  ];

  $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $sql = "
    INSERT INTO moon_customer_notification (
      customer_access_id,
      customer_branch_id,
      organization_id,
      notification_type,
      title,
      body,
      payload_json,
      status
    ) VALUES (
      ?,
      ?,
      ?,
      'points_earned',
      ?,
      ?,
      ?,
      0
    )
  ";

  $stmt = $con->prepare($sql);

  if (!$stmt) {
    throw new Exception("Error al preparar notificación: " . $con->error);
  }

  $stmt->bind_param(
    "iiisss",
    $customer_access_id,
    $customer_branch_id,
    $organization_id,
    $title,
    $body,
    $payload_json
  );

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al crear notificación de puntos: " . $error);
  }

  $notification_id = (int)$stmt->insert_id;
  $stmt->close();

  return [
    "notification_created" => true,
    "notification_id" => $notification_id,
    "notification_title" => $title,
    "notification_body" => $body
  ];
}

function mp_apply_sale_points_if_app_customer($con, $organization_id, $customer_id, $sale_id, $total_qty_for_rewards) {
  $branchInfo = mp_get_customer_branch_info_for_points($con, $organization_id, $customer_id);

  if (!$branchInfo || (int)$branchInfo["customer_branch_id"] <= 0) {
    return [
      "points_applied" => false,
      "points_reason" => "Cliente sin acceso app o sin relación cliente/sucursal activa.",
      "customer_branch_id" => null,
      "customer_access_id" => null,
      "level_points_awarded" => 0,
      "reward_points_awarded" => 0,
      "notification_created" => false,
      "notification_reason" => "No se generaron puntos."
    ];
  }

  $customer_branch_id = (int)$branchInfo["customer_branch_id"];
  $customer_access_id = (int)$branchInfo["customer_access_id"];
  $branch_name = (string)$branchInfo["branch_name"];

  $reward_points = max(0, (int)$total_qty_for_rewards) * 5;

  $level_points_awarded = mp_call_level_points_sp($con, $customer_branch_id, $sale_id);
  $reward_points_awarded = mp_call_reward_points_sp($con, $customer_branch_id, $sale_id, $reward_points);

  $notificationResult = [
    "notification_created" => false,
    "notification_reason" => "No se intentó crear notificación."
  ];

  try {
    $notificationResult = mp_create_points_notification(
      $con,
      $customer_access_id,
      $customer_branch_id,
      (int)$branchInfo["organization_id"],
      $branch_name,
      $level_points_awarded,
      $reward_points_awarded,
      $sale_id
    );
  } catch (Throwable $notificationError) {
    $notificationResult = [
      "notification_created" => false,
      "notification_reason" => "Los puntos fueron aplicados, pero no fue posible crear la notificación.",
      "notification_error" => $notificationError->getMessage()
    ];
  }

  return array_merge([
    "points_applied" => true,
    "points_reason" => "Puntos aplicados correctamente.",
    "customer_branch_id" => $customer_branch_id,
    "customer_access_id" => $customer_access_id,
    "level_points_awarded" => $level_points_awarded,
    "reward_points_awarded" => $reward_points_awarded
  ], $notificationResult);
}

try {
  $con->begin_transaction();

  $sqlH = "INSERT INTO `moon_point`.`moon_sale`
    (organization_id, pending_order_id, customer_id, customer_name, source, channel, status, payment_method,
     subtotal, discount_amount, discount_percent, tax_amount, total, cash_received, change_amount, note, attributes)
    VALUES (
      ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,''), ?, NULLIF(?,''), ?, ?, ?, ?, NULLIF(?,NULL), ?, ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,''), NULLIF(?, '')
    )";

  $stmtH = $con->prepare($sqlH);

  if (!$stmtH) {
    throw new Exception("Error al preparar encabezado: " . $con->error);
  }

  $stmtH->bind_param(
    "iiisisiidddddddss",
    $organization_id,
    $pending_order_id,
    $customer_id,
    $customer_name,
    $source,
    $channel,
    $status,
    $payment_method,
    $subtotal,
    $discount_amount,
    $discount_percent,
    $tax_amount,
    $total,
    $cash_received,
    $change_amount,
    $note,
    $attributes
  );

  if (!$stmtH->execute()) {
    throw new Exception("Error al insertar venta: " . $stmtH->error);
  }

  $sale_id = (int)$stmtH->insert_id;
  $stmtH->close();

  $sqlI = "INSERT INTO `moon_point`.`moon_sale_item`
           (sale_id, product_id, name, image_name, qty, unit_price, line_subtotal, note)
           VALUES (?, ?, ?, NULLIF(?,''), ?, ?, ?, NULLIF(?,''))";

  $stmtI = $con->prepare($sqlI);

  if (!$stmtI) {
    throw new Exception("Error al preparar items: " . $con->error);
  }

  foreach ($items as $it) {
    $pid   = (int)$it['product_id'];
    $name  = trim((string)$it['name']);
    $img   = isset($it['image_name']) ? trim((string)$it['image_name']) : '';
    $qty   = (int)$it['qty'];
    $price = (float)$it['unit_price'];
    $n     = isset($it['note']) ? trim((string)$it['note']) : '';
    $line  = round($qty * $price, 2);

    $stmtI->bind_param("iissidds", $sale_id, $pid, $name, $img, $qty, $price, $line, $n);

    if (!$stmtI->execute()) {
      throw new Exception("Error al insertar item: " . $stmtI->error);
    }
  }

  $stmtI->close();

  $closePO = isset($in['close_pending_order']) ? (int)$in['close_pending_order'] : 0;

  if ($closePO === 1 && $pending_order_id > 0) {
    $sqlPO = "UPDATE `moon_point`.`moon_pending_order`
              SET status = 3, total = ?, updated_at = NOW()
              WHERE id = ? LIMIT 1";

    $stmtPO = $con->prepare($sqlPO);

    if ($stmtPO) {
      $stmtPO->bind_param("di", $total, $pending_order_id);
      $stmtPO->execute();
      $stmtPO->close();
    }
  }

  $con->commit();

  $points_result = [
    "points_applied" => false,
    "points_reason" => "No se intentó aplicar puntos.",
    "customer_branch_id" => null,
    "customer_access_id" => null,
    "level_points_awarded" => 0,
    "reward_points_awarded" => 0,
    "notification_created" => false,
    "notification_reason" => "No se intentó crear notificación."
  ];

  try {
    $points_result = mp_apply_sale_points_if_app_customer(
      $con,
      $organization_id,
      $customer_id,
      $sale_id,
      $total_qty_for_rewards
    );
  } catch (Throwable $pointsError) {
    $points_result = [
      "points_applied" => false,
      "points_reason" => "La venta fue registrada, pero no fue posible aplicar puntos.",
      "points_error" => $pointsError->getMessage(),
      "customer_branch_id" => null,
      "customer_access_id" => null,
      "level_points_awarded" => 0,
      "reward_points_awarded" => 0,
      "notification_created" => false,
      "notification_reason" => "No se creó notificación porque falló la aplicación de puntos."
    ];
  }

  echo json_encode([
    "success" => true,
    "message" => "Venta registrada",
    "id" => $sale_id,
    "points" => $points_result
  ]);

} catch (Throwable $e) {
  $con->rollback();

  echo json_encode([
    "success" => false,
    "error" => $e->getMessage()
  ]);
}

$con->close();