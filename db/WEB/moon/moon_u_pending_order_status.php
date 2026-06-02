<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success"=>false,"error"=>"Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if (!$path || !file_exists($path)) { echo json_encode(["success"=>false,"error"=>"No se encontró Conexion.php en $path"]); exit; }
include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

foreach (["id","organization_id","status"] as $k) {
  if (!isset($in[$k]) || $in[$k] === "") {
    echo json_encode(["success"=>false,"error"=>"Falta $k"]); exit;
  }
}

$id              = (int)$in['id'];
$organization_id = (int)$in['organization_id'];
$status          = (int)$in['status']; // 0,1,2,3

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$sql = "UPDATE `moon_point`.`moon_pending_order`
        SET status = ?
        WHERE id = ? AND organization_id = ?";

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error al preparar consulta: ".$con->error]); $con->close(); exit; }

$stmt->bind_param("iii", $status, $id, $organization_id);

if ($stmt->execute()) {
  echo json_encode(["success"=>true,"message"=>"Estado actualizado"]);
} else {
  echo json_encode(["success"=>false,"error"=>"Error al actualizar: ".$stmt->error]);
}

$stmt->close();
$con->close();
