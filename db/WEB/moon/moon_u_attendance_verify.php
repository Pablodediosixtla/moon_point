<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success"=>false,"error"=>"Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if ($path && file_exists($path)) {
  include $path;
} else {
  echo json_encode(["success"=>false,"error"=>"No se encontró Conexion.php en $path"]); exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) { $input = []; }

$required = ["organization_id","id","verified"];
foreach ($required as $k) {
  if (!isset($input[$k]) || $input[$k] === "") {
    echo json_encode(["success"=>false,"error"=>"Falta parámetro obligatorio: $k"]); exit;
  }
}
$organization_id = (int)$input["organization_id"];
$id              = (int)$input["id"];
$verified        = (int)$input["verified"];   // 0,1,2
$verified_by     = isset($input["verified_by"]) ? (int)$input["verified_by"] : null;

if (!in_array($verified,[0,1,2],true)) {
  echo json_encode(["success"=>false,"error"=>"Valor de 'verified' inválido"]); exit;
}

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la BD"]); exit; }
$con->set_charset("utf8mb4");

// Verifica pertenencia
$exists = $con->prepare("SELECT id FROM moon_attendance WHERE id=? AND organization_id=?");
$exists->bind_param("ii",$id,$organization_id);
$exists->execute();
$r = $exists->get_result();
$ok = $r && $r->fetch_row();
$exists->close();
if (!$ok) {
  echo json_encode(["success"=>false,"error"=>"Registro no encontrado en la organización"]); $con->close(); exit;
}

if ($verified_by !== null) {
  $sql = "UPDATE moon_attendance 
          SET verified=?, verified_by=?, verified_at=NOW() 
          WHERE id=? AND organization_id=?";
  $stmt = $con->prepare($sql);
  $stmt->bind_param("iiii",$verified,$verified_by,$id,$organization_id);
} else {
  $sql = "UPDATE moon_attendance 
          SET verified=?, verified_at=NOW() 
          WHERE id=? AND organization_id=?";
  $stmt = $con->prepare($sql);
  $stmt->bind_param("iii",$verified,$id,$organization_id);
}

if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error preparando UPDATE"]); $con->close(); exit; }

if ($stmt->execute()) {
  echo json_encode(["success"=>true,"message"=>"Asistencia actualizada"]);
} else {
  echo json_encode(["success"=>false,"error"=>"Error al actualizar: ".$con->error]);
}
$stmt->close();
$con->close();
