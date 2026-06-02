<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if ($path && file_exists($path)) { include $path; }
else { echo json_encode(["success" => false, "error" => "No se encontró Conexion.php en la ruta $path"]); exit; }

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) { $input = []; }

if (!isset($input["organization_id"]) || $input["organization_id"] === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: organization_id"]);
  exit;
}

$organization_id = (int)$input["organization_id"];

$tipo_img   = isset($input["tipo_img"]) ? (int)$input["tipo_img"] : null;       // opcional
$search     = isset($input["search"]) ? trim((string)$input["search"]) : "";    // opcional
$onlyActive = isset($input["only_active"]) ? (int)$input["only_active"] : 1;    // 1=solo activas

$page      = max(1, isset($input["page"]) ? (int)$input["page"] : 1);
$page_size = max(1, min(500, isset($input["page_size"]) ? (int)$input["page_size"] : 100));
$offset    = ($page - 1) * $page_size;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}

$where  = [];
$params = [];
$types  = "";

/**
 * Regresamos imágenes de la organización + globales (organization_id IS NULL)
 */
$where[]  = "(organization_id = ? OR organization_id IS NULL)";
$params[] = $organization_id;
$types   .= "i";

if ($tipo_img !== null) {
  $where[]  = "tipo_img = ?";
  $params[] = $tipo_img;
  $types   .= "i";
}

if ($onlyActive === 1) {
  $where[] = "is_active = 1";
}

if ($search !== "") {
  $where[]  = "(name LIKE ? OR display_name LIKE ?)";
  $like     = "%".$search."%";
  $params[] = $like; $types .= "s";
  $params[] = $like; $types .= "s";
}

$whereSQL = count($where) ? ("WHERE " . implode(" AND ", $where)) : "";

/* Total */
$sqlCount = "SELECT COUNT(*) AS total FROM moon_images $whereSQL";
$stmt = $con->prepare($sqlCount);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar COUNT"]);
  $con->close(); exit;
}
if ($types !== "") { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$resCount = $stmt->get_result();
$total = ($resCount && $resCount->num_rows) ? (int)$resCount->fetch_assoc()['total'] : 0;
$stmt->close();

/* Datos */
$sql = "SELECT
          id, organization_id, tipo_img, name, display_name, image_url,
          is_active, sort_order, created_at, updated_at
        FROM moon_images
        $whereSQL
        ORDER BY sort_order ASC, name ASC
        LIMIT ? OFFSET ?";

$paramsData = $params;
$typesData  = $types . "ii";
$paramsData[] = $page_size;
$paramsData[] = $offset;

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta"]);
  $con->close(); exit;
}
$stmt->bind_param($typesData, ...$paramsData);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $row['id']              = (int)$row['id'];
    $row['organization_id'] = isset($row['organization_id']) ? (int)$row['organization_id'] : null;
    $row['tipo_img']        = (int)$row['tipo_img'];
    $row['is_active']       = (int)$row['is_active'];
    $row['sort_order']      = (int)$row['sort_order'];
    $data[] = $row;
  }
}

$stmt->close();
$con->close();

echo json_encode([
  "success"   => true,
  "total"     => $total,
  "page"      => $page,
  "page_size" => $page_size,
  "data"      => $data
]);
