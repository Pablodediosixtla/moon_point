<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// === Cargar conexión estilo ejemplo ===
$path = realpath("/home/site/wwwroot/db/conn/Conn_android.php");
if ($path && file_exists($path)) {
    include $path;
} else {
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"CONN_FILE_NOT_FOUND","path"=>$path]);
    exit;
}

$con = function_exists('conectar_android') ? conectar_android()
     : (function_exists('conectar') ? conectar() : null);

if (!$con) {
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"DB_CONNECT_FAILED"]);
    exit;
}
mysqli_set_charset($con, "utf8mb4");

// === Util: leer JSON y GET de forma homogénea ===
$input = json_decode(file_get_contents("php://input"), true) ?: [];
function param($key, $default = null) {
    global $input;
    return isset($_GET[$key]) ? $_GET[$key]
         : (array_key_exists($key, $input) ? $input[$key] : $default);
}

// === Params ===
// Acepta 'id' (Android) o 'product_id' (compat)
$id = intval(param('id', param('product_id', 0)));
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["ok"=>false, "error"=>"id (o product_id) es requerido"]);
    exit;
}

// Campos opcionales
$sets = [];
// name
if (array_key_exists('name', $input) || isset($_GET['name'])) {
    $name = trim((string)param('name', ''));
    $nameEsc = mysqli_real_escape_string($con, $name);
    $sets[] = "name = '$nameEsc'";
}
// price
if (array_key_exists('price', $input) || isset($_GET['price'])) {
    $price = (float) param('price', 0);
    $sets[] = "price = ".($price+0);
}
// category_id
if (array_key_exists('category_id', $input) || isset($_GET['category_id'])) {
    $categoryId = intval(param('category_id', 0));
    $sets[] = "category_id = $categoryId";
}
// stock
if (array_key_exists('stock', $input) || isset($_GET['stock'])) {
    $stock = intval(param('stock', 0));
    $sets[] = "stock = $stock";
}
// image_url (permitir forzar NULL)
if (array_key_exists('image_url', $input) || isset($_GET['image_url'])) {
    $img = param('image_url', null);
    if ($img === null || $img === '') {
        $sets[] = "image_url = NULL";
    } else {
        $imgEsc = mysqli_real_escape_string($con, (string)$img);
        $sets[] = "image_url = '$imgEsc'";
    }
}
// status/active -> is_active
if (array_key_exists('status', $input) || isset($_GET['status']) ||
    array_key_exists('active', $input) || isset($_GET['active'])) {
    $active = intval(param('status', param('active', 1)));
    $active = ($active ? 1 : 0);
    $sets[] = "is_active = $active";
}

if (empty($sets)) {
    http_response_code(400);
    echo json_encode(["ok"=>false, "error"=>"No hay campos para actualizar"]);
    mysqli_close($con);
    exit;
}

$sets[] = "updated_at = NOW()";
$sql = "UPDATE products SET ".implode(", ", $sets)." WHERE id = $id";

if (!mysqli_query($con, $sql)) {
    http_response_code(500);
    echo json_encode(["ok"=>false, "error"=>"UPDATE_FAILED", "message"=>mysqli_error($con)]);
    mysqli_close($con);
    exit;
}

// Devuelve el registro actualizado (opcional)
$q = "SELECT
        p.id, p.sku, p.barcode, p.name, p.description,
        p.category_id, p.price, p.cost, p.stock, p.image_url,
        p.is_active, p.created_at, p.updated_at
      FROM products p
      WHERE p.id = $id
      LIMIT 1";
$res = mysqli_query($con, $q);
$item = null;
if ($res && ($row = mysqli_fetch_assoc($res))) {
    $row['id'] = (int)$row['id'];
    $row['category_id'] = (int)$row['category_id'];
    $row['is_active'] = (int)$row['is_active'];
    $row['stock'] = (int)$row['stock'];
    $row['price'] = (float)$row['price'];
    $row['cost'] = (float)$row['cost'];
    $item = $row;
}

echo json_encode(["ok"=>true, "id"=>$id, "item"=>$item], JSON_UNESCAPED_UNICODE);
mysqli_close($con);
