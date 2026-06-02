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

if (!isset($input["organization_id"])) {
  echo json_encode(["success"=>false,"error"=>"Falta parámetro obligatorio: organization_id"]); exit;
}
$organization_id = (int)$input["organization_id"];
$employee_id     = isset($input["employee_id"]) ? (int)$input["employee_id"] : null;
$event_type      = isset($input["event_type"]) ? (int)$input["event_type"] : null;
$date            = isset($input["date"]) ? trim((string)$input["date"]) : null; // YYYY-MM-DD
$date_from       = isset($input["date_from"]) ? trim((string)$input["date_from"]) : null;
$date_to         = isset($input["date_to"]) ? trim((string)$input["date_to"]) : null;

$page     = isset($input["page"]) ? max(1,(int)$input["page"]) : 1;
$pageSize = isset($input["page_size"]) ? max(1,min(500,(int)$input["page_size"])) : 100;
$offset   = ($page-1)*$pageSize;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la BD"]); exit; }
$con->set_charset("utf8mb4");

$where = ["a.organization_id = ?"];
$types = "i";
$vals  = [$organization_id];

if ($employee_id !== null) { $where[]="a.employee_id = ?"; $types.="i"; $vals[]=$employee_id; }
if ($event_type !== null)  { $where[]="a.event_type = ?";   $types.="i"; $vals[]=$event_type; }

if ($date) {
  $where[]="a.event_local_date = ?";
  $types.="s"; $vals[]=$date;
} else {
  if ($date_from) { $where[]="a.event_local_date >= ?"; $types.="s"; $vals[]=$date_from; }
  if ($date_to)   { $where[]="a.event_local_date <= ?"; $types.="s"; $vals[]=$date_to; }
}

$sql = "SELECT SQL_CALC_FOUND_ROWS
          a.id, a.organization_id, a.employee_id, a.customer_id,
          a.event_type, a.event_at_utc, a.event_tz, a.event_local_date,
          a.lat, a.lng, a.accuracy_m, a.photo_url, a.device_id, a.source, a.notes,
          a.verified, a.verified_by, a.verified_at, a.status, a.created_at, a.updated_at,
          e.role,
          c.customer_name, c.full_name AS customer_full_name
        FROM moon_attendance a
        LEFT JOIN moon_employee e
          ON e.organization_id = a.organization_id AND e.id = a.employee_id
        LEFT JOIN moon_customer c
          ON c.organization_id = a.organization_id AND c.id = a.customer_id
        WHERE ".implode(" AND ", $where)."
        ORDER BY a.event_at_utc DESC
        LIMIT ? OFFSET ?";

$types .= "ii";
$vals[] = $pageSize;
$vals[] = $offset;

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error preparando consulta"]); $con->close(); exit; }
$stmt->bind_param($types, ...$vals);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) { $data[] = $row; }

$stmt->close();

$totalRes = $con->query("SELECT FOUND_ROWS() AS total");
$total = 0;
if ($totalRes && $tr=$totalRes->fetch_assoc()) { $total=(int)$tr["total"]; }

echo json_encode([
  "success"=>true,
  "page"=>$page,
  "page_size"=>$pageSize,
  "total"=>$total,
  "data"=>$data
]);

$con->close();
