<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if ($path && file_exists($path)) {
  include $path;
} else {
  echo json_encode(["success" => false, "error" => "No se encontró Conexion.php en la ruta $path"]);
  exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) { $input = []; }

$required = ["organization_id", "employee_id", "event_type"];
foreach ($required as $k) {
  if (!isset($input[$k]) || $input[$k] === "") {
    echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: $k"]);
    exit;
  }
}

$organization_id = (int)$input["organization_id"];
$employee_id     = (int)$input["employee_id"];
$event_type      = (int)$input["event_type"]; // 1=IN,2=OUT,3=BREAK_IN,4=BREAK_OUT
$event_tz        = isset($input["event_tz"]) ? trim((string)$input["event_tz"]) : "America/Mexico_City";
$lat             = array_key_exists("lat",$input) ? $input["lat"] : null;
$lng             = array_key_exists("lng",$input) ? $input["lng"] : null;
$accuracy_m      = array_key_exists("accuracy_m",$input) ? $input["accuracy_m"] : null;
$device_id       = isset($input["device_id"]) ? trim((string)$input["device_id"]) : null;
$source          = isset($input["source"]) ? trim((string)$input["source"]) : "ios";
$notes           = isset($input["notes"]) ? trim((string)$input["notes"]) : null;
$created_by      = isset($input["created_by"]) ? (int)$input["created_by"] : null;

// Foto (opcional): photo_url directo o photo_b64 para guardar archivo
$photo_url       = isset($input["photo_url"]) ? trim((string)$input["photo_url"]) : null;
$photo_b64       = isset($input["photo_b64"]) ? (string)$input["photo_b64"] : null;

if (!in_array($event_type, [1,2,3,4], true)) {
  echo json_encode(["success" => false, "error" => "event_type inválido (usa 1,2,3,4)"]);
  exit;
}

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}
$con->set_charset("utf8mb4");

/* =========================
   Forzar zona horaria (sesión)
   ========================= */
if (!$con->query("SET time_zone = @@global.time_zone")) {
  // Fallback cuando el servidor no tiene tablas tz cargadas
  $con->query("SET time_zone = '-06:00'");
}
// (Opcional debug)
// $rs=$con->query("SELECT @@session.time_zone tz"); if($rs){error_log('attendance.tz='.$rs->fetch_assoc()['tz']);}

/* ===== 1) Verificar empleado ===== */
$sqlEmp = "SELECT id, customer_id, role FROM moon_employee 
           WHERE organization_id = ? AND id = ? LIMIT 1";
$stmtEmp = $con->prepare($sqlEmp);
if (!$stmtEmp) { echo json_encode(["success"=>false,"error"=>"Error preparando consulta empleado"]); $con->close(); exit; }
$stmtEmp->bind_param("ii", $organization_id, $employee_id);
$stmtEmp->execute();
$resEmp = $stmtEmp->get_result();
$emp = $resEmp ? $resEmp->fetch_assoc() : null;
$stmtEmp->close();

if (!$emp) {
  echo json_encode(["success" => false, "error" => "Empleado no existe en esta organización"]);
  $con->close(); exit;
}
$customer_id = (int)$emp["customer_id"];

/* ===== 2) Calcular evento UTC y fecha local (según event_tz) ===== */
try {
  $utcNow = new DateTime("now", new DateTimeZone("UTC"));
  $event_at_utc = $utcNow->format("Y-m-d H:i:s");

  try {
    $tzObj = new DateTimeZone($event_tz);
  } catch (Exception $e) {
    $event_tz = "UTC";
    $tzObj = new DateTimeZone("UTC");
  }
  $local = clone $utcNow;
  $local->setTimezone($tzObj);
  $event_local_date = $local->format("Y-m-d");
} catch (Exception $e) {
  echo json_encode(["success" => false, "error" => "Error calculando fechas: ".$e->getMessage()]);
  $con->close(); exit;
}

/* ===== 3) Guardar selfie si viene en base64 (opcional) ===== */
if ($photo_b64) {
  $filename = null;
  $dir = "/home/site/wwwroot/uploads/attendance/".$organization_id;
  if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

  $ext = "jpg";
  if (preg_match('/^data:image\/(png|jpg|jpeg|webp);base64,/', $photo_b64, $m)) {
    $ext = ($m[1] === "jpeg") ? "jpg" : $m[1];
    $photo_b64 = preg_replace('/^data:image\/(png|jpg|jpeg|webp);base64,/', '', $photo_b64);
  }
  $bin = base64_decode($photo_b64, true);
  if ($bin !== false) {
    $filename = "att_" . $employee_id . "_" . time() . "." . $ext;
    $full = $dir . "/" . $filename;
    if (file_put_contents($full, $bin) !== false) {
      // URL pública relativa
      $photo_url = "/uploads/attendance/".$organization_id."/".$filename;
    }
  }
}

/* ===== 4) Insert dinámico ===== */
$cols = ["organization_id","employee_id","customer_id","event_type","event_at_utc","event_tz","event_local_date"];
$phs  = ["?","?","?","?","?","?","?"];
$types = "iiiisss";
$vals  = [$organization_id,$employee_id,$customer_id,$event_type,$event_at_utc,$event_tz,$event_local_date];

if ($lat !== null)       { $cols[]="lat";         $phs[]="?"; $types.="d"; $vals[]=(float)$lat; }
if ($lng !== null)       { $cols[]="lng";         $phs[]="?"; $types.="d"; $vals[]=(float)$lng; }
if ($accuracy_m !== null){ $cols[]="accuracy_m";  $phs[]="?"; $types.="d"; $vals[]=(float)$accuracy_m; }
if ($photo_url)          { $cols[]="photo_url";   $phs[]="?"; $types.="s"; $vals[]=$photo_url; }
if ($device_id)          { $cols[]="device_id";   $phs[]="?"; $types.="s"; $vals[]=$device_id; }
if ($source)             { $cols[]="source";      $phs[]="?"; $types.="s"; $vals[]=$source; }
if ($notes)              { $cols[]="notes";       $phs[]="?"; $types.="s"; $vals[]=$notes; }
if ($created_by !== null){ $cols[]="created_by";  $phs[]="?"; $types.="i"; $vals[]=$created_by; }

$sql = "INSERT INTO moon_attendance (".implode(",",$cols).") VALUES (".implode(",",$phs).")";
$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error al preparar INSERT"]); $con->close(); exit; }

$stmt->bind_param($types, ...$vals);

if ($stmt->execute()) {
  echo json_encode([
    "success" => true,
    "message" => "Asistencia registrada",
    "id"      => (int)$stmt->insert_id,
    "customer_id" => $customer_id,
    "event_at_utc" => $event_at_utc,
    "event_local_date" => $event_local_date,
    "photo_url" => $photo_url
  ]);
} else {
  if ($con->errno == 1062) {
    http_response_code(409);
    echo json_encode(["success" => false, "error" => "Ya existe un registro de este tipo para el empleado en la fecha local ".$event_local_date]);
  } else {
    echo json_encode(["success" => false, "error" => "Error al insertar: ".$con->error]);
  }
}

$stmt->close();
$con->close();
