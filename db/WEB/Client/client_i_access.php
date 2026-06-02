<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$phone = trim((string)($in['phone'] ?? ''));
$phone_normalized = client_normalize_phone($phone);

$email = client_null_if_empty($in['email'] ?? null);
$username = trim((string)($in['username'] ?? ''));
$password = trim((string)($in['password'] ?? ''));

$full_name = client_null_if_empty($in['full_name'] ?? null);
$birth_date = client_valid_date_or_null($in['birth_date'] ?? null);

if ($phone === "" || $phone_normalized === "") {
  client_response(["success" => false, "error" => "El teléfono es obligatorio."], 400);
}

if (strlen($phone_normalized) < 10 || strlen($phone_normalized) > 15) {
  client_response(["success" => false, "error" => "El teléfono no tiene un formato válido."], 400);
}

if ($username === "") {
  client_response(["success" => false, "error" => "El usuario es obligatorio."], 400);
}

if ($password === "") {
  client_response(["success" => false, "error" => "La contraseña es obligatoria."], 400);
}

if (strlen($password) < 6) {
  client_response(["success" => false, "error" => "La contraseña debe tener al menos 6 caracteres."], 400);
}

if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  client_response(["success" => false, "error" => "El correo no tiene un formato válido."], 400);
}

$email_check = $email ?? "";

$sqlValidate = "
  SELECT id, phone_normalized, username, email
  FROM moon_customer_access
  WHERE deleted_at IS NULL
    AND (
      phone_normalized = ?
      OR username = ?
      OR (? <> '' AND email = ?)
    )
  LIMIT 1
";

$stmt = $con->prepare($sqlValidate);
$stmt->bind_param("ssss", $phone_normalized, $username, $email_check, $email_check);
$stmt->execute();
$result = $stmt->get_result();
$existing = $result->fetch_assoc();
$stmt->close();

if ($existing) {
  if ($existing['phone_normalized'] === $phone_normalized) {
    client_response(["success" => false, "error" => "Ya existe una cuenta con este teléfono."], 409);
  }

  if ($existing['username'] === $username) {
    client_response(["success" => false, "error" => "El usuario ya está en uso."], 409);
  }

  if ($email !== null && $existing['email'] === $email) {
    client_response(["success" => false, "error" => "Ya existe una cuenta con este correo."], 409);
  }

  client_response(["success" => false, "error" => "Ya existe una cuenta con esos datos."], 409);
}

$password_hash = password_hash($password, PASSWORD_BCRYPT);

$access_status = 1;
$requires_password_change = 0;
$failed_attempts = 0;
$token_version = 1;

$sqlInsert = "
  INSERT INTO moon_customer_access (
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
    token_version
  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

$stmt = $con->prepare($sqlInsert);
$stmt->bind_param(
  "sssssssiiii",
  $phone,
  $phone_normalized,
  $email,
  $username,
  $password_hash,
  $full_name,
  $birth_date,
  $access_status,
  $requires_password_change,
  $failed_attempts,
  $token_version
);

if ($stmt->execute()) {
  $new_id = $stmt->insert_id;

  $stmt->close();
  $con->close();

  client_response([
    "success" => true,
    "message" => "Cuenta creada correctamente.",
    "id" => $new_id,
    "data" => [
      "id" => $new_id,
      "phone" => $phone,
      "phone_normalized" => $phone_normalized,
      "email" => $email,
      "username" => $username,
      "full_name" => $full_name,
      "birth_date" => $birth_date,
      "access_status" => $access_status
    ]
  ]);
}

$error = $stmt->error;
$stmt->close();
$con->close();

client_response(["success" => false, "error" => "Error al insertar: " . $error], 500);