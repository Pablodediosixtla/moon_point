<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); echo json_encode(["success"=>false,"error"=>"Método no permitido. Usa POST."]); exit;
}
include realpath("/home/site/wwwroot/db/conn/Conexion.php");
$in = json_decode(file_get_contents("php://input"), true); if (!is_array($in)) { $in = []; }

foreach (['organization_id','customer_id'] as $r) {
  if (!isset($in[$r]) || $in[$r] === "") { echo json_encode(["success"=>false,"error"=>"Falta $r"]); exit; }
}

$organization_id = (int)$in['organization_id'];
$customer_id     = (int)$in['customer_id'];
$employee_code   = isset($in['employee_code']) ? trim((string)$in['employee_code']) : null;
$role            = isset($in['role']) ? trim((string)$in['role']) : null;
$hire_date       = isset($in['hire_date']) ? trim((string)$in['hire_date']) : null; // YYYY-MM-DD
$days_mask       = isset($in['days_mask']) ? (int)$in['days_mask'] : 0;
$timezone        = isset($in['timezone']) ? trim((string)$in['timezone']) : 'America/Mexico_City';
$is_active       = isset($in['is_active']) ? (int)$in['is_active'] : 1;
$attributes      = isset($in['attributes']) ? $in['attributes'] : null;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

// Validar que el cliente exista y pertenezca a la misma org
$chk = $con->prepare("SELECT 1 FROM `moon_point`.`moon_customer` WHERE id=? AND organization_id=?");
$chk->bind_param("ii", $customer_id, $organization_id);
$chk->execute(); $chk->store_result();
if ($chk->num_rows === 0) { echo json_encode(["success"=>false,"error"=>"Cliente no encontrado en la organización"]); $chk->close(); $con->close(); exit; }
$chk->close();

// Evitar duplicado por (org, customer)
$dup = $con->prepare("SELECT id FROM `moon_point`.`moon_employee` WHERE organization_id=? AND customer_id=?");
$dup->bind_param("ii", $organization_id, $customer_id);
$dup->execute(); $dup->store_result();
if ($dup->num_rows > 0) { echo json_encode(["success"=>false,"error"=>"Ya existe un empleado para este cliente"]); $dup->close(); $con->close(); exit; }
$dup->close();

$sql = "INSERT INTO `moon_point`.`moon_employee`
        (organization_id, customer_id, employee_code, role, hire_date, days_mask, timezone, is_active, attributes)
        VALUES (?,?,?,?,?,?,?,?,?)";
$stmt = $con->prepare($sql);
$attrJson = ($attributes && is_array($attributes)) ? json_encode($attributes, JSON_UNESCAPED_UNICODE) : null;
$stmt->bind_param("iisssiiss",
  $organization_id, $customer_id, $employee_code, $role, $hire_date, $days_mask, $timezone, $is_active, $attrJson
);

if (!$stmt->execute()) { echo json_encode(["success"=>false,"error"=>"Error al insertar: ".$stmt->error]); $stmt->close(); $con->close(); exit; }

$newId = $stmt->insert_id;
echo json_encode(["success"=>true,"id"=>$newId,"message"=>"Empleado creado"]);
$stmt->close(); $con->close();
