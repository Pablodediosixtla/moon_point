<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if (!$path || !file_exists($path)) {
  echo json_encode(["success" => false, "error" => "No se encontró Conexion.php"]);
  exit;
}
include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (!isset($in['id']) || $in['id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: id"]);
  exit;
}

$id           = (int)$in['id'];
$id_promo     = array_key_exists('id_promo', $in)     && $in['id_promo'] !== ''     ? (int)$in['id_promo']     : null;
$id_cliente   = array_key_exists('id_cliente', $in)   && $in['id_cliente'] !== ''   ? (int)$in['id_cliente']   : null;
$id_producto  = array_key_exists('id_producto', $in)  && $in['id_producto'] !== ''  ? (int)$in['id_producto']  : null;
$id_compuesto = array_key_exists('id_compuesto', $in) && $in['id_compuesto'] !== '' ? (int)$in['id_compuesto'] : null;
$cantidad     = array_key_exists('cantidad', $in)     && $in['cantidad'] !== ''     ? (int)$in['cantidad']     : null;
$start_date   = array_key_exists('start_date', $in)   ? trim((string)$in['start_date']) : null;
$end_date     = array_key_exists('end_date', $in)     ? trim((string)$in['end_date'])   : null;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset('utf8mb4');

$sets  = [];
$types = "";
$args  = [];

if ($id_promo !== null) {
  $sets[]  = "id_promo = ?";
  $types  .= "i";
  $args[]  = $id_promo;
}
if ($id_cliente !== null) {
  $sets[]  = "id_cliente = ?";
  $types  .= "i";
  $args[]  = $id_cliente;
}
if ($id_producto !== null) {
  $sets[]  = "id_producto = ?";
  $types  .= "i";
  $args[]  = $id_producto;
}
if ($id_compuesto !== null) {
  $sets[]  = "id_compuesto = ?";
  $types  .= "i";
  $args[]  = $id_compuesto;
}
if ($cantidad !== null) {
  $sets[]  = "cantidad = ?";
  $types  .= "i";
  $args[]  = $cantidad;
}
// Para fechas, usamos NULLIF para permitir mandar string vacío y que se vuelva NULL en la BD
if ($start_date !== null) {
  $sets[]  = "start_date = NULLIF(?, '')";
  $types  .= "s";
  $args[]  = $start_date;
}
if ($end_date !== null) {
  $sets[]  = "end_date = NULLIF(?, '')";
  $types  .= "s";
  $args[]  = $end_date;
}

if (count($sets) === 0) {
    echo json_encode(["success" => false, "error" => "No hay campos para actualizar"]);
    exit;
}

$sets[] = "updated_at = NOW()";

$sql = "UPDATE `moon_lista_promo` SET " . implode(", ", $sets) . " WHERE id = ?";
$types .= "i";
$args[]  = $id;

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta: " . $con->error]);
  $con->close();
  exit;
}

$stmt->bind_param($types, ...$args);

if ($stmt->execute()) {
  echo json_encode(["success" => true]);
} else {
  echo json_encode(["success" => false, "error" => "Error al actualizar: " . $stmt->error]);
}

$stmt->close();
$con->close();