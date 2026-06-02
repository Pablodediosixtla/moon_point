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

$vendor_id       = array_key_exists('vendor_id', $in) ? (int)$in['vendor_id'] : null;
$description     = array_key_exists('description', $in) ? trim((string)$in['description']) : null;
$expense_date    = array_key_exists('expense_date', $in) ? trim((string)$in['expense_date']) : null;
$amount          = array_key_exists('amount', $in) ? (float)$in['amount'] : null;
$payment_method  = array_key_exists('payment_method', $in) ? (int)$in['payment_method'] : null;
$status          = array_key_exists('status', $in) ? (int)$in['status'] : null;
$note            = array_key_exists('note', $in) ? trim((string)$in['note']) : null;
$attributes      = array_key_exists('attributes',$in) ? json_encode($in['attributes']) : null;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$fields = [];
$types  = "";
$params = [];

if ($vendor_id !== null)      { $fields[]="vendor_id=?";      $types.="i"; $params[]=$vendor_id; }
if ($description !== null)    { $fields[]="description=?";    $types.="s"; $params[]=$description; }
if ($expense_date !== null)   { $fields[]="expense_date=?";   $types.="s"; $params[]=$expense_date; }
if ($amount !== null)         { $fields[]="amount=?";         $types.="d"; $params[]=$amount; }
if ($payment_method !== null) { $fields[]="payment_method=?"; $types.="i"; $params[]=$payment_method; }
if ($status !== null)         { $fields[]="status=?";         $types.="i"; $params[]=$status; }
if ($note !== null)           { $fields[]="note=?";           $types.="s"; $params[]=$note; }
if ($attributes !== null)     { $fields[]="attributes=?";     $types.="s"; $params[]=$attributes; }

if (empty($fields)) {
    echo json_encode(["success"=>true,"message"=>"Nada que actualizar"]);
    $con->close(); exit;
}

$sql = "UPDATE `moon_point`.`moon_expense`
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

echo json_encode(["success"=>true,"message"=>"Gasto actualizado"]);
$stmt->close();
$con->close();
