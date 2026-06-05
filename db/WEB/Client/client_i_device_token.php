<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$platform = isset($in['platform']) ? trim((string)$in['platform']) : "ios";
$device_token = isset($in['device_token']) ? trim((string)$in['device_token']) : "";
$device_id = isset($in['device_id']) ? trim((string)$in['device_id']) : "";
$app_version = isset($in['app_version']) ? trim((string)$in['app_version']) : "";
$os_version = isset($in['os_version']) ? trim((string)$in['os_version']) : "";

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

if ($device_token === "") {
  client_response(["success" => false, "error" => "El device_token es obligatorio."], 400);
}

if ($platform === "") {
  $platform = "ios";
}

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

  if (!$stmtCustomer->execute()) {
    $error = $stmtCustomer->error;
    $stmtCustomer->close();
    throw new Exception("Error al validar cliente: " . $error);
  }

  $customer = $stmtCustomer->get_result()->fetch_assoc();
  $stmtCustomer->close();

  if (!$customer) {
    $con->close();
    client_response(["success" => false, "error" => "Cliente no encontrado o inactivo."], 404);
  }

  $sql = "
    INSERT INTO moon_customer_device_token (
      customer_access_id,
      platform,
      device_token,
      device_id,
      app_version,
      os_version,
      is_active,
      last_seen_at
    ) VALUES (
      ?,
      ?,
      ?,
      NULLIF(?, ''),
      NULLIF(?, ''),
      NULLIF(?, ''),
      1,
      NOW()
    )
    ON DUPLICATE KEY UPDATE
      customer_access_id = VALUES(customer_access_id),
      platform = VALUES(platform),
      device_id = VALUES(device_id),
      app_version = VALUES(app_version),
      os_version = VALUES(os_version),
      is_active = 1,
      last_seen_at = NOW(),
      updated_at = NOW()
  ";

  $stmt = $con->prepare($sql);

  if (!$stmt) {
    throw new Exception("Error al preparar token: " . $con->error);
  }

  $stmt->bind_param(
    "isssss",
    $customer_access_id,
    $platform,
    $device_token,
    $device_id,
    $app_version,
    $os_version
  );

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al guardar device token: " . $error);
  }

  $affected = $stmt->affected_rows;
  $insert_id = $stmt->insert_id;
  $stmt->close();

  $sqlFind = "
    SELECT id
    FROM moon_customer_device_token
    WHERE device_token = ?
    LIMIT 1
  ";

  $stmtFind = $con->prepare($sqlFind);
  $stmtFind->bind_param("s", $device_token);
  $stmtFind->execute();
  $row = $stmtFind->get_result()->fetch_assoc();
  $stmtFind->close();

  $con->close();

  client_response([
    "success" => true,
    "message" => "Device token registrado correctamente.",
    "data" => [
      "device_token_id" => $row ? (int)$row['id'] : (int)$insert_id,
      "customer_access_id" => $customer_access_id,
      "platform" => $platform,
      "created_or_updated" => $affected
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