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

$vendor_type     = isset($in['vendor_type']) ? (int)$in['vendor_type'] : null;
$vendor_name     = isset($in['vendor_name']) ? trim((string)$in['vendor_name']) : null;
$contact_name    = array_key_exists('contact_name', $in) ? trim((string)$in['contact_name']) : null;
$phone           = array_key_exists('phone', $in) ? trim((string)$in['phone']) : null;
$email           = array_key_exists('email', $in) ? trim((string)$in['email']) : null;
$tax_id          = array_key_exists('tax_id', $in) ? trim((string)$in['tax_id']) : null;
$is_active       = isset($in['is_active']) ? (int)$in['is_active'] : null;
$attributes      = array_key_exists('attributes',$in) ? json_encode($in['attributes']) : null;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$fields = [];
$types  = "";
$params = [];

if ($vendor_type !== null) { $fields[]="vendor_type=?";     $types.="i"; $params[]=$vendor_type; }
if ($vendor_name !== null) { $fields[]="vendor_name=?";     $types.="s"; $params[]=$vendor_name; }
if ($contact_name !== null){ $fields[]="contact_name=?";    $types.="s"; $params[]=$contact_name; }
if ($phone !== null)       { $fields[]="phone=?";           $types.="s"; $params[]=$phone; }
if ($email !== null)       { $fields[]="email=?";           $types.="s"; $params[]=$email; }
if ($tax_id !== null)      { $fields[]="tax_id=?";          $types.="s"; $params[]=$tax_id; }
if ($is_active !== null)   { $fields[]="is_active=?";       $types.="i"; $params[]=$is_active; }
if ($attributes !== null)  { $fields[]="attributes=?";      $types.="s"; $params[]=$attributes; }

if (empty($fields)) {
    echo json_encode(["success"=>true,"message"=>"Nada que actualizar"]); 
    $con->close(); exit;
}

$sql = "UPDATE `moon_point`.`moon_vendor`
        SET ".implode(",",$fields)."
        WHERE id=? AND organization_id=?";

$types .= "ii"; 
$params[] = $id;
$params[] = $organization_id;

$stmt = $con->prepare($sql);
if(!$stmt){
  echo json_encode(["success"=>false,"error"=>"Error al preparar consulta: ".$con->error]);
  $con->close(); exit;
}

$stmt->bind_param($types, ...$params);
if(!$stmt->execute()){
  echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]);
  $stmt->close(); $con->close(); exit;
}

echo json_encode(["success"=>true,"message"=>"Proveedor actualizado"]);
$stmt->close();
$con->close();
