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

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
  echo json_encode(['error' => 'Invalid CSRF token']);
  exit;
}

// Get snippet ID
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
  echo json_encode(['error' => 'Invalid snippet ID']);
  exit;
}

$ctx = UserContext::getLoggedInUserContext();

try {
  $success = EmailSnippets::deleteSnippet($ctx, $id);
  
  if ($success) {
    echo json_encode(['success' => true]);
  } else {
    echo json_encode(['error' => 'Failed to delete snippet']);
  }
} catch (Exception $e) {
  echo json_encode(['error' => $e->getMessage()]);
}
