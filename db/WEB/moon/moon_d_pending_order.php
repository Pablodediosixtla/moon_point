<?php
// db/WEB/moon/moon_d_pending_order.php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if ($path && file_exists($path)) { include $path; }
else { echo json_encode(["success"=>false,"error"=>"No se encontró Conexion.php en la ruta $path"]); exit; }

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

$required = ["organization_id","id"];
foreach ($required as $k) {
  if (!isset($in[$k]) || $in[$k] === "") { echo json_encode(["success"=>false,"error"=>"Falta parámetro obligatorio: $k"]); exit; }
}

$organization_id = (int)$in["organization_id"];
$id              = (int)$in["id"];
$hard            = !empty($in["hard"]);  // 1=borra físico; 0=marca cancelado (status=9)

$con = conectar();
if (!$con) { echo json_encode(["success"=>false,"error"=>"No se pudo conectar a la base de datos"]); exit; }
$con->set_charset("utf8mb4");

try {
  $con->begin_transaction();

  if ($hard) {
    // Borrado físico
    $stmtD1 = $con->prepare("DELETE FROM moon_pending_order_item WHERE pending_order_id = ?");
    if (!$stmtD1) { throw new Exception("Error al preparar DELETE items"); }
    $stmtD1->bind_param("i", $id);
    if (!$stmtD1->execute()) { throw new Exception("Error al borrar items: ".$con->error); }
    $stmtD1->close();

    $stmtD2 = $con->prepare("DELETE FROM moon_pending_order WHERE id = ? AND organization_id = ?");
    if (!$stmtD2) { throw new Exception("Error al preparar DELETE header"); }
    $stmtD2->bind_param("ii", $id, $organization_id);
    if (!$stmtD2->execute()) { throw new Exception("Error al borrar orden: ".$con->error); }
    $stmtD2->close();
  } else {
    // Marcar como cancelado
    $newStatus = 9; // cancelado
    $stmtU = $con->prepare("UPDATE moon_pending_order SET status = ? WHERE id = ? AND organization_id = ?");
    if (!$stmtU) { throw new Exception("Error al preparar UPDATE status"); }
    $stmtU->bind_param("dii", $newStatus, $id, $organization_id); // d o i, ambos funcionan para int
    $stmtU->bind_param("iii", $newStatus, $id, $organization_id);
    if (!$stmtU->execute()) { throw new Exception("Error al actualizar status: ".$con->error); }
    $stmtU->close();
  }

  $con->commit();
  echo json_encode(["success"=>true,"message"=>$hard ? "Orden eliminada" : "Orden cancelada"]);
} catch (Throwable $e) {
  $con->rollback();
  echo json_encode(["success"=>false,"error"=>$e->getMessage()]);
}
$con->close();
