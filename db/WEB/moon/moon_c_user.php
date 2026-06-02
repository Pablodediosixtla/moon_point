<?php
header('Content-Type: application/json');

// ===============================
// 1. Validar método
// ===============================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode([
    "success" => false,
    "error"   => "Método no permitido. Usa POST."
  ]);
  exit;
}

// ===============================
// 2. Incluir conexión
// ===============================
$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if (!$path || !file_exists($path)) {
  echo json_encode([
    "success" => false,
    "error"   => "No se encontró Conexion.php en $path"
  ]);
  exit;
}
include $path;

// ===============================
// 3. Leer body JSON
// ===============================
$in_raw = file_get_contents("php://input");
$in = json_decode($in_raw, true);
if (!is_array($in)) { $in = []; }

// user_id es obligatorio
if (!isset($in['user_id']) || $in['user_id'] === "") {
  echo json_encode([
    "success" => false,
    "error"   => "Falta parámetro obligatorio: user_id"
  ]);
  exit;
}

$user_id = (int)$in['user_id'];

// ===============================
// 4. Conectar a la BD
// ===============================
$con = conectar();
if (!$con) {
  echo json_encode([
    "success" => false,
    "error"   => "No se pudo conectar a la base de datos"
  ]);
  exit;
}
$con->set_charset('utf8mb4');

// ===============================
// 5. Armar query
//    Tabla: moon_point.moon_user
//    Campos: id, admin_code
//    Solo usuarios activos (Status = 1)
// ===============================
$sql = "SELECT 
          id,
          admin_code
        FROM `moon_point`.`moon_user`
        WHERE id = ?
          AND Status = 1
        LIMIT 1";

$stmt = $con->prepare($sql);
if (!$stmt) {
  echo json_encode([
    "success" => false,
    "error"   => "Error al preparar consulta: " . $con->error
  ]);
  $con->close();
  exit;
}

$stmt->bind_param("i", $user_id);

// ===============================
// 6. Ejecutar
// ===============================
if (!$stmt->execute()) {
  echo json_encode([
    "success" => false,
    "error"   => "Error al ejecutar: " . $stmt->error
  ]);
  $stmt->close();
  $con->close();
  exit;
}

// ===============================
// 7. Formar respuesta
// ===============================
$res = $stmt->get_result();
$out = [];

while ($row = $res->fetch_assoc()) {
  $out[] = [
    "id"          => isset($row['id']) ? (int)$row['id'] : 0,
    "admin_code"  => isset($row['admin_code']) ? (int)$row['admin_code'] : 0
  ];
}

// ===============================
// 8. Enviar JSON
// ===============================
echo json_encode([
  "success" => true,
  "data"    => $out
  // (mantenemos el mismo shape que tus otros servicios:
  // si hay éxito no mandamos "error")
]);

$stmt->close();
$con->close();
