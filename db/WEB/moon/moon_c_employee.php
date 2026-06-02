<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success"=>false,"error"=>"Método no permitido. Usa POST."]); exit;
}

include realpath("/home/site/wwwroot/db/conn/Conexion.php");
$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (!isset($in['organization_id']) || $in['organization_id'] === "") {
  echo json_encode(["success"=>false,"error"=>"Falta organization_id"]); exit;
}

$organization_id = (int)$in['organization_id'];
$employee_id     = isset($in['employee_id']) ? (int)$in['employee_id'] : 0;
$customer_id     = isset($in['customer_id']) ? (int)$in['customer_id'] : 0;
$is_active       = isset($in['is_active']) ? (int)$in['is_active'] : null; // 1/0
$search          = isset($in['search']) ? trim((string)$in['search']) : '';
$limit           = isset($in['limit']) ? max(1, min(500, (int)$in['limit'])) : 100;
$offset          = isset($in['offset']) ? max(0, (int)$in['offset']) : 0;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset('utf8mb4');

$sql = "SELECT
          e.id, e.organization_id, e.customer_id, e.employee_code, e.role,
          e.hire_date, e.days_mask, e.timezone, e.is_active,
          e.attributes, e.created_at, e.updated_at,
          c.customer_name, c.full_name, c.phone, c.email
        FROM `moon_point`.`moon_employee` e
        JOIN `moon_point`.`moon_customer` c
          ON c.id = e.customer_id AND c.organization_id = e.organization_id
        WHERE e.organization_id = ?";
$types = "i";
$params = [$organization_id];

if ($employee_id > 0) { $sql .= " AND e.id = ?"; $types .= "i"; $params[] = $employee_id; }
if ($customer_id > 0) { $sql .= " AND e.customer_id = ?"; $types .= "i"; $params[] = $customer_id; }
if ($is_active !== null) { $sql .= " AND e.is_active = ?"; $types .= "i"; $params[] = $is_active; }
if ($search !== '') {
  $sql .= " AND (c.customer_name LIKE CONCAT('%',?,'%')
              OR COALESCE(c.full_name,'') LIKE CONCAT('%',?,'%')
              OR COALESCE(e.employee_code,'') LIKE CONCAT('%',?,'%')
              OR COALESCE(e.role,'') LIKE CONCAT('%',?,'%'))";
  $types .= "ssss"; $params[] = $search; $params[] = $search; $params[] = $search; $params[] = $search;
}

$sql .= " ORDER BY c.customer_name ASC, e.id DESC LIMIT ? OFFSET ?";
$types .= "ii"; $params[] = $limit; $params[] = $offset;

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error al preparar: ".$con->error]); $con->close(); exit; }

$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) { echo json_encode(["success"=>false,"error"=>"Error al ejecutar: ".$stmt->error]); $stmt->close(); $con->close(); exit; }

$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
  if (isset($row['attributes']) && $row['attributes'] !== null && $row['attributes'] !== '') {
    $row['attributes'] = json_decode($row['attributes'], true);
  }
  $row['id']              = (int)$row['id'];
  $row['organization_id'] = (int)$row['organization_id'];
  $row['customer_id']     = (int)$row['customer_id'];
  $row['days_mask']       = (int)$row['days_mask'];
  $row['is_active']       = (int)$row['is_active'];
  $out[] = $row;
}

echo json_encode(["success"=>true,"data"=>$out]);
$stmt->close(); $con->close();
