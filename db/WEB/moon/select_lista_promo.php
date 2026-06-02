<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
include $path;

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) { $in = []; }

if (empty($in['Id_company'])) {
  echo json_encode(["success" => false, "error" => "Falta parámetro obligatorio: Id_company"]);
  exit;
}

$Id_company = (int)$in['Id_company'];
$id_promo   = !empty($in['id_promo']) ? (int)$in['id_promo'] : null;

$con = conectar();
$con->set_charset('utf8mb4');

// Hacemos JOIN con clientes, productos y compuestos
$sql = "SELECT lp.*, 
               p.nombre AS nombre_promocion,
               c.customer_name, 
               c.full_name,
               prod.name AS producto_nombre,
               comp.id_compuesto AS compuesto_nombre
        FROM `moon_lista_promo` lp
        INNER JOIN `moon_promo` p ON lp.id_promo = p.id
        LEFT JOIN `moon_customer` c ON lp.id_cliente = c.id
        LEFT JOIN `moon_product` prod ON lp.id_producto = prod.id
        LEFT JOIN `moon_producto_compuesto` comp ON lp.id_compuesto = comp.id
        WHERE lp.Id_company = ?";

$types = "i";
$args = [$Id_company];

if ($id_promo !== null) {
    $sql .= " AND lp.id_promo = ?";
    $types .= "i";
    $args[] = $id_promo;
}

$stmt = $con->prepare($sql);
$stmt->bind_param($types, ...$args);
$stmt->execute();
$result = $stmt->get_result();

$lista_promo = [];
while ($row = $result->fetch_assoc()) {
    // Casteo seguro para Swift
    $row['id'] = (int)$row['id'];
    $row['id_promo'] = (int)$row['id_promo'];
    $row['id_cliente'] = $row['id_cliente'] !== null ? (int)$row['id_cliente'] : null;
    $row['id_producto'] = $row['id_producto'] !== null ? (int)$row['id_producto'] : null;
    $row['id_compuesto'] = $row['id_compuesto'] !== null ? (int)$row['id_compuesto'] : null;
    $row['cantidad'] = $row['cantidad'] !== null ? (int)$row['cantidad'] : null;
    
    $lista_promo[] = $row;
}

echo json_encode(["success" => true, "data" => $lista_promo]);
$stmt->close();
$con->close();