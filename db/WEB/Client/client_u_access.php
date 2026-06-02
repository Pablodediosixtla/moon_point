<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$id = isset($in['id']) ? (int)$in['id'] : 0;

if ($id <= 0) {
  client_response(["success" => false, "error" => "El id es obligatorio."], 400);
}

$sqlCurrent = "
  SELECT *
  FROM moon_customer_access
  WHERE id = ?
    AND deleted_at IS NULL
  LIMIT 1
";

$stmt = $con->prepare($sqlCurrent);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$current = $result->fetch_assoc();
$stmt->close();

if (!$current) {
  $con->close();
  client_response(["success" => false, "error" => "No se encontró la cuenta."], 404);
}

$new_phone = array_key_exists('phone', $in)
  ? trim((string)$in['phone'])
  : $current['phone'];

$new_phone_normalized = client_normalize_phone($new_phone);

$new_email = array_key_exists('email', $in)
  ? client_null_if_empty($in['email'])
  : $current['email'];

$new_username = array_key_exists('username', $in)
  ? trim((string)$in['username'])
  : $current['username'];

$new_full_name = array_key_exists('full_name', $in)
  ? client_null_if_empty($in['full_name'])
  : $current['full_name'];

$new_birth_date = array_key_exists('birth_date', $in)
  ? client_valid_date_or_null($in['birth_date'])
  : $current['birth_date'];

$new_access_status = array_key_exists('access_status', $in)
  ? (int)$in['access_status']
  : (int)$current['access_status'];

$new_requires_password_change = array_key_exists('requires_password_change', $in)
  ? (int)$in['requires_password_change']
  : (int)$current['requires_password_change'];

$new_password_hash = $current['password_hash'];

if (array_key_exists('password', $in)) {
  $new_password = trim((string)$in['password']);

  if ($new_password !== "") {
    if (strlen($new_password) < 6) {
      client_response(["success" => false, "error" => "La contraseña debe tener al menos 6 caracteres."], 400);
    }

    $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT);
  }
}

if ($new_phone === "" || $new_phone_normalized === "") {
  client_response(["success" => false, "error" => "El teléfono es obligatorio."], 400);
}

if (strlen($new_phone_normalized) < 10 || strlen($new_phone_normalized) > 15) {
  client_response(["success" => false, "error" => "El teléfono no tiene un formato válido."], 400);
}

if ($new_username === "") {
  client_response(["success" => false, "error" => "El usuario es obligatorio."], 400);
}

if ($new_email !== null && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
  client_response(["success" => false, "error" => "El correo no tiene un formato válido."], 400);
}

if (!in_array($new_access_status, [1, 2, 3, 4], true)) {
  client_response(["success" => false, "error" => "Estatus inválido. Usa 1=ACTIVE, 2=BLOCKED, 3=PENDING_VALIDATION, 4=DISABLED."], 400);
}

$new_requires_password_change = $new_requires_password_change === 1 ? 1 : 0;

$email_check = $new_email ?? "";

$sqlValidate = "
  SELECT id, phone_normalized, username, email
  FROM moon_customer_access
  WHERE deleted_at IS NULL
    AND id <> ?
    AND (
      phone_normalized = ?
      OR username = ?
      OR (? <> '' AND email = ?)
    )
  LIMIT 1
";

$stmt = $con->prepare($sqlValidate);
$stmt->bind_param("issss", $id, $new_phone_normalized, $new_username, $email_check, $email_check);
$stmt->execute();
$result = $stmt->get_result();
$existing = $result->fetch_assoc();
$stmt->close();

if ($existing) {
  if ($existing['phone_normalized'] === $new_phone_normalized) {
    client_response(["success" => false, "error" => "Ya existe otra cuenta con este teléfono."], 409);
  }

  if ($existing['username'] === $new_username) {
    client_response(["success" => false, "error" => "El usuario ya está en uso por otra cuenta."], 409);
  }

  if ($new_email !== null && $existing['email'] === $new_email) {
    client_response(["success" => false, "error" => "Ya existe otra cuenta con este correo."], 409);
  }

  client_response(["success" => false, "error" => "Ya existe otra cuenta con esos datos."], 409);
}

$sqlUpdate = "
  UPDATE moon_customer_access
  SET
    phone = ?,
    phone_normalized = ?,
    email = ?,
    username = ?,
    password_hash = ?,
    full_name = ?,
    birth_date = ?,
    access_status = ?,
    requires_password_change = ?,
    token_version = token_version + 1
  WHERE id = ?
    AND deleted_at IS NULL
";

$stmt = $con->prepare($sqlUpdate);
$stmt->bind_param(
  "sssssssiii",
  $new_phone,
  $new_phone_normalized,
  $new_email,
  $new_username,
  $new_password_hash,
  $new_full_name,
  $new_birth_date,
  $new_access_status,
  $new_requires_password_change,
  $id
);

if ($stmt->execute()) {
  $stmt->close();

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
    LIMIT 1
  ";

  $stmtGet = $con->prepare($sqlGet);
  $stmtGet->bind_param("i", $id);
  $stmtGet->execute();
  $updated = $stmtGet->get_result()->fetch_assoc();
  $stmtGet->close();

  $con->close();

  client_response([
    "success" => true,
    "message" => "Cuenta actualizada correctamente.",
    "data" => $updated
  ]);
}

$error = $stmt->error;
$stmt->close();
$con->close();

client_response(["success" => false, "error" => "Error al actualizar: " . $error], 500);