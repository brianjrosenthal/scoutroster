<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/UserManagement.php';

final class Reimbursements {
  private static function pdo(): PDO { return pdo(); }

  // ----- Roles / Permissions -----

  public static function isApprover(?UserContext $ctx): bool {
    if (!$ctx) return false;
    if ($ctx->admin) return true;
    return UserManagement::isApprover($ctx->id);
  }

  public static function canView(?UserContext $ctx, array $req): bool {
    if (!$ctx) return false;
    if ($ctx->admin) return true;
    if ((int)$req['created_by'] === (int)$ctx->id) return true;
    return self::isApprover($ctx);
  }

  public static function canComment(?UserContext $ctx, array $req): bool {
    return self::canView($ctx, $req);
  }

  // ----- Status / Transitions -----

  public static function allowedTransitionsFor(?UserContext $ctx, array $req): array {
    if (!$ctx) return [];
    $s = (string)$req['status'];
    $creator = (int)$req['created_by'] === (int)$ctx->id;
    $approver = self::isApprover($ctx);

    // Terminal states
    if ($s === 'rejected' || $s === 'revoked' || $s === 'paid') return [];

    $next = [];
    if ($s === 'submitted') {
      if ($creator) $next[] = 'revoked';
      if ($approver) {
        $next[] = 'more_info_requested';
        $next[] = 'approved';
        $next[] = 'rejected';
        $next[] = 'paid';
      }
    } elseif ($s === 'more_info_requested') {
      if ($creator) $next[] = 'resubmitted';
      if ($approver) {
        $next[] = 'approved';
        $next[] = 'rejected';
        $next[] = 'paid';
      }
    } elseif ($s === 'resubmitted') {
      if ($creator) $next[] = 'revoked';
      if ($approver) {
        $next[] = 'more_info_requested';
        $next[] = 'approved';
        $next[] = 'rejected';
        $next[] = 'paid';
      }
    } elseif ($s === 'approved') {
      if ($approver) {
        $next[] = 'paid';
      }
    }
    return $next;
  }

  private static function validStatus(string $s): bool {
    static $all = ['submitted','revoked','more_info_requested','resubmitted','approved','rejected','paid'];
    return in_array($s, $all, true);
  }

  // ----- CRUD / Actions -----

  public static function create(UserContext $ctx, string $title, ?string $description = null): int {
    if (!$ctx) throw new RuntimeException('Login required');
    $title = trim($title);
    if ($title === '') throw new InvalidArgumentException('Title is required.');
    $st = self::pdo()->prepare(
      "INSERT INTO reimbursement_requests (title, description, created_by, status, created_at, last_modified_at)
       VALUES (?, ?, ?, 'submitted', NOW(), NOW())"
    );
    $st->execute([$title, $description, (int)$ctx->id]);
    $newId = (int)self::pdo()->lastInsertId();

    // Best-effort notification; non-fatal on errors or if no recipients
    try {
      self::notifyNewRequest($newId);
    } catch (\Throwable $e) {
      // swallow to avoid blocking creation
    }

    return $newId;
  }

  public static function getById(int $id): ?array {
    $st = self::pdo()->prepare("SELECT * FROM reimbursement_requests WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
  }

  public static function getWithAuth(UserContext $ctx, int $id): array {
    $r = self::getById($id);
    if (!$r) throw new RuntimeException('Not found');
    if (!self::canView($ctx, $r)) throw new RuntimeException('Forbidden');
    return $r;
  }

  public static function listMine(UserContext $ctx, bool $includeAll = false): array {
    if (!$ctx) throw new RuntimeException('Login required');
    if ($includeAll && self::isApprover($ctx)) {
      $st = self::pdo()->query("SELECT * FROM reimbursement_requests ORDER BY last_modified_at DESC");
      return $st->fetchAll();
    }
    $st = self::pdo()->prepare("SELECT * FROM reimbursement_requests WHERE created_by=? ORDER BY last_modified_at DESC");
    $st->execute([(int)$ctx->id]);
    return $st->fetchAll();
  }

  public static function fetchFiles(UserContext $ctx, int $reqId): array {
    $req = self::getWithAuth($ctx, $reqId);
    $st = self::pdo()->prepare("SELECT f.*, u.first_name, u.last_name
                                FROM reimbursement_request_files f
                                JOIN users u ON u.id = f.created_by
                                WHERE f.reimbursement_request_id=?
                                ORDER BY f.created_at DESC");
    $st->execute([(int)$req['id']]);
    return $st->fetchAll();
  }

  public static function fetchComments(UserContext $ctx, int $reqId): array {
    $req = self::getWithAuth($ctx, $reqId);
    $st = self::pdo()->prepare("SELECT c.*, u.first_name, u.last_name
                                FROM reimbursement_request_comments c
                                JOIN users u ON u.id = c.created_by
                                WHERE c.reimbursement_request_id=?
                                ORDER BY c.created_at ASC");
    $st->execute([(int)$req['id']]);
    return $st->fetchAll();
  }

  public static function addComment(UserContext $ctx, int $reqId, string $text): void {
    if (!$ctx) throw new RuntimeException('Login required');
    $req = self::getWithAuth($ctx, $reqId);
    $text = trim($text);
    if ($text === '') throw new InvalidArgumentException('Comment is required.');
    $st = self::pdo()->prepare("INSERT INTO reimbursement_request_comments (reimbursement_request_id, created_by, created_at, status_changed_to, comment_text)
                                VALUES (?, ?, NOW(), NULL, ?)");
    $st->execute([(int)$req['id'], (int)$ctx->id, $text]);

    try {
      self::notifyNewComment((int)$req['id'], (int)$ctx->id, $text);
    } catch (\Throwable $e) {
      // ignore notification failures
    }
  }

  public static function changeStatus(UserContext $ctx, int $reqId, string $newStatus, string $comment): void {
    if (!$ctx) throw new RuntimeException('Login required');
    $req = self::getWithAuth($ctx, $reqId);
    $newStatus = trim($newStatus);
    if (!self::validStatus($newStatus)) throw new InvalidArgumentException('Invalid status.');
    $comment = trim($comment);
    if ($comment === '') throw new InvalidArgumentException('Comment is required.');
    $allowed = self::allowedTransitionsFor($ctx, $req);
    if (!in_array($newStatus, $allowed, true)) throw new RuntimeException('Transition not allowed.');

    $pdo = self::pdo();
    $pdo->beginTransaction();
    try {
      // Insert comment with status change
      $ins = $pdo->prepare("INSERT INTO reimbursement_request_comments (reimbursement_request_id, created_by, created_at, status_changed_to, comment_text)
                            VALUES (?, ?, NOW(), ?, ?)");
      $ins->execute([(int)$req['id'], (int)$ctx->id, $newStatus, $comment]);

      // Update request
      $upd = $pdo->prepare("UPDATE reimbursement_requests
                            SET status = ?, comment_from_last_status_change = ?, last_status_set_by = ?, last_status_set_at = NOW(), last_modified_at = NOW()
                            WHERE id = ?");
      $upd->execute([$newStatus, $comment, (int)$ctx->id, (int)$req['id']]);

      $pdo->commit();

      try {
        self::notifyStatusChange((int)$req['id'], (int)$ctx->id, $newStatus, $comment);
      } catch (\Throwable $e) {
        // ignore notification failures
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }

  // Files: record an already moved file path (page handles move_uploaded_file)
  public static function recordFile(UserContext $ctx, int $reqId, string $storedPath, string $originalFilename, ?string $description = null): void {
    if (!$ctx) throw new RuntimeException('Login required');
    $req = self::getWithAuth($ctx, $reqId); // permission check
    $st = self::pdo()->prepare("INSERT INTO reimbursement_request_files
      (reimbursement_request_id, original_filename, stored_path, description, created_by, created_at)
      VALUES (?, ?, ?, ?, ?, NOW())");
    $st->execute([(int)$req['id'], $originalFilename, $storedPath, $description, (int)$ctx->id]);

    try {
      self::notifyNewFile((int)$req['id'], (int)$ctx->id, $originalFilename, $description);
    } catch (\Throwable $e) {
      // ignore notification failures
    }
  }

  // ----- Utility for page logic -----

  public static function canUploadFiles(?UserContext $ctx, array $req): bool {
    return self::canView($ctx, $req); // spec: "All users should be able to add supporting files" -> any viewer
  }

  // ============ Notifications / Recipients ============

  // Internal: fetch all recipients (Treasurer/Cubmaster with email), safe if none
  private static function approverRecipients(): array {
    $st = self::pdo()->prepare(
      "SELECT DISTINCT u.id, u.email, u.first_name, u.last_name
       FROM adult_leadership_positions alp
       JOIN users u ON u.id = alp.adult_id
       WHERE alp.position IN ('Treasurer','Cubmaster') AND u.email IS NOT NULL"
    );
    $st->execute();
    $out = [];
    while ($r = $st->fetch()) {
      if (!empty($r['email'])) {
        $out[] = [
          'id' => (int)$r['id'],
          'email' => (string)$r['email'],
          'first_name' => (string)($r['first_name'] ?? ''),
          'last_name' => (string)($r['last_name'] ?? ''),
        ];
      }
    }
    return $out;
  }

  // Public: for UI display on reimbursements.php
  public static function listApproverRecipients(): array {
    return self::approverRecipients();
  }

  // Notify treasurer/cubmaster on new request
  private static function notifyNewRequest(int $reqId): void {
    // Load request and creator
    $st = self::pdo()->prepare("SELECT r.id, r.title, r.description, r.created_by FROM reimbursement_requests r WHERE r.id=? LIMIT 1");
    $st->execute([$reqId]);
    $r = $st->fetch();
    if (!$r) return;

    $stU = self::pdo()->prepare("SELECT first_name, last_name FROM users WHERE id=? LIMIT 1");
    $stU->execute([(int)$r['created_by']]);
    $u = $stU->fetch();
    $creatorName = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));

    $recips = self::approverRecipients();
    if (empty($recips)) return; // No recipients configured; do nothing

    // Build link
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url    = $scheme.'://'.$host.'/reimbursement_view.php?id='.(int)$r['id'];

    // Subject per spec
    $subject = 'Pack 440 New Reimbursement Request from ' . ($creatorName ?: 'Unknown');

    // Compose HTML body (escape values)
    require_once __DIR__ . '/../settings.php';
    require_once __DIR__ . '/../mailer.php';

    $safeTitle = htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8');
    $safeDesc  = nl2br(htmlspecialchars((string)($r['description'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $safeUrl   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $safeCreator = htmlspecialchars($creatorName, ENT_QUOTES, 'UTF-8');

    $html = '<p>A new reimbursement request has been submitted by <strong>'.$safeCreator.'</strong>.</p>'
          . '<p><strong>Title:</strong> '.$safeTitle.'</p>'
          . '<p><strong>Description:</strong><br>'.$safeDesc.'</p>'
          . '<p><a href="'.$safeUrl.'">View this request</a></p>';

    // Send to each recipient, ignore per-recipient failures
    foreach ($recips as $rcp) {
      $to = (string)$rcp['email'];
      $name = trim((string)($rcp['first_name'] ?? '').' '.(string)($rcp['last_name'] ?? ''));
      try {
        @send_email($to, $subject, $html, $name ?: $to);
      } catch (\Throwable $e) {
        // ignore
      }
    }
  }

  // Determine recipients for reimbursement events with initiator exclusion and deduplication
  private static function recipientsForEvent(int $reqId, int $byUserId): array {
    $st = self::pdo()->prepare("SELECT r.id, r.title, r.created_by FROM reimbursement_requests r WHERE r.id=? LIMIT 1");
    $st->execute([$reqId]);
    $r = $st->fetch();
    if (!$r) return [];

    $creatorId = (int)$r['created_by'];
    $recips = self::approverRecipients();

    if ($byUserId !== $creatorId) {
      // Include request creator (if they have an email) when an approver initiates the action
      $stU = self::pdo()->prepare("SELECT id, email, first_name, last_name FROM users WHERE id=? LIMIT 1");
      $stU->execute([$creatorId]);
      if ($u = $stU->fetch()) {
        if (!empty($u['email'])) {
          $recips[] = [
            'id' => (int)$u['id'],
            'email' => (string)$u['email'],
            'first_name' => (string)($u['first_name'] ?? ''),
            'last_name'  => (string)($u['last_name'] ?? ''),
          ];
        }
      }
    }

    // Exclude initiator and dedupe by user id; ensure non-empty email
    $out = [];
    $seen = [];
    foreach ($recips as $rcp) {
      $id = (int)$rcp['id'];
      $email = trim((string)($rcp['email'] ?? ''));
      if ($id === (int)$byUserId) continue;
      if ($email === '') continue;
      if (isset($seen[$id])) continue;
      $seen[$id] = true;
      $out[] = $rcp;
    }
    return $out;
  }

  private static function buildRequestLink(int $reqId): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme.'://'.$host.'/reimbursement_view.php?id='.$reqId;
  }

  private static function loadUserName(int $userId): string {
    $st = self::pdo()->prepare("SELECT first_name, last_name FROM users WHERE id=? LIMIT 1");
    $st->execute([$userId]);
    $u = $st->fetch();
    return trim((string)($u['first_name'] ?? '').' '.(string)($u['last_name'] ?? ''));
  }

  private static function notifyNewComment(int $reqId, int $byUserId, string $commentText): void {
    $st = self::pdo()->prepare("SELECT r.id, r.title FROM reimbursement_requests r WHERE r.id=? LIMIT 1");
    $st->execute([$reqId]);
    $r = $st->fetch();
    if (!$r) return;

    $recips = self::recipientsForEvent($reqId, $byUserId);
    if (empty($recips)) return;

    require_once __DIR__ . '/../settings.php';
    require_once __DIR__ . '/../mailer.php';

    $actorName = self::loadUserName($byUserId);
    $subject = 'Pack 440 New Comment on Reimbursement #'.(int)$r['id'].' by '.($actorName ?: 'Unknown');

    $safeTitle   = htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8');
    $safeComment = nl2br(htmlspecialchars($commentText, ENT_QUOTES, 'UTF-8'));
    $safeUrl     = htmlspecialchars(self::buildRequestLink((int)$r['id']), ENT_QUOTES, 'UTF-8');
    $safeActor   = htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8');

    $html = '<p><strong>'.$safeActor.'</strong> added a new comment on a reimbursement request.</p>'
          . '<p><strong>Title:</strong> '.$safeTitle.'</p>'
          . '<p><strong>Comment:</strong><br>'.$safeComment.'</p>'
          . '<p><a href="'.$safeUrl.'">View this request</a></p>';

    foreach ($recips as $rcp) {
      $to = (string)$rcp['email'];
      $name = trim((string)($rcp['first_name'] ?? '').' '.(string)($rcp['last_name'] ?? ''));
      try {
        @send_email($to, $subject, $html, $name ?: $to);
      } catch (\Throwable $e) {
        // ignore
      }
    }
  }

  private static function notifyNewFile(int $reqId, int $byUserId, string $originalFilename, ?string $description): void {
    $st = self::pdo()->prepare("SELECT r.id, r.title FROM reimbursement_requests r WHERE r.id=? LIMIT 1");
    $st->execute([$reqId]);
    $r = $st->fetch();
    if (!$r) return;

    $recips = self::recipientsForEvent($reqId, $byUserId);
    if (empty($recips)) return;

    require_once __DIR__ . '/../settings.php';
    require_once __DIR__ . '/../mailer.php';

    $actorName = self::loadUserName($byUserId);
    $subject = 'Pack 440 New File on Reimbursement #'.(int)$r['id'].' by '.($actorName ?: 'Unknown');

    $safeTitle = htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8');
    $safeFile  = htmlspecialchars($originalFilename, ENT_QUOTES, 'UTF-8');
    $safeDesc  = nl2br(htmlspecialchars((string)($description ?? ''), ENT_QUOTES, 'UTF-8'));
    $safeUrl   = htmlspecialchars(self::buildRequestLink((int)$r['id']), ENT_QUOTES, 'UTF-8');
    $safeActor = htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8');

    $html = '<p><strong>'.$safeActor.'</strong> uploaded a new file to a reimbursement request.</p>'
          . '<p><strong>Title:</strong> '.$safeTitle.'</p>'
          . '<p><strong>File:</strong> '.$safeFile.'</p>';
    if ($description !== null && $description !== '') {
      $html .= '<p><strong>Description:</strong><br>'.$safeDesc.'</p>';
    }
    $html .= '<p><a href="'.$safeUrl.'">View this request</a></p>';

    foreach ($recips as $rcp) {
      $to = (string)$rcp['email'];
      $name = trim((string)($rcp['first_name'] ?? '').' '.(string)($rcp['last_name'] ?? ''));
      try {
        @send_email($to, $subject, $html, $name ?: $to);
      } catch (\Throwable $e) {
        // ignore
      }
    }
  }

  private static function notifyStatusChange(int $reqId, int $byUserId, string $newStatus, string $commentText): void {
    $st = self::pdo()->prepare("SELECT r.id, r.title FROM reimbursement_requests r WHERE r.id=? LIMIT 1");
    $st->execute([$reqId]);
    $r = $st->fetch();
    if (!$r) return;

    $recips = self::recipientsForEvent($reqId, $byUserId);
    if (empty($recips)) return;

    require_once __DIR__ . '/../settings.php';
    require_once __DIR__ . '/../mailer.php';

    $actorName = self::loadUserName($byUserId);
    $statusLabel = strtoupper(str_replace('_', ' ', $newStatus));
    $subject = 'Pack 440 Reimbursement #'.(int)$r['id'].' Status Changed to '.$statusLabel.' by '.($actorName ?: 'Unknown');

    $safeTitle   = htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8');
    $safeComment = nl2br(htmlspecialchars($commentText, ENT_QUOTES, 'UTF-8'));
    $safeUrl     = htmlspecialchars(self::buildRequestLink((int)$r['id']), ENT_QUOTES, 'UTF-8');
    $safeActor   = htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8');
    $safeStatus  = htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8');

    $html = '<p><strong>'.$safeActor.'</strong> changed the reimbursement status to <strong>'.$safeStatus.'</strong>.</p>'
          . '<p><strong>Title:</strong> '.$safeTitle.'</p>'
          . '<p><strong>Comment:</strong><br>'.$safeComment.'</p>'
          . '<p><a href="'.$safeUrl.'">View this request</a></p>';

    foreach ($recips as $rcp) {
      $to = (string)$rcp['email'];
      $name = trim((string)($rcp['first_name'] ?? '').' '.(string)($rcp['last_name'] ?? ''));
      try {
        @send_email($to, $subject, $html, $name ?: $to);
      } catch (\Throwable $e) {
        // ignore
      }
    }
  }
}
