<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$only_unread = isset($in['only_unread']) ? (int)$in['only_unread'] : 0;
$limit = isset($in['limit']) ? (int)$in['limit'] : 50;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

$limit = max(1, min($limit, 100));

try {
  $sqlCustomer = "
    SELECT id
    FROM moon_customer_access
    WHERE id = ?
      AND access_status = 1
      AND deleted_at IS NULL
    LIMIT 1
  ";

  $stmtCustomer = $con->prepare($sqlCustomer);

  if (!$stmtCustomer) {
    throw new Exception("Error al preparar validación de cliente: " . $con->error);
  }

  $stmtCustomer->bind_param("i", $customer_access_id);
  $stmtCustomer->execute();
  $customer = $stmtCustomer->get_result()->fetch_assoc();
  $stmtCustomer->close();

  if (!$customer) {
    $con->close();
    client_response(["success" => false, "error" => "Cliente no encontrado o inactivo."], 404);
  }

  $sqlUnread = "
    SELECT COUNT(*) AS unread_total
    FROM moon_customer_notification
    WHERE customer_access_id = ?
      AND read_at IS NULL
      AND status <> 3
  ";

  $stmtUnread = $con->prepare($sqlUnread);
  $stmtUnread->bind_param("i", $customer_access_id);
  $stmtUnread->execute();
  $unreadRow = $stmtUnread->get_result()->fetch_assoc();
  $stmtUnread->close();

  $sql = "
    SELECT
      id,
      customer_access_id,
      customer_branch_id,
      organization_id,
      notification_type,
      title,
      body,
      payload_json,
      status,
      read_at,
      sent_at,
      last_error,
      attempts,
      created_at,
      updated_at
    FROM moon_customer_notification
    WHERE customer_access_id = ?
      AND status <> 3
  ";

  $types = "i";
  $params = [$customer_access_id];

  if ($only_unread === 1) {
    $sql .= " AND read_at IS NULL ";
  }

  $sql .= "
    ORDER BY created_at DESC
    LIMIT ?
  ";

  $types .= "i";
  $params[] = $limit;

  $stmt = $con->prepare($sql);

  if (!$stmt) {
    throw new Exception("Error al preparar notificaciones: " . $con->error);
  }

  $stmt->bind_param($types, ...$params);

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al consultar notificaciones: " . $error);
  }

  $result = $stmt->get_result();
  $data = [];

  while ($row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['customer_access_id'] = (int)$row['customer_access_id'];
    $row['customer_branch_id'] = $row['customer_branch_id'] !== null ? (int)$row['customer_branch_id'] : null;
    $row['organization_id'] = $row['organization_id'] !== null ? (int)$row['organization_id'] : null;
    $row['status'] = (int)$row['status'];
    $row['attempts'] = (int)$row['attempts'];
    $row['is_read'] = $row['read_at'] !== null;

    if (isset($row['payload_json']) && $row['payload_json'] !== null && $row['payload_json'] !== "") {
      $decodedPayload = json_decode($row['payload_json'], true);
      $row['payload'] = is_array($decodedPayload) ? $decodedPayload : null;
    } else {
      $row['payload'] = null;
    }

    unset($row['payload_json']);

    $data[] = $row;
  }

  $stmt->close();
  $con->close();

  client_response([
    "success" => true,
    "total" => count($data),
    "unread_total" => isset($unreadRow['unread_total']) ? (int)$unreadRow['unread_total'] : 0,
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