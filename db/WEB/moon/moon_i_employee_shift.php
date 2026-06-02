<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); echo json_encode(["success"=>false,"error"=>"Método no permitido. Usa POST."]); exit;
}
include realpath("/home/site/wwwroot/db/conn/Conexion.php");
$in = json_decode(file_get_contents("php://input"), true); if (!is_array($in)) { $in = []; }

foreach (['organization_id','employee_id','day_of_week','start_time','end_time'] as $r) {
  if (!isset($in[$r]) || $in[$r] === "") { echo json_encode(["success"=>false,"error"=>"Falta $r"]); exit; }
}

$organization_id = (int)$in['organization_id'];
$employee_id     = (int)$in['employee_id'];
$day_of_week     = (int)$in['day_of_week'];       // 1..7
$start_time      = trim((string)$in['start_time']); // HH:MM:SS
$end_time        = trim((string)$in['end_time']);   // HH:MM:SS
$break_minutes   = isset($in['break_minutes']) ? (int)$in['break_minutes'] : 0;
$effective_from  = isset($in['effective_from']) ? trim((string)$in['effective_from']) : null; // YYYY-MM-DD
$effective_to    = isset($in['effective_to']) ? trim((string)$in['effective_to']) : null;     // YYYY-MM-DD
$is_active       = isset($in['is_active']) ? (int)$in['is_active'] : 1;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

// simple sanity
if ($day_of_week < 1 || $day_of_week > 7) { echo json_encode(["success"=>false,"error"=>"day_of_week inválido (1..7)"]); exit; }

$sql = "INSERT INTO `moon_point`.`moon_employee_shift`
        (organization_id, employee_id, day_of_week, start_time, end_time, break_minutes, effective_from, effective_to, is_active)
        VALUES (?,?,?,?,?,?,?,?,?)";
$stmt = $con->prepare($sql);
$stmt->bind_param("iiississi",
  $organization_id, $employee_id, $day_of_week, $start_time, $end_time, $break_minutes, $effective_from, $effective_to, $is_active
);

if (!$stmt->execute()) { echo json_encode(["success"=>false,"error"=>"Error al insertar: ".$stmt->error]); $stmt->close(); $con->close(); exit; }
echo json_encode(["success"=>true,"id"=>$stmt->insert_id,"message"=>"Turno creado"]);
$stmt->close(); $con->close();
