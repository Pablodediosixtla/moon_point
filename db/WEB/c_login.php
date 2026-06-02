<?php
header('Content-Type: application/json');

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if ($path && file_exists($path)) {
    include $path;
} else {
    die(json_encode(["error" => "No se encontró Conexion.php en la ruta $path"]));
}

// Leer datos de entrada (JSON)
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['user_name']) || !isset($input['password_name'])) {
    die(json_encode(["error" => "Faltan parámetros obligatorios: 'user_name' y 'password_name'"]));
}

$user = trim($input['user_name']);
$pass = trim($input['password_name']);

$con = conectar();
if (!$con) {
    die(json_encode(["error" => "No se pudo conectar a la base de datos"]));
}

$user = mysqli_real_escape_string($con, $user);
$pass = mysqli_real_escape_string($con, $pass);

$query = "SELECT 
            id,
            Nombre,
            user_name,
            Status,
            admin_code,
            created_at,
            updated_at
          FROM moon_user
          WHERE user_name = '$user' AND password_name = '$pass' AND Status = 1
          LIMIT 1";

$result = mysqli_query($con, $query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $row['id'] = (int)$row['id'];
    $row['Status'] = (int)$row['Status'];
    $row['admin_code'] = (int)$row['admin_code'];

    echo json_encode($row);
} else {
    echo json_encode(["error" => "Usuario o contraseña incorrectos"]);
}

$con->close();
?>
