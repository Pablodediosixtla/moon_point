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

$required = ["organization_id", "name"];
foreach ($required as $k) {
  if (!isset($input[$k]) || $input[$k] === "") {
    echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: $k"]);
    exit;
  }
}

$organization_id = (int)$input["organization_id"];
$name            = trim((string)$input["name"]);
$is_active       = isset($input["is_active"]) ? (int)$input["is_active"] : 1;
$sort_order      = isset($input["sort_order"]) ? (int)$input["sort_order"] : 0;
/**
 * image_name es opcional: si NO viene, dejamos que MySQL use el DEFAULT 'cat_default'.
 */
$image_name      = array_key_exists("image_name", $input) ? trim((string)$input["image_name"]) : null;

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}

if ($image_name !== null && $image_name !== "") {
  $sql = "INSERT INTO moon_categories (organization_id, name, image_name, is_active, sort_order)
          VALUES (?, ?, ?, ?, ?)";
  $stmt = $con->prepare($sql);
  if (!$stmt) { echo json_encode(["success" => false, "error" => "Error al preparar consulta"]); $con->close(); exit; }
  $stmt->bind_param("issii", $organization_id, $name, $image_name, $is_active, $sort_order);
} else {
  $sql = "INSERT INTO moon_categories (organization_id, name, is_active, sort_order)
          VALUES (?, ?, ?, ?)";
  $stmt = $con->prepare($sql);
  if (!$stmt) { echo json_encode(["success" => false, "error" => "Error al preparar consulta"]); $con->close(); exit; }
  $stmt->bind_param("isii", $organization_id, $name, $is_active, $sort_order);
}

if ($stmt->execute()) {
  echo json_encode([
    "success" => true,
    "message" => "Categoría creada",
    "id"      => (int)$stmt->insert_id
  ]);
} else {
  if ($con->errno == 1062) {
    echo json_encode(["success" => false, "error" => "Ya existe una categoría con ese nombre en la organización"]);
  } else {
    echo json_encode(["success" => false, "error" => "Error al insertar: " . $con->error]);
  }
}

$stmt->close();
$con->close();
