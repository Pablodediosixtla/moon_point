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

$id = (int)$input["id"];
$organization_id = (int)$input["organization_id"];

$con = conectar();
if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}

$sql = "DELETE FROM moon_categories WHERE id = ? AND organization_id = ?";
$stmt = $con->prepare($sql);
if (!$stmt) { echo json_encode(["success" => false, "error" => "Error al preparar consulta"]); $con->close(); exit; }
$stmt->bind_param("ii", $id, $organization_id);

if ($stmt->execute()) {
  if ($stmt->affected_rows > 0) {
    echo json_encode(["success" => true, "message" => "Categoría eliminada"]);
  } else {
    echo json_encode(["success" => false, "error" => "No se encontró la categoría para eliminar"]);
  }
} else {
  if ($con->errno == 1451) {
    echo json_encode(["success" => false, "error" => "No se puede eliminar: la categoría está referenciada por otros registros"]);
  } else {
    echo json_encode(["success" => false, "error" => "Error al eliminar: " . $con->error]);
  }
}

$stmt->close();
$con->close();
