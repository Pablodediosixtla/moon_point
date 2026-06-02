<?php
header('Content-Type: application/json');

function client_response($data, $code = 200) {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function client_require_post() {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    client_response(["success" => false, "error" => "Método no permitido. Usa POST."], 405);
  }
}

function client_input() {
  $in = json_decode(file_get_contents("php://input"), true);
  return is_array($in) ? $in : [];
}

function client_connect() {
  $path = realpath("/home/site/wwwroot/db/conn/Conexion.php");

  if (!$path) {
    client_response(["success" => false, "error" => "No se encontró el archivo de conexión."], 500);
  }

  include $path;

  $con = conectar();
  $con->set_charset('utf8mb4');

  return $con;
}

function client_normalize_phone($phone) {
  $digits = preg_replace('/\D+/', '', (string)$phone);

  if (strlen($digits) === 10) {
    return "52" . $digits;
  }

  if (strlen($digits) === 12 && substr($digits, 0, 2) === "52") {
    return $digits;
  }

  return $digits;
}

function client_null_if_empty($value) {
  if (!isset($value)) {
    return null;
  }

  $value = trim((string)$value);

  return $value === "" ? null : $value;
}

function client_valid_date_or_null($value) {
  $value = client_null_if_empty($value);

  if ($value === null) {
    return null;
  }

  $dt = DateTime::createFromFormat('Y-m-d', $value);
  if (!$dt || $dt->format('Y-m-d') !== $value) {
    client_response(["success" => false, "error" => "Formato de fecha inválido. Usa YYYY-MM-DD."], 400);
  }

  return $value;
}