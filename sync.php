<?php
// SIAKAD ABSENSI - Automatic Cross-Device Server Sync Endpoint
// Cukup letakkan file ini di folder yang sama dengan index.html di hosting Anda.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$dataFile = __DIR__ . '/siakad_data.json';
$allowedKeys = [
  'settings', 'roles', 'users', 'students',
  'teachers', 'classes', 'majors', 'attendance', 'permissions'
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if ($method === 'GET') {
  if (!file_exists($dataFile)) {
    echo json_encode(['updatedAt' => 0, '_meta' => (object)[]]);
    exit;
  }
  $fp = fopen($dataFile, 'r');
  if ($fp && flock($fp, LOCK_SH)) {
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    echo ($content !== false && trim($content) !== '') ? $content : json_encode(['updatedAt' => 0, '_meta' => (object)[]]);
  } else {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal membaca file data']);
  }
  exit;
}

if ($method === 'POST' || $method === 'PUT') {
  $rawBody = file_get_contents('php://input');
  $incoming = json_decode($rawBody, true);
  if (!is_array($incoming)) {
    http_response_code(400);
    echo json_encode(['error' => 'Format JSON tidak valid']);
    exit;
  }

  $fp = fopen($dataFile, 'c+');
  if (!$fp || !flock($fp, LOCK_EX)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server tidak memiliki izin tulis (chmod 755/775 pada folder)']);
    exit;
  }

  $existingRaw = stream_get_contents($fp);
  $db = ($existingRaw !== false && trim($existingRaw) !== '') ? json_decode($existingRaw, true) : [];
  if (!is_array($db)) $db = [];
  if (!isset($db['_meta']) || !is_array($db['_meta'])) $db['_meta'] = [];

  $incomingTs = isset($incoming['updatedAt']) ? (int)$incoming['updatedAt'] : (int)(microtime(true) * 1000);
  $incomingDevice = isset($incoming['deviceId']) ? (string)$incoming['deviceId'] : '';
  $updates = isset($incoming['updates']) && is_array($incoming['updates']) ? $incoming['updates'] : $incoming;

  foreach ($allowedKeys as $key) {
    if (array_key_exists($key, $updates)) {
      $prevTs = isset($db['_meta'][$key]['updatedAt']) ? (int)$db['_meta'][$key]['updatedAt'] : 0;
      if ($incomingTs >= $prevTs) {
        $db[$key] = $updates[$key];
        $db['_meta'][$key] = [
          'updatedAt' => $incomingTs,
          'deviceId' => $incomingDevice
        ];
      }
    }
  }

  $db['updatedAt'] = max((int)($db['updatedAt'] ?? 0), $incomingTs);
  $db['deviceId'] = $incomingDevice;

  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($db, JSON_UNESCAPED_UNICODE));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  echo json_encode(['ok' => true, 'updatedAt' => $db['updatedAt']]);
  exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method tidak didukung']);
