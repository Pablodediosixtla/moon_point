<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$identifier = trim((string)($in['identifier'] ?? ''));
$password = trim((string)($in['password'] ?? ''));

if ($identifier === "") {
  client_response(["success" => false, "error" => "El usuario, correo o teléfono es obligatorio."], 400);
}

if ($password === "") {
  client_response(["success" => false, "error" => "La contraseña es obligatoria."], 400);
}

$identifier_phone = client_normalize_phone($identifier);

$sql = "
  SELECT
    id,
    phone,
    phone_normalized,
    email,
    username,
    password_hash,
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
  WHERE deleted_at IS NULL
    AND (
      username = ?
      OR email = ?
      OR phone = ?
      OR phone_normalized = ?
    )
  LIMIT 1
";

$stmt = $con->prepare($sql);
$stmt->bind_param("ssss", $identifier, $identifier, $identifier, $identifier_phone);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
  $con->close();
  client_response(["success" => false, "error" => "Usuario o contraseña incorrectos."], 401);
}

$access_status = (int)$user['access_status'];

if ($access_status !== 1) {
  $con->close();

  $message = "La cuenta no está activa.";

  if ($access_status === 2) {
    $message = "La cuenta está bloqueada.";
  } elseif ($access_status === 3) {
    $message = "La cuenta está pendiente de validación.";
  } elseif ($access_status === 4) {
    $message = "La cuenta está deshabilitada.";
  }

  client_response(["success" => false, "error" => $message], 403);
}

if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
  $con->close();
  client_response([
    "success" => false,
    "error" => "La cuenta está bloqueada temporalmente. Intenta más tarde.",
    "locked_until" => $user['locked_until']
  ], 423);
}

if (!password_verify($password, $user['password_hash'])) {
  $failed_attempts = ((int)$user['failed_attempts']) + 1;
  $locked_until = null;

  if ($failed_attempts >= 5) {
    $locked_until = date("Y-m-d H:i:s", strtotime("+15 minutes"));
  }

  $sqlFail = "
    UPDATE moon_customer_access
    SET
      failed_attempts = ?,
      locked_until = ?
    WHERE id = ?
  ";

  $stmtFail = $con->prepare($sqlFail);
  $stmtFail->bind_param("isi", $failed_attempts, $locked_until, $user['id']);
  $stmtFail->execute();
  $stmtFail->close();

  $con->close();

  client_response([
    "success" => false,
    "error" => "Usuario o contraseña incorrectos.",
    "failed_attempts" => $failed_attempts,
    "locked" => $failed_attempts >= 5
  ], 401);
}

$last_login_ip = $_SERVER['REMOTE_ADDR'] ?? null;

$sqlSuccess = "
  UPDATE moon_customer_access
  SET
    failed_attempts = 0,
    locked_until = NULL,
    last_login_at = NOW(),
    last_login_ip = ?
  WHERE id = ?
";

$stmtSuccess = $con->prepare($sqlSuccess);
$stmtSuccess->bind_param("si", $last_login_ip, $user['id']);
$stmtSuccess->execute();
$stmtSuccess->close();

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
$stmtGet->bind_param("i", $user['id']);
$stmtGet->execute();
$loggedUser = $stmtGet->get_result()->fetch_assoc();
$stmtGet->close();

$con->close();

client_response([
  "success" => true,
  "message" => "Login correcto.",
  "data" => $loggedUser
]);