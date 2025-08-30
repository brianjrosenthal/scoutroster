<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

class Application {
  public static function init(): void {
    // Ensure a session is available (config.php already starts it, but be defensive)
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }

    // Normalize from DB (via current_user) and seed per-request UserContext
    // Ensure current_user() is available
    require_once __DIR__ . '/../auth.php';
    $u = current_user(); // May be null if not logged in

    // Backfill is_admin in the session for legacy/edge sessions
    if (!empty($_SESSION['uid']) && !array_key_exists('is_admin', $_SESSION)) {
      $_SESSION['is_admin'] = (!empty($u) && !empty($u['is_admin'])) ? 1 : 0;
    }

    // Seed the request-scoped UserContext using freshest info
    require_once __DIR__ . '/UserContext.php';
    if (!empty($_SESSION['uid'])) {
      $isAdmin = $u ? !empty($u['is_admin']) : (!empty($_SESSION['is_admin']));
      UserContext::set(new UserContext((int)$_SESSION['uid'], $isAdmin ? true : false));
    }

    // Optional future boot: e.g., timezone from Settings
    // require_once __DIR__ . '/../settings.php';
    // date_default_timezone_set(Settings::timezone() ?: 'UTC');
  }
}
