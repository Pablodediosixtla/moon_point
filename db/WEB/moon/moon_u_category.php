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

/**
 * Campos opcionales (si vienen, se actualizan)
 * name, image_name, is_active, sort_order
 */
if (array_key_exists("name", $input)) {
  $fields[] = "name = ?";
  $params[] = trim((string)$input["name"]);
  $types   .= "s";
}
if (array_key_exists("image_name", $input)) {
  // puede ser cadena vacía o null si quieres "limpiar", pero aquí lo dejamos literal
  $fields[] = "image_name = ?";
  $params[] = ($input["image_name"] === null) ? null : trim((string)$input["image_name"]);
  $types   .= "s";
}
if (array_key_exists("is_active", $input)) {
  $fields[] = "is_active = ?";
  $params[] = (int)$input["is_active"];
  $types   .= "i";
}
if (array_key_exists("sort_order", $input)) {
  $fields[] = "sort_order = ?";
  $params[] = (int)$input["sort_order"];
  $types   .= "i";
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

$sql = "UPDATE moon_categories SET " . implode(", ", $fields) . " WHERE id = ? AND organization_id = ?";
$params[] = $id;
$params[] = $organization_id;
$types   .= "ii";

$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success" => false, "error" => "Error al preparar consulta"]); $con->close(); exit; }

$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
  if ($stmt->affected_rows > 0) {
    echo json_encode(["success" => true, "message" => "Categoría actualizada"]);
  } else {
    echo json_encode(["success" => false, "error" => "No se encontró la categoría o no hubo cambios"]);
  }
} else {
  if ($con->errno == 1062) {
    echo json_encode(["success" => false, "error" => "Ya existe una categoría con ese nombre en la organización"]);
  } else {
    echo json_encode(["success" => false, "error" => "Error al actualizar: " . $con->error]);
  }
}

$stmt->close();
$con->close();
