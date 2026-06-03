<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;

$full_name = array_key_exists('full_name', $in)
  ? trim((string)$in['full_name'])
  : null;

$email = array_key_exists('email', $in)
  ? trim((string)$in['email'])
  : null;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

if ($full_name === null && $email === null) {
  client_response(["success" => false, "error" => "Debes enviar full_name o email."], 400);
}

if ($email !== null && $email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  client_response(["success" => false, "error" => "El correo no tiene un formato válido."], 400);
}

try {
  $sql = "CALL sp_moon_sync_customer_profile_to_branches(?, ?, ?)";
  $stmt = $con->prepare($sql);

  if (!$stmt) {
    throw new Exception("Error al preparar SP: " . $con->error);
  }

  $stmt->bind_param("iss", $customer_access_id, $full_name, $email);

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al ejecutar SP: " . $error);
  }

  $result = $stmt->get_result();
  $data = $result ? $result->fetch_assoc() : null;

  $stmt->close();

  while ($con->more_results() && $con->next_result()) {
    $extra = $con->use_result();
    if ($extra instanceof mysqli_result) {
      $extra->free();
    }
  }

  $sqlGet = "
    SELECT
      id,
      phone,
      phone_normalized,
      email,
      username,
      full_name,
      birth_date,
      access_status,
      requires_password_change,
      failed_attempts,
      locked_until,
      last_login_at,
      token_version,
      created_at,
      updated_at
    FROM moon_customer_access
    WHERE id = ?
      AND deleted_at IS NULL
    LIMIT 1
  ";

  $stmtGet = $con->prepare($sqlGet);
  $stmtGet->bind_param("i", $customer_access_id);
  $stmtGet->execute();
  $customer = $stmtGet->get_result()->fetch_assoc();
  $stmtGet->close();

  $con->close();

  client_response([
    "success" => true,
    "message" => "Perfil actualizado y sincronizado con sucursales.",
    "sync" => $data,
    "data" => $customer
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