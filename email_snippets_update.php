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

// Get form data
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$value = isset($_POST['value']) ? trim($_POST['value']) : '';
$sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;

if ($id <= 0) {
  echo json_encode(['error' => 'Invalid snippet ID']);
  exit;
}

if ($name === '' || $value === '') {
  echo json_encode(['error' => 'Name and value are required']);
  exit;
}

$ctx = UserContext::getLoggedInUserContext();

try {
  $success = EmailSnippets::updateSnippet($ctx, $id, $name, $value, $sortOrder);
  
  if ($success) {
    echo json_encode(['success' => true]);
  } else {
    echo json_encode(['error' => 'Failed to update snippet']);
  }
} catch (Exception $e) {
  echo json_encode(['error' => $e->getMessage()]);
}
