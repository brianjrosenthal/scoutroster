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
    if ($s === 'approved' || $s === 'rejected' || $s === 'revoked') return [];

    $next = [];
    if ($s === 'submitted') {
      if ($creator) $next[] = 'revoked';
      if ($approver) {
        $next[] = 'more_info_requested';
        $next[] = 'approved';
        $next[] = 'rejected';
      }
    } elseif ($s === 'more_info_requested') {
      if ($creator) $next[] = 'resubmitted';
      if ($approver) {
        $next[] = 'approved';
        $next[] = 'rejected';
      }
    } elseif ($s === 'resubmitted') {
      if ($creator) $next[] = 'revoked';
      if ($approver) {
        $next[] = 'more_info_requested';
        $next[] = 'approved';
        $next[] = 'rejected';
      }
    }
    return $next;
  }

  private static function validStatus(string $s): bool {
    static $all = ['submitted','revoked','more_info_requested','resubmitted','approved','rejected'];
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
    return (int)self::pdo()->lastInsertId();
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
  }

  // ----- Utility for page logic -----

  public static function canUploadFiles(?UserContext $ctx, array $req): bool {
    return self::canView($ctx, $req); // spec: "All users should be able to add supporting files" -> any viewer
  }
}
