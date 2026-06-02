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

if (!isset($input["organization_id"]) || $input["organization_id"] === "") {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: organization_id"]);
  exit;
}
$organization_id = (int)$input["organization_id"];

$con = conectar();
if (!$con) { echo json_encode(["success" => false, "error" => "No se pudo conectar a la base de datos"]); exit; }

/** GET ONE POR ID **/
if (isset($input["id"]) && $input["id"] !== "") {
  $id = (int)$input["id"];
  $sql = "SELECT id, organization_id, category_id, sku, name, description, price, cost, image_name,
                 is_active, sort_order, attributes, created_at, updated_at
          FROM moon_product
          WHERE id = ? AND organization_id = ?
          LIMIT 1";
  $stmt = $con->prepare($sql);
  if (!$stmt) { echo json_encode(["success" => false, "error" => "Error al preparar consulta"]); $con->close(); exit; }
  $stmt->bind_param("ii", $id, $organization_id);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      $row["id"] = (int)$row["id"];
      $row["organization_id"] = (int)$row["organization_id"];
      $row["category_id"] = (int)$row["category_id"];
      $row["price"] = (float)$row["price"];
      $row["cost"] = isset($row["cost"]) ? (float)$row["cost"] : null;
      $row["is_active"] = (int)$row["is_active"];
      $row["sort_order"] = (int)$row["sort_order"];
      // attributes: lo devolvemos como JSON si está
      if ($row["attributes"] !== null && $row["attributes"] !== "") {
        $decoded = json_decode($row["attributes"], true);
        if (json_last_error() === JSON_ERROR_NONE) { $row["attributes"] = $decoded; }
      }
      echo json_encode(["success" => true, "data" => $row]);
    } else {
      echo json_encode(["success" => false, "error" => "No se encontró el producto"]);
    }
  } else {
    echo json_encode(["success" => false, "error" => "Error al consultar: " . $con->error]);
  }
  $stmt->close();
  $con->close();
  exit;
}

/** LISTA CON FILTROS **/
$search      = isset($input["search"]) ? trim((string)$input["search"]) : "";
$category_id = isset($input["category_id"]) && $input["category_id"] !== "" ? (int)$input["category_id"] : null;
$is_active   = isset($input["is_active"]) && $input["is_active"] !== "" ? (int)$input["is_active"] : null;
$min_price   = isset($input["min_price"]) ? (float)$input["min_price"] : null;
$max_price   = isset($input["max_price"]) ? (float)$input["max_price"] : null;
$page        = isset($input["page"]) ? max(1, (int)$input["page"]) : 1;
$page_size   = isset($input["page_size"]) ? max(1, min(100, (int)$input["page_size"])) : 20;
$offset      = ($page - 1) * $page_size;

$where = ["organization_id = ?"];
$params = [$organization_id];
$types = "i";

if ($search !== "") {
  $where[] = "(name LIKE ? OR sku LIKE ? OR description LIKE ?)";
  $like = "%$search%";
  $params[] = $like; $types .= "s";
  $params[] = $like; $types .= "s";
  $params[] = $like; $types .= "s";
}
if ($category_id !== null) { $where[] = "category_id = ?"; $params[] = $category_id; $types .= "i"; }
if ($is_active !== null)   { $where[] = "is_active = ?";   $params[] = $is_active;   $types .= "i"; }
if ($min_price !== null)   { $where[] = "price >= ?";      $params[] = $min_price;   $types .= "d"; }
if ($max_price !== null)   { $where[] = "price <= ?";      $params[] = $max_price;   $types .= "d"; }

$where_sql = implode(" AND ", $where);

/* total */
$sql_count = "SELECT COUNT(*) AS total FROM moon_product WHERE $where_sql";
$stmt = $con->prepare($sql_count);
if (!$stmt) { echo json_encode(["success" => false, "error" => "Error al preparar conteo"]); $con->close(); exit; }
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$total = 0; if ($row = $res->fetch_assoc()) { $total = (int)$row["total"]; }
$stmt->close();

/* lista */
$sql = "SELECT id, organization_id, category_id, sku, name, description, price, cost, image_name,
               is_active, sort_order, attributes, created_at, updated_at
        FROM moon_product
        WHERE $where_sql
        ORDER BY sort_order ASC, name ASC
        LIMIT ? OFFSET ?";
$params_list = $params;
$types_list  = $types . "ii";
$params_list[] = $page_size;
$params_list[] = $offset;

$stmt2 = $con->prepare($sql);
if (!$stmt2) { echo json_encode(["success" => false, "error" => "Error al preparar listado"]); $con->close(); exit; }
$stmt2->bind_param($types_list, ...$params_list);

if ($stmt2->execute()) {
  $res2 = $stmt2->get_result();
  $rows = [];
  while ($r = $res2->fetch_assoc()) {
    $r["id"] = (int)$r["id"];
    $r["organization_id"] = (int)$r["organization_id"];
    $r["category_id"] = (int)$r["category_id"];
    $r["price"] = (float)$r["price"];
    $r["cost"]  = isset($r["cost"]) ? (float)$r["cost"] : null;
    $r["is_active"] = (int)$r["is_active"];
    $r["sort_order"] = (int)$r["sort_order"];
    if ($r["attributes"] !== null && $r["attributes"] !== "") {
      $decoded = json_decode($r["attributes"], true);
      if (json_last_error() === JSON_ERROR_NONE) { $r["attributes"] = $decoded; }
    }
    $rows[] = $r;
  }
  echo json_encode([
    "success" => true,
    "total" => $total,
    "page" => $page,
    "page_size" => $page_size,
    "data" => $rows
  ]);
} else {
  echo json_encode(["success" => false, "error" => "Error al listar: " . $con->error]);
}

$stmt2->close();
$con->close();
