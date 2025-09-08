<?php
declare(strict_types=1);

/**
 * Backfill legacy filesystem-stored uploads into DB-backed storage.
 *
 * Usage:
 *   php bin/backfill_files.php [--dry-run] [--verbose] [--limit=N] [--only=users,youth,events,reimbursements]
 *
 * Notes:
 * - Run AFTER database migrations that create public_files/secure_files and link columns.
 * - This script reads bytes from legacy paths and inserts rows into public_files (for users/youth/events)
 *   or secure_files (for reimbursement attachments), updating the referencing FK columns.
 * - Legacy columns (photo_path, stored_path) are intentionally NOT cleared here; keep them until you verify.
 *
 * Safety:
 * - Only reads files that resolve within the project root (no traversal outside).
 * - Skips missing/unsafe paths and logs the reason.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Files.php';

const ENTITY_USERS = 'users';
const ENTITY_YOUTH = 'youth';
const ENTITY_EVENTS = 'events';
const ENTITY_REIMB = 'reimbursements';

$projectRoot = realpath(__DIR__ . '/..') ?: getcwd();

/** Parse CLI args */
$dryRun = false;
$verbose = false;
$limit = null; // null means no limit
$only = [ENTITY_USERS, ENTITY_YOUTH, ENTITY_EVENTS, ENTITY_REIMB];

foreach ($argv as $arg) {
  if ($arg === '--dry-run') $dryRun = true;
  if ($arg === '--verbose') $verbose = true;
  if (strpos($arg, '--limit=') === 0) {
    $v = (int)substr($arg, strlen('--limit='));
    if ($v > 0) $limit = $v;
  }
  if (strpos($arg, '--only=') === 0) {
    $v = trim(substr($arg, strlen('--only=')));
    if ($v !== '') {
      $parts = array_map('trim', explode(',', $v));
      $valid = [];
      foreach ($parts as $p) {
        $p = strtolower($p);
        if (in_array($p, [ENTITY_USERS, ENTITY_YOUTH, ENTITY_EVENTS, ENTITY_REIMB], true)) {
          $valid[] = $p;
        }
      }
      if (!empty($valid)) $only = array_values(array_unique($valid));
    }
  }
}

function logln(string $msg): void {
  fwrite(STDOUT, $msg . PHP_EOL);
}
function warn(string $msg): void {
  fwrite(STDERR, "[WARN] " . $msg . PHP_EOL);
}
function err(string $msg): void {
  fwrite(STDERR, "[ERROR] " . $msg . PHP_EOL);
}

/**
 * Resolve a legacy path to an on-disk file and return bytes, mime, and original name.
 * - Only accepts files within $projectRoot.
 * - Accepts legacy paths with or without leading slash.
 */
function readLegacyFile(string $projectRoot, string $legacyPath): ?array {
  $legacyPath = trim($legacyPath);
  if ($legacyPath === '') return null;

  // Normalize to relative (no leading slash)
  $rel = ltrim($legacyPath, '/');

  // Attempt under project root
  $candidate = $projectRoot . DIRECTORY_SEPARATOR . $rel;

  // Allow direct absolute under project root if stored as absolute-within-project-root
  $pathsToTry = [$candidate];
  if (strpos($legacyPath, $projectRoot) === 0) {
    $pathsToTry[] = $legacyPath;
  }

  $full = null;
  foreach ($pathsToTry as $p) {
    if (is_file($p)) {
      $rp = realpath($p);
      if ($rp !== false && strpos($rp, $projectRoot) === 0) {
        $full = $rp;
        break;
      }
    }
  }

  if ($full === null) return null;

  $data = @file_get_contents($full);
  if ($data === false) return null;

  $finfo = @finfo_open(FILEINFO_MIME_TYPE);
  $mime = $finfo ? @finfo_file($finfo, $full) : null;
  if ($finfo) @finfo_close($finfo);
  if (!is_string($mime) || $mime === '') $mime = 'application/octet-stream';

  $originalName = basename($full);

  return ['data' => $data, 'mime' => $mime, 'name' => $originalName, 'path' => $full];
}

/** Backfill users.photo_path -> users.photo_public_file_id */
function backfillUsers(string $projectRoot, ?int $limit, bool $dryRun, bool $verbose): array {
  $pdo = pdo();
  $sql = "SELECT id, photo_path, photo_public_file_id FROM users WHERE (photo_public_file_id IS NULL OR photo_public_file_id = 0) AND photo_path IS NOT NULL AND photo_path <> ''";
  if ($limit !== null) $sql .= " LIMIT " . (int)$limit;
  $st = $pdo->query($sql);
  $rows = $st->fetchAll() ?: [];
  $processed = 0; $inserted = 0; $skipped = 0; $errors = 0;

  foreach ($rows as $r) {
    $processed++;
    $uid = (int)$r['id'];
    $legacy = (string)$r['photo_path'];

    $file = readLegacyFile($projectRoot, $legacy);
    if ($file === null) {
      $skipped++;
      if ($verbose) warn("users id=$uid legacy file missing/unsafe: $legacy");
      continue;
    }

    $data = $file['data'];
    $mime = $file['mime'];
    $orig = $file['name'];

    if ($dryRun) {
      $inserted++;
      logln("[DRY-RUN] users id=$uid would insert public_files and set photo_public_file_id (src: {$file['path']})");
      continue;
    }

    try {
      $pfId = Files::insertPublicFile($data, $mime, $orig, $uid /* creator */);
      $up = $pdo->prepare("UPDATE users SET photo_public_file_id = ? WHERE id = ?");
      $up->execute([$pfId, $uid]);
      $inserted++;
      if ($verbose) logln("users id=$uid -> public_files id=$pfId (src: {$file['path']})");
    } catch (Throwable $e) {
      $errors++;
      err("users id=$uid failed: " . $e->getMessage());
    }
  }

  return compact('processed','inserted','skipped','errors');
}

/** Backfill youth.photo_path -> youth.photo_public_file_id */
function backfillYouth(string $projectRoot, ?int $limit, bool $dryRun, bool $verbose): array {
  $pdo = pdo();
  $sql = "SELECT id, photo_path, photo_public_file_id FROM youth WHERE (photo_public_file_id IS NULL OR photo_public_file_id = 0) AND photo_path IS NOT NULL AND photo_path <> ''";
  if ($limit !== null) $sql .= " LIMIT " . (int)$limit;
  $st = $pdo->query($sql);
  $rows = $st->fetchAll() ?: [];
  $processed = 0; $inserted = 0; $skipped = 0; $errors = 0;

  foreach ($rows as $r) {
    $processed++;
    $yid = (int)$r['id'];
    $legacy = (string)$r['photo_path'];

    $file = readLegacyFile($projectRoot, $legacy);
    if ($file === null) {
      $skipped++;
      if ($verbose) warn("youth id=$yid legacy file missing/unsafe: $legacy");
      continue;
    }

    $data = $file['data'];
    $mime = $file['mime'];
    $orig = $file['name'];

    if ($dryRun) {
      $inserted++;
      logln("[DRY-RUN] youth id=$yid would insert public_files and set photo_public_file_id (src: {$file['path']})");
      continue;
    }

    try {
      $pfId = Files::insertPublicFile($data, $mime, $orig, null /* unknown creator */);
      $up = $pdo->prepare("UPDATE youth SET photo_public_file_id = ? WHERE id = ?");
      $up->execute([$pfId, $yid]);
      $inserted++;
      if ($verbose) logln("youth id=$yid -> public_files id=$pfId (src: {$file['path']})");
    } catch (Throwable $e) {
      $errors++;
      err("youth id=$yid failed: " . $e->getMessage());
    }
  }

  return compact('processed','inserted','skipped','errors');
}

/** Backfill events.photo_path -> events.photo_public_file_id */
function backfillEvents(string $projectRoot, ?int $limit, bool $dryRun, bool $verbose): array {
  $pdo = pdo();
  $sql = "SELECT id, photo_path, photo_public_file_id FROM events WHERE (photo_public_file_id IS NULL OR photo_public_file_id = 0) AND photo_path IS NOT NULL AND photo_path <> ''";
  if ($limit !== null) $sql .= " LIMIT " . (int)$limit;
  $st = $pdo->query($sql);
  $rows = $st->fetchAll() ?: [];
  $processed = 0; $inserted = 0; $skipped = 0; $errors = 0;

  foreach ($rows as $r) {
    $processed++;
    $eid = (int)$r['id'];
    $legacy = (string)$r['photo_path'];

    $file = readLegacyFile($projectRoot, $legacy);
    if ($file === null) {
      $skipped++;
      if ($verbose) warn("events id=$eid legacy file missing/unsafe: $legacy");
      continue;
    }

    $data = $file['data'];
    $mime = $file['mime'];
    $orig = $file['name'];

    if ($dryRun) {
      $inserted++;
      logln("[DRY-RUN] events id=$eid would insert public_files and set photo_public_file_id (src: {$file['path']})");
      continue;
    }

    try {
      $pfId = Files::insertPublicFile($data, $mime, $orig, null /* unknown creator */);
      $up = $pdo->prepare("UPDATE events SET photo_public_file_id = ? WHERE id = ?");
      $up->execute([$pfId, $eid]);
      $inserted++;
      if ($verbose) logln("events id=$eid -> public_files id=$pfId (src: {$file['path']})");
    } catch (Throwable $e) {
      $errors++;
      err("events id=$eid failed: " . $e->getMessage());
    }
  }

  return compact('processed','inserted','skipped','errors');
}

/** Backfill reimbursement_request_files.stored_path -> reimbursement_request_files.secure_file_id via secure_files */
function backfillReimbursements(string $projectRoot, ?int $limit, bool $dryRun, bool $verbose): array {
  $pdo = pdo();
  $sql = "SELECT id, reimbursement_request_id, original_filename, stored_path, created_by
          FROM reimbursement_request_files
          WHERE (secure_file_id IS NULL OR secure_file_id = 0)
            AND stored_path IS NOT NULL AND stored_path <> ''";
  if ($limit !== null) $sql .= " LIMIT " . (int)$limit;
  $st = $pdo->query($sql);
  $rows = $st->fetchAll() ?: [];
  $processed = 0; $inserted = 0; $skipped = 0; $errors = 0;

  foreach ($rows as $r) {
    $processed++;
    $rowId = (int)$r['id'];
    $legacy = (string)$r['stored_path'];
    $origFromRow = (string)($r['original_filename'] ?? '');
    $creator = isset($r['created_by']) ? (int)$r['created_by'] : null;

    $file = readLegacyFile($projectRoot, $legacy);
    if ($file === null) {
      $skipped++;
      if ($verbose) warn("reimb_file id=$rowId legacy file missing/unsafe: $legacy");
      continue;
    }

    $data = $file['data'];
    $mime = $file['mime'];
    $orig = $origFromRow !== '' ? $origFromRow : $file['name'];

    if ($dryRun) {
      $inserted++;
      logln("[DRY-RUN] reimb_file id=$rowId would insert secure_files and set secure_file_id (src: {$file['path']})");
      continue;
    }

    try {
      $sfId = Files::insertSecureFile($data, $mime, $orig, $creator);
      $up = $pdo->prepare("UPDATE reimbursement_request_files SET secure_file_id = ? WHERE id = ?");
      $up->execute([$sfId, $rowId]);
      $inserted++;
      if ($verbose) logln("reimb_file id=$rowId -> secure_files id=$sfId (src: {$file['path']})");
    } catch (Throwable $e) {
      $errors++;
      err("reimb_file id=$rowId failed: " . $e->getMessage());
    }
  }

  return compact('processed','inserted','skipped','errors');
}

/** Run backfill per selected entities */
$overall = [
  ENTITY_USERS => ['processed' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => 0],
  ENTITY_YOUTH => ['processed' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => 0],
  ENTITY_EVENTS => ['processed' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => 0],
  ENTITY_REIMB => ['processed' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => 0],
];

logln(sprintf("Backfill start%s. Project root: %s", $dryRun ? " [DRY-RUN]" : "", $projectRoot));
logln("Entities: " . implode(',', $only) . "; Limit: " . ($limit !== null ? (string)$limit : 'none') . "; Verbose: " . ($verbose ? 'yes' : 'no'));

if (in_array(ENTITY_USERS, $only, true)) {
  logln("\n[users] Backfilling user profile photos...");
  $res = backfillUsers($projectRoot, $limit, $dryRun, $verbose);
  $overall[ENTITY_USERS] = $res;
  logln(sprintf("[users] processed=%d inserted=%d skipped=%d errors=%d", $res['processed'], $res['inserted'], $res['skipped'], $res['errors']));
}

if (in_array(ENTITY_YOUTH, $only, true)) {
  logln("\n[youth] Backfilling youth profile photos...");
  $res = backfillYouth($projectRoot, $limit, $dryRun, $verbose);
  $overall[ENTITY_YOUTH] = $res;
  logln(sprintf("[youth] processed=%d inserted=%d skipped=%d errors=%d", $res['processed'], $res['inserted'], $res['skipped'], $res['errors']));
}

if (in_array(ENTITY_EVENTS, $only, true)) {
  logln("\n[events] Backfilling event photos...");
  $res = backfillEvents($projectRoot, $limit, $dryRun, $verbose);
  $overall[ENTITY_EVENTS] = $res;
  logln(sprintf("[events] processed=%d inserted=%d skipped=%d errors=%d", $res['processed'], $res['inserted'], $res['skipped'], $res['errors']));
}

if (in_array(ENTITY_REIMB, $only, true)) {
  logln("\n[reimbursements] Backfilling reimbursement attachments...");
  $res = backfillReimbursements($projectRoot, $limit, $dryRun, $verbose);
  $overall[ENTITY_REIMB] = $res;
  logln(sprintf("[reimbursements] processed=%d inserted=%d skipped=%d errors=%d", $res['processed'], $res['inserted'], $res['skipped'], $res['errors']));
}

logln("\nSummary:");
$totals = ['processed'=>0,'inserted'=>0,'skipped'=>0,'errors'=>0];
foreach ($overall as $entity => $res) {
  logln(sprintf("  %-16s processed=%d inserted=%d skipped=%d errors=%d",
    $entity, $res['processed'], $res['inserted'], $res['skipped'], $res['errors']));
  $totals['processed'] += $res['processed'];
  $totals['inserted']  += $res['inserted'];
  $totals['skipped']   += $res['skipped'];
  $totals['errors']    += $res['errors'];
}
logln(sprintf("  %-16s processed=%d inserted=%d skipped=%d errors=%d",
  'TOTAL', $totals['processed'], $totals['inserted'], $totals['skipped'], $totals['errors']));

if ($dryRun) {
  logln("\nDry-run complete. Re-run without --dry-run to apply changes.");
} else {
  logln("\nBackfill complete.");
}
