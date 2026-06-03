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

if (!isset($in['organization_id']) || $in['organization_id'] === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: organization_id"]);
  exit;
}

if (!isset($in['customer_name']) || trim((string)$in['customer_name']) === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: customer_name"]);
  exit;
}

$organization_id = (int)$in['organization_id'];
$customer_name = trim((string)$in['customer_name']);

$full_name  = isset($in['full_name'])  ? trim((string)$in['full_name'])  : '';
$birth_date = isset($in['birth_date']) ? trim((string)$in['birth_date']) : '';
$phone      = isset($in['phone'])      ? trim((string)$in['phone'])      : '';
$email      = isset($in['email'])      ? trim((string)$in['email'])      : '';
$address    = isset($in['address'])    ? trim((string)$in['address'])    : '';

function normalize_phone_local($phone) {
  $digits = preg_replace('/\D+/', '', (string)$phone);

  if (strlen($digits) === 10) {
    return "52" . $digits;
  }

  if (strlen($digits) === 12 && substr($digits, 0, 2) === "52") {
    return $digits;
  }

  return $digits;
}

function get_initial_level_id_local($con, $organization_id) {
  $sql = "
    SELECT id
    FROM moon_branch_level
    WHERE organization_id = ?
      AND is_active = 1
    ORDER BY min_level_points ASC
    LIMIT 1
  ";

  $stmt = $con->prepare($sql);
  $stmt->bind_param("i", $organization_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return $row ? (int)$row['id'] : null;
}

function link_access_if_exists_local($con, $organization_id, $moon_customer_id, $phone, $phone_normalized) {
  if ($phone === "" && $phone_normalized === "") {
    return [
      "linked" => false,
      "customer_access_id" => null,
      "customer_branch_id" => null
    ];
  }

  $sqlAccess = "
    SELECT id
    FROM moon_customer_access
    WHERE deleted_at IS NULL
      AND access_status = 1
      AND (
        (? <> '' AND phone = ?)
        OR (? <> '' AND phone_normalized = ?)
        OR (? <> '' AND phone = ?)
      )
    LIMIT 1
  ";

  $stmt = $con->prepare($sqlAccess);
  $stmt->bind_param(
    "ssssss",
    $phone,
    $phone,
    $phone_normalized,
    $phone_normalized,
    $phone_normalized,
    $phone_normalized
  );
  $stmt->execute();
  $access = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$access) {
    return [
      "linked" => false,
      "customer_access_id" => null,
      "customer_branch_id" => null
    ];
  }

  $customer_access_id = (int)$access['id'];
  $current_level_id = get_initial_level_id_local($con, $organization_id);

  $sqlExisting = "
    SELECT id
    FROM moon_customer_branch
    WHERE customer_access_id = ?
      AND organization_id = ?
    LIMIT 1
  ";

  $stmt = $con->prepare($sqlExisting);
  $stmt->bind_param("ii", $customer_access_id, $organization_id);
  $stmt->execute();
  $existing = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($existing) {
    $customer_branch_id = (int)$existing['id'];

    $sqlUpdate = "
      UPDATE moon_customer_branch
      SET
        moon_customer_id = ?,
        current_level_id = COALESCE(current_level_id, ?),
        relation_status = 1,
        linked_by = 'branch_customer_api',
        updated_at = NOW()
      WHERE id = ?
    ";

    $stmt = $con->prepare($sqlUpdate);
    $stmt->bind_param("iii", $moon_customer_id, $current_level_id, $customer_branch_id);
    $stmt->execute();
    $stmt->close();

    return [
      "linked" => true,
      "customer_access_id" => $customer_access_id,
      "customer_branch_id" => $customer_branch_id
    ];
  }

  $sqlInsert = "
    INSERT INTO moon_customer_branch (
      customer_access_id,
      organization_id,
      moon_customer_id,
      current_level_id,
      level_points,
      reward_points_balance,
      total_purchases,
      total_amount,
      relation_status,
      linked_by
    ) VALUES (?, ?, ?, ?, 0, 0, 0, 0.00, 1, 'branch_customer_api')
  ";

  $stmt = $con->prepare($sqlInsert);
  $stmt->bind_param("iiii", $customer_access_id, $organization_id, $moon_customer_id, $current_level_id);
  $stmt->execute();
  $customer_branch_id = (int)$stmt->insert_id;
  $stmt->close();

  return [
    "linked" => true,
    "customer_access_id" => $customer_access_id,
    "customer_branch_id" => $customer_branch_id
  ];
}

$con = conectar();

if (!$con) {
  echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]);
  exit;
}

$con->set_charset('utf8mb4');

$phone_normalized = normalize_phone_local($phone);
$phone_to_save = $phone_normalized !== "" ? $phone_normalized : $phone;

try {
  $con->begin_transaction();

  $sqlFind = "
    SELECT id
    FROM moon_customer
    WHERE organization_id = ?
      AND (
        customer_name = ?
        OR (? <> '' AND phone = ?)
        OR (? <> '' AND phone = ?)
      )
    LIMIT 1
  ";

  $stmt = $con->prepare($sqlFind);
  $stmt->bind_param(
    "isssss",
    $organization_id,
    $customer_name,
    $phone,
    $phone,
    $phone_to_save,
    $phone_to_save
  );
  $stmt->execute();
  $existing = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($existing) {
    $moon_customer_id = (int)$existing['id'];

    $sqlUpdate = "
      UPDATE moon_customer
      SET
        full_name = NULLIF(?, ''),
        birth_date = NULLIF(?, ''),
        phone = NULLIF(?, ''),
        email = NULLIF(?, ''),
        address = NULLIF(?, ''),
        is_active = 1,
        updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ";

    $stmt = $con->prepare($sqlUpdate);
    $stmt->bind_param(
      "sssssi",
      $full_name,
      $birth_date,
      $phone_to_save,
      $email,
      $address,
      $moon_customer_id
    );

    if (!$stmt->execute()) {
      $error = $stmt->error;
      $stmt->close();
      throw new Exception("Error al actualizar cliente: " . $error);
    }

    $stmt->close();

    $linkResult = link_access_if_exists_local(
      $con,
      $organization_id,
      $moon_customer_id,
      $phone,
      $phone_to_save
    );

    $con->commit();
    $con->close();

    echo json_encode([
      "success" => true,
      "message" => "Cliente existente actualizado/reactivado",
      "id" => $moon_customer_id,
      "already_exists" => true,
      "linked_customer_access" => $linkResult["linked"],
      "customer_access_id" => $linkResult["customer_access_id"],
      "customer_branch_id" => $linkResult["customer_branch_id"]
    ]);
    exit;
  }

  $sqlInsert = "
    INSERT INTO moon_customer (
      organization_id,
      customer_name,
      full_name,
      birth_date,
      phone,
      email,
      address,
      is_active
    ) VALUES (?, ?, NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), 1)
  ";

  $stmt = $con->prepare($sqlInsert);

  if (!$stmt) {
    throw new Exception("Error al preparar consulta: " . $con->error);
  }

  $stmt->bind_param(
    "issssss",
    $organization_id,
    $customer_name,
    $full_name,
    $birth_date,
    $phone_to_save,
    $email,
    $address
  );

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al insertar: " . $error);
  }

  $moon_customer_id = (int)$stmt->insert_id;
  $stmt->close();

  $linkResult = link_access_if_exists_local(
    $con,
    $organization_id,
    $moon_customer_id,
    $phone,
    $phone_to_save
  );

  $con->commit();
  $con->close();

  echo json_encode([
    "success" => true,
    "message" => "Cliente creado",
    "id" => $moon_customer_id,
    "already_exists" => false,
    "linked_customer_access" => $linkResult["linked"],
    "customer_access_id" => $linkResult["customer_access_id"],
    "customer_branch_id" => $linkResult["customer_branch_id"]
  ]);
  exit;

} catch (Throwable $e) {
  if ($con) {
    $con->rollback();
    $con->close();
  }

  echo json_encode([
    "success" => false,
    "error" => $e->getMessage()
  ]);
  exit;
}