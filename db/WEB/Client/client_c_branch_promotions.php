<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$customer_access_id = isset($in['customer_access_id']) ? (int)$in['customer_access_id'] : 0;
$customer_branch_id = isset($in['customer_branch_id']) ? (int)$in['customer_branch_id'] : 0;
$include_general = isset($in['include_general']) ? (int)$in['include_general'] : 0;

if ($customer_access_id <= 0) {
  client_response(["success" => false, "error" => "El customer_access_id es obligatorio."], 400);
}

if ($customer_branch_id <= 0) {
  client_response(["success" => false, "error" => "El customer_branch_id es obligatorio."], 400);
}

function mp_clean_text($value) {
  if (!isset($value)) {
    return "";
  }

  $clean = trim((string)$value);

  if (strtolower($clean) === "null") {
    return "";
  }

  return $clean;
}

function mp_discount_text($porcentaje, $cantidad, $formato_descuento) {
  $porcentaje = (int)$porcentaje;
  $cantidad = (int)$cantidad;
  $formato_descuento = (int)$formato_descuento;

  if ($porcentaje > 0) {
    return $porcentaje . "% de descuento";
  }

  if ($cantidad > 0) {
    return "$" . $cantidad . " de descuento";
  }

  return "Promoción disponible";
}

try {
  $sqlBranch = "
    SELECT
      cb.id AS customer_branch_id,
      cb.customer_access_id,
      cb.organization_id,
      cb.moon_customer_id,
      cb.relation_status,
      ca.phone,
      ca.phone_normalized,
      ca.full_name,
      ca.email,
      COALESCE(NULLIF(bp.public_name, ''), NULLIF(u.Nombre, ''), 'Sucursal') AS branch_name
    FROM moon_customer_branch cb
    INNER JOIN moon_customer_access ca
      ON ca.id = cb.customer_access_id
    INNER JOIN moon_user u
      ON u.id = cb.organization_id
    INNER JOIN moon_branch_profile bp
      ON bp.organization_id = cb.organization_id
    WHERE cb.id = ?
      AND cb.customer_access_id = ?
      AND cb.relation_status = 1
      AND ca.deleted_at IS NULL
      AND ca.access_status = 1
      AND u.Status = 1
      AND bp.is_active = 1
    LIMIT 1
  ";

  $stmtBranch = $con->prepare($sqlBranch);

  if (!$stmtBranch) {
    throw new Exception("Error al preparar consulta de relación: " . $con->error);
  }

  $stmtBranch->bind_param("ii", $customer_branch_id, $customer_access_id);

  if (!$stmtBranch->execute()) {
    $error = $stmtBranch->error;
    $stmtBranch->close();
    throw new Exception("Error al consultar relación cliente/sucursal: " . $error);
  }

  $branch = $stmtBranch->get_result()->fetch_assoc();
  $stmtBranch->close();

  if (!$branch) {
    $con->close();
    client_response([
      "success" => false,
      "error" => "No se encontró la relación activa entre cliente y sucursal."
    ], 404);
  }

  $organization_id = (int)$branch['organization_id'];
  $relation_moon_customer_id = $branch['moon_customer_id'] !== null ? (int)$branch['moon_customer_id'] : 0;
  $phone_normalized = mp_clean_text($branch['phone_normalized']);
  $phone = mp_clean_text($branch['phone']);

  /*
    Homologación:
    1. Si moon_customer_branch.moon_customer_id existe, se prioriza.
    2. Si no, se busca por teléfono normalizado.
    3. Si no, se busca por teléfono original.
  */
  $customerWhere = [];
  $customerTypes = "";
  $customerParams = [];

  $customerSql = "
    SELECT
      id,
      organization_id,
      customer_name,
      full_name,
      phone,
      email,
      is_active
    FROM moon_customer
    WHERE organization_id = ?
      AND is_active = 1
      AND (
  ";

  $customerTypes .= "i";
  $customerParams[] = $organization_id;

  if ($relation_moon_customer_id > 0) {
    $customerWhere[] = "id = ?";
    $customerTypes .= "i";
    $customerParams[] = $relation_moon_customer_id;
  }

  if ($phone_normalized !== "") {
    $customerWhere[] = "phone = ?";
    $customerTypes .= "s";
    $customerParams[] = $phone_normalized;
  }

  if ($phone !== "" && $phone !== $phone_normalized) {
    $customerWhere[] = "phone = ?";
    $customerTypes .= "s";
    $customerParams[] = $phone;
  }

  if (empty($customerWhere)) {
    $con->close();
    client_response([
      "success" => true,
      "branch" => [
        "customer_branch_id" => $customer_branch_id,
        "organization_id" => $organization_id,
        "branch_name" => $branch['branch_name']
      ],
      "customer_found" => false,
      "total" => 0,
      "data" => []
    ]);
  }

  $customerSql .= implode(" OR ", $customerWhere);
  $customerSql .= "
      )
    ORDER BY
      CASE
        WHEN id = ? THEN 0
        ELSE 1
      END
    LIMIT 1
  ";

  $customerTypes .= "i";
  $customerParams[] = $relation_moon_customer_id;

  $stmtCustomer = $con->prepare($customerSql);

  if (!$stmtCustomer) {
    throw new Exception("Error al preparar homologación de cliente: " . $con->error);
  }

  $stmtCustomer->bind_param($customerTypes, ...$customerParams);

  if (!$stmtCustomer->execute()) {
    $error = $stmtCustomer->error;
    $stmtCustomer->close();
    throw new Exception("Error al homologar cliente por teléfono: " . $error);
  }

  $localCustomer = $stmtCustomer->get_result()->fetch_assoc();
  $stmtCustomer->close();

  if (!$localCustomer) {
    $con->close();
    client_response([
      "success" => true,
      "branch" => [
        "customer_branch_id" => $customer_branch_id,
        "organization_id" => $organization_id,
        "branch_name" => $branch['branch_name']
      ],
      "customer_found" => false,
      "total" => 0,
      "data" => []
    ]);
  }

  $moon_customer_id = (int)$localCustomer['id'];

  /*
    Si la relación no tenía moon_customer_id, se actualiza para dejarla homologada.
  */
  if ($relation_moon_customer_id <= 0) {
    $sqlUpdateRelation = "
      UPDATE moon_customer_branch
      SET
        moon_customer_id = ?,
        updated_at = NOW()
      WHERE id = ?
        AND customer_access_id = ?
      LIMIT 1
    ";

    $stmtUpdateRelation = $con->prepare($sqlUpdateRelation);

    if ($stmtUpdateRelation) {
      $stmtUpdateRelation->bind_param("iii", $moon_customer_id, $customer_branch_id, $customer_access_id);
      $stmtUpdateRelation->execute();
      $stmtUpdateRelation->close();
    }
  }

  $params = [];
  $types = "";

  $sqlPromos = "
    SELECT
      lp.id AS lista_promo_id,
      lp.id_promo,
      lp.id_cliente,
      lp.id_producto,
      lp.id_compuesto,
      lp.cantidad AS lista_cantidad,
      lp.start_date,
      lp.end_date,
      lp.Id_company,

      p.nombre AS promo_name,
      p.porcentaje,
      p.cantidad AS promo_cantidad,
      p.formato_descuento,
      p.status AS promo_status,

      pt.nombre AS promo_type_name
    FROM moon_lista_promo lp
    INNER JOIN moon_promo p
      ON p.id = lp.id_promo
     AND p.Id_company = lp.Id_company
    INNER JOIN moon_promo_type pt
      ON pt.id = p.promo_type
     AND pt.Id_company = p.Id_company
    WHERE lp.Id_company = ?
      AND p.status = 1
      AND (lp.start_date IS NULL OR lp.start_date <= CURDATE())
      AND (lp.end_date IS NULL OR lp.end_date >= CURDATE())
      AND (
        lp.id_cliente = ?
  ";

  $types .= "ii";
  $params[] = $organization_id;
  $params[] = $moon_customer_id;

  if ($include_general === 1) {
    $sqlPromos .= " OR lp.id_cliente IS NULL ";
  }

  $sqlPromos .= "
      )
    ORDER BY
      COALESCE(lp.end_date, '9999-12-31') ASC,
      lp.created_at DESC
  ";

  $stmtPromos = $con->prepare($sqlPromos);

  if (!$stmtPromos) {
    throw new Exception("Error al preparar promociones: " . $con->error);
  }

  $stmtPromos->bind_param($types, ...$params);

  if (!$stmtPromos->execute()) {
    $error = $stmtPromos->error;
    $stmtPromos->close();
    throw new Exception("Error al consultar promociones activas: " . $error);
  }

  $result = $stmtPromos->get_result();
  $data = [];

  while ($row = $result->fetch_assoc()) {
    $row['lista_promo_id'] = (int)$row['lista_promo_id'];
    $row['id_promo'] = (int)$row['id_promo'];
    $row['id_cliente'] = $row['id_cliente'] !== null ? (int)$row['id_cliente'] : null;
    $row['id_producto'] = $row['id_producto'] !== null ? (int)$row['id_producto'] : null;
    $row['id_compuesto'] = $row['id_compuesto'] !== null ? (int)$row['id_compuesto'] : null;
    $row['lista_cantidad'] = (int)$row['lista_cantidad'];
    $row['Id_company'] = (int)$row['Id_company'];
    $row['porcentaje'] = (int)$row['porcentaje'];
    $row['promo_cantidad'] = (int)$row['promo_cantidad'];
    $row['formato_descuento'] = (int)$row['formato_descuento'];
    $row['promo_status'] = (int)$row['promo_status'];
    $row['discount_text'] = mp_discount_text(
      $row['porcentaje'],
      $row['promo_cantidad'],
      $row['formato_descuento']
    );

    $data[] = $row;
  }

  $stmtPromos->close();
  $con->close();

  client_response([
    "success" => true,
    "branch" => [
      "customer_branch_id" => $customer_branch_id,
      "organization_id" => $organization_id,
      "branch_name" => $branch['branch_name']
    ],
    "customer_found" => true,
    "moon_customer_id" => $moon_customer_id,
    "total" => count($data),
    "data" => $data
  ]);

} catch (Throwable $e) {
  if ($con) {
    $con->close();
  }

  client_response([
    "success" => false,
    "error" => $e->getMessage()
  ], 500);
}