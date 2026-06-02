<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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

// Parámetros (GET o POST JSON)
$input = json_decode(file_get_contents("php://input"), true) ?: [];
$onlyActive = isset($_GET['only_active']) ? intval($_GET['only_active'])
            : (isset($input['only_active']) ? intval($input['only_active']) : 1);

$sql = "SELECT id, name, is_active, sort_order, created_at, updated_at
        FROM categories";
if ($onlyActive) $sql .= " WHERE is_active = 1";
$sql .= " ORDER BY sort_order ASC, name ASC";

$res = mysqli_query($con, $sql);
$items = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (int)$row['is_active'];
        $row['sort_order'] = (int)$row['sort_order'];
        $items[] = $row;
    }
    echo json_encode(["ok"=>true, "data"=>["items"=>$items, "count"=>count($items)]], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(["ok"=>false, "error"=>"QUERY_FAILED", "message"=>mysqli_error($con)]);
}
mysqli_close($con);
