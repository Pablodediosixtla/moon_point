<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$id = isset($in['id']) ? (int)$in['id'] : 0;
$phone = trim((string)($in['phone'] ?? ''));
$phone_normalized = client_normalize_phone($phone);
$username = trim((string)($in['username'] ?? ''));
$email = trim((string)($in['email'] ?? ''));

$fields = "
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
";

if ($id > 0) {
  $sql = "SELECT $fields FROM moon_customer_access WHERE id = ? AND deleted_at IS NULL LIMIT 1";
  $stmt = $con->prepare($sql);
  $stmt->bind_param("i", $id);

} elseif ($phone_normalized !== "") {
  $sql = "SELECT $fields FROM moon_customer_access WHERE phone_normalized = ? AND deleted_at IS NULL LIMIT 1";
  $stmt = $con->prepare($sql);
  $stmt->bind_param("s", $phone_normalized);

} elseif ($username !== "") {
  $sql = "SELECT $fields FROM moon_customer_access WHERE username = ? AND deleted_at IS NULL LIMIT 1";
  $stmt = $con->prepare($sql);
  $stmt->bind_param("s", $username);

} elseif ($email !== "") {
  $sql = "SELECT $fields FROM moon_customer_access WHERE email = ? AND deleted_at IS NULL LIMIT 1";
  $stmt = $con->prepare($sql);
  $stmt->bind_param("s", $email);

} else {
  $limit = 50;
  $sql = "SELECT $fields FROM moon_customer_access WHERE deleted_at IS NULL ORDER BY id DESC LIMIT ?";
  $stmt = $con->prepare($sql);
  $stmt->bind_param("i", $limit);
}

if (!$stmt->execute()) {
  $error = $stmt->error;
  $stmt->close();
  $con->close();

  client_response(["success" => false, "error" => "Error al consultar: " . $error], 500);
}

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
  $data[] = $row;
}

$stmt->close();
$con->close();

client_response([
  "success" => true,
  "total" => count($data),
  "data" => $data
]);