<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/Search.php';

require_admin();
header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? (string)$_GET['q'] : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

try {
  $items = [];
  $qTrim = trim($q);
  if ($qTrim !== '') {
    $limit = max(1, min(100, $limit));
    
    // Search adults
    $adultRows = \UserManagement::searchAdults($qTrim, $limit);
    foreach ($adultRows as $r) {
      $first = (string)($r['first_name'] ?? '');
      $last  = (string)($r['last_name'] ?? '');
      $email = (string)($r['email'] ?? '');
      $label = trim($last . ', ' . $first) . ($email !== '' ? ' <' . $email . '>' : '');
      $items[] = [
        'id' => (int)$r['id'],
        'type' => 'adult',
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'label' => $label,
      ];
    }
    
    // Search youth if we haven't hit the limit
    if (count($items) < $limit) {
      $remainingLimit = $limit - count($items);
      $youthRows = searchYouth($qTrim, $remainingLimit);
      foreach ($youthRows as $r) {
        $first = (string)($r['first_name'] ?? '');
        $last  = (string)($r['last_name'] ?? '');
        $label = trim($last . ', ' . $first) . ' (child)';
        $items[] = [
          'id' => (int)$r['id'],
          'type' => 'youth',
          'first_name' => $first,
          'last_name' => $last,
          'label' => $label,
        ];
      }
    }
  }
  
  echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'items' => [], 'error' => 'search_failed'], JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Search youth by name (case-insensitive), for admin typeahead.
 */
function searchYouth(string $q, int $limit = 20): array {
  if (trim($q) === '') return [];
  
  $tokens = \Search::tokenize($q);
  if (empty($tokens)) return [];
  
  $params = [];
  $likeClause = \Search::buildAndLikeClause(['y.first_name', 'y.last_name'], $tokens, $params);
  
  $sql = "
    SELECT y.id, y.first_name, y.last_name
    FROM youth y
    WHERE 1=1 {$likeClause}
    ORDER BY y.last_name, y.first_name
    LIMIT ?
  ";
  $params[] = (int)$limit;
  
  $st = pdo()->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}
