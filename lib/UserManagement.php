<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

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

  // Placeholder for future activity logging
  private static function log(string $action, ?int $userId, array $details = []): void {
    // no-op for now
  }

  // Admin-created users are auto-verified (email_verify_token=NULL, email_verified_at=NOW())
  public static function createAdmin(array $data): int {
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

  public static function delete(int $id): bool {
    $st = self::pdo()->prepare("DELETE FROM users WHERE id=?");
    $ok = $st->execute([$id]);
    if ($ok) self::log('user.delete', $id);
    return $ok;
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
}
