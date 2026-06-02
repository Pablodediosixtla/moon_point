<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); echo json_encode(["success"=>false,"error"=>"Método no permitido. Usa POST."]); exit;
}
include realpath("/home/site/wwwroot/db/conn/Conexion.php");
$in = json_decode(file_get_contents("php://input"), true); if (!is_array($in)) { $in = []; }

foreach (['organization_id','id'] as $r) {
  if (!isset($in[$r]) || $in[$r] === "") { echo json_encode(["success"=>false,"error"=>"Falta $r"]); exit; }
}

$organization_id = (int)$in['organization_id'];
$id              = (int)$in['id'];
$hard            = isset($in['hard']) ? (int)$in['hard'] : 0;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

if ($hard === 1) {
  $stmt = $con->prepare("DELETE FROM `moon_point`.`moon_employee` WHERE organization_id=? AND id=?");
  $stmt->bind_param("ii", $organization_id, $id);
} else {
  $stmt = $con->prepare("UPDATE `moon_point`.`moon_employee` SET is_active=0 WHERE organization_id=? AND id=?");
  $stmt->bind_param("ii", $organization_id, $id);
}

if (!$stmt->execute()) { echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]); $stmt->close(); $con->close(); exit; }
echo json_encode(["success"=>true,"message"=> $hard===1 ? "Empleado eliminado" : "Empleado dado de baja"]);
$stmt->close(); $con->close();
