<?php
require_once __DIR__ . "/_client_common.php";

client_require_post();

$in = client_input();
$con = client_connect();

$worker_secret = getenv("MOON_PUSH_WORKER_SECRET") ?: "";
$received_secret = "";

if (isset($_SERVER["HTTP_X_CRON_SECRET"])) {
  $received_secret = trim((string)$_SERVER["HTTP_X_CRON_SECRET"]);
}

if ($received_secret === "" && isset($in["cron_secret"])) {
  $received_secret = trim((string)$in["cron_secret"]);
}

if ($worker_secret === "" || $received_secret === "" || !hash_equals($worker_secret, $received_secret)) {
  $con->close();
  client_response(["success" => false, "error" => "No autorizado."], 401);
}

$limit = isset($in["limit"]) ? (int)$in["limit"] : 20;
$limit = max(1, min($limit, 50));

$max_attempts = isset($in["max_attempts"]) ? (int)$in["max_attempts"] : 5;
$max_attempts = max(1, min($max_attempts, 10));

$apns_key_id = getenv("APNS_KEY_ID") ?: "";
$apns_team_id = getenv("APNS_TEAM_ID") ?: "";
$apns_bundle_id = getenv("APNS_BUNDLE_ID") ?: "";
$apns_auth_key_path = getenv("APNS_AUTH_KEY_PATH") ?: "";
$apns_env = getenv("APNS_ENV") ?: "sandbox";

if ($apns_key_id === "" || $apns_team_id === "" || $apns_bundle_id === "" || $apns_auth_key_path === "") {
  $con->close();
  client_response([
    "success" => false,
    "error" => "Faltan variables APNs: APNS_KEY_ID, APNS_TEAM_ID, APNS_BUNDLE_ID o APNS_AUTH_KEY_PATH."
  ], 500);
}

if (!file_exists($apns_auth_key_path)) {
  $con->close();
  client_response([
    "success" => false,
    "error" => "No existe el archivo APNs .p8 en APNS_AUTH_KEY_PATH."
  ], 500);
}

function mp_base64url_encode($data) {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function mp_der_to_jose_signature($der, $partLength = 32) {
  $offset = 0;

  if (ord($der[$offset]) !== 0x30) {
    throw new Exception("Firma DER inválida.");
  }

  $offset++;

  $seqLength = ord($der[$offset]);
  $offset++;

  if ($seqLength & 0x80) {
    $numBytes = $seqLength & 0x7f;
    $seqLength = 0;

    for ($i = 0; $i < $numBytes; $i++) {
      $seqLength = ($seqLength << 8) | ord($der[$offset]);
      $offset++;
    }
  }

  if (ord($der[$offset]) !== 0x02) {
    throw new Exception("Firma DER inválida en R.");
  }

  $offset++;
  $rLength = ord($der[$offset]);
  $offset++;
  $r = substr($der, $offset, $rLength);
  $offset += $rLength;

  if (ord($der[$offset]) !== 0x02) {
    throw new Exception("Firma DER inválida en S.");
  }

  $offset++;
  $sLength = ord($der[$offset]);
  $offset++;
  $s = substr($der, $offset, $sLength);

  $r = ltrim($r, "\x00");
  $s = ltrim($s, "\x00");

  $r = str_pad($r, $partLength, "\x00", STR_PAD_LEFT);
  $s = str_pad($s, $partLength, "\x00", STR_PAD_LEFT);

  return $r . $s;
}

function mp_create_apns_jwt($teamId, $keyId, $privateKeyPath) {
  $privateKey = file_get_contents($privateKeyPath);

  if ($privateKey === false || trim($privateKey) === "") {
    throw new Exception("No se pudo leer la llave privada APNs.");
  }

  $header = [
    "alg" => "ES256",
    "kid" => $keyId
  ];

  $payload = [
    "iss" => $teamId,
    "iat" => time()
  ];

  $segments = [
    mp_base64url_encode(json_encode($header)),
    mp_base64url_encode(json_encode($payload))
  ];

  $unsignedToken = implode(".", $segments);

  $signatureDer = "";

  $ok = openssl_sign(
    $unsignedToken,
    $signatureDer,
    $privateKey,
    OPENSSL_ALGO_SHA256
  );

  if (!$ok) {
    throw new Exception("No se pudo firmar JWT APNs con openssl.");
  }

  $signatureJose = mp_der_to_jose_signature($signatureDer);

  return $unsignedToken . "." . mp_base64url_encode($signatureJose);
}

function mp_apns_host($env) {
  return strtolower($env) === "production"
    ? "https://api.push.apple.com"
    : "https://api.sandbox.push.apple.com";
}

function mp_send_apns($deviceToken, $jwt, $bundleId, $env, $title, $body, $payloadData) {
  $url = mp_apns_host($env) . "/3/device/" . $deviceToken;

  $custom = is_array($payloadData) ? $payloadData : [];

  $payload = array_merge($custom, [
    "aps" => [
      "alert" => [
        "title" => $title,
        "body" => $body
      ],
      "sound" => "default"
    ]
  ]);

  $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $headers = [
    "authorization: bearer " . $jwt,
    "apns-topic: " . $bundleId,
    "apns-push-type: alert",
    "apns-priority: 10",
    "content-type: application/json"
  ];

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);

  $rawResponse = curl_exec($ch);
  $curlError = curl_error($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

  curl_close($ch);

  if ($rawResponse === false) {
    return [
      "ok" => false,
      "http_code" => 0,
      "provider_message_id" => null,
      "error" => $curlError !== "" ? $curlError : "Error cURL desconocido.",
      "deactivate_token" => false
    ];
  }

  $rawHeaders = substr($rawResponse, 0, $headerSize);
  $responseBody = substr($rawResponse, $headerSize);

  $providerMessageId = null;

  foreach (explode("\r\n", $rawHeaders) as $headerLine) {
    if (stripos($headerLine, "apns-id:") === 0) {
      $providerMessageId = trim(substr($headerLine, strlen("apns-id:")));
      break;
    }
  }

  if ($httpCode === 200) {
    return [
      "ok" => true,
      "http_code" => $httpCode,
      "provider_message_id" => $providerMessageId,
      "error" => null,
      "deactivate_token" => false
    ];
  }

  $decoded = json_decode($responseBody, true);
  $reason = is_array($decoded) && isset($decoded["reason"]) ? $decoded["reason"] : $responseBody;

  $deactivateReasons = [
    "BadDeviceToken",
    "Unregistered",
    "DeviceTokenNotForTopic"
  ];

  return [
    "ok" => false,
    "http_code" => $httpCode,
    "provider_message_id" => $providerMessageId,
    "error" => $reason !== "" ? $reason : "APNs HTTP " . $httpCode,
    "deactivate_token" => in_array($reason, $deactivateReasons, true) || $httpCode === 410
  ];
}

try {
  $jwt = mp_create_apns_jwt($apns_team_id, $apns_key_id, $apns_auth_key_path);

  $sqlNotifications = "
    SELECT
      id,
      customer_access_id,
      customer_branch_id,
      organization_id,
      notification_type,
      title,
      body,
      payload_json,
      attempts
    FROM moon_customer_notification
    WHERE status = 0
      AND attempts < ?
    ORDER BY created_at ASC
    LIMIT ?
  ";

  $stmtNotifications = $con->prepare($sqlNotifications);

  if (!$stmtNotifications) {
    throw new Exception("Error al preparar consulta de notificaciones: " . $con->error);
  }

  $stmtNotifications->bind_param("ii", $max_attempts, $limit);

  if (!$stmtNotifications->execute()) {
    $error = $stmtNotifications->error;
    $stmtNotifications->close();
    throw new Exception("Error al consultar notificaciones pendientes: " . $error);
  }

  $resultNotifications = $stmtNotifications->get_result();
  $notifications = [];

  while ($row = $resultNotifications->fetch_assoc()) {
    $notifications[] = $row;
  }

  $stmtNotifications->close();

  $processed = 0;
  $sent = 0;
  $errors = 0;
  $details = [];

  foreach ($notifications as $notification) {
    $notification_id = (int)$notification["id"];
    $customer_access_id = (int)$notification["customer_access_id"];

    $processed++;

    $sqlIncreaseAttempt = "
      UPDATE moon_customer_notification
      SET attempts = attempts + 1,
          updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ";

    $stmtIncrease = $con->prepare($sqlIncreaseAttempt);
    $stmtIncrease->bind_param("i", $notification_id);
    $stmtIncrease->execute();
    $stmtIncrease->close();

    $sqlTokens = "
      SELECT id, device_token
      FROM moon_customer_device_token
      WHERE customer_access_id = ?
        AND is_active = 1
    ";

    $stmtTokens = $con->prepare($sqlTokens);
    $stmtTokens->bind_param("i", $customer_access_id);
    $stmtTokens->execute();
    $resultTokens = $stmtTokens->get_result();

    $tokens = [];

    while ($tokenRow = $resultTokens->fetch_assoc()) {
      $tokens[] = $tokenRow;
    }

    $stmtTokens->close();

    if (empty($tokens)) {
      $sqlNoToken = "
        UPDATE moon_customer_notification
        SET status = 2,
            last_error = 'No active device tokens',
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
      ";

      $stmtNoToken = $con->prepare($sqlNoToken);
      $stmtNoToken->bind_param("i", $notification_id);
      $stmtNoToken->execute();
      $stmtNoToken->close();

      $errors++;

      $details[] = [
        "notification_id" => $notification_id,
        "status" => "error",
        "error" => "No active device tokens"
      ];

      continue;
    }

    foreach ($tokens as $tokenRow) {
      $device_token_id = (int)$tokenRow["id"];

      $sqlInsertDelivery = "
        INSERT IGNORE INTO moon_customer_notification_delivery (
          notification_id,
          device_token_id,
          status
        ) VALUES (?, ?, 0)
      ";

      $stmtInsertDelivery = $con->prepare($sqlInsertDelivery);
      $stmtInsertDelivery->bind_param("ii", $notification_id, $device_token_id);
      $stmtInsertDelivery->execute();
      $stmtInsertDelivery->close();
    }

    $sqlDeliveries = "
      SELECT
        d.id AS delivery_id,
        d.device_token_id,
        dt.device_token
      FROM moon_customer_notification_delivery d
      INNER JOIN moon_customer_device_token dt
        ON dt.id = d.device_token_id
      WHERE d.notification_id = ?
        AND d.status = 0
        AND dt.is_active = 1
    ";

    $stmtDeliveries = $con->prepare($sqlDeliveries);
    $stmtDeliveries->bind_param("i", $notification_id);
    $stmtDeliveries->execute();
    $resultDeliveries = $stmtDeliveries->get_result();

    $deliveries = [];

    while ($deliveryRow = $resultDeliveries->fetch_assoc()) {
      $deliveries[] = $deliveryRow;
    }

    $stmtDeliveries->close();

    $notificationSentCount = 0;
    $notificationErrorCount = 0;
    $lastError = null;

    $payloadData = [];

    if (isset($notification["payload_json"]) && $notification["payload_json"] !== null && $notification["payload_json"] !== "") {
      $decodedPayload = json_decode($notification["payload_json"], true);
      $payloadData = is_array($decodedPayload) ? $decodedPayload : [];
    }

    foreach ($deliveries as $delivery) {
      $delivery_id = (int)$delivery["delivery_id"];
      $device_token_id = (int)$delivery["device_token_id"];
      $device_token = trim((string)$delivery["device_token"]);

      $apnsResult = mp_send_apns(
        $device_token,
        $jwt,
        $apns_bundle_id,
        $apns_env,
        (string)$notification["title"],
        (string)$notification["body"],
        $payloadData
      );

      if ($apnsResult["ok"]) {
        $notificationSentCount++;
        $sent++;

        $status = 1;
        $provider_message_id = $apnsResult["provider_message_id"];
        $last_error = null;

        $sqlUpdateDelivery = "
          UPDATE moon_customer_notification_delivery
          SET status = ?,
              provider_message_id = ?,
              last_error = NULL,
              attempts = attempts + 1,
              sent_at = NOW(),
              updated_at = NOW()
          WHERE id = ?
          LIMIT 1
        ";

        $stmtUpdateDelivery = $con->prepare($sqlUpdateDelivery);
        $stmtUpdateDelivery->bind_param("isi", $status, $provider_message_id, $delivery_id);
        $stmtUpdateDelivery->execute();
        $stmtUpdateDelivery->close();

      } else {
        $notificationErrorCount++;
        $errors++;

        $lastError = (string)$apnsResult["error"];
        $status = 2;
        $provider_message_id = $apnsResult["provider_message_id"];
        $last_error = $lastError;

        $sqlUpdateDelivery = "
          UPDATE moon_customer_notification_delivery
          SET status = ?,
              provider_message_id = ?,
              last_error = ?,
              attempts = attempts + 1,
              updated_at = NOW()
          WHERE id = ?
          LIMIT 1
        ";

        $stmtUpdateDelivery = $con->prepare($sqlUpdateDelivery);
        $stmtUpdateDelivery->bind_param("issi", $status, $provider_message_id, $last_error, $delivery_id);
        $stmtUpdateDelivery->execute();
        $stmtUpdateDelivery->close();

        if ($apnsResult["deactivate_token"]) {
          $sqlDeactivateToken = "
            UPDATE moon_customer_device_token
            SET is_active = 0,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
          ";

          $stmtDeactivateToken = $con->prepare($sqlDeactivateToken);
          $stmtDeactivateToken->bind_param("i", $device_token_id);
          $stmtDeactivateToken->execute();
          $stmtDeactivateToken->close();
        }
      }
    }

    if ($notificationSentCount > 0) {
      $sqlUpdateNotification = "
        UPDATE moon_customer_notification
        SET status = 1,
            sent_at = NOW(),
            last_error = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
      ";

      $lastNotificationError = $notificationErrorCount > 0 ? "Some deliveries failed: " . $lastError : null;

      $stmtUpdateNotification = $con->prepare($sqlUpdateNotification);
      $stmtUpdateNotification->bind_param("si", $lastNotificationError, $notification_id);
      $stmtUpdateNotification->execute();
      $stmtUpdateNotification->close();

      $details[] = [
        "notification_id" => $notification_id,
        "status" => "sent",
        "sent_deliveries" => $notificationSentCount,
        "error_deliveries" => $notificationErrorCount
      ];

    } else {
      $currentAttempts = ((int)$notification["attempts"]) + 1;
      $newStatus = $currentAttempts >= $max_attempts ? 2 : 0;

      $sqlUpdateNotification = "
        UPDATE moon_customer_notification
        SET status = ?,
            last_error = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
      ";

      $stmtUpdateNotification = $con->prepare($sqlUpdateNotification);
      $stmtUpdateNotification->bind_param("isi", $newStatus, $lastError, $notification_id);
      $stmtUpdateNotification->execute();
      $stmtUpdateNotification->close();

      $details[] = [
        "notification_id" => $notification_id,
        "status" => $newStatus === 2 ? "error" : "pending_retry",
        "error" => $lastError,
        "sent_deliveries" => 0,
        "error_deliveries" => $notificationErrorCount
      ];
    }
  }

  $con->close();

  client_response([
    "success" => true,
    "processed" => $processed,
    "sent" => $sent,
    "errors" => $errors,
    "details" => $details
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