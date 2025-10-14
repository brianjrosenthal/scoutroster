<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * EmailSnippets - Manages saved email snippets for key 3 positions
 * 
 * Provides CRUD operations for email snippets that can be reused
 * in communications. Access is restricted to approvers (Cubmaster,
 * Committee Chair, Treasurer).
 */
class EmailSnippets {
  
  private static function pdo(): PDO {
    return pdo();
  }

  /**
   * Verify that the current user is an approver (key 3 position holder)
   */
  private static function assertApprover(?UserContext $ctx): void {
    if (!$ctx) {
      throw new RuntimeException('Login required');
    }
    
    require_once __DIR__ . '/UserManagement.php';
    if (!UserManagement::isApprover($ctx->id)) {
      throw new RuntimeException('Access restricted to key 3 positions (Cubmaster, Committee Chair, Treasurer)');
    }
  }

  /**
   * Activity logging helper
   */
  private static function log(string $action, ?int $snippetId, array $details = []): void {
    try {
      $ctx = UserContext::getLoggedInUserContext();
      $meta = $details;
      if ($snippetId !== null && !array_key_exists('snippet_id', $meta)) {
        $meta['snippet_id'] = $snippetId;
      }
      ActivityLog::log($ctx, $action, $meta);
    } catch (Throwable $e) {
      // Best-effort logging; never disrupt the main flow
    }
  }

  /**
   * List all email snippets ordered by sort_order
   * 
   * @param UserContext|null $ctx User context for authorization
   * @return array Array of snippet records
   * @throws RuntimeException if user is not an approver
   */
  public static function listSnippets(?UserContext $ctx): array {
    self::assertApprover($ctx);
    
    $st = self::pdo()->query(
      "SELECT id, name, value, sort_order, created_at, updated_at, created_by
       FROM email_snippets
       ORDER BY sort_order ASC, id ASC"
    );
    
    return $st->fetchAll();
  }

  /**
   * Get a single snippet by ID
   * 
   * @param UserContext|null $ctx User context for authorization
   * @param int $id Snippet ID
   * @return array|null Snippet record or null if not found
   * @throws RuntimeException if user is not an approver
   */
  public static function getSnippet(?UserContext $ctx, int $id): ?array {
    self::assertApprover($ctx);
    
    $st = self::pdo()->prepare(
      "SELECT id, name, value, sort_order, created_at, updated_at, created_by
       FROM email_snippets
       WHERE id = ?
       LIMIT 1"
    );
    $st->execute([$id]);
    
    $row = $st->fetch();
    return $row ?: null;
  }

  /**
   * Create a new email snippet
   * 
   * @param UserContext|null $ctx User context for authorization
   * @param string $name Snippet name
   * @param string $value Snippet content
   * @param int|null $sortOrder Sort order (defaults to end of list if null)
   * @return int ID of created snippet
   * @throws RuntimeException if user is not an approver
   * @throws InvalidArgumentException if required fields are missing
   */
  public static function createSnippet(?UserContext $ctx, string $name, string $value, ?int $sortOrder = null): int {
    self::assertApprover($ctx);
    
    $name = trim($name);
    $value = trim($value);
    
    if ($name === '') {
      throw new InvalidArgumentException('Snippet name is required');
    }
    
    if ($value === '') {
      throw new InvalidArgumentException('Snippet value is required');
    }
    
    // If no sort order provided, place at the end
    if ($sortOrder === null) {
      $st = self::pdo()->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM email_snippets");
      $row = $st->fetch();
      $sortOrder = (int)($row['next_order'] ?? 1);
    }
    
    $st = self::pdo()->prepare(
      "INSERT INTO email_snippets (name, value, sort_order, created_by)
       VALUES (?, ?, ?, ?)"
    );
    
    $st->execute([$name, $value, $sortOrder, $ctx->id]);
    $id = (int)self::pdo()->lastInsertId();
    
    self::log('email_snippet.create', $id, ['name' => $name]);
    
    return $id;
  }

  /**
   * Update an existing email snippet
   * 
   * @param UserContext|null $ctx User context for authorization
   * @param int $id Snippet ID
   * @param string $name Snippet name
   * @param string $value Snippet content
   * @param int $sortOrder Sort order
   * @return bool True if updated successfully
   * @throws RuntimeException if user is not an approver
   * @throws InvalidArgumentException if required fields are missing
   */
  public static function updateSnippet(?UserContext $ctx, int $id, string $name, string $value, int $sortOrder): bool {
    self::assertApprover($ctx);
    
    $name = trim($name);
    $value = trim($value);
    
    if ($name === '') {
      throw new InvalidArgumentException('Snippet name is required');
    }
    
    if ($value === '') {
      throw new InvalidArgumentException('Snippet value is required');
    }
    
    $st = self::pdo()->prepare(
      "UPDATE email_snippets
       SET name = ?, value = ?, sort_order = ?
       WHERE id = ?"
    );
    
    $ok = $st->execute([$name, $value, $sortOrder, $id]);
    
    if ($ok && $st->rowCount() > 0) {
      self::log('email_snippet.update', $id, ['name' => $name]);
    }
    
    return $ok;
  }

  /**
   * Delete an email snippet
   * 
   * @param UserContext|null $ctx User context for authorization
   * @param int $id Snippet ID
   * @return bool True if deleted successfully
   * @throws RuntimeException if user is not an approver
   */
  public static function deleteSnippet(?UserContext $ctx, int $id): bool {
    self::assertApprover($ctx);
    
    // Get snippet name for logging before deleting
    $snippet = self::getSnippet($ctx, $id);
    $name = $snippet ? $snippet['name'] : 'Unknown';
    
    $st = self::pdo()->prepare("DELETE FROM email_snippets WHERE id = ?");
    $ok = $st->execute([$id]);
    
    if ($ok && $st->rowCount() > 0) {
      self::log('email_snippet.delete', $id, ['name' => $name]);
    }
    
    return $ok;
  }

  // =========================
  // Dynamic Snippets
  // =========================

  /**
   * Format a date/time range according to specific rules:
   * - Drop minutes if :00
   * - Drop am/pm if start and end have same am/pm
   * - Don't repeat date if same day
   * 
   * @param string $startsAt Start datetime (Y-m-d H:i:s)
   * @param string|null $endsAt End datetime (Y-m-d H:i:s) or null
   * @return string Formatted date/time string
   */
  private static function formatEventDateTime(string $startsAt, ?string $endsAt): string {
    $startDT = new DateTime($startsAt);
    
    // Format start date: "October 23, 2025"
    $dateStr = $startDT->format('F j,');
    
    // Format start time
    $startHour = (int)$startDT->format('G'); // 24-hour format
    $startMin = (int)$startDT->format('i');
    $startAmPm = $startDT->format('a'); // am or pm
    
    $startTimeStr = $startHour > 12 ? ($startHour - 12) : ($startHour == 0 ? 12 : $startHour);
    if ($startMin > 0) {
      $startTimeStr .= ':' . str_pad((string)$startMin, 2, '0', STR_PAD_LEFT);
    }
    
    // If no end time, just return start
    if (!$endsAt || trim($endsAt) === '') {
      return $dateStr . ' ' . $startTimeStr . $startAmPm;
    }
    
    $endDT = new DateTime($endsAt);
    
    // Check if same day
    $sameDay = $startDT->format('Y-m-d') === $endDT->format('Y-m-d');
    
    // Format end time
    $endHour = (int)$endDT->format('G');
    $endMin = (int)$endDT->format('i');
    $endAmPm = $endDT->format('a');
    
    $endTimeStr = $endHour > 12 ? ($endHour - 12) : ($endHour == 0 ? 12 : $endHour);
    if ($endMin > 0) {
      $endTimeStr .= ':' . str_pad((string)$endMin, 2, '0', STR_PAD_LEFT);
    }
    
    if ($sameDay) {
      // Same day: "October 23, 2025 3-4:30pm" or "October 23, 2025 11am-1pm"
      if ($startAmPm === $endAmPm) {
        // Same am/pm: omit from start time
        return $dateStr . ' ' . $startTimeStr . '-' . $endTimeStr . $endAmPm;
      } else {
        // Different am/pm: include both
        return $dateStr . ' ' . $startTimeStr . $startAmPm . '-' . $endTimeStr . $endAmPm;
      }
    } else {
      // Different days: "November 7, 2025 1pm - November 8, 2025 9am"
      $endDateStr = $endDT->format('F j, Y');
      return $dateStr . ' ' . $startTimeStr . $startAmPm . ' - ' . $endDateStr . ' ' . $endTimeStr . $endAmPm;
    }
  }

  /**
   * Generate a formatted list of upcoming events for the next 3 months
   * 
   * @param UserContext|null $ctx User context for authorization
   * @return string Formatted list of events ready to copy/paste
   * @throws RuntimeException if user is not an approver
   */
  public static function generateUpcomingEventsList(?UserContext $ctx): string {
    self::assertApprover($ctx);
    
    require_once __DIR__ . '/EventManagement.php';
    
    // Get events for next 3 months
    $threeMonthsFromNow = date('Y-m-d H:i:s', strtotime('+3 months'));
    $events = EventManagement::listBetween(date('Y-m-d H:i:s'), $threeMonthsFromNow);
    
    if (empty($events)) {
      return 'No upcoming events in the next 3 months.';
    }
    
    $lines = ['Upcoming Events:'];
    
    foreach ($events as $event) {
      $dateTime = self::formatEventDateTime($event['starts_at'], $event['ends_at'] ?? null);
      $name = $event['name'];
      $location = !empty($event['location']) ? ', at ' . $event['location'] : '';
      
      $lines[] = '- ' . $dateTime . ' - ' . $name . $location;
    }
    
    return implode("\n", $lines);
  }
}
