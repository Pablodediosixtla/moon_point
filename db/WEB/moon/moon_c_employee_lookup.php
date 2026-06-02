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

$required = ["organization_id"];
foreach ($required as $k) {
  if (!isset($input[$k]) || $input[$k] === "") {
    echo json_encode(["success"=>false,"error"=>"Falta parámetro obligatorio: $k"]); exit;
  }
}
$organization_id = (int)$input["organization_id"];
$search = isset($input["search"]) ? trim((string)$input["search"]) : "";
$only_active = isset($input["only_active"]) ? (int)$input["only_active"] : 1;
$limit = isset($input["limit"]) ? max(1,min(50,(int)$input["limit"])) : 10;

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la BD"]); exit; }
$con->set_charset("utf8mb4");

$where = ["e.organization_id = ?"];
$types = "i";
$vals  = [$organization_id];

if ($only_active === 1) { $where[] = "e.is_active = 1"; }

if ($search !== "") {
  // Busca por código exacto o por nombre (%like%)
  $where[] = "(e.employee_code = ? OR c.customer_name LIKE ? OR c.full_name LIKE ?)";
  $types  .= "sss";
  $p = "%".$search."%";
  $vals[] = $search;
  $vals[] = $p;
  $vals[] = $p;
}

$sql = "SELECT e.id AS employee_id, e.customer_id, e.employee_code, e.role,
               c.customer_name, c.full_name AS customer_full_name
        FROM moon_employee e
        LEFT JOIN moon_customer c
          ON c.organization_id = e.organization_id AND c.id = e.customer_id
        WHERE ".implode(" AND ", $where)."
        ORDER BY (c.full_name IS NULL), c.full_name, c.customer_name
        LIMIT ?";

$types .= "i"; $vals[] = $limit;

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success"=>false,"error"=>"Error preparando consulta"]); $con->close(); exit; }
$stmt->bind_param($types, ...$vals);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) { $data[] = $row; }

echo json_encode(["success"=>true,"data"=>$data]);

$stmt->close();
$con->close();
