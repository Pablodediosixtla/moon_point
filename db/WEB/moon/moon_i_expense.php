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
if (!isset($in['description']) || trim($in['description']) === "") {
  echo json_encode(["success" => false, "error" => "Falta description"]); exit;
}
if (!isset($in['expense_date']) || trim($in['expense_date']) === "") {
  echo json_encode(["success" => false, "error" => "Falta expense_date (YYYY-MM-DD)"]); exit;
}
if (!isset($in['amount']) || $in['amount'] === "") {
  echo json_encode(["success" => false, "error" => "Falta amount"]); exit;
}

$organization_id = (int)$in['organization_id'];
$vendor_id       = isset($in['vendor_id']) ? (int)$in['vendor_id'] : null;
$description     = trim((string)$in['description']);
$expense_date    = trim((string)$in['expense_date']);
$amount          = (float)$in['amount'];
$payment_method  = isset($in['payment_method']) ? (int)$in['payment_method'] : null;
$status          = isset($in['status']) ? (int)$in['status'] : 1;
$note            = isset($in['note']) ? trim((string)$in['note']) : null;
$attributes      = isset($in['attributes']) ? json_encode($in['attributes']) : null;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

/* =========================
   Forzar zona horaria local
   ========================= */
if (!$con->query("SET time_zone = @@global.time_zone")) {
  // Fallback seguro cuando no hay tablas de zona horaria cargadas
  $con->query("SET time_zone = '-06:00'");
}
/* (Opcional de diagnóstico rápido)
$resTZ = $con->query("SELECT @@session.time_zone AS tz"); $row = $resTZ? $resTZ->fetch_assoc():null;
error_log('session.tz=' . ($row['tz'] ?? '?'));
*/

// Inserción
$sql = "INSERT INTO `moon_point`.`moon_expense`
        (organization_id, vendor_id, description, expense_date, amount,
         payment_method, status, note, attributes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $con->prepare($sql);
if(!$stmt){
  echo json_encode(["success"=>false,"error"=>"Error al preparar consulta: ".$con->error]);
  $con->close(); exit;
}

$stmt->bind_param(
    "iissdisss",
    $organization_id,
    $vendor_id,
    $description,
    $expense_date,
    $amount,
    $payment_method,
    $status,
    $note,
    $attributes
);

if(!$stmt->execute()){
  echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]);
  $stmt->close(); $con->close(); exit;
}

$newId = $stmt->insert_id;
echo json_encode(["success"=>true,"id"=>$newId,"message"=>"Gasto registrado"]);
$stmt->close();
$con->close();
