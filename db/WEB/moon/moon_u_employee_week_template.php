<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success"=>false,"error"=>"Método no permitido. Usa POST."]); exit;
}

include realpath("/home/site/wwwroot/db/conn/Conexion.php");
$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

foreach (['organization_id','employee_id','template'] as $r) {
  if (!isset($in[$r]) || $in[$r]==="") { echo json_encode(["success"=>false,"error"=>"Falta $r"]); exit; }
}
$organization_id = (int)$in['organization_id'];
$employee_id     = (int)$in['employee_id'];
$template        = is_array($in['template']) ? $in['template'] : [];

$effective_from  = isset($in['effective_from']) ? (string)$in['effective_from'] : null; // YYYY-MM-DD
$effective_to    = isset($in['effective_to'])   ? (string)$in['effective_to']   : null; // YYYY-MM-DD
$is_active       = isset($in['is_active']) ? (int)$in['is_active'] : 1;
$replace_all     = isset($in['replace_all']) ? (int)$in['replace_all'] : 1; // 1 = borra turnos previos del empleado

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

try {
  $con->begin_transaction();

  // Valida existencia del empleado dentro de la organización
  $chk = $con->prepare("SELECT 1 FROM `moon_point`.`moon_employee` WHERE id=? AND organization_id=? AND is_active IN (0,1)");
  $chk->bind_param("ii", $employee_id, $organization_id);
  $chk->execute(); $chk->store_result();
  if ($chk->num_rows === 0) {
    $chk->close(); throw new Exception("Empleado no encontrado en la organización");
  }
  $chk->close();

  if ($replace_all === 1) {
    $del = $con->prepare("DELETE FROM `moon_point`.`moon_employee_shift` WHERE organization_id=? AND employee_id=?");
    $del->bind_param("ii", $organization_id, $employee_id);
    if (!$del->execute()) { throw new Exception("Error al limpiar turnos: ".$del->error); }
    $del->close();
  }

  $ins = $con->prepare(
    "INSERT INTO `moon_point`.`moon_employee_shift`
     (organization_id, employee_id, day_of_week, start_time, end_time, break_minutes, effective_from, effective_to, is_active)
     VALUES (?,?,?,?,?,?,?,?,?)"
  );
  if (!$ins) { throw new Exception("Error al preparar inserción: ".$con->error); }

  foreach ($template as $row) {
    $dow   = (int)($row['dow'] ?? $row['day_of_week'] ?? 0);          // 1..7
    $start = (string)($row['start'] ?? $row['start_time'] ?? '');
    $end   = (string)($row['end']   ?? $row['end_time']   ?? '');
    $break = (int)   ($row['break'] ?? $row['break_minutes'] ?? 0);
    $active= (int)   ($row['active']?? $row['is_active'] ?? 1);

    // Validaciones mínimas
    if ($dow < 1 || $dow > 7) { continue; }
    if ($start === '' || $end === '') { continue; }
    if ($active !== 1) { continue; }

    $ins->bind_param(
      "iiississi",
      $organization_id, $employee_id, $dow, $start, $end, $break, $effective_from, $effective_to, $is_active
    );
    if (!$ins->execute()) { throw new Exception("Error al insertar turno (día $dow): ".$ins->error); }
  }
  $ins->close();

  $con->commit();
  echo json_encode(["success"=>true,"message"=>"Plantilla semanal guardada"]);
} catch (Throwable $e) {
  $con->rollback();
  echo json_encode(["success"=>false,"error"=>$e->getMessage()]);
}
$con->close();
