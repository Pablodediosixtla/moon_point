<?php
header('Content-Type: application/json; charset=UTF-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

// --- Incluir conexión a la BD
$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if ($path && file_exists($path)) {
    include $path;
} else {
    die(json_encode(["error" => "No se encontró Conexion.php en la ruta $path"]));
}

// --- 1. Capturar datos de JSON o POST tradicional
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

// Si no llegó JSON válido, intentar leer como $_POST
if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

// --- 2. Validar parámetros obligatorios
if (empty($input['nombre_persona']) || empty($input['numero_tarjeta'])) {
    echo json_encode([
        "error" => "Faltan parámetros obligatorios: 'nombre_persona' y 'numero_tarjeta'",
        "rawInput" => $rawInput,
        "postData" => $_POST
    ]);
    exit;
}

$nombre_persona = trim($input['nombre_persona']);
$numero_tarjeta = trim($input['numero_tarjeta']);

// --- 3. Conexión a la BD
$con = conectar();
if (!$con) {
    die(json_encode(["error" => "No se pudo conectar a la base de datos"]));
}

// --- 4. Escapar datos
$nombre_persona = mysqli_real_escape_string($con, $nombre_persona);
$numero_tarjeta = mysqli_real_escape_string($con, $numero_tarjeta);

// --- 5. Llave secreta AES
$secret_key = "MiClaveUltraSecreta2025";

// --- 6. Insertar registro
$sql = "INSERT INTO moon_point.cliente_tarjeta (nombre_persona, tarjeta_encriptada)
        VALUES ('$nombre_persona', AES_ENCRYPT('$numero_tarjeta', '$secret_key'))";

if (mysqli_query($con, $sql)) {
    $id_cliente = mysqli_insert_id($con);
    echo json_encode([
        "status" => "success",
        "message" => "Cliente insertado correctamente",
        "id_cliente" => $id_cliente
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Error al insertar: " . mysqli_error($con)
    ]);
}

mysqli_close($con);
?>
