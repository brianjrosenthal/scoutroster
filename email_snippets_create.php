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
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$value = isset($_POST['value']) ? trim($_POST['value']) : '';

if ($name === '' || $value === '') {
  echo json_encode(['error' => 'Name and value are required']);
  exit;
}

$ctx = UserContext::getLoggedInUserContext();

try {
  $id = EmailSnippets::createSnippet($ctx, $name, $value);
  echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
  echo json_encode(['error' => $e->getMessage()]);
}
