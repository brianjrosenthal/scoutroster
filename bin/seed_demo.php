#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Seed demo data for Cub Scouts app:
 *  - Ensures baseline Settings (site_title, timezone)
 *  - Creates an admin user (admin@example.com / Admin123!)
 *  - Creates a sample upcoming event with location + address
 *
 * Usage:
 *   php cub_scouts/bin/seed_demo.php
 *
 * Prereqs:
 *   - cub_scouts/config.local.php configured
 *   - DB schema + migrations applied (php cub_scouts/bin/migrate.php)
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/settings.php';

if (php_sapi_name() !== 'cli') {
  header('Content-Type: text/plain; charset=utf-8');
  echo "Run this script from the command line: php cub_scouts/bin/seed_demo.php\n";
  exit(1);
}

try {
  $pdo = pdo();
} catch (Throwable $e) {
  fwrite(STDERR, "Failed to connect to database. Check cub_scouts/config.local.php\nError: " . $e->getMessage() . "\n");
  exit(2);
}

// Basic checks that schema was applied (exists events table)
try {
  $pdo->query("SELECT 1 FROM events LIMIT 1");
} catch (Throwable $e) {
  fwrite(STDERR, "It looks like the schema/migrations haven't been applied yet.\n");
  fwrite(STDERR, "Run: php cub_scouts/bin/migrate.php\n");
  exit(3);
}

echo "Seeding settings...\n";
// Settings
$insSetting = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
$insSetting->execute(['site_title', 'Cub Scouts Pack 440']);
$insSetting->execute(['announcement', '']);
$insSetting->execute(['timezone', 'America/New_York']);

echo "Ensuring admin user exists...\n";
// Admin user (Admin123!)
$adminEmail = 'admin@example.com';
$st = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$st->execute([$adminEmail]);
$adminId = (int)($st->fetchColumn() ?: 0);

if ($adminId === 0) {
  $ins = $pdo->prepare("
    INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
    VALUES (?, ?, ?, ?, 1, NOW())
  ");
  // Hash corresponds to password: Admin123! (from schema.sql comment)
  $hash = '$2y$10$9xH7Jq4v3o6s9k3y8i4rVOyWb0yBYZ5rW.0f9pZ.gG9K6l7lS6b2S';
  $ins->execute(['Admin', 'User', $adminEmail, $hash]);
  $adminId = (int)$pdo->lastInsertId();
  echo "Created admin user: {$adminEmail} (id={$adminId}, password=Admin123!)\n";
} else {
  echo "Admin user already exists: {$adminEmail} (id={$adminId})\n";
}

echo "Creating a sample upcoming event if none exist...\n";
$st = $pdo->query("SELECT id FROM events WHERE starts_at > NOW() ORDER BY starts_at ASC LIMIT 1");
$existingEventId = (int)($st->fetchColumn() ?: 0);

if ($existingEventId === 0) {
  // Build a start time ~7 days from now at 18:00, end +2 hours
  $start = new DateTime('+7 days');
  $start->setTime(18, 0, 0);
  $end = clone $start;
  $end->modify('+2 hours');

  $insE = $pdo->prepare("
    INSERT INTO events (name, starts_at, ends_at, location, location_address, description, max_cub_scouts)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $insE->execute([
    'Pack Meeting',
    $start->format('Y-m-d H:i:s'),
    $end->format('Y-m-d H:i:s'),
    'Community Center Gym',
    "123 Main St\nTownsville, NY 10001",
    "Join us for our monthly Pack Meeting.\nAgenda: awards, activities, and announcements.",
    50
  ]);
  $eventId = (int)$pdo->lastInsertId();
  echo "Created sample event: Pack Meeting (id={$eventId})\n";
  echo "URL (once server is running): /event.php?id={$eventId}\n";
  echo "Public RSVP URL: /event_public.php?event_id={$eventId}\n";
} else {
  echo "Upcoming event exists (id={$existingEventId}).\n";
  echo "URL (once server is running): /event.php?id={$existingEventId}\n";
  echo "Public RSVP URL: /event_public.php?event_id={$existingEventId}\n";
}

echo "\nDone.\n";
