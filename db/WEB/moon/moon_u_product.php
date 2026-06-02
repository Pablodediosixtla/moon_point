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

$required = ["id", "organization_id"];
foreach ($required as $k) {
  if (!isset($input[$k]) || $input[$k] === "") {
    echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: $k"]);
    exit;
  }
}

$id              = (int)$input["id"];
$organization_id = (int)$input["organization_id"];

$fields = [];
$params = [];
$types  = "";

// ---- Campos opcionales (usar variables, no literales) ----
if (array_key_exists("category_id", $input)) {
  $p_category_id = (int)$input["category_id"];
  $fields[] = "category_id = ?";
  $params[] = $p_category_id; $types .= "i";
}
if (array_key_exists("sku", $input)) {
  $p_sku = trim((string)$input["sku"]);
  $fields[] = "sku = ?";
  $params[] = $p_sku; $types .= "s";
}
if (array_key_exists("name", $input)) {
  $p_name = trim((string)$input["name"]);
  $fields[] = "name = ?";
  $params[] = $p_name; $types .= "s";
}
if (array_key_exists("description", $input)) {
  $p_description = trim((string)$input["description"]);
  $fields[] = "description = ?";
  $params[] = $p_description; $types .= "s";
}
if (array_key_exists("price", $input)) {
  $p_price = (float)$input["price"];
  $fields[] = "price = ?";
  $params[] = $p_price; $types .= "d";
}
if (array_key_exists("cost", $input)) {
  // puede venir null para limpiar
  $p_cost = isset($input["cost"]) ? (float)$input["cost"] : null;
  $fields[] = "cost = ?";
  $params[] = $p_cost; $types .= "d";
}
if (array_key_exists("image_name", $input)) {
  // puede venir null para limpiar
  $p_image_name = ($input["image_name"] === null) ? null : trim((string)$input["image_name"]);
  $fields[] = "image_name = ?";
  $params[] = $p_image_name; $types .= "s";
}
if (array_key_exists("is_active", $input)) {
  $p_is_active = (int)$input["is_active"];
  $fields[] = "is_active = ?";
  $params[] = $p_is_active; $types .= "i";
}
if (array_key_exists("sort_order", $input)) {
  $p_sort_order = (int)$input["sort_order"];
  $fields[] = "sort_order = ?";
  $params[] = $p_sort_order; $types .= "i";
}
if (array_key_exists("attributes", $input)) {
  // acepta objeto/array/string/null; guardamos como JSON (o NULL)
  $attr = $input["attributes"];
  $p_attr_json = ($attr === null) ? null : (is_string($attr) ? $attr : json_encode($attr, JSON_UNESCAPED_UNICODE));
  $fields[] = "attributes = CAST(? AS JSON)";
  $params[] = $p_attr_json; $types .= "s";
}

if (empty($fields)) {
  echo json_encode(["success" => false, "error" => "No hay campos a actualizar"]);
  exit;
}

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}

$sql = "UPDATE moon_product SET " . implode(", ", $fields) . " WHERE id = ? AND organization_id = ?";

// IDs al final
$p_id = $id;
$p_org = $organization_id;
$params[] = $p_id;
$params[] = $p_org;
$types   .= "ii";

// IMPORTANTE: bind_param requiere referencias
$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode(["success" => false, "error" => "Error al preparar consulta"]);
  $con->close(); exit;
}

// Convertir $params a referencias para bind_param
$bindParams = [];
foreach ($params as $k => $v) { $bindParams[$k] = &$params[$k]; }

array_unshift($bindParams, $types); // primero el string de tipos

if (!call_user_func_array([$stmt, 'bind_param'], $bindParams)) {
  echo json_encode(["success" => false, "error" => "Error al vincular parámetros"]);
  $stmt->close(); $con->close(); exit;
}

if ($stmt->execute()) {
  if ($stmt->affected_rows > 0) {
    echo json_encode(["success" => true, "message" => "Producto actualizado"]);
  } else {
    echo json_encode(["success" => false, "error" => "No se encontró el producto o no hubo cambios"]);
  }
} else {
  if ($con->errno == 1062) {
    echo json_encode(["success" => false, "error" => "SKU o nombre duplicado en la organización"]);
  } else if ($con->errno == 1452) {
    echo json_encode(["success" => false, "error" => "Categoría no válida"]);
  } else {
    echo json_encode(["success" => false, "error" => "Error al actualizar: " . $con->error]);
  }
}

$stmt->close();
$con->close();
