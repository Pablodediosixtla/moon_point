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

$required = ["organization_id", "category_id", "name", "price"];
foreach ($required as $k) {
  if (!isset($input[$k]) || $input[$k] === "") {
    echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: $k"]);
    exit;
  }
}

$organization_id = (int)$input["organization_id"];
$category_id     = (int)$input["category_id"];
$name            = trim((string)$input["name"]);
$price           = (float)$input["price"];

$sku         = isset($input["sku"]) ? trim((string)$input["sku"]) : null;
$description = isset($input["description"]) ? trim((string)$input["description"]) : null;
$cost        = isset($input["cost"]) ? (float)$input["cost"] : null;
$image_name  = array_key_exists("image_name", $input) ? trim((string)$input["image_name"]) : null; // si viene vacío -> default DB
$is_active   = isset($input["is_active"]) ? (int)$input["is_active"] : 1;
$sort_order  = isset($input["sort_order"]) ? (int)$input["sort_order"] : 0;
$attributes  = array_key_exists("attributes", $input) ? $input["attributes"] : null; // array|obj|string|null

// Normaliza attributes a JSON string (o null)
$attributes_json = null;
if ($attributes !== null) {
  if (is_string($attributes)) {
    $attributes_json = $attributes;
  } else {
    $attributes_json = json_encode($attributes, JSON_UNESCAPED_UNICODE);
  }
}

$con = conectar();
if (!$con) { echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]); exit; }

$cols   = "organization_id, category_id, name, price, is_active, sort_order";
$marks  = "?, ?, ?, ?, ?, ?";
$types  = "iisdii";
$params = [$organization_id, $category_id, $name, $price, $is_active, $sort_order];

if ($sku !== null && $sku !== "") { $cols .= ", sku"; $marks .= ", ?"; $types .= "s"; $params[] = $sku; }
if ($description !== null && $description !== "") { $cols .= ", description"; $marks .= ", ?"; $types .= "s"; $params[] = $description; }
if ($cost !== null) { $cols .= ", cost"; $marks .= ", ?"; $types .= "d"; $params[] = $cost; }
if ($image_name !== null && $image_name !== "") { $cols .= ", image_name"; $marks .= ", ?"; $types .= "s"; $params[] = $image_name; }
if ($attributes_json !== null) { $cols .= ", attributes"; $marks .= ", CAST(? AS JSON)"; $types .= "s"; $params[] = $attributes_json; }

$sql = "INSERT INTO moon_product ($cols) VALUES ($marks)";
$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success" => false, "error" => "Error al preparar consulta"]); $con->close(); exit; }

$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
  echo json_encode(["success" => true, "message" => "Producto creado", "id" => (int)$stmt->insert_id]);
} else {
  if ($con->errno == 1452) { // FK
    echo json_encode(["success" => false, "error" => "Organización o categoría no válidas"]);
  } else if ($con->errno == 1062) { // unique
    echo json_encode(["success" => false, "error" => "SKU o nombre duplicado en la organización"]);
  } else {
    echo json_encode(["success" => false, "error" => "Error al insertar: " . $con->error]);
  }
}

$stmt->close();
$con->close();
