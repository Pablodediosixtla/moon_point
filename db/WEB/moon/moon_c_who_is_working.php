<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success"=>false,"error"=>"Método no permitido. Usa POST."]); exit;
}

include realpath("/home/site/wwwroot/db/conn/Conexion.php");
$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (!isset($in['organization_id']) || $in['organization_id']==="") {
  echo json_encode(["success"=>false,"error"=>"Falta organization_id"]); exit;
}
$organization_id = (int)$in['organization_id'];

$timezone = isset($in['timezone']) ? (string)$in['timezone'] : 'America/Mexico_City';

// Defaults calculados en TZ solicitada
try {
  $now = new DateTime('now', new DateTimeZone($timezone));
} catch (Throwable $e) {
  $now = new DateTime('now', new DateTimeZone('America/Mexico_City'));
}
$on_date = isset($in['on_date']) ? (string)$in['on_date'] : $now->format('Y-m-d');      // YYYY-MM-DD
$at_time = isset($in['at_time']) ? (string)$in['at_time'] : $now->format('H:i:s');      // HH:MM:SS
$only_now= isset($in['only_now']) ? (int)$in['only_now'] : 0;                            // 1: filtra sólo los que están ahora
$only_active = isset($in['only_active']) ? (int)$in['only_active'] : 1;                  // 1 default
$limit = isset($in['limit']) ? max(1, min(1000, (int)$in['limit'])) : 500;

// 1 = lunes ... 7 = domingo (PHP: N)
$dow = (int)date('N', strtotime($on_date));
if ($dow < 1 || $dow > 7) { $dow = 1; }

// bit para days_mask (0 = lunes) ⇒ 1<<(dow-1)
$maskBit = 1 << ($dow - 1);

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

// Nota: combinamos regla de empleado (days_mask) + turnos vigentes para el día
$sql = "SELECT
          e.id AS employee_id, e.organization_id, e.customer_id, e.role, e.days_mask, e.timezone, e.is_active AS emp_active,
          c.customer_name, c.full_name, c.phone, c.email,
          s.id AS shift_id, s.day_of_week, s.start_time, s.end_time, s.break_minutes, s.effective_from, s.effective_to, s.is_active AS shift_active
        FROM `moon_point`.`moon_employee` e
        JOIN `moon_point`.`moon_customer` c
          ON c.id = e.customer_id AND c.organization_id = e.organization_id
        JOIN `moon_point`.`moon_employee_shift` s
          ON s.organization_id = e.organization_id
         AND s.employee_id = e.id
         AND s.day_of_week = ?
         AND s.is_active = 1
         AND ( (s.effective_from IS NULL OR s.effective_from <= ?) AND (s.effective_to IS NULL OR s.effective_to >= ?) )
        WHERE e.organization_id = ?
          ".($only_active ? " AND e.is_active = 1 " : "")."
          AND ( (e.days_mask & ?) <> 0 )
        ORDER BY c.customer_name ASC, s.start_time ASC
        LIMIT ?";

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error al preparar: ".$con->error]); $con->close(); exit; }

$stmt->bind_param("issiii", $dow, $on_date, $on_date, $organization_id, $maskBit, $limit);
if (!$stmt->execute()) { echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]); $stmt->close(); $con->close(); exit; }

$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
  $nowFlag = ($row['start_time'] <= $at_time && $at_time < $row['end_time']) ? 1 : 0;
  if ($only_now && !$nowFlag) { continue; }

  $out[] = [
    "employee_id"   => (int)$row['employee_id'],
    "organization_id"=> (int)$row['organization_id'],
    "customer_id"   => (int)$row['customer_id'],
    "customer_name" => $row['customer_name'],
    "role"          => $row['role'],
    "employee_timezone" => $row['timezone'],
    "shift_id"      => (int)$row['shift_id'],
    "day_of_week"   => (int)$row['day_of_week'],
    "start_time"    => $row['start_time'],
    "end_time"      => $row['end_time'],
    "break_minutes" => (int)$row['break_minutes'],
    "effective_from"=> $row['effective_from'],
    "effective_to"  => $row['effective_to'],
    "is_working_now"=> $nowFlag
  ];
}

echo json_encode(["success"=>true,"data"=>$out, "meta"=>[
  "on_date"=>$on_date, "at_time"=>$at_time, "timezone"=>$timezone, "only_now"=>$only_now
]]);
$stmt->close(); $con->close();
