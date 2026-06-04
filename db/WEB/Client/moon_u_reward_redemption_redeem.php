<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false, "error" => "Método no permitido. Usa POST."]);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if (!$path || !file_exists($path)) {
  echo json_encode(["success" => false, "error" => "No se encontró Conexion.php"]);
  exit;
}

include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

$organization_id = isset($in['organization_id']) ? (int)$in['organization_id'] : 0;
$redemption_code = isset($in['redemption_code']) ? strtoupper(trim((string)$in['redemption_code'])) : "";
$redeemed_by = isset($in['redeemed_by']) ? (int)$in['redeemed_by'] : null;

if ($organization_id <= 0) {
  echo json_encode(["success" => false, "error" => "Falta organization_id"]);
  exit;
}

if ($redemption_code === "") {
  echo json_encode(["success" => false, "error" => "Falta redemption_code"]);
  exit;
}

$con = conectar();

if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}

$con->set_charset('utf8mb4');

try {
  $con->begin_transaction();

  $sqlFind = "
    SELECT
      id,
      status,
      expires_at
    FROM moon_customer_reward_redemption
    WHERE organization_id = ?
      AND redemption_code = ?
    LIMIT 1
    FOR UPDATE
  ";

  $stmtFind = $con->prepare($sqlFind);

  if (!$stmtFind) {
    throw new Exception("Error al preparar búsqueda: " . $con->error);
  }

  $stmtFind->bind_param("is", $organization_id, $redemption_code);

  if (!$stmtFind->execute()) {
    $error = $stmtFind->error;
    $stmtFind->close();
    throw new Exception("Error al buscar código: " . $error);
  }

  $row = $stmtFind->get_result()->fetch_assoc();
  $stmtFind->close();

  if (!$row) {
    throw new Exception("Código no encontrado para esta sucursal.");
  }

  if ((int)$row['status'] !== 1) {
    throw new Exception("El código no está pendiente de canje.");
  }

  if ($row['expires_at'] !== null && strtotime($row['expires_at']) < time()) {
    $sqlExpire = "
      UPDATE moon_customer_reward_redemption
      SET status = 4, updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ";

    $stmtExpire = $con->prepare($sqlExpire);
    $redemption_id = (int)$row['id'];
    $stmtExpire->bind_param("i", $redemption_id);
    $stmtExpire->execute();
    $stmtExpire->close();

    throw new Exception("El código ya expiró.");
  }

  $redemption_id = (int)$row['id'];

  $sqlUpdate = "
    UPDATE moon_customer_reward_redemption
    SET
      status = 2,
      redeemed_at = NOW(),
      redeemed_by = ?,
      updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ";

  $stmtUpdate = $con->prepare($sqlUpdate);

  if (!$stmtUpdate) {
    throw new Exception("Error al preparar confirmación: " . $con->error);
  }

  $stmtUpdate->bind_param("ii", $redeemed_by, $redemption_id);

  if (!$stmtUpdate->execute()) {
    $error = $stmtUpdate->error;
    $stmtUpdate->close();
    throw new Exception("Error al confirmar canje: " . $error);
  }

  $stmtUpdate->close();

  $con->commit();
  $con->close();

  echo json_encode([
    "success" => true,
    "message" => "Canje confirmado correctamente.",
    "redemption_id" => $redemption_id,
    "redemption_code" => $redemption_code
  ]);

} catch (Throwable $e) {
  if ($con) {
    $con->rollback();
    $con->close();
  }

  echo json_encode([
    "success" => false,
    "error" => $e->getMessage()
  ]);
}