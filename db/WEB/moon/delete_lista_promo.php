<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (empty($in['id']) || empty($in['Id_company'])) {
  echo json_encode(["success" => false, "error" => "Faltan parámetros: id, Id_company"]);
  exit;
}

$id = (int)$in['id'];
$Id_company = (int)$in['Id_company'];

$con = conectar();

$sql = "DELETE FROM `moon_lista_promo` WHERE id = ? AND Id_company = ?";
$stmt = $con->prepare($sql);
$stmt->bind_param("ii", $id, $Id_company);

if ($stmt->execute()) {
  echo json_encode(["success" => true]);
} else {
  echo json_encode(["success" => false, "error" => "Error al eliminar: " . $stmt->error]);
}

$stmt->close();
$con->close();