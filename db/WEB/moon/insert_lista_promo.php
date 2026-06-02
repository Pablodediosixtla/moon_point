<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (empty($in['id_promo']) || empty($in['Id_company'])) {
  echo json_encode(["success" => false, "error" => "Faltan parámetros: id_promo, Id_company"]);
  exit;
}

$id_promo   = (int)$in['id_promo'];
$Id_company = (int)$in['Id_company'];

$id_cliente   = isset($in['id_cliente'])   ? (int)$in['id_cliente']   : null;
$id_producto  = isset($in['id_producto'])  ? (int)$in['id_producto']  : null;
$id_compuesto = isset($in['id_compuesto']) ? (int)$in['id_compuesto'] : null;

$start_date = isset($in['start_date']) ? trim((string)$in['start_date']) : null;
$end_date   = isset($in['end_date'])   ? trim((string)$in['end_date'])   : null;
$cantidad   = isset($in['cantidad'])   ? (int)$in['cantidad'] : null;

$con = conectar();
$con->set_charset('utf8mb4');

$sql = "INSERT INTO `moon_lista_promo` (`id_promo`, `id_cliente`, `id_producto`, `id_compuesto`, `start_date`, `end_date`, `cantidad`, `Id_company`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $con->prepare($sql);
$stmt->bind_param("iiiissii", $id_promo, $id_cliente, $id_producto, $id_compuesto, $start_date, $end_date, $cantidad, $Id_company);

if ($stmt->execute()) {
  echo json_encode(["success" => true, "id" => $stmt->insert_id]);
} else {
  echo json_encode(["success" => false, "error" => "Error al insertar: " . $stmt->error]);
}

$stmt->close();
$con->close();