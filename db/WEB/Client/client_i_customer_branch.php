<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$organization_id = isset($in['organization_id']) ? (int)$in['organization_id'] : 0;
$qr_token = isset($in['qr_token']) ? trim((string)$in['qr_token']) : "";
$linked_by = isset($in['linked_by']) ? trim((string)$in['linked_by']) : "manual";

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

if ($organization_id <= 0 && $qr_token === "") {
  client_response(["success" => false, "error" => "Debes enviar organization_id o qr_token."], 400);
}

if (!in_array($linked_by, ["manual", "qr", "search"], true)) {
  $linked_by = "manual";
}

function mp_clean_value($value) {
  if (!isset($value)) {
    return "";
  }

  return trim((string)$value);
}

function mp_customer_name_exists($con, $organization_id, $customer_name) {
  $sql = "
    SELECT id
    FROM moon_customer
    WHERE organization_id = ?
      AND customer_name = ?
    LIMIT 1
  ";

  $stmt = $con->prepare($sql);
  $stmt->bind_param("is", $organization_id, $customer_name);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return !empty($row);
}

function mp_random_digits($length = 19) {
  $digits = "";

  for ($i = 0; $i < $length; $i++) {
    if ($i === 0) {
      $digits .= (string)random_int(1, 9);
    } else {
      $digits .= (string)random_int(0, 9);
    }
  }

  return $digits;
}

function mp_generate_unique_customer_name($con, $organization_id, $prefix = "US") {
  $max_attempts = 20;

  for ($i = 0; $i < $max_attempts; $i++) {
    $candidate = $prefix . "-" . mp_random_digits(19);

    if (!mp_customer_name_exists($con, $organization_id, $candidate)) {
      return $candidate;
    }
  }

  $fallback = $prefix . "-" . date("ymdHis") . "-" . random_int(1000, 9999);

  if (strlen($fallback) > 64) {
    $fallback = substr($fallback, 0, 64);
  }

  return $fallback;
}

function mp_get_initial_level_id($con, $organization_id) {
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

function mp_upsert_moon_customer($con, $organization_id, $customer) {
  $raw_phone = mp_clean_value($customer['phone'] ?? "");
  $phone_normalized = mp_clean_value($customer['phone_normalized'] ?? "");

  if ($phone_normalized === "" && $raw_phone !== "") {
    $phone_normalized = client_normalize_phone($raw_phone);
  }

  $phone_for_customer = $phone_normalized !== "" ? $phone_normalized : $raw_phone;

  $email = mp_clean_value($customer['email'] ?? "");
  $full_name = mp_clean_value($customer['full_name'] ?? "");
  $birth_date = mp_clean_value($customer['birth_date'] ?? "");

  $sqlFind = "
    SELECT id, customer_name
    FROM moon_customer
    WHERE organization_id = ?
      AND (
        (? <> '' AND phone = ?)
        OR (? <> '' AND phone = ?)
        OR (? <> '' AND email = ?)
      )
    LIMIT 1
  ";

  $stmt = $con->prepare($sqlFind);
  $stmt->bind_param(
    "issssss",
    $organization_id,
    $phone_for_customer,
    $phone_for_customer,
    $raw_phone,
    $raw_phone,
    $email,
    $email
  );
  $stmt->execute();
  $existing = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($existing) {
    $moon_customer_id = (int)$existing['id'];
    $customer_name = (string)$existing['customer_name'];

    $sqlUpdate = "
      UPDATE moon_customer
      SET
        full_name = NULLIF(?, ''),
        birth_date = NULLIF(?, ''),
        phone = NULLIF(?, ''),
        email = NULLIF(?, ''),
        is_active = 1,
        updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ";

    $stmt = $con->prepare($sqlUpdate);
    $stmt->bind_param(
      "ssssi",
      $full_name,
      $birth_date,
      $phone_for_customer,
      $email,
      $moon_customer_id
    );

    if (!$stmt->execute()) {
      $error = $stmt->error;
      $stmt->close();
      throw new Exception("Error al actualizar moon_customer: " . $error);
    }

    $stmt->close();

    return [
      "moon_customer_id" => $moon_customer_id,
      "customer_name" => $customer_name,
      "created" => false
    ];
  }

  $customer_name = mp_generate_unique_customer_name($con, $organization_id, "US");
  $address = "";

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
    ) VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), 1)
  ";

  $stmt = $con->prepare($sqlInsert);
  $stmt->bind_param(
    "issssss",
    $organization_id,
    $customer_name,
    $full_name,
    $birth_date,
    $phone_for_customer,
    $email,
    $address
  );

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al insertar moon_customer: " . $error);
  }

  $moon_customer_id = (int)$stmt->insert_id;
  $stmt->close();

  return [
    "moon_customer_id" => $moon_customer_id,
    "customer_name" => $customer_name,
    "created" => true
  ];
}

try {
  $sqlCustomer = "
    SELECT
      id,
      phone,
      phone_normalized,
      email,
      username,
      full_name,
      birth_date
    FROM moon_customer_access
    WHERE id = ?
      AND deleted_at IS NULL
      AND access_status = 1
    LIMIT 1
  ";

  $stmt = $con->prepare($sqlCustomer);
  $stmt->bind_param("i", $customer_access_id);
  $stmt->execute();
  $customer = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$customer) {
    $con->close();
    client_response(["success" => false, "error" => "No se encontró el cliente o no está activo."], 404);
  }

  if ($organization_id <= 0 && $qr_token !== "") {
    $sqlOrgByQR = "
      SELECT organization_id
      FROM moon_branch_profile
      WHERE qr_token = ?
        AND qr_enabled = 1
        AND is_active = 1
      LIMIT 1
    ";

    $stmt = $con->prepare($sqlOrgByQR);
    $stmt->bind_param("s", $qr_token);
    $stmt->execute();
    $branchQR = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$branchQR) {
      $con->close();
      client_response(["success" => false, "error" => "QR inválido o sucursal inactiva."], 404);
    }

    $organization_id = (int)$branchQR['organization_id'];
  }

  $sqlBranch = "
    SELECT
      u.id,
      u.Nombre,
      bp.public_name
    FROM moon_user u
    INNER JOIN moon_branch_profile bp
      ON bp.organization_id = u.id
    WHERE u.id = ?
      AND u.Status = 1
      AND bp.is_active = 1
    LIMIT 1
  ";

  $stmt = $con->prepare($sqlBranch);
  $stmt->bind_param("i", $organization_id);
  $stmt->execute();
  $branch = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$branch) {
    $con->close();
    client_response(["success" => false, "error" => "No se encontró la sucursal o no está activa."], 404);
  }

  $con->begin_transaction();

  $customerResult = mp_upsert_moon_customer($con, $organization_id, $customer);

  $moon_customer_id = (int)$customerResult["moon_customer_id"];
  $moon_customer_name = (string)$customerResult["customer_name"];
  $moon_customer_created = (bool)$customerResult["created"];

  $current_level_id = mp_get_initial_level_id($con, $organization_id);

  /*
    Importante:
    No se borra ni reinicia la relación si ya existe.
    Esto conserva:
    - level_points
    - reward_points_balance
    - total_purchases
    - total_amount
    - historial de movimientos
    - current_level_id si ya existía
  */
  $sqlExisting = "
    SELECT
      id,
      relation_status
    FROM moon_customer_branch
    WHERE customer_access_id = ?
      AND organization_id = ?
    LIMIT 1
  ";

  $stmt = $con->prepare($sqlExisting);
  $stmt->bind_param("ii", $customer_access_id, $organization_id);
  $stmt->execute();
  $existingRelation = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($existingRelation) {
    $relation_id = (int)$existingRelation['id'];
    $previous_relation_status = isset($existingRelation['relation_status']) ? (int)$existingRelation['relation_status'] : 0;
    $was_reactivated = $previous_relation_status !== 1;

    $sqlUpdateRelation = "
      UPDATE moon_customer_branch
      SET
        moon_customer_id = ?,
        current_level_id = COALESCE(current_level_id, ?),
        relation_status = 1,
        linked_by = ?,
        linked_at = NOW(),
        updated_at = NOW()
      WHERE id = ?
    ";

    $stmt = $con->prepare($sqlUpdateRelation);
    $stmt->bind_param(
      "iisi",
      $moon_customer_id,
      $current_level_id,
      $linked_by,
      $relation_id
    );

    if (!$stmt->execute()) {
      $error = $stmt->error;
      $stmt->close();
      throw new Exception("Error al actualizar relación sucursal-cliente: " . $error);
    }

    $stmt->close();
    $con->commit();
    $con->close();

    client_response([
      "success" => true,
      "message" => $was_reactivated ? "Sucursal reactivada correctamente." : "La sucursal ya estaba enlazada.",
      "already_linked" => !$was_reactivated,
      "reactivated" => $was_reactivated,
      "id" => $relation_id,
      "moon_customer_id" => $moon_customer_id,
      "moon_customer_name" => $moon_customer_name,
      "moon_customer_created" => $moon_customer_created,
      "data" => [
        "id" => $relation_id,
        "customer_access_id" => $customer_access_id,
        "organization_id" => $organization_id,
        "moon_customer_id" => $moon_customer_id,
        "moon_customer_name" => $moon_customer_name,
        "branch_name" => $branch['public_name'] ?: $branch['Nombre'],
        "linked_by" => $linked_by,
        "relation_status" => 1,
        "already_linked" => !$was_reactivated,
        "reactivated" => $was_reactivated
      ]
    ]);
  }

  $sqlInsertRelation = "
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
    ) VALUES (?, ?, ?, ?, 0, 0, 0, 0.00, 1, ?)
  ";

  $stmt = $con->prepare($sqlInsertRelation);
  $stmt->bind_param(
    "iiiis",
    $customer_access_id,
    $organization_id,
    $moon_customer_id,
    $current_level_id,
    $linked_by
  );

  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new Exception("Error al enlazar sucursal: " . $error);
  }

  $new_id = (int)$stmt->insert_id;
  $stmt->close();

  $con->commit();
  $con->close();

  client_response([
    "success" => true,
    "message" => "Sucursal agregada correctamente.",
    "already_linked" => false,
    "reactivated" => false,
    "id" => $new_id,
    "moon_customer_id" => $moon_customer_id,
    "moon_customer_name" => $moon_customer_name,
    "moon_customer_created" => $moon_customer_created,
    "data" => [
      "id" => $new_id,
      "customer_access_id" => $customer_access_id,
      "organization_id" => $organization_id,
      "moon_customer_id" => $moon_customer_id,
      "moon_customer_name" => $moon_customer_name,
      "branch_name" => $branch['public_name'] ?: $branch['Nombre'],
      "linked_by" => $linked_by,
      "relation_status" => 1,
      "already_linked" => false,
      "reactivated" => false
    ]
  ]);

} catch (Throwable $e) {
  if ($con) {
    $con->rollback();
    $con->close();
  }

  client_response([
    "success" => false,
    "error" => $e->getMessage()
  ], 500);
}