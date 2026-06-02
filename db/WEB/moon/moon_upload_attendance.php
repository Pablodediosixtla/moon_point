<?php
// CORS opcional si consumes desde app y web:
header('Content-Type: application/json');

$path = realpath("/home/site/wwwroot/db/conn/Conexion.php");
if ($path && file_exists($path)) {
  include $path;
} else {
  http_response_code(500);
  echo json_encode(["success" => false, "error" => "No se encontró Conexion.php"]);
  exit;
}

try {
  // Validar campos multipart
  if (!isset($_POST['organization_id']) || !isset($_POST['employee_id'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Faltan organization_id o employee_id"]);
    exit;
  }
  $orgId = (int)$_POST['organization_id'];
  $empId = (int)$_POST['employee_id'];

  if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $e = isset($_FILES['photo']['error']) ? $_FILES['photo']['error'] : 'nofile';
    echo json_encode(["success" => false, "error" => "Archivo inválido (photo). Código: ".$e]);
    exit;
  }

  // Validaciones del archivo
  $tmp  = $_FILES['photo']['tmp_name'];
  $size = (int)$_FILES['photo']['size'];
  if ($size <= 0 || $size > 6 * 1024 * 1024) { // máx 6MB
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Tamaño inválido (máx 6MB)"]);
    exit;
  }

  // Asegurar MIME de imagen
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $tmp);
  finfo_close($finfo);
  $allowed = ["image/jpeg","image/png","image/webp","image/heic","image/heif"];
  if (!in_array($mime, $allowed)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "MIME no permitido: $mime"]);
    exit;
  }

  // Ruta física donde guardar
  // Ajusta si tu webroot es distinto (en Azure Linux suele ser /home/site/wwwroot)
  $baseDisk = "/home/site/wwwroot/uploads/attendance";
  $subdir   = sprintf("%d/%d/%s", $orgId, $empId, date('Y/m'));
  $dirPath  = $baseDisk . "/" . $subdir;

  if (!is_dir($dirPath)) {
    if (!mkdir($dirPath, 0775, true)) {
      http_response_code(500);
      echo json_encode(["success" => false, "error" => "No se pudo crear carpeta destino"]);
      exit;
    }
  }

  // Nombre único
  $ext = ".jpg";
  if ($mime === "image/png")  $ext = ".png";
  if ($mime === "image/webp") $ext = ".webp";
  if ($mime === "image/heic" || $mime === "image/heif") $ext = ".heic";

  $fname   = "selfie_" . date('Ymd_His') . "_" . bin2hex(random_bytes(4)) . $ext;
  $destAbs = $dirPath . "/" . $fname;

  if (!move_uploaded_file($tmp, $destAbs)) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "No se pudo mover el archivo"]);
    exit;
  }

  // URL pública (ajusta dominio/base según tu hosting)
  // Si Nginx/Apache sirve /uploads directamente desde wwwroot:
  $publicBase = "/uploads/attendance/" . $subdir . "/" . $fname;

  echo json_encode([
    "success" => true,
    "url"     => $publicBase
  ]);
} catch (Throwable $th) {
  http_response_code(500);
  echo json_encode(["success" => false, "error" => $th->getMessage()]);
}
