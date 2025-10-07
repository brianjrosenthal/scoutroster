<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/GradeCalculator.php';
require_once __DIR__ . '/Search.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/YouthManagement.php';

class UserManagement {
  private static function pdo(): PDO {
    return pdo();
  }

  private static function str(string $v): string {
    return trim($v);
  }

  private static function normEmail(?string $email): ?string {
    if ($email === null) return null;
    $email = strtolower(trim($email));
    return $email === '' ? null : $email;
  }

  private static function boolInt($v): int {
    return !empty($v) ? 1 : 0;
  }

  // Activity logging - do not perform extra queries, just log what's provided.
  private static function log(string $action, ?int $targetUserId, array $details = []): void {
    try {
      // Actor is the currently logged in user (if any). May be null for some flows.
      $ctx = \UserContext::getLoggedInUserContext();
      $meta = $details;
      if ($targetUserId !== null && !array_key_exists('target_user_id', $meta)) {
        $meta['target_user_id'] = (int)$targetUserId;
      }
      \ActivityLog::log($ctx, (string)$action, (array)$meta);
    } catch (\Throwable $e) {
      // Best-effort logging; never disrupt the main flow.
    }
  }

  private static function assertAdmin(?UserContext $ctx): void {
    if (!$ctx || !$ctx->admin) { throw new RuntimeException('Admins only'); }
  }

  private static function assertCanUpdate(?UserContext $ctx, int $targetUserId): void {
    if (!$ctx) { throw new RuntimeException('Login required'); }
    if (!$ctx->admin && $ctx->id !== $targetUserId) { throw new RuntimeException('Forbidden (assertCanUpdate)'); }
  }

  // Admin-created users are auto-verified (email_verify_token=NULL, email_verified_at=NOW())
  public static function createAdmin(UserContext $ctx, array $data): int {
    self::assertAdmin($ctx);
    $first = self::str($data['first_name'] ?? '');
    $last  = self::str($data['last_name'] ?? '');
    $email = self::normEmail($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $isAdmin = self::boolInt($data['is_admin'] ?? 1);

    if ($first === '' || $last === '' || !$email || $password === '') {
      throw new InvalidArgumentException('Missing required fields for admin user creation.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $st = self::pdo()->prepare(
      "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
       VALUES (?,?,?,?,?,NULL,NOW())"
    );
    $st->execute([$first, $last, $email, $hash, $isAdmin]);
    $id = (int)self::pdo()->lastInsertId();
    self::log('user.create_admin', $id, ['email' => $email, 'is_admin' => $isAdmin]);
    return $id;
  }

  // Admin-invite or self-registration flow (non-admin), requires a verification token
  public static function createInvited(array $data, string $verifyToken): int {
    $first = self::str($data['first_name'] ?? '');
    $last  = self::str($data['last_name'] ?? '');
    $email = self::normEmail($data['email'] ?? '');

    if ($first === '' || $last === '' || !$email || $verifyToken === '') {
      throw new InvalidArgumentException('Missing required fields for invited user creation.');
    }

    // Set a random temporary password (must be reset on first login via activation)
    $tempPassword = bin2hex(random_bytes(8));
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $st = self::pdo()->prepare(
      "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
       VALUES (?,?,?,?,0,?,NULL)"
    );
    $st->execute([$first, $last, $email, $hash, $verifyToken]);
    $id = (int)self::pdo()->lastInsertId();
    self::log('user.create_invited', $id, ['email' => $email]);
    return $id;
  }

  // Self-registration (if enabled) creates a non-admin user with verification token
  public static function createSelf(array $data, string $verifyToken): int {
    $first = self::str($data['first_name'] ?? '');
    $last  = self::str($data['last_name'] ?? '');
    $email = self::normEmail($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if ($first === '' || $last === '' || !$email || $password === '' || $verifyToken === '') {
      throw new InvalidArgumentException('Missing required fields for self-registration.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $st = self::pdo()->prepare(
      "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
       VALUES (?,?,?,?,0,?,NULL)"
    );
    $st->execute([$first, $last, $email, $hash, $verifyToken]);
    $id = (int)self::pdo()->lastInsertId();
    self::log('user.create_self', $id, ['email' => $email]);
    return $id;
  }

  public static function changePassword(int $id, string $newPassword): bool {
    if ($newPassword === '') {
      throw new InvalidArgumentException('New password is required.');
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $st = self::pdo()->prepare("UPDATE users SET password_hash=? WHERE id=?");
    $ok = $st->execute([$hash, $id]);
    if ($ok) self::log('user.change_password', $id);
    return $ok;
  }

  public static function delete(UserContext $ctx, int $id): int {
    self::assertAdmin($ctx);
    if ($id === $ctx->id) { throw new RuntimeException('You cannot delete your own account.'); }

    $pdo = self::pdo();
    try {
      $pdo->beginTransaction();

      // 1) Remove RSVP memberships where this adult appears as a participant
      $stmt = $pdo->prepare('DELETE FROM rsvp_members WHERE adult_id = ?');
      $stmt->execute([$id]);

      // 2) Remove RSVPs created by this user (and their rsvp_members)
      $st = $pdo->prepare('SELECT id FROM rsvps WHERE created_by_user_id = ?');
      $st->execute([$id]);
      $rsvpIds = [];
      while ($r = $st->fetch()) { $rsvpIds[] = (int)$r['id']; }
      if (!empty($rsvpIds)) {
        $ph = implode(',', array_fill(0, count($rsvpIds), '?'));
        $pdo->prepare("DELETE FROM rsvp_members WHERE rsvp_id IN ($ph)")->execute($rsvpIds);
        $pdo->prepare("DELETE FROM rsvps WHERE id IN ($ph)")->execute($rsvpIds);
      }

      // 3) Reimbursement-related artifacts authored by this user on any requests
      $pdo->prepare('DELETE FROM reimbursement_request_comments WHERE created_by = ?')->execute([$id]);
      $pdo->prepare('DELETE FROM reimbursement_request_files WHERE created_by = ?')->execute([$id]);

      // 3.5) For requests where this user only entered on behalf of others, clear FK by setting entered_by to created_by
      $pdo->prepare('UPDATE reimbursement_requests SET entered_by = created_by WHERE entered_by = ?')->execute([$id]);

      // 4) Reimbursement requests created by this user (files/comments will cascade via FK on request_id)
      $pdo->prepare('DELETE FROM reimbursement_requests WHERE created_by = ?')->execute([$id]);

      // 5) Clear last_status_set_by references so deletion can proceed
      $pdo->prepare('UPDATE reimbursement_requests SET last_status_set_by = NULL WHERE last_status_set_by = ?')->execute([$id]);

      // 6) Finally remove the user record
      $stDel = $pdo->prepare('DELETE FROM users WHERE id=?');
      $stDel->execute([$id]);
      $count = (int)$stDel->rowCount();

      $pdo->commit();
      if ($count > 0) self::log('user.delete', $id);
      return $count;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }

  public static function setEmailVerifyToken(int $id, string $token): bool {
    if ($token === '') throw new InvalidArgumentException('Token is required.');
    $st = self::pdo()->prepare("UPDATE users SET email_verify_token=? WHERE id=?");
    $ok = $st->execute([$token, $id]);
    if ($ok) self::log('user.set_verify_token', $id);
    return $ok;
  }

  public static function verifyByToken(string $token): bool {
    if ($token === '') return false;
    $pdo = self::pdo();
    $st = $pdo->prepare('SELECT id FROM users WHERE email_verify_token = ? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch();
    if (!$row) return false;

    $upd = $pdo->prepare('UPDATE users SET email_verified_at = NOW(), email_verify_token = NULL WHERE id = ?');
    $ok = $upd->execute([(int)$row['id']]);
    if ($ok) self::log('user.verify', (int)$row['id']);
    return $ok;
  }

  public static function markVerifiedNow(int $id): bool {
    $st = self::pdo()->prepare("UPDATE users SET email_verified_at = NOW(), email_verify_token = NULL WHERE id = ?");
    $ok = $st->execute([$id]);
    if ($ok) self::log('user.mark_verified', $id);
    return $ok;
  }

  public static function setPasswordResetToken(int $id, string $tokenHash, int $minutesValid = 30): bool {
    if ($tokenHash === '') throw new InvalidArgumentException('Token hash is required.');
    $expiresAt = date('Y-m-d H:i:s', time() + ($minutesValid * 60));
    $st = self::pdo()->prepare("UPDATE users SET password_reset_token_hash=?, password_reset_expires_at=? WHERE id=?");
    $ok = $st->execute([$tokenHash, $expiresAt, $id]);
    if ($ok) self::log('user.set_password_reset', $id, ['minutes' => $minutesValid]);
    return $ok;
  }

  public static function finalizePasswordReset(int $id, string $newPassword): bool {
    if ($newPassword === '') throw new InvalidArgumentException('New password is required.');
    $pdo = self::pdo();
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    try {
      $pdo->beginTransaction();
      $st = $pdo->prepare('UPDATE users SET password_hash=?, password_reset_token_hash=NULL, password_reset_expires_at=NULL WHERE id=?');
      $ok = $st->execute([$hash, $id]);
      if (!$ok) { $pdo->rollBack(); return false; }
      $pdo->commit();
      self::log('user.finalize_password_reset', $id);
      return true;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }

  // =========================
  // Additional user operations
  // =========================

  public static function findById(int $id): ?array {
    $st = self::pdo()->prepare('SELECT id, first_name, last_name, email, is_admin, email_verified_at FROM users WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function findFullById(int $id): ?array {
    $st = self::pdo()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function findByEmail(string $email): ?array {
    $email = self::normEmail($email);
    if (!$email) return null;
    $st = self::pdo()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
    $st->execute([$email]);
    $row = $st->fetch();
    return $row ?: null;
  }

  // For login/auth checks – returns full row to avoid coupling to a limited column list
  public static function findAuthByEmail(string $email): ?array {
    return self::findByEmail($email);
  }

  public static function findIdByEmail(string $email): ?int {
    $row = self::findByEmail($email);
    return $row ? (int)$row['id'] : null;
  }

  public static function listAllBasic(): array {
    return self::pdo()
      ->query('SELECT id, first_name, last_name, email, is_admin, email_verified_at FROM users ORDER BY last_name, first_name')
      ->fetchAll();
  }

  public static function listAllForSelect(): array {
    return self::pdo()
      ->query('SELECT id, first_name, last_name, email FROM users ORDER BY last_name, first_name')
      ->fetchAll();
  }

  // Lightweight search for adults by name or email (case-insensitive), for admin typeahead.
  public static function searchAdults(string $q, int $limit = 20): array {
    $q = trim($q);
    if ($q === '') return [];
    $limit = max(1, min(100, (int)$limit));

    // Tokenize on whitespace; require all tokens to match across name/email fields.
    $tokens = preg_split('/\s+/', mb_strtolower($q));
    $tokens = array_values(array_filter(array_map(static function ($t) {
      $t = trim((string)$t);
      return $t === '' ? null : $t;
    }, $tokens)));

    if (empty($tokens)) return [];

    $whereParts = [];
    $params = [];
    foreach ($tokens as $tok) {
      // Each token must match either first_name OR last_name OR email
      $whereParts[] = '(LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ? OR LOWER(email) LIKE ?)';
      $like = '%' . $tok . '%';
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
    }

    $sql = 'SELECT id, first_name, last_name, email
            FROM users
            WHERE ' . implode(' AND ', $whereParts) . '
            ORDER BY last_name, first_name
            LIMIT ' . $limit;

    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
  }

  // Batch fetch parents (adults) for a set of youth IDs.
  // Returns map: youth_id => [ { adult fields ... }, ... ]
  public static function listParentsForYouthIds(UserContext $ctx, array $youthIds): array {
    // Require login but do not enforce admin to view roster contacts
    if (!$ctx) { throw new RuntimeException('Login required'); }

    // Sanitize IDs
    $ids = array_values(array_unique(array_filter(array_map(static function($v) {
      $n = (int)$v;
      return $n > 0 ? $n : null;
    }, $youthIds))));

    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT pr.youth_id,
                   u.id AS adult_id,
                   u.first_name,
                   u.last_name,
                   u.phone_cell,
                   u.phone_home,
                   u.email,
                   u.suppress_email_directory,
                   u.suppress_phone_directory
            FROM parent_relationships pr
            JOIN users u ON u.id = pr.adult_id
            WHERE pr.youth_id IN ($placeholders)
            ORDER BY pr.youth_id, u.last_name, u.first_name";

    $st = self::pdo()->prepare($sql);
    $st->execute($ids);
    $map = [];
    while ($row = $st->fetch()) {
      $yid = (int)$row['youth_id'];
      if (!isset($map[$yid])) $map[$yid] = [];
      
      // Add positions using the new system
      $row['positions'] = self::getAdultPositionsString((int)$row['adult_id']);
      
      $map[$yid][] = $row;
    }
    return $map;
  }

  public static function setAdminFlag(UserContext $ctx, int $id, bool $isAdmin): bool {
    self::assertAdmin($ctx);
    $st = self::pdo()->prepare('UPDATE users SET is_admin=? WHERE id=?');
    $ok = $st->execute([$isAdmin ? 1 : 0, $id]);
    if ($ok) self::log('user.set_admin', $id, ['is_admin' => $isAdmin ? 1 : 0]);
    return $ok;
  }

  // Update profile and extended details; by default does NOT allow changing is_admin
  public static function updateProfile(UserContext $ctx, int $id, array $fields, bool $allowAdminFlag = false): bool {
    self::assertCanUpdate($ctx, $id);
    
    // Medical forms expiration: approver-only (Cubmaster/Committee Chair/Treasurer)
    if (array_key_exists('medical_forms_expiration_date', $fields)) {
      if (!self::isApprover((int)$ctx->id)) {
        throw new InvalidArgumentException('Forbidden - not approver');
      }
    }
    
    // Medical form in-person opt-in: approver-only (Cubmaster/Committee Chair/Treasurer)
    if (array_key_exists('medical_form_in_person_opt_in', $fields)) {
      if (!self::isApprover((int)$ctx->id)) {
        throw new InvalidArgumentException('Forbidden - not approver');
      }
    }
    
    // Whitelist of updatable columns
    $allowed = [
      'first_name','last_name','email',
      'preferred_name','street1','street2','city','state','zip',
      'email2','phone_home','phone_cell','shirt_size',
      'suppress_email_directory','suppress_phone_directory',
      'bsa_membership_number','bsa_registration_expires_on','safeguarding_training_completed_on',
      'medical_forms_expiration_date','medical_form_in_person_opt_in',
      'emergency_contact1_name','emergency_contact1_phone','emergency_contact2_name','emergency_contact2_phone',
      'dietary_vegetarian','dietary_vegan','dietary_lactose_free','dietary_no_pork_shellfish','dietary_nut_allergy','dietary_gluten_free','dietary_other'
    ];
    if ($allowAdminFlag && $ctx->admin && array_key_exists('is_admin', $fields)) {
      $allowed[] = 'is_admin';
    } else {
      unset($fields['is_admin']);
    }

    $set = [];
    $params = [];

    foreach ($allowed as $key) {
      if (!array_key_exists($key, $fields)) continue;

      if ($key === 'email') {
        $val = self::normEmail($fields['email']);
        $set[] = 'email = ?';
        $params[] = $val; // NULL supported
      } elseif ($key === 'is_admin') {
        $set[] = 'is_admin = ?';
        $params[] = self::boolInt($fields['is_admin']);
      } elseif ($key === 'suppress_email_directory' || $key === 'suppress_phone_directory' || $key === 'medical_form_in_person_opt_in' || 
                $key === 'dietary_vegetarian' || $key === 'dietary_vegan' || $key === 'dietary_lactose_free' || 
                $key === 'dietary_no_pork_shellfish' || $key === 'dietary_nut_allergy') {
        $set[] = "$key = ?";
        $params[] = self::boolInt($fields[$key] ?? 0);
      } else {
        $val = $fields[$key];
        if (is_string($val)) {
          $val = trim($val);
          if ($val === '') $val = null;
        }
        $set[] = "$key = ?";
        $params[] = $val; // allow NULL for optional fields
      }
    }

    if (empty($set)) return false;
    $params[] = $id;

    $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?';
    $st = self::pdo()->prepare($sql);
    $ok = $st->execute($params);
    if ($ok) {
      $updatedFields = array_intersect_key($fields, array_flip($allowed));
      self::log('user.update_profile', $id, $updatedFields);
      if (array_key_exists('medical_forms_expiration_date', $updatedFields)) {
        self::log('user.update_medical_forms_expiration', $id, ['medical_forms_expiration_date' => $updatedFields['medical_forms_expiration_date']]);
      }
    }
    return $ok;
  }

  public static function getByVerifyToken(string $token): ?array {
    if ($token === '') return null;
    $st = self::pdo()->prepare('SELECT id, email FROM users WHERE email_verify_token = ? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function getResetStateByEmail(string $email): ?array {
    $email = self::normEmail($email);
    if (!$email) return null;
    $st = self::pdo()->prepare('SELECT id, password_reset_token_hash, password_reset_expires_at FROM users WHERE email=? LIMIT 1');
    $st->execute([$email]);
    $row = $st->fetch();
    return $row ?: null;
  }

  // Create a basic adult record without activation; email may be NULL
  public static function createAdultRecord(UserContext $ctx, array $data): int {
    self::assertAdmin($ctx);
    $first = self::str($data['first_name'] ?? '');
    $last  = self::str($data['last_name'] ?? '');
    $email = self::normEmail($data['email'] ?? null);
    $isAdmin = self::boolInt($data['is_admin'] ?? 0);

    if ($first === '' || $last === '') {
      throw new InvalidArgumentException('First and last name are required.');
    }

    // random placeholder password; real password set during activation/reset
    $tempPassword = bin2hex(random_bytes(8));
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $st = self::pdo()->prepare(
      'INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verify_token, email_verified_at)
       VALUES (?, ?, ?, ?, ?, NULL, NULL)'
    );
    $st->execute([$first, $last, $email, $hash, $isAdmin]);
    $id = (int)self::pdo()->lastInsertId();
    self::log('user.create_basic', $id, ['email' => $email, 'is_admin' => $isAdmin]);
    return $id;
  }

  // Create an adult record with extended profile details in one insert (used by admin_adult_add.php)
  public static function createAdultWithDetails(UserContext $ctx, array $data): int {
    self::assertAdmin($ctx);
    $first = self::str($data['first_name'] ?? '');
    $last  = self::str($data['last_name'] ?? '');
    if ($first === '' || $last === '') {
      throw new InvalidArgumentException('First and last name are required.');
    }

    $email = self::normEmail($data['email'] ?? null);
    $is_admin = self::boolInt($data['is_admin'] ?? 0);

    // Helper to normalize empty strings to NULL
    $nn = function ($v) {
      if (is_string($v)) $v = trim($v);
      return ($v === '' ? null : $v);
    };

    // Optional personal/contact
    $preferred_name = $nn($data['preferred_name'] ?? null);
    $street1 = $nn($data['street1'] ?? null);
    $street2 = $nn($data['street2'] ?? null);
    $city    = $nn($data['city'] ?? null);
    $state   = $nn($data['state'] ?? null);
    $zip     = $nn($data['zip'] ?? null);
    $email2  = $nn($data['email2'] ?? null);
    $phone_home = $nn($data['phone_home'] ?? null);
    $phone_cell = $nn($data['phone_cell'] ?? null);
    $shirt_size = $nn($data['shirt_size'] ?? null);

    // Optional scouting
    $bsa_membership_number = $nn($data['bsa_membership_number'] ?? null);
    $bsa_registration_expires_on = $nn($data['bsa_registration_expires_on'] ?? null);
    $safeguarding_training_completed_on = $nn($data['safeguarding_training_completed_on'] ?? null);

    // Optional emergency
    $em1_name  = $nn($data['emergency_contact1_name'] ?? null);
    $em1_phone = $nn($data['emergency_contact1_phone'] ?? null);
    $em2_name  = $nn($data['emergency_contact2_name'] ?? null);
    $em2_phone = $nn($data['emergency_contact2_phone'] ?? null);

    // Random placeholder password; account becomes usable after activation/reset
    $rand = bin2hex(random_bytes(18));
    $hash = password_hash($rand, PASSWORD_DEFAULT);

    $sql = "INSERT INTO users
      (first_name, last_name, email, password_hash, is_admin,
       preferred_name, street1, street2, city, state, zip,
       email2, phone_home, phone_cell, shirt_size,
       bsa_membership_number, bsa_registration_expires_on, safeguarding_training_completed_on,
       emergency_contact1_name, emergency_contact1_phone, emergency_contact2_name, emergency_contact2_phone,
       email_verify_token, email_verified_at, password_reset_token_hash, password_reset_expires_at)
      VALUES
      (:first_name, :last_name, :email, :password_hash, :is_admin,
       :preferred_name, :street1, :street2, :city, :state, :zip,
       :email2, :phone_home, :phone_cell, :shirt_size,
       :bsa_no, :bsa_exp, :safe_done,
       :em1_name, :em1_phone, :em2_name, :em2_phone,
       NULL, NULL, NULL, NULL)";

    $stmt = self::pdo()->prepare($sql);

    $stmt->bindValue(':first_name', $first, PDO::PARAM_STR);
    $stmt->bindValue(':last_name',  $last,  PDO::PARAM_STR);
    if ($email === null) $stmt->bindValue(':email', null, PDO::PARAM_NULL);
    else $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':password_hash', $hash, PDO::PARAM_STR);
    $stmt->bindValue(':is_admin', $is_admin, PDO::PARAM_INT);

    // Personal/contact
    $stmt->bindValue(':preferred_name', $preferred_name, $preferred_name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':street1', $street1, $street1 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':street2', $street2, $street2 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':city', $city, $city === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':state', $state, $state === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':zip', $zip, $zip === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':email2', $email2, $email2 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':phone_home', $phone_home, $phone_home === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':phone_cell', $phone_cell, $phone_cell === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':shirt_size', $shirt_size, $shirt_size === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

    // Scouting
    $stmt->bindValue(':bsa_no',  $bsa_membership_number, $bsa_membership_number === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':bsa_exp', $bsa_registration_expires_on, $bsa_registration_expires_on === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':safe_done', $safeguarding_training_completed_on, $safeguarding_training_completed_on === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

    // Emergency
    $stmt->bindValue(':em1_name',  $em1_name,  $em1_name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':em1_phone', $em1_phone, $em1_phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':em2_name',  $em2_name,  $em2_name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':em2_phone', $em2_phone, $em2_phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

    $ok = $stmt->execute();
    if (!$ok) {
      throw new RuntimeException('Failed to insert adult record.');
    }
    $id = (int)self::pdo()->lastInsertId();
    self::log('user.create_with_details', $id, ['email' => $email, 'is_admin' => $is_admin]);
    return $id;
  }

  // Send an invite email to an existing, unverified adult with an email on file.
  // Returns true if an invite was sent, false if not eligible (no email or already verified).
  public static function sendInvite(UserContext $ctx, int $id): bool {
    self::assertAdmin($ctx);
    // Load minimal user info
    $u = self::findById($id);
    if (!$u) return false;
    if (empty($u['email']) || !empty($u['email_verified_at'])) {
      return false; // Not eligible
    }

    // Generate and persist a verification token
    $token = bin2hex(random_bytes(32));
    self::setEmailVerifyToken((int)$u['id'], $token);

    // Build verify URL from current request context
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $verifyUrl = $scheme.'://'.$host.'/verify_email.php?token='.urlencode($token);

    // Compose and send email
    require_once __DIR__ . '/../settings.php';
    require_once __DIR__ . '/../mailer.php';

    $safeName  = htmlspecialchars(trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $safeUrl   = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
    $siteTitle = htmlspecialchars(Settings::siteTitle(), ENT_QUOTES, 'UTF-8');

    $html = '<p>Hello '.($safeName ?: htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8')).',</p>'
          . '<p>Please verify your email to activate your account for '.$siteTitle.'.</p>'
          . '<p><a href="'.$safeUrl.'">'.$safeUrl.'</a></p>'
          . '<p>After verifying, you will be prompted to set your password.</p>';

    @send_email((string)$u['email'], 'Activate your '.$siteTitle.' account', $html, $safeName ?: (string)$u['email']);
    self::log('user.invite_sent', (int)$u['id']);
    return true;
  }

  // =========================
  // Approver/Leadership helpers
  // =========================

  public static function isApprover(int $userId): bool {
    $st = self::pdo()->prepare(
      "SELECT 1
       FROM adult_leadership_position_assignments alpa
       JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
       WHERE alpa.adult_id = ?
         AND alp.name IN ('Cubmaster','Committee Chair','Treasurer')
       LIMIT 1"
    );
    $st->execute([$userId]);
    return (bool)$st->fetchColumn();
  }

  public static function isCubmaster(int $userId): bool {
    $st = self::pdo()->prepare(
      "SELECT 1
       FROM adult_leadership_position_assignments alpa
       JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
       WHERE alpa.adult_id = ?
         AND alp.name = 'Cubmaster'
       LIMIT 1"
    );
    $st->execute([$userId]);
    return (bool)$st->fetchColumn();
  }

  // =========================
  // Leadership Positions (legacy methods - now use LeadershipManagement)
  // =========================

  public static function listLeadershipPositions(?UserContext $ctx, int $adultId): array {
    // Backward compatibility - convert new format to old format
    require_once __DIR__ . '/LeadershipManagement.php';
    
    if (!$ctx) { throw new RuntimeException('Login required'); }
    if (!$ctx->admin && $ctx->id !== $adultId) { throw new RuntimeException('Forbidden - listLeadershipPositions'); }

    $allPositions = \LeadershipManagement::listAdultAllPositions($adultId);
    $legacy = [];
    
    foreach ($allPositions as $pos) {
      if ($pos['type'] === 'pack') {
        $legacy[] = [
          'id' => $pos['id'],
          'position' => $pos['name'],
          'class_of' => null,
          'created_at' => $pos['created_at']
        ];
      } elseif ($pos['type'] === 'den_leader') {
        $legacy[] = [
          'id' => $pos['class_of'], // Use class_of as fake ID for backward compatibility
          'position' => 'Den Leader',
          'class_of' => $pos['class_of'],
          'created_at' => $pos['created_at']
        ];
      }
    }
    
    return $legacy;
  }

  public static function addLeadershipPosition(?UserContext $ctx, int $adultId, string $position, ?int $grade = null): void {
    // Legacy method - redirect to new system
    require_once __DIR__ . '/LeadershipManagement.php';
    
    $pos = trim($position);
    if ($pos === '') { throw new InvalidArgumentException('Position is required.'); }
    
    if ($pos === 'Den Leader' || $pos === 'Assistant Den Leader') {
      if ($grade !== null) {
        \LeadershipManagement::assignDenLeader($ctx, $adultId, $grade);
      } else {
        throw new InvalidArgumentException('Grade is required for Den Leader positions.');
      }
    } else {
      // Try to find existing pack position by name
      $packPositions = \LeadershipManagement::listPackPositions();
      $positionId = null;
      foreach ($packPositions as $packPos) {
        if ($packPos['name'] === $pos) {
          $positionId = (int)$packPos['id'];
          break;
        }
      }
      
      if ($positionId) {
        \LeadershipManagement::assignPackPosition($ctx, $adultId, $positionId);
      } else {
        // Create new position if it doesn't exist (for "Other" positions)
        $positionId = \LeadershipManagement::createPackPosition($ctx, $pos, 99); // High sort priority for custom positions
        \LeadershipManagement::assignPackPosition($ctx, $adultId, $positionId);
      }
    }
  }

  public static function removeLeadershipPosition(?UserContext $ctx, int $adultId, int $leadershipId): void {
    // Legacy method - this is complex since we need to determine what type of position to remove
    // For now, we'll throw an error directing users to use the new modal interface
    throw new RuntimeException('Please use the new leadership position management interface to remove positions.');
  }
  // Helper method to get positions for display (used by youth.php and adults.php)
  public static function getAdultPositionsString(int $adultId): string {
    try {
      require_once __DIR__ . '/LeadershipManagement.php';
      return \LeadershipManagement::getAdultPositionString($adultId);
    } catch (Throwable $e) {
      return '';
    }
  }

  // =========================
  // Read helpers and utilities (Users)
  // =========================

  public static function getFullName(int $id): ?string {
    $st = self::pdo()->prepare('SELECT first_name, last_name FROM users WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    $first = trim((string)($row['first_name'] ?? ''));
    $last  = trim((string)($row['last_name'] ?? ''));
    $name = trim($first . ' ' . $last);
    return $name !== '' ? $name : null;
  }

  public static function existsById(int $id): bool {
    $st = self::pdo()->prepare('SELECT 1 FROM users WHERE id=? LIMIT 1');
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
  }

  public static function emailExists(string $email): bool {
    $norm = self::normEmail($email);
    if (!$norm) return false;
    $st = self::pdo()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
    $st->execute([$norm]);
    return (bool)$st->fetchColumn();
  }

  public static function computeClassOfFromGradeLabel(?string $gradeLabel): ?int {
    $gradeLabel = (string)$gradeLabel;
    if ($gradeLabel === '') return null;
    $g = \GradeCalculator::parseGradeLabel($gradeLabel);
    if ($g === null) return null;
    $currentFifth = \GradeCalculator::schoolYearEndYear();
    return $currentFifth + (5 - (int)$g);
  }

  // Returns grouped adults with their children according to filters.
  // filters: ['q' => ?string, 'class_of' => ?int, 'registered_only' => bool]
  public static function listAdultsWithChildren(array $filters): array {
    $q = isset($filters['q']) ? trim((string)$filters['q']) : '';
    $classOf = array_key_exists('class_of', $filters) ? ($filters['class_of'] === null ? null : (int)$filters['class_of']) : null;
    $registeredOnly = !empty($filters['registered_only']);

    $params = [];
    $sql = "
      SELECT 
        u.*,
        y.id         AS child_id,
        y.first_name AS child_first_name,
        y.last_name  AS child_last_name,
        y.class_of   AS child_class_of
      FROM users u
      LEFT JOIN parent_relationships pr ON pr.adult_id = u.id
      LEFT JOIN youth y ON y.id = pr.youth_id
      WHERE 1=1
    ";

    if ($q !== '') {
      $tokens = \Search::tokenize($q);
      $sql .= \Search::buildAndLikeClause(
        ['u.first_name','u.last_name','u.email','y.first_name','y.last_name'],
        $tokens,
        $params
      );
    }

    if ($classOf !== null) {
      $sql .= " AND y.class_of = ?";
      $params[] = $classOf;
    }

    if ($registeredOnly) {
      $sql .= " AND ("
            . " (u.bsa_membership_number IS NOT NULL AND u.bsa_membership_number <> '')"
            . " OR (y.bsa_registration_number IS NOT NULL AND y.bsa_registration_number <> '')"
            . " OR (y.date_paid_until IS NOT NULL AND y.date_paid_until >= CURDATE())"
            . " OR EXISTS (SELECT 1 FROM pending_registrations pr WHERE pr.youth_id = y.id AND pr.status <> 'deleted')"
            . " OR EXISTS (SELECT 1 FROM payment_notifications_from_users pn WHERE pn.youth_id = y.id AND pn.status <> 'deleted')"
            . ")";
    }

    $sql .= " ORDER BY u.last_name, u.first_name, y.last_name, y.first_name";

    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $grouped = []; // id => ['adult' => ..., 'children' => []]
    foreach ($rows as $r) {
      $aid = (int)$r['id'];
      if (!isset($grouped[$aid])) {
        $grouped[$aid] = [
          'adult' => [
            'id' => $aid,
            'first_name' => $r['first_name'],
            'last_name' => $r['last_name'],
            'email' => $r['email'],
            'email2' => $r['email2'] ?? null,
            'phone_home' => $r['phone_home'] ?? null,
            'phone_cell' => $r['phone_cell'] ?? null,
            'email_verified_at' => $r['email_verified_at'] ?? null,
            'suppress_email_directory' => (int)($r['suppress_email_directory'] ?? 0),
            'suppress_phone_directory' => (int)($r['suppress_phone_directory'] ?? 0),
            'positions' => self::getAdultPositionsString($aid), // Use new system
          ],
          'children' => [],
        ];
      }
      if (!empty($r['child_id'])) {
        $classOfChild = (int)$r['child_class_of'];
        $grade = \GradeCalculator::gradeForClassOf($classOfChild);
        $grouped[$aid]['children'][] = [
          'id' => (int)$r['child_id'],
          'name' => trim((string)($r['child_first_name'] ?? '') . ' ' . (string)($r['child_last_name'] ?? '')),
          'class_of' => $classOfChild,
          'grade' => $grade,
        ];
      }
    }

    return array_values($grouped);
  }

  public static function listAdultsWithAnyEmail(): array {
    $st = self::pdo()->query("SELECT id, first_name, last_name, email FROM users WHERE email IS NOT NULL AND email <> '' ORDER BY last_name, first_name");
    return $st->fetchAll();
  }

  public static function listAdultsWithRegisteredChildrenEmails(): array {
    $sql = "
      SELECT DISTINCT u.id, u.first_name, u.last_name, u.email
      FROM users u
      JOIN parent_relationships pr ON pr.adult_id = u.id
      JOIN youth y ON y.id = pr.youth_id
      WHERE u.email IS NOT NULL AND u.email <> '' AND y.bsa_registration_number IS NOT NULL
      ORDER BY u.last_name, u.first_name
    ";
    $st = self::pdo()->prepare($sql);
    $st->execute();
    return $st->fetchAll();
  }

  public static function listAdultsByChildClassOfEmails(int $classOf): array {
    $sql = "
      SELECT DISTINCT u.id, u.first_name, u.last_name, u.email
      FROM users u
      JOIN parent_relationships pr ON pr.adult_id = u.id
      JOIN youth y ON y.id = pr.youth_id
      WHERE u.email IS NOT NULL AND u.email <> '' AND y.class_of = ?
      ORDER BY u.last_name, u.first_name
    ";
    $st = self::pdo()->prepare($sql);
    $st->execute([$classOf]);
    return $st->fetchAll();
  }

  public static function findBasicForEmailingById(int $id): ?array {
    $st = self::pdo()->prepare("SELECT id, first_name, last_name, email FROM users WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /**
   * List adults with email addresses based on multiple filter criteria.
   * Returns array of ['id' => int, 'first_name' => str, 'last_name' => str, 'email' => str]
   * 
   * @param array $filters Filter criteria:
   *   - 'registration_status' => 'all'|'registered'|'unregistered'
   *   - 'grades' => array of grade numbers (0=K, 1-5)
   *   - 'rsvp_status' => 'all'|'not_rsvped'
   *   - 'event_id' => int (required for RSVP filtering)
   *   - 'specific_adult_ids' => array of user IDs to include
   * @return array
   */
  public static function listAdultsWithFilters(array $filters): array {
    $registrationStatus = $filters['registration_status'] ?? 'all';
    $grades = $filters['grades'] ?? [];
    $rsvpStatus = $filters['rsvp_status'] ?? 'all';
    $eventId = isset($filters['event_id']) ? (int)$filters['event_id'] : null;
    $specificAdultIds = $filters['specific_adult_ids'] ?? [];

    // Normalize grades to class_of values
    $classOfValues = [];
    if (!empty($grades)) {
      $currentFifth = \GradeCalculator::schoolYearEndYear();
      foreach ($grades as $grade) {
        $g = (int)$grade;
        if ($g >= 0 && $g <= 5) {
          $classOfValues[] = $currentFifth + (5 - $g);
        }
      }
    }

    // Normalize specific adult IDs
    $specificAdultIds = array_values(array_unique(array_filter(array_map(static function($v) {
      $n = (int)$v;
      return $n > 0 ? $n : null;
    }, $specificAdultIds))));

    // Base query - adults with email addresses
    $sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email FROM users u";
    $joins = [];
    $wheres = ["u.email IS NOT NULL", "u.email <> ''"];
    $params = [];

    // Registration status filtering
    if ($registrationStatus === 'registered') {
      // Use the same logic as youth.php by leveraging YouthManagement::searchRoster()
      $ctx = \UserContext::getLoggedInUserContext();
      if (!$ctx || !$ctx->admin) {
        throw new RuntimeException('Admin context required for registered filter');
      }
      
      // Get qualifying youth using the same logic as youth.php
      $qualifyingYouth = \YouthManagement::searchRoster($ctx, null, null, false);
      $qualifyingYouthIds = array_column($qualifyingYouth, 'id');
      
      if (empty($qualifyingYouthIds)) {
        // No qualifying youth means no parents to invite
        return [];
      }
      
      // Find parents of qualifying youth
      $joins[] = "JOIN parent_relationships pr_reg ON pr_reg.adult_id = u.id";
      $placeholders = implode(',', array_fill(0, count($qualifyingYouthIds), '?'));
      $wheres[] = "pr_reg.youth_id IN ($placeholders)";
      $params = array_merge($params, $qualifyingYouthIds);
    } elseif ($registrationStatus === 'unregistered') {
      $joins[] = "LEFT JOIN parent_relationships pr_unreg ON pr_unreg.adult_id = u.id";
      $joins[] = "LEFT JOIN youth y_unreg ON y_unreg.id = pr_unreg.youth_id";
      $wheres[] = "(u.bsa_membership_number IS NULL OR u.bsa_membership_number = '') AND NOT EXISTS (SELECT 1 FROM parent_relationships pr2 JOIN youth y2 ON y2.id = pr2.youth_id WHERE pr2.adult_id = u.id AND y2.bsa_registration_number IS NOT NULL AND y2.bsa_registration_number <> '')";
    } elseif ($registrationStatus === 'leadership') {
      // Include adults who hold pack-wide leadership positions OR are den leaders
      $wheres[] = "(
        EXISTS (
          SELECT 1 FROM adult_leadership_position_assignments alpa
          WHERE alpa.adult_id = u.id
        )
        OR EXISTS (
          SELECT 1 FROM adult_den_leader_assignments adla
          WHERE adla.adult_id = u.id
        )
      )";
    }

    // Grade filtering
    if (!empty($classOfValues)) {
      $joins[] = "JOIN parent_relationships pr_grade ON pr_grade.adult_id = u.id";
      $joins[] = "JOIN youth y_grade ON y_grade.id = pr_grade.youth_id";
      $placeholders = implode(',', array_fill(0, count($classOfValues), '?'));
      $wheres[] = "y_grade.class_of IN ($placeholders)";
      $params = array_merge($params, $classOfValues);
    }

    // RSVP status filtering
    if ($rsvpStatus === 'not_rsvped' && $eventId) {
      $wheres[] = "NOT EXISTS (
        SELECT 1 FROM rsvps r 
        WHERE r.event_id = ? AND r.created_by_user_id = u.id
      ) AND NOT EXISTS (
        SELECT 1 FROM rsvp_members rm 
        JOIN rsvps r2 ON r2.id = rm.rsvp_id 
        WHERE rm.event_id = ? AND rm.participant_type = 'adult' AND rm.adult_id = u.id
      )";
      $params[] = $eventId;
      $params[] = $eventId;
    }

    // Specific adults inclusion
    if (!empty($specificAdultIds)) {
      $placeholders = implode(',', array_fill(0, count($specificAdultIds), '?'));
      $wheres[] = "u.id IN ($placeholders)";
      $params = array_merge($params, $specificAdultIds);
    }

    // Add unsubscribe filter
    $wheres[] = "u.unsubscribed = 0";

    // Build final query
    if (!empty($joins)) {
      $sql .= " " . implode(" ", $joins);
    }
    $sql .= " WHERE " . implode(" AND ", $wheres);
    $sql .= " ORDER BY u.last_name, u.first_name";

    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
  }

  /**
   * Count adults that would be returned by listAdultsWithFilters() without fetching full data.
   * Uses same filtering logic but returns just the count for performance.
   */
  public static function countAdultsWithFilters(array $filters): int {
    $registrationStatus = $filters['registration_status'] ?? 'all';
    $grades = $filters['grades'] ?? [];
    $rsvpStatus = $filters['rsvp_status'] ?? 'all';
    $eventId = isset($filters['event_id']) ? (int)$filters['event_id'] : null;
    $specificAdultIds = $filters['specific_adult_ids'] ?? [];

    // Normalize grades to class_of values
    $classOfValues = [];
    if (!empty($grades)) {
      $currentFifth = \GradeCalculator::schoolYearEndYear();
      foreach ($grades as $grade) {
        $g = (int)$grade;
        if ($g >= 0 && $g <= 5) {
          $classOfValues[] = $currentFifth + (5 - $g);
        }
      }
    }

    // Normalize specific adult IDs
    $specificAdultIds = array_values(array_unique(array_filter(array_map(static function($v) {
      $n = (int)$v;
      return $n > 0 ? $n : null;
    }, $specificAdultIds))));

    // Base query - count distinct adults with email addresses
    $sql = "SELECT COUNT(DISTINCT u.id) as count FROM users u";
    $joins = [];
    $wheres = ["u.email IS NOT NULL", "u.email <> ''"];
    $params = [];

    // Registration status filtering
    if ($registrationStatus === 'registered') {
      // Use the same logic as youth.php by leveraging YouthManagement::searchRoster()
      $ctx = \UserContext::getLoggedInUserContext();
      if (!$ctx || !$ctx->admin) {
        throw new RuntimeException('Admin context required for registered filter');
      }
      
      // Get qualifying youth using the same logic as youth.php
      $qualifyingYouth = \YouthManagement::searchRoster($ctx, null, null, false);
      $qualifyingYouthIds = array_column($qualifyingYouth, 'id');
      
      if (empty($qualifyingYouthIds)) {
        // No qualifying youth means no parents to count
        return 0;
      }
      
      // Find parents of qualifying youth
      $joins[] = "JOIN parent_relationships pr_reg ON pr_reg.adult_id = u.id";
      $placeholders = implode(',', array_fill(0, count($qualifyingYouthIds), '?'));
      $wheres[] = "pr_reg.youth_id IN ($placeholders)";
      $params = array_merge($params, $qualifyingYouthIds);
    } elseif ($registrationStatus === 'unregistered') {
      $joins[] = "LEFT JOIN parent_relationships pr_unreg ON pr_unreg.adult_id = u.id";
      $joins[] = "LEFT JOIN youth y_unreg ON y_unreg.id = pr_unreg.youth_id";
      $wheres[] = "(u.bsa_membership_number IS NULL OR u.bsa_membership_number = '') AND NOT EXISTS (SELECT 1 FROM parent_relationships pr2 JOIN youth y2 ON y2.id = pr2.youth_id WHERE pr2.adult_id = u.id AND y2.bsa_registration_number IS NOT NULL AND y2.bsa_registration_number <> '')";
    } elseif ($registrationStatus === 'leadership') {
      // Include adults who hold pack-wide leadership positions OR are den leaders
      $wheres[] = "(
        EXISTS (
          SELECT 1 FROM adult_leadership_position_assignments alpa
          WHERE alpa.adult_id = u.id
        )
        OR EXISTS (
          SELECT 1 FROM adult_den_leader_assignments adla
          WHERE adla.adult_id = u.id
        )
      )";
    }

    // Grade filtering
    if (!empty($classOfValues)) {
      $joins[] = "JOIN parent_relationships pr_grade ON pr_grade.adult_id = u.id";
      $joins[] = "JOIN youth y_grade ON y_grade.id = pr_grade.youth_id";
      $placeholders = implode(',', array_fill(0, count($classOfValues), '?'));
      $wheres[] = "y_grade.class_of IN ($placeholders)";
      $params = array_merge($params, $classOfValues);
    }

    // RSVP status filtering
    if ($rsvpStatus === 'not_rsvped' && $eventId) {
      $wheres[] = "NOT EXISTS (
        SELECT 1 FROM rsvps r 
        WHERE r.event_id = ? AND r.created_by_user_id = u.id
      ) AND NOT EXISTS (
        SELECT 1 FROM rsvp_members rm 
        JOIN rsvps r2 ON r2.id = rm.rsvp_id 
        WHERE rm.event_id = ? AND rm.participant_type = 'adult' AND rm.adult_id = u.id
      )";
      $params[] = $eventId;
      $params[] = $eventId;
    }

    // Specific adults inclusion
    if (!empty($specificAdultIds)) {
      $placeholders = implode(',', array_fill(0, count($specificAdultIds), '?'));
      $wheres[] = "u.id IN ($placeholders)";
      $params = array_merge($params, $specificAdultIds);
    }

    // Build final query
    if (!empty($joins)) {
      $sql .= " " . implode(" ", $joins);
    }
    $sql .= " WHERE " . implode(" AND ", $wheres);

    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return (int)($row['count'] ?? 0);
  }

  // Determine if a user may upload a photo for the target adult.
  public static function canUploadAdultPhoto(?UserContext $ctx, int $targetAdultId): bool {
    if (!$ctx) {
        error_log(sprintf(
            '%s denied: no context (ctx=null), targetAdultId=%d, uri=%s, ip=%s',
            __METHOD__,
            $targetAdultId,
            $_SERVER['REQUEST_URI'] ?? '-',
            $_SERVER['REMOTE_ADDR'] ?? '-'
        ));
        return false;
    }

    if ($ctx->admin || $ctx->id === $targetAdultId) {
        return true;
    }

    $st = self::pdo()->prepare("
      SELECT 1
      FROM parent_relationships pr1
      JOIN parent_relationships pr2 ON pr1.youth_id = pr2.youth_id
      WHERE pr1.adult_id = ? AND pr2.adult_id = ?
      LIMIT 1
    ");
    $st->execute([(int)$ctx->id, (int)$targetAdultId]);
    $ok = (bool)$st->fetchColumn();

    if (!$ok) {
        error_log(sprintf(
            '%s denied: no shared youth; ctx_id=%d, admin=%s, targetAdultId=%d, uri=%s, ip=%s',
            __METHOD__,
            (int)$ctx->id,
            $ctx->admin ? '1' : '0',
            $targetAdultId,
            $_SERVER['REQUEST_URI'] ?? '-',
            $_SERVER['REMOTE_ADDR'] ?? '-'
        ));
    }

    return $ok;
}


  // List all youth linked to an adult (for relationship management UIs).
  public static function listChildrenForAdult(int $adultId): array {
    $st = self::pdo()->prepare("
      SELECT y.*
      FROM parent_relationships pr
      JOIN youth y ON y.id = pr.youth_id
      WHERE pr.adult_id = ?
      ORDER BY y.last_name, y.first_name
    ");
    $st->execute([$adultId]);
    return $st->fetchAll();
  }

  /**
   * List co-parents (other adults) for the given set of youth IDs, excluding the provided adult.
   * Returns rows: id, first_name, last_name, photo_public_file_id, bsa_membership_number, positions (comma-separated)
   */
  public static function listCoParentsForYouthIds(int $selfAdultId, array $youthIds): array {
    $ids = array_values(array_unique(array_filter(array_map(static function($v) {
      $n = (int)$v;
      return $n > 0 ? $n : null;
    }, $youthIds))));
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $params[] = (int)$selfAdultId;

    $sql = "SELECT u.id, u.first_name, u.last_name, u.photo_public_file_id, u.bsa_membership_number,
                   GROUP_CONCAT(DISTINCT alp.name ORDER BY alp.name SEPARATOR ', ') AS positions
            FROM users u
            JOIN parent_relationships pr ON pr.adult_id = u.id
            LEFT JOIN adult_leadership_position_assignments alpa ON alpa.adult_id = u.id
            LEFT JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
            WHERE pr.youth_id IN ($placeholders) AND u.id <> ?
            GROUP BY u.id, u.first_name, u.last_name, u.photo_public_file_id, u.bsa_membership_number
            ORDER BY u.last_name, u.first_name";

    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
  }

  /**
   * Find the display name "First Last" of the first adult holding the given leadership position.
   * Matching is case-insensitive. Returns null if none found.
   */
  public static function findLeaderNameByPosition(string $position): ?string {
    $pos = trim($position);
    if ($pos === '') return null;
    $st = self::pdo()->prepare("SELECT u.first_name, u.last_name
                                FROM adult_leadership_position_assignments alpa
                                JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
                                JOIN users u ON u.id = alpa.adult_id
                                WHERE LOWER(alp.name) = LOWER(?)
                                LIMIT 1");
    $st->execute([$pos]);
    $r = $st->fetch();
    if (!$r) return null;
    $name = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
    return $name !== '' ? $name : null;
  }

  // =========================
  // Email Unsubscribe Management
  // =========================

  /**
   * Update the unsubscribed status for a user.
   * This is a separate function from updateProfile to handle unsubscribe functionality distinctly.
   */
  public static function updateUnsubscribeStatus(?UserContext $ctx, int $userId, bool $unsubscribed): bool {
    self::assertCanUpdate($ctx, $userId);
    
    $st = self::pdo()->prepare("UPDATE users SET unsubscribed = ? WHERE id = ?");
    $ok = $st->execute([$unsubscribed ? 1 : 0, $userId]);
    
    if ($ok) {
      self::log('user.update_unsubscribe_status', $userId, ['unsubscribed' => $unsubscribed]);
    }
    
    return $ok;
  }

  /**
   * Check if a user is unsubscribed from emails.
   */
  public static function isUnsubscribed(int $userId): bool {
    $st = self::pdo()->prepare("SELECT unsubscribed FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch();
    return !empty($row['unsubscribed']);
  }

  /**
   * Get unsubscribe status for multiple users at once.
   * Returns array with userId => boolean mapping.
   */
  public static function getUnsubscribeStatusForUsers(array $userIds): array {
    $userIds = array_values(array_unique(array_filter(array_map(static function($v) {
      $n = (int)$v;
      return $n > 0 ? $n : null;
    }, $userIds))));

    if (empty($userIds)) return [];

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $st = self::pdo()->prepare("SELECT id, unsubscribed FROM users WHERE id IN ($placeholders)");
    $st->execute($userIds);
    
    $result = [];
    while ($row = $st->fetch()) {
      $result[(int)$row['id']] = !empty($row['unsubscribed']);
    }
    
    return $result;
  }
}
