<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';

class Volunteers {
  // Return roles with counts and volunteer names for an event
  public static function rolesWithCounts(int $eventId): array {
    $eventId = (int)$eventId;
    if ($eventId <= 0) return [];

    $st = pdo()->prepare("
      SELECT vr.id, vr.title, vr.description, vr.slots_needed, vr.sort_order,
             COALESCE(COUNT(vs.id),0) AS filled_count
      FROM volunteer_roles vr
      LEFT JOIN volunteer_signups vs ON vs.role_id = vr.id
      WHERE vr.event_id = ?
      GROUP BY vr.id
      ORDER BY vr.sort_order, vr.title
    ");
    $st->execute([$eventId]);
    $roles = $st->fetchAll() ?: [];

    // Fetch all volunteers for these roles
    $st2 = pdo()->prepare("
      SELECT vs.role_id, vs.comment, u.id AS user_id, u.first_name, u.last_name
      FROM volunteer_signups vs
      JOIN users u ON u.id = vs.user_id
      WHERE vs.event_id = ?
      ORDER BY u.last_name, u.first_name
    ");
    $st2->execute([$eventId]);
    $byRole = [];
    foreach ($st2->fetchAll() as $row) {
      $rid = (int)$row['role_id'];
      if (!isset($byRole[$rid])) $byRole[$rid] = [];
      $byRole[$rid][] = [
        'user_id' => (int)$row['user_id'],
        'name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
        'comment' => (string)($row['comment'] ?? ''),
      ];
    }

    $out = [];
    foreach ($roles as $r) {
      $rid = (int)$r['id'];
      $slots = (int)($r['slots_needed'] ?? 0);
      $filled = (int)($r['filled_count'] ?? 0);
      $open = max(0, $slots - $filled);
      $out[] = [
        'id' => $rid,
        'title' => (string)$r['title'],
        'description' => (string)($r['description'] ?? ''),
        'slots_needed' => $slots,
        'filled_count' => $filled,
        'open_count' => $open,
        'is_unlimited' => ($slots === 0),
        'sort_order' => (int)$r['sort_order'],
        'volunteers' => $byRole[$rid] ?? [],
      ];
    }
    return $out;
  }

  // Is there at least one role with open slots for this event?
  public static function openRolesExist(int $eventId): bool {
    $st = pdo()->prepare("
      SELECT 1
      FROM volunteer_roles vr
      LEFT JOIN volunteer_signups vs ON vs.role_id = vr.id
      WHERE vr.event_id = ?
      GROUP BY vr.id, vr.slots_needed
      HAVING vr.slots_needed = 0 OR COUNT(vs.id) < vr.slots_needed
      LIMIT 1
    ");
    $st->execute([(int)$eventId]);
    return (bool)$st->fetchColumn();
  }

  // Ensure the user has RSVP'd YES for the event
  public static function userHasYesRsvp(int $eventId, int $userId): bool {
    // Membership-based: either the user created the RSVP or was included as an adult member
    $st = pdo()->prepare("
      SELECT 1
      FROM rsvps r
      WHERE r.event_id=? AND r.answer='yes' AND (
        r.created_by_user_id=? OR EXISTS (
          SELECT 1 FROM rsvp_members rm
          WHERE rm.rsvp_id = r.id
            AND rm.event_id = r.event_id
            AND rm.participant_type='adult'
            AND rm.adult_id=?
        )
      )
      LIMIT 1
    ");
    $st->execute([(int)$eventId, (int)$userId, (int)$userId]);
    return (bool)$st->fetchColumn();
  }

  // Sign up a user for a role, enforcing capacity atomically
  public static function signup(int $eventId, int $roleId, int $userId, ?string $comment = null): void {
    $eventId = (int)$eventId; $roleId = (int)$roleId; $userId = (int)$userId;
    if ($eventId <= 0 || $roleId <= 0 || $userId <= 0) {
      throw new RuntimeException('Invalid signup request.');
    }
    // Require YES RSVP to volunteer
    if (!self::userHasYesRsvp($eventId, $userId)) {
      throw new RuntimeException('You must RSVP "Yes" to volunteer.');
    }

    $db = pdo();
    $db->beginTransaction();
    try {
      // Lock role row to prevent concurrent overfills
      $st = $db->prepare("SELECT id, slots_needed FROM volunteer_roles WHERE id=? AND event_id=? FOR UPDATE");
      $st->execute([$roleId, $eventId]);
      $role = $st->fetch();
      if (!$role) {
        throw new RuntimeException('Volunteer role not found.');
      }
      $slots = (int)$role['slots_needed'];

      // Count current signups for this role (within txn)
      $st = $db->prepare("SELECT COUNT(*) AS c FROM volunteer_signups WHERE role_id=?");
      $st->execute([$roleId]);
      $filled = (int)($st->fetch()['c'] ?? 0);

      if ($slots > 0 && $filled >= $slots) {
        throw new RuntimeException('This role is already full.');
      }

      // Normalize comment
      $commentValue = ($comment !== null && trim($comment) !== '') ? trim($comment) : null;

      // Insert signup if not already signed up
      $st = $db->prepare("INSERT INTO volunteer_signups (event_id, role_id, user_id, comment) VALUES (?,?,?,?)");
      $st->execute([$eventId, $roleId, $userId, $commentValue]);

      $db->commit();
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      // Duplicate means user already signed for this role
      if ($e instanceof PDOException && (int)$e->getCode() === 23000) {
        throw new RuntimeException('You are already signed up for this role.');
      }
      throw $e;
    }
  }

  // Admin signup: sign up another user for a role (bypasses RSVP check)
  public static function adminSignup(int $eventId, int $roleId, int $userId, ?string $comment = null): void {
    $eventId = (int)$eventId; $roleId = (int)$roleId; $userId = (int)$userId;
    if ($eventId <= 0 || $roleId <= 0 || $userId <= 0) {
      throw new RuntimeException('Invalid signup request.');
    }

    $db = pdo();
    $db->beginTransaction();
    try {
      // Lock role row to prevent concurrent overfills
      $st = $db->prepare("SELECT id, slots_needed FROM volunteer_roles WHERE id=? AND event_id=? FOR UPDATE");
      $st->execute([$roleId, $eventId]);
      $role = $st->fetch();
      if (!$role) {
        throw new RuntimeException('Volunteer role not found.');
      }
      $slots = (int)$role['slots_needed'];

      // Count current signups for this role (within txn)
      $st = $db->prepare("SELECT COUNT(*) AS c FROM volunteer_signups WHERE role_id=?");
      $st->execute([$roleId]);
      $filled = (int)($st->fetch()['c'] ?? 0);

      if ($slots > 0 && $filled >= $slots) {
        throw new RuntimeException('This role is already full.');
      }

      // Normalize comment
      $commentValue = ($comment !== null && trim($comment) !== '') ? trim($comment) : null;

      // Insert signup if not already signed up
      $st = $db->prepare("INSERT INTO volunteer_signups (event_id, role_id, user_id, comment) VALUES (?,?,?,?)");
      $st->execute([$eventId, $roleId, $userId, $commentValue]);

      $db->commit();
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      // Duplicate means user already signed for this role
      if ($e instanceof PDOException && (int)$e->getCode() === 23000) {
        throw new RuntimeException('This person is already signed up for this role.');
      }
      throw $e;
    }
  }

  // Remove a user's signup for a role
  public static function removeSignup(int $roleId, int $userId): void {
    $st = pdo()->prepare("DELETE FROM volunteer_signups WHERE role_id=? AND user_id=?");
    $st->execute([(int)$roleId, (int)$userId]);
  }

  // Save roles for an event: upsert present rows, delete missing
  // Input: $roles = [ ['id'=>int|0, 'title'=>string, 'slots_needed'=>int>=0, 'sort_order'=>int>=0], ... ]
  public static function saveRoles(int $eventId, array $roles): void {
    $eventId = (int)$eventId;
    $db = pdo();
    $db->beginTransaction();
    try {
      // Load existing IDs
      $st = $db->prepare("SELECT id FROM volunteer_roles WHERE event_id=?");
      $st->execute([$eventId]);
      $existingIds = array_map(fn($r)=> (int)$r['id'], $st->fetchAll());

      $keepIds = [];

      // Upsert
      foreach ($roles as $r) {
        $id = (int)($r['id'] ?? 0);
        $title = trim((string)($r['title'] ?? ''));
        $slots = (int)max(0, (int)($r['slots_needed'] ?? 0));
        $order = (int)max(0, (int)($r['sort_order'] ?? 0));
        $desc = trim((string)($r['description'] ?? ''));
        $desc = ($desc === '') ? null : $desc;
        if ($title === '') continue;

        if ($id > 0) {
          $up = $db->prepare("UPDATE volunteer_roles SET title=?, description=?, slots_needed=?, sort_order=? WHERE id=? AND event_id=?");
          $up->execute([$title, $desc, $slots, $order, $id, $eventId]);
          $keepIds[] = $id;
        } else {
          $ins = $db->prepare("INSERT INTO volunteer_roles (event_id, title, description, slots_needed, sort_order) VALUES (?,?,?,?,?)");
          $ins->execute([$eventId, $title, $desc, $slots, $order]);
          $keepIds[] = (int)$db->lastInsertId();
        }
      }

      // Delete missing
      $toDelete = array_diff($existingIds, $keepIds);
      if (!empty($toDelete)) {
        $in = implode(',', array_fill(0, count($toDelete), '?'));
        $params = array_map('intval', array_values($toDelete));
        $del = $db->prepare("DELETE FROM volunteer_roles WHERE event_id=? AND id IN ($in)");
        $del->execute(array_merge([$eventId], $params));
      }

      $db->commit();
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      throw $e;
    }
  }
}
