<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
  'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
  'HTTP_CLIENT_IP' => $_SERVER['HTTP_CLIENT_IP'] ?? null,
  'all_headers' => function_exists('getallheaders') ? getallheaders() : null,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
?>