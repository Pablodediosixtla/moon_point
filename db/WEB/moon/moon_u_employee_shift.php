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

$fields = []; $params = []; $types="";

$map = [
  "employee_id"    => "i",
  "day_of_week"    => "i",
  "start_time"     => "s",
  "end_time"       => "s",
  "break_minutes"  => "i",
  "effective_from" => "s",
  "effective_to"   => "s",
  "is_active"      => "i"
];

foreach ($map as $k=>$t) {
  if (array_key_exists($k, $in)) {
    $fields[] = "$k = ?";
    $types .= $t;
    $params[] = ($t==="i") ? (int)$in[$k] : (string)$in[$k];
  }
}
if (empty($fields)) { echo json_encode(["success"=>false,"error"=>"Nada para actualizar"]); exit; }

$sql = "UPDATE `moon_point`.`moon_employee_shift` SET ".implode(',', $fields)." WHERE organization_id=? AND id=?";
$types .= "ii"; $params[]=$organization_id; $params[]=$id;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error al preparar: ".$con->error]); $con->close(); exit; }

$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) { echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]); $stmt->close(); $con->close(); exit; }

echo json_encode(["success"=>true,"message"=>"Turno actualizado"]);
$stmt->close(); $con->close();
