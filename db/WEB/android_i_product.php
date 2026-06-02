<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$path = realpath("/home/site/wwwroot/db/conn/Conn_android.php");
if ($path && file_exists($path)) { include $path; }
else { http_response_code(500); echo json_encode(["ok"=>false,"error"=>"CONN_FILE_NOT_FOUND","path"=>$path]); exit; }

$con = function_exists('conectar_android') ? conectar_android()
     : (function_exists('conectar') ? conectar() : null);
if (!$con) { http_response_code(500); echo json_encode(["ok"=>false,"error"=>"DB_CONNECT_FAILED"]); exit; }
mysqli_set_charset($con,"utf8mb4");

$input = json_decode(file_get_contents("php://input"), true) ?: [];
function param($k,$d=null){ global $input; return isset($_GET[$k])?$_GET[$k]:(array_key_exists($k,$input)?$input[$k]:$d); }

// Requeridos
$name = trim((string) param('name',''));
$price = param('price', null);
$categoryId = param('category_id', null);
$stock = param('stock', null);

if ($name === '' || $price === null || $categoryId === null || $stock === null) {
    http_response_code(400);
    echo json_encode(["ok"=>false,"error"=>"Faltan campos requeridos: name, price, category_id, stock"]);
    mysqli_close($con);
    exit;
}

// Opcionales
$image = param('image_url', null);
$status = intval(param('status', param('active', 1))) ? 1 : 0;

$nameEsc = mysqli_real_escape_string($con, $name);
$price = (float)$price;
$categoryId = (int)$categoryId;
$stock = (int)$stock;

$cols = "name, price, category_id, stock, is_active, created_at, updated_at";
$vals = "'$nameEsc', $price, $categoryId, $stock, $status, NOW(), NOW()";
if ($image !== null && $image !== '') {
    $imgEsc = mysqli_real_escape_string($con, (string)$image);
    $cols .= ", image_url";
    $vals .= ", '$imgEsc'";
}

$sql = "INSERT INTO products ($cols) VALUES ($vals)";

if (!mysqli_query($con, $sql)) {
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"INSERT_FAILED","message"=>mysqli_error($con)]);
    mysqli_close($con);
    exit;
}

$id = (int) mysqli_insert_id($con);
echo json_encode(["ok"=>true, "id"=>$id], JSON_UNESCAPED_UNICODE);
mysqli_close($con);
