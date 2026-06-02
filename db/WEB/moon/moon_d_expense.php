<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}
include realpath("/home/site/wwwroot/db/conn/Conexion.php");

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (!isset($in['id']) || $in['id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta id"]); exit;
}
if (!isset($in['organization_id']) || $in['organization_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta organization_id"]); exit;
}

$id              = (int)$in['id'];
$organization_id = (int)$in['organization_id'];

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$sql = "DELETE FROM `moon_point`.`moon_expense`
        WHERE id=? AND organization_id=?";

$stmt = $con->prepare($sql);
if(!$stmt){
  echo json_encode(["success"=>false,"error"=>"Error al preparar consulta: ".$con->error]);
  $con->close(); exit;
}

$stmt->bind_param("ii", $id, $organization_id);
if(!$stmt->execute()){
  echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]);
  $stmt->close(); $con->close(); exit;
}

echo json_encode(["success"=>true,"message"=>"Gasto eliminado"]);
$stmt->close();
$con->close();
