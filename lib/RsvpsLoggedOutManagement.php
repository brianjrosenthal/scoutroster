<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

final class RsvpsLoggedOutManagement {
  private static function pdo(): \PDO {
    return pdo();
  }

  // ========== Helpers ==========

  private static function normalizeAnswer(string $v): string {
    $ans = strtolower(trim($v));
    return in_array($ans, ['yes','maybe','no'], true) ? $ans : 'yes';
  }

  private static function totalsFromRow(?array $row): array {
    $a = (int)($row['a'] ?? 0);
    $k = (int)($row['k'] ?? 0);
    return ['adults' => $a, 'kids' => $k];
  }

  // ========== Reads ==========

  /**
   * List public RSVP entries (logged-out) for an event filtered by answer.
   * Returns rows including: first_name, last_name, total_adults, total_kids, comment
   */
  public static function listByAnswer(int $eventId, string $answer): array {
    $ans = self::normalizeAnswer($answer);
    $st = self::pdo()->prepare("
      SELECT first_name, last_name, total_adults, total_kids, comment
      FROM rsvps_logged_out
      WHERE event_id=? AND answer=?
      ORDER BY last_name, first_name, id
    ");
    $st->execute([(int)$eventId, $ans]);
    return $st->fetchAll() ?: [];
  }

  /**
   * Sum totals for an event filtered by answer.
   * Returns: ['adults' => int, 'kids' => int]
   */
  public static function totalsByAnswer(int $eventId, string $answer): array {
    $ans = self::normalizeAnswer($answer);
    $st = self::pdo()->prepare("
      SELECT COALESCE(SUM(total_adults),0) AS a, COALESCE(SUM(total_kids),0) AS k
      FROM rsvps_logged_out
      WHERE event_id=? AND answer=?
    ");
    $st->execute([(int)$eventId, $ans]);
    return self::totalsFromRow($st->fetch() ?: []);
  }

  /**
   * Sum totals for an event across all answers.
   * Returns: ['adults' => int, 'kids' => int]
   */
  public static function totalsAllAnswers(int $eventId): array {
    $st = self::pdo()->prepare("
      SELECT COALESCE(SUM(total_adults),0) AS a, COALESCE(SUM(total_kids),0) AS k
      FROM rsvps_logged_out
      WHERE event_id=?
    ");
    $st->execute([(int)$eventId]);
    return self::totalsFromRow($st->fetch() ?: []);
  }

  /**
   * Find a public RSVP by id, or null if not found.
   */
  public static function findById(int $id): ?array {
    $st = self::pdo()->prepare("SELECT * FROM rsvps_logged_out WHERE id=? LIMIT 1");
    $st->execute([(int)$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  // ========== Writes (with optional UserContext for logging) ==========

  /**
   * Create a logged-out (public) RSVP row and return its id plus the plain token.
   * The token_hash (sha256 of plain token) is stored in DB.
   *
   * Returns: ['id' => int, 'plain_token' => string]
   */
  public static function create(
    int $eventId,
    string $firstName,
    string $lastName,
    string $email,
    ?string $phone,
    int $totalAdults,
    int $totalKids,
    string $answer,
    ?string $comment,
    ?\UserContext $ctx
  ): array {
    $eventId = (int)$eventId;
    if ($eventId <= 0) {
      throw new \InvalidArgumentException('Invalid event.');
    }
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    if ($firstName === '' || $lastName === '') {
      throw new \InvalidArgumentException('Name is required.');
    }
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('A valid email is required.');
    }
    $phone = ($phone !== null && trim($phone) !== '') ? trim($phone) : null;

    $totalAdults = max(0, (int)$totalAdults);
    $totalKids = max(0, (int)$totalKids);
    $ans = self::normalizeAnswer($answer);
    $comment = ($comment !== null && trim($comment) !== '') ? trim($comment) : null;

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);

    $st = self::pdo()->prepare("
      INSERT INTO rsvps_logged_out (event_id, first_name, last_name, email, phone, total_adults, total_kids, answer, comment, token_hash)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $st->execute([
      $eventId, $firstName, $lastName, $email, $phone, $totalAdults, $totalKids, $ans, $comment, $tokenHash
    ]);
    $id = (int)self::pdo()->lastInsertId();

    // Activity log (user_id may be null in ctx)
    try {
      \ActivityLog::log($ctx, 'public_rsvp_created', [
        'event_id' => $eventId,
        'rsvp_id' => $id,
        'email' => $email,
        'answer' => $ans,
        'total_adults' => $totalAdults,
        'total_kids' => $totalKids,
      ]);
    } catch (\Throwable $e) {
      // swallow logging errors
    }

    return ['id' => $id, 'plain_token' => $plainToken];
  }

  /**
   * Update totals/answer/comment for a public RSVP row.
   */
  public static function update(
    int $id,
    int $totalAdults,
    int $totalKids,
    string $answer,
    ?string $comment,
    ?\UserContext $ctx
  ): void {
    $id = (int)$id;
    if ($id <= 0) {
      throw new \InvalidArgumentException('Invalid RSVP id.');
    }
    $totalAdults = max(0, (int)$totalAdults);
    $totalKids = max(0, (int)$totalKids);
    $ans = self::normalizeAnswer($answer);
    $comment = ($comment !== null && trim($comment) !== '') ? trim($comment) : null;

    $st = self::pdo()->prepare("UPDATE rsvps_logged_out SET total_adults=?, total_kids=?, answer=?, comment=? WHERE id=?");
    $st->execute([$totalAdults, $totalKids, $ans, $comment, $id]);

    // Activity log
    try {
      \ActivityLog::log($ctx, 'public_rsvp_updated', [
        'rsvp_id' => $id,
        'answer' => $ans,
        'total_adults' => $totalAdults,
        'total_kids' => $totalKids,
      ]);
    } catch (\Throwable $e) {
      // swallow
    }
  }

  /**
   * Delete a public RSVP row by id.
   */
  public static function delete(int $id, ?\UserContext $ctx): void {
    $id = (int)$id;
    if ($id <= 0) {
      throw new \InvalidArgumentException('Invalid RSVP id.');
    }
    $st = self::pdo()->prepare("DELETE FROM rsvps_logged_out WHERE id=?");
    $st->execute([$id]);

    // Activity log
    try {
      \ActivityLog::log($ctx, 'public_rsvp_deleted', [
        'rsvp_id' => $id,
      ]);
    } catch (\Throwable $e) {
      // swallow
    }
  }
}
