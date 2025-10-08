<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EmailSnippets.php';
require_once __DIR__ . '/lib/UserContext.php';

header('Content-Type: application/json');

// Require login
$u = current_user();
if (!$u) {
  echo json_encode(['error' => 'Login required']);
  exit;
}

// Check if user is an approver
if (!UserManagement::isApprover((int)$u['id'])) {
  echo json_encode(['error' => 'Access restricted to key 3 positions']);
  exit;
}

$snippetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($snippetId <= 0) {
  echo json_encode(['error' => 'Invalid snippet ID']);
  exit;
}

$ctx = UserContext::getLoggedInUserContext();

try {
  $snippet = EmailSnippets::getSnippet($ctx, $snippetId);
  
  if (!$snippet) {
    echo json_encode(['error' => 'Snippet not found']);
    exit;
  }
  
  echo json_encode([
    'id' => (int)$snippet['id'],
    'name' => $snippet['name'],
    'value' => $snippet['value'],
    'sort_order' => (int)$snippet['sort_order']
  ]);
} catch (Exception $e) {
  echo json_encode(['error' => $e->getMessage()]);
}
