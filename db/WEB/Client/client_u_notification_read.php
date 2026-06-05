<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$notification_id = isset($in['notification_id']) ? (int)$in['notification_id'] : 0;
$mark_all = isset($in['mark_all']) ? (int)$in['mark_all'] : 0;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

if ($notification_id <= 0 && $mark_all !== 1) {
  client_response(["success" => false, "error" => "Debes enviar notification_id o mark_all = 1."], 400);
}

try {
  if ($mark_all === 1) {
    $sql = "
      UPDATE moon_customer_notification
      SET read_at = COALESCE(read_at, NOW()),
          updated_at = NOW()
      WHERE customer_access_id = ?
        AND read_at IS NULL
        AND status <> 3
    ";

    $stmt = $con->prepare($sql);

    if (!$stmt) {
      throw new Exception("Error al preparar actualización: " . $con->error);
    }

    $stmt->bind_param("i", $customer_access_id);

  } else {
    $sql = "
      UPDATE moon_customer_notification
      SET read_at = COALESCE(read_at, NOW()),
          updated_at = NOW()
      WHERE id = ?
        AND customer_access_id = ?
        AND status <> 3
      LIMIT 1
    ";

    $stmt = $con->prepare($sql);

    if (!$stmt) {
      throw new Exception("Error al preparar actualización: " . $con->error);
    }

    $stmt->bind_param("ii", $notification_id, $customer_access_id);
  }

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al marcar notificación: " . $error);
  }

  $affected = $stmt->affected_rows;
  $stmt->close();
  $con->close();

  client_response([
    "success" => true,
    "message" => $mark_all === 1 ? "Notificaciones marcadas como leídas." : "Notificación marcada como leída.",
    "affected_rows" => $affected
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