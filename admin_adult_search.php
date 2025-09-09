<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/UserManagement.php';

require_admin();
header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? (string)$_GET['q'] : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

try {
  $items = [];
  $qTrim = trim($q);
  if ($qTrim !== '') {
    $rows = \UserManagement::searchAdults($qTrim, max(1, min(100, $limit)));
    foreach ($rows as $r) {
      $first = (string)($r['first_name'] ?? '');
      $last  = (string)($r['last_name'] ?? '');
      $email = (string)($r['email'] ?? '');
      $label = trim($last . ', ' . $first) . ($email !== '' ? ' <' . $email . '>' : '');
      $items[] = [
        'id' => (int)$r['id'],
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'label' => $label,
      ];
    }
  }
  echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'items' => [], 'error' => 'search_failed'], JSON_UNESCAPED_SLASHES);
  exit;
}
