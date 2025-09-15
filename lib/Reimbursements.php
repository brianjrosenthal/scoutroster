<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/EventManagement.php';

final class Reimbursements {
  private static function pdo(): PDO { return pdo(); }

  // Best-effort activity logging with no DB enrichment
  private static function logAction(string $action, array $meta = []): void {
    try {
      $ctx = \UserContext::getLoggedInUserContext();
      \ActivityLog::log($ctx, $action, $meta);
    } catch (\Throwable $e) {
      // swallow
    }
  }

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
        // Allow approvers to mark resubmitted on behalf of submitter if needed
        $next[] = 'resubmitted';
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

  public static function create(UserContext $ctx, string $title, ?string $description = null, ?string $paymentDetails = null, ?string $amount = null, ?int $createdByOverride = null, ?int $eventId = null, ?string $paymentMethod = null): int {
    if (!$ctx) throw new RuntimeException('Login required');
    $title = trim($title);
    if ($title === '') throw new InvalidArgumentException('Title is required.');
    $paymentDetails = self::validatePaymentDetails($paymentDetails);
    $amountCanon = self::validateAmount($amount);

    // Validate event id if provided
    $eventIdVal = null;
    if ($eventId !== null) {
      $ev = \EventManagement::findBasicById((int)$eventId);
      if (!$ev) throw new InvalidArgumentException('Selected event not found.');
      $eventIdVal = (int)$eventId;
    }

    // Validate payment method if provided
    $pm = self::validatePaymentMethod($paymentMethod);

    // Determine created_by and entered_by
    $createdBy = (int)$ctx->id;
    $enteredBy = (int)$ctx->id;
    if ($createdByOverride !== null && (int)$createdByOverride > 0) {
      if (!self::isApprover($ctx)) {
        throw new RuntimeException('Only approvers can submit on behalf of another user.');
      }
      if (!\UserManagement::existsById((int)$createdByOverride)) {
        throw new InvalidArgumentException('Selected user not found.');
      }
      $createdBy = (int)$createdByOverride;
      $enteredBy = (int)$ctx->id;
    }

    $st = self::pdo()->prepare(
      "INSERT INTO reimbursement_requests (title, description, payment_details, amount, payment_method, created_by, entered_by, event_id, status, created_at, last_modified_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NOW(), NOW())"
    );
    $st->execute([$title, $description, $paymentDetails, $amountCanon, $pm, $createdBy, $enteredBy, $eventIdVal]);
    $newId = (int)self::pdo()->lastInsertId();

    // Activity log: reimbursement created
    self::logAction('reimbursement.create', [
      'request_id' => $newId,
      'title' => $title,
      'has_amount' => $amountCanon !== null,
      'created_by' => $createdBy,
      'entered_by' => $enteredBy,
    ]);

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

  // List up to $limit pending requests for approvers, newest first,
  // including submitter name and the latest comment (or description fallback).
  public static function listPendingForApprover(int $limit = 5): array {
    $limit = max(1, min(50, (int)$limit));
    $pdo = self::pdo();

    // Pending statuses per spec
    $sql = "SELECT r.id, r.title, r.status, r.last_modified_at, r.description, r.created_by,
                   u.first_name, u.last_name
            FROM reimbursement_requests r
            JOIN users u ON u.id = r.created_by
            WHERE r.status IN ('submitted','resubmitted')
            ORDER BY r.last_modified_at DESC
            LIMIT {$limit}";
    $st = $pdo->query($sql);
    $rows = $st ? $st->fetchAll() : [];
    if (!$rows) return [];

    $stc = $pdo->prepare("SELECT comment_text
                          FROM reimbursement_request_comments
                          WHERE reimbursement_request_id = ?
                          ORDER BY created_at DESC
                          LIMIT 1");

    foreach ($rows as &$r) {
      $latest = '';
      $stc->execute([(int)$r['id']]);
      $c = $stc->fetch();
      if ($c && isset($c['comment_text'])) {
        $latest = (string)$c['comment_text'];
      } else {
        $latest = (string)($r['description'] ?? '');
      }
      $r['submitter_name'] = trim((string)($r['first_name'] ?? '').' '.(string)($r['last_name'] ?? ''));
      $r['latest_note'] = $latest;
    }
    unset($r);

    return $rows;
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

    // Activity log: comment added
    self::logAction('reimbursement.add_comment', [
      'request_id' => (int)$req['id'],
    ]);

    try {
      self::notifyNewComment((int)$req['id'], (int)$ctx->id, $text);
    } catch (\Throwable $e) {
      // ignore notification failures
    }
  }

  public static function changeStatus(UserContext $ctx, int $reqId, string $newStatus, string $comment): void {
    if (!$ctx) throw new RuntimeException('Login required');
    $req = self::getWithAuth($ctx, $reqId);
    $oldStatus = (string)$req['status'];
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

      // Activity log: status change
      self::logAction('reimbursement.status_change', [
        'request_id' => (int)$req['id'],
        'from' => $oldStatus,
        'to' => $newStatus,
      ]);

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

  // Payment Details validation and update

  // Validate monetary amount; returns canonical string with 2 decimals or NULL
  private static function validateAmount(?string $in): ?string {
    if ($in === null) return null;
    $s = trim($in);
    if ($s === '') return null;
    // Normalize commas
    $s = str_replace(',', '', $s);
    // Valid non-negative money with up to 2 decimals
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
      throw new InvalidArgumentException('Amount must be a non-negative number with up to 2 decimals.');
    }
    $f = (float)$s;
    if ($f < 0) {
      throw new InvalidArgumentException('Amount cannot be negative.');
    }
    return number_format($f, 2, '.', '');
  }


  private static function validatePaymentMethod(?string $pm): ?string {
    if ($pm === null) return null;
    $pm = trim($pm);
    if ($pm === '') return null;
    $allowed = ['Zelle','Check','Donation Letter Only'];
    if (!in_array($pm, $allowed, true)) {
      throw new InvalidArgumentException('Invalid payment method.');
    }
    return $pm;
  }

  /**
   * Detect possible account numbers in a string while skipping likely phone numbers.
   * Returns an array of the original matched substrings that look like account numbers.
   *
   * Rules:
   * - Find digit runs of length >= 8 allowing separators (space, dot, dash) between digits
   * - Skip if the run looks like a valid North American phone number (10 digits, or 11 with leading 1 and area code 2–9xx)
   */
  private static function detectAccountNumbers(string $s): array {
    $hits = [];
    if ($s === '') return $hits;

    // Hard fail: any 10+ consecutive digits without separators should be flagged immediately
    if (preg_match_all('/\d{10,}/', $s, $hard)) {
      foreach ($hard[0] as $h) { $hits[] = $h; }
    }

    if (preg_match_all('/(?<!\d)(?:\d[\s\.\-]?){8,}(?!\d)/', $s, $matches)) {
      foreach ($matches[0] as $match) {
        $digitsOnly = preg_replace('/\D/', '', $match) ?? '';
        if ($digitsOnly === null) $digitsOnly = '';

        // Skip if it appears to be a (US/Canada) phone number:
        if (strlen($digitsOnly) === 10 || (strlen($digitsOnly) === 11 && $digitsOnly[0] === '1')) {
          $number = $digitsOnly;
          if (strlen($number) === 11 && $number[0] === '1') {
            $number = substr($number, 1);
          }
          $areaCode = substr($number, 0, 3);
          if ($areaCode !== false && preg_match('/^[2-9][0-9]{2}$/', $areaCode)) {
            // Likely a valid NANP phone number — skip
            continue;
          }
        }

        if (strlen($digitsOnly) >= 8) {
          $hits[] = $match;
        }
      }
    }

    return $hits;
  }

  private static function validatePaymentDetails(?string $s): ?string {
    $s = $s === null ? null : trim($s);
    if ($s === null || $s === '') return null;

    if (mb_strlen($s) > 500) {
      throw new InvalidArgumentException('Payment Details must be 500 characters or less.');
    }

    // Detect account numbers using enhanced rules (skip likely phone numbers)
    $suspects = self::detectAccountNumbers($s);
    if (!empty($suspects)) {
      throw new InvalidArgumentException('Payment Details appears to contain an account number or long numeric string (8+ digits). Please remove it.');
    }

    return $s;
  }

  public static function updatePaymentDetails(UserContext $ctx, int $reqId, ?string $paymentDetails): void {
    if (!$ctx) throw new RuntimeException('Login required');
    $req = self::getWithAuth($ctx, $reqId);
    if ((int)$req['created_by'] !== (int)$ctx->id) {
      throw new RuntimeException('Only the request creator can edit payment details.');
    }
    $pd = self::validatePaymentDetails($paymentDetails);
    $st = self::pdo()->prepare("UPDATE reimbursement_requests SET payment_details = ?, last_modified_at = NOW() WHERE id = ?");
    $st->execute([$pd, (int)$req['id']]);

    // Activity log: payment details updated (do not log the text)
    self::logAction('reimbursement.update_payment_details', [
      'request_id' => (int)$req['id'],
      'has_payment_details' => $pd !== null && $pd !== '',
    ]);
  }

  // Amount update: only creator can edit, and only when status is in 'submitted', 'resubmitted', or 'more_info_requested'
  public static function updateAmount(UserContext $ctx, int $reqId, ?string $amount): void {
    if (!$ctx) throw new RuntimeException('Login required');
    $req = self::getWithAuth($ctx, $reqId);
    if ((int)$req['created_by'] !== (int)$ctx->id) {
      throw new RuntimeException('Only the request creator can edit amount.');
    }
    $editableStatuses = ['submitted','resubmitted','more_info_requested'];
    if (!in_array((string)$req['status'], $editableStatuses, true)) {
      throw new RuntimeException('Amount can only be edited when status is "submitted", "resubmitted", or "more_info_requested".');
    }
    $canon = self::validateAmount($amount);
    $st = self::pdo()->prepare("UPDATE reimbursement_requests SET amount = ?, last_modified_at = NOW() WHERE id = ?");
    $st->execute([$canon, (int)$req['id']]);

    // Activity log: amount updated
    self::logAction('reimbursement.update_amount', [
      'request_id' => (int)$req['id'],
      'amount' => $canon,
    ]);
  }


  // Store a secure file blob reference for this reimbursement (DB-backed, no filesystem path)
  public static function recordSecureFile(UserContext $ctx, int $reqId, int $secureFileId, string $originalFilename, ?string $description = null): void {
    if (!$ctx) throw new RuntimeException('Login required');
    $req = self::getWithAuth($ctx, $reqId); // permission check
    $st = self::pdo()->prepare("INSERT INTO reimbursement_request_files
      (reimbursement_request_id, original_filename, description, created_by, created_at, secure_file_id)
      VALUES (?, ?, ?, ?, NOW(), ?)");
    $st->execute([(int)$req['id'], $originalFilename, $description, (int)$ctx->id, (int)$secureFileId]);

    // Activity log: file added
    self::logAction('reimbursement.add_file', [
      'request_id' => (int)$req['id'],
      'secure_file_id' => (int)$secureFileId,
      'filename' => (string)$originalFilename,
    ]);

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

  // Allow creator or approver to set/clear associated event
  public static function updateEventId(UserContext $ctx, int $reqId, ?int $eventId): void {
    if (!$ctx) throw new RuntimeException('Login required');
    $req = self::getWithAuth($ctx, $reqId);
    $isOwner = ((int)$req['created_by'] === (int)$ctx->id);
    $isApprover = self::isApprover($ctx);
    if (!$isOwner && !$isApprover) {
      throw new RuntimeException('Only the creator or an approver can change the associated event.');
    }

    $eventIdVal = null;
    if ($eventId !== null) {
      $ev = \EventManagement::findBasicById((int)$eventId);
      if (!$ev) throw new InvalidArgumentException('Selected event not found.');
      $eventIdVal = (int)$eventId;
    }

    $st = self::pdo()->prepare("UPDATE reimbursement_requests SET event_id = ?, last_modified_at = NOW() WHERE id = ?");
    $st->execute([$eventIdVal, (int)$req['id']]);

    self::logAction('reimbursement.update_event', [
      'request_id' => (int)$req['id'],
      'event_id' => $eventIdVal,
    ]);
  }

  // Expose leadership title for approvers (Cubmaster > Treasurer > Committee Chair)
  public static function getLeadershipTitleForUser(int $userId): string {
    $st = self::pdo()->prepare(
      "SELECT position FROM adult_leadership_positions WHERE adult_id = ? AND position IN ('Cubmaster','Treasurer','Committee Chair')"
    );
    $st->execute([(int)$userId]);
    $priority = ['Cubmaster' => 3, 'Treasurer' => 2, 'Committee Chair' => 1];
    $best = null;
    $bestScore = -1;
    while ($r = $st->fetch()) {
      $pos = (string)($r['position'] ?? '');
      $score = $priority[$pos] ?? 0;
      if ($score > $bestScore) { $best = $pos; $bestScore = $score; }
    }
    return $best ?: 'Leader';
  }

  // Allow creator or approver to set/clear payment method
  public static function updatePaymentMethod(UserContext $ctx, int $reqId, ?string $paymentMethod): void {
    if (!$ctx) throw new RuntimeException('Login required');
    $req = self::getWithAuth($ctx, $reqId);
    $isOwner = ((int)$req['created_by'] === (int)$ctx->id);
    $isApprover = self::isApprover($ctx);
    if (!$isOwner && !$isApprover) {
      throw new RuntimeException('Only the creator or an approver can change the payment method.');
    }
    $pm = self::validatePaymentMethod($paymentMethod);
    $st = self::pdo()->prepare("UPDATE reimbursement_requests SET payment_method = ?, last_modified_at = NOW() WHERE id = ?");
    $st->execute([$pm, (int)$req['id']]);

    self::logAction('reimbursement.update_payment_method', [
      'request_id' => (int)$req['id'],
      'payment_method' => $pm,
    ]);
  }

  // Approver-only: send donation letter email to the submitter when payment_method is "Donation Letter Only"
  public static function sendDonationLetter(UserContext $ctx, int $reqId, string $body): void {
    if (!$ctx) throw new RuntimeException('Login required');
    if (!self::isApprover($ctx)) {
      throw new RuntimeException('Approvers only');
    }
    $req = self::getWithAuth($ctx, $reqId);
    $method = (string)($req['payment_method'] ?? '');
    if ($method !== 'Donation Letter Only') {
      throw new RuntimeException('Donation letter is allowed only when payment method is "Donation Letter Only".');
    }

    // Load submitter (must have email)
    $stU = self::pdo()->prepare("SELECT id, email, first_name, last_name FROM users WHERE id=? LIMIT 1");
    $stU->execute([(int)$req['created_by']]);
    $u = $stU->fetch();
    $toEmail = trim((string)($u['email'] ?? ''));
    $toName = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
    if ($toEmail === '') {
      throw new RuntimeException('Submitter does not have an email on file.');
    }

    // Compose and send email
    require_once __DIR__ . '/../settings.php';
    require_once __DIR__ . '/../mailer.php';
    $subject = 'Pack 440 Donation Receipt';
    $safeBody = nl2br(htmlspecialchars((string)$body, ENT_QUOTES, 'UTF-8'));

    try {
      @send_email($toEmail, $subject, $safeBody, $toName !== '' ? $toName : $toEmail);
    } catch (\Throwable $e) {
      throw new RuntimeException('Failed to send email.');
    }

    // Record an audit comment
    $ins = self::pdo()->prepare("INSERT INTO reimbursement_request_comments (reimbursement_request_id, created_by, created_at, status_changed_to, comment_text)
                                 VALUES (?, ?, NOW(), NULL, ?)");
    $ins->execute([(int)$req['id'], (int)$ctx->id, 'Donation letter sent.']);

    // Activity log
    self::logAction('reimbursement.send_donation_letter', [
      'request_id' => (int)$req['id'],
      'to' => $toEmail,
    ]);
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
    $st = self::pdo()->prepare("SELECT r.id, r.title, r.description, r.amount, r.created_by FROM reimbursement_requests r WHERE r.id=? LIMIT 1");
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

    // Amount display (always include a line; show "—" if not provided)
    $rawAmount = $r['amount'] ?? null;
    $displayAmount = '—';
    if ($rawAmount !== null && $rawAmount !== '') {
      $displayAmount = '$' . number_format((float)$rawAmount, 2);
    }
    $safeAmount = htmlspecialchars($displayAmount, ENT_QUOTES, 'UTF-8');

    $html = '<p>A new reimbursement request has been submitted by <strong>'.$safeCreator.'</strong>.</p>'
          . '<p><strong>Title:</strong> '.$safeTitle.'</p>'
          . '<p><strong>Amount:</strong> '.$safeAmount.'</p>'
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
