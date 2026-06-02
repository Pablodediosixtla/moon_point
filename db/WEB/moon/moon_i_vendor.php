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

if (!isset($in['organization_id']) || $in['organization_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta organization_id"]); exit;
}
if (!isset($in['vendor_name']) || trim($in['vendor_name']) === "") {
  echo json_encode(["success" => false, "error" => "Falta vendor_name"]); exit;
}

$organization_id = (int)$in['organization_id'];
$vendor_type     = isset($in['vendor_type']) ? (int)$in['vendor_type'] : 1;
$vendor_name     = trim((string)$in['vendor_name']);
$contact_name    = isset($in['contact_name']) ? trim((string)$in['contact_name']) : null;
$phone           = isset($in['phone']) ? trim((string)$in['phone']) : null;
$email           = isset($in['email']) ? trim((string)$in['email']) : null;
$tax_id          = isset($in['tax_id']) ? trim((string)$in['tax_id']) : null;
$is_active       = isset($in['is_active']) ? (int)$in['is_active'] : 1;
$attributes      = isset($in['attributes']) ? json_encode($in['attributes']) : null;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$sql = "INSERT INTO `moon_point`.`moon_vendor`
        (organization_id, vendor_type, vendor_name, contact_name, phone, email, tax_id,
         is_active, attributes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $con->prepare($sql);
if(!$stmt){
  echo json_encode(["success"=>false,"error"=>"Error al preparar consulta: ".$con->error]);
  $con->close(); exit;
}

$stmt->bind_param(
    "iisssssis",
    $organization_id,
    $vendor_type,
    $vendor_name,
    $contact_name,
    $phone,
    $email,
    $tax_id,
    $is_active,
    $attributes
);

if (!$stmt->execute()) {
  echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]);
  $stmt->close(); $con->close(); exit;
}

$newId = $stmt->insert_id;
echo json_encode(["success"=>true,"id"=>$newId,"message"=>"Proveedor creado"]);
$stmt->close();
$con->close();
