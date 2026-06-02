<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); echo json_encode(["success"=>false,"error"=>"Método no permitido. Usa POST."]); exit;
}
include realpath("/home/site/wwwroot/db/conn/Conexion.php");
$in = json_decode(file_get_contents("php://input"), true); if (!is_array($in)) { $in = []; }

if (!isset($in['organization_id']) || $in['organization_id'] === "") {
  echo json_encode(["success"=>false,"error"=>"Falta organization_id"]); exit;
}

$organization_id = (int)$in['organization_id'];
$employee_id     = isset($in['employee_id']) ? (int)$in['employee_id'] : 0;
$day_of_week     = isset($in['day_of_week']) ? (int)$in['day_of_week'] : 0; // 1..7
$only_active     = isset($in['only_active']) ? (int)$in['only_active'] : null;
$on_date         = isset($in['on_date']) ? trim((string)$in['on_date']) : ''; // YYYY-MM-DD
$limit           = isset($in['limit']) ? max(1, min(500, (int)$in['limit'])) : 200;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$sql = "SELECT id, organization_id, employee_id, day_of_week, start_time, end_time,
               break_minutes, effective_from, effective_to, is_active, created_at, updated_at
        FROM `moon_point`.`moon_employee_shift`
        WHERE organization_id = ?";
$types = "i"; $params = [$organization_id];

if ($employee_id > 0) { $sql .= " AND employee_id = ?"; $types.="i"; $params[]=$employee_id; }
if ($day_of_week >= 1 && $day_of_week <= 7) { $sql.=" AND day_of_week = ?"; $types.="i"; $params[]=$day_of_week; }
if ($only_active !== null) { $sql .= " AND is_active = ?"; $types.="i"; $params[]=$only_active; }
if ($on_date !== '') {
  $sql .= " AND ( (effective_from IS NULL OR effective_from <= ?) AND (effective_to IS NULL OR effective_to >= ?) )";
  $types .= "ss"; $params[] = $on_date; $params[] = $on_date;
}
$sql .= " ORDER BY employee_id ASC, day_of_week ASC, start_time ASC LIMIT ?";
$types.="i"; $params[]=$limit;

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error al preparar: ".$con->error]); $con->close(); exit; }

$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) { echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]); $stmt->close(); $con->close(); exit; }

$res = $stmt->get_result(); $out=[];
while ($row = $res->fetch_assoc()) {
  $row['id']              = (int)$row['id'];
  $row['organization_id'] = (int)$row['organization_id'];
  $row['employee_id']     = (int)$row['employee_id'];
  $row['day_of_week']     = (int)$row['day_of_week'];
  $row['break_minutes']   = (int)$row['break_minutes'];
  $row['is_active']       = (int)$row['is_active'];
  $out[] = $row;
}
echo json_encode(["success"=>true,"data"=>$out]);
$stmt->close(); $con->close();
