<?php
require_once __DIR__ . '/config.php';

class Settings {
  private static ?array $cache = null;

  private static function loadAll(): void {
    if (self::$cache !== null) return;
    self::$cache = [];
    try {
      $rows = pdo()->query("SELECT key_name, value FROM settings")->fetchAll();
      foreach ($rows as $r) {
        self::$cache[$r['key_name']] = $r['value'];
      }
    } catch (Throwable $e) {
      // Table may not exist yet; keep cache empty
      self::$cache = [];
    }
  }

  public static function get(string $key, string $default = ''): string {
    self::loadAll();
    return array_key_exists($key, self::$cache) ? (string)self::$cache[$key] : $default;
  }

  public static function set(string $key, ?string $value): void {
    $st = pdo()->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
    $st->execute([$key, $value]);
    if (self::$cache !== null) {
      self::$cache[$key] = $value;
    }
  }

  // Returns the selected timezone ID (IANA), defaulting to PHP's default if not set
  public static function timezoneId(): string {
    $tz = self::get('timezone', date_default_timezone_get());
    return $tz ?: 'UTC';
  }

  // Formats a SQL DATETIME string into YYYY-MM-DD HH:MM in the configured timezone
  public static function formatDateTime(?string $sqlDateTime): string {
    if (!$sqlDateTime) return '';
    try {
      $dt = new DateTime($sqlDateTime); // treat as server default tz
      $tz = new DateTimeZone(self::timezoneId());
      $dt->setTimezone($tz);
      return $dt->format('Y-m-d H:i T');
    } catch (Throwable $e) {
      return $sqlDateTime;
    }
  }

  // Formats a start/end SQL DATETIME into "Sep 7, 2025 3pm-5pm ET" (or without end time)
  public static function formatRangeShort(?string $startSql, ?string $endSql = null): string {
    if (!$startSql) return '';
    try {
      $tz = new DateTimeZone(self::timezoneId());

      $s = new DateTime($startSql); // server tz
      $s->setTimezone($tz);

      $e = null;
      if ($endSql) {
        $e = new DateTime($endSql);
        $e->setTimezone($tz);
      }

      $dateStr = $s->format('M j, Y');

      // time like 3pm or 3:30pm (lowercase am/pm and drop :00)
      $fmtTime = function (DateTime $dt): string {
        $t = strtolower($dt->format('g:ia'));
        return str_replace(':00', '', $t);
      };

      // collapse EST/EDT -> ET, otherwise use system abbreviation
      $tzAbbr = $s->format('T');
      $tzOut = ($tzAbbr === 'EST' || $tzAbbr === 'EDT') ? 'ET' : $tzAbbr;

      if ($e) {
        $sameDay = $s->format('Y-m-d') === $e->format('Y-m-d');
        if ($sameDay) {
          return sprintf('%s %s-%s %s', $dateStr, $fmtTime($s), $fmtTime($e), $tzOut);
        } else {
          // Different day: include both dates (keep it compact but clear)
          $endDateStr = $e->format('M j, Y');
          $tzAbbrE = $e->format('T');
          $tzOutE = ($tzAbbrE === 'EST' || $tzAbbrE === 'EDT') ? 'ET' : $tzAbbrE;
          return sprintf('%s %s %s – %s %s %s', $dateStr, $fmtTime($s), $tzOut, $endDateStr, $fmtTime($e), $tzOutE);
        }
      }

      return sprintf('%s %s %s', $dateStr, $fmtTime($s), $tzOut);
    } catch (Throwable $e) {
      return $startSql;
    }
  }

  // Site title helper (falls back to APP_NAME)
  public static function siteTitle(): string {
    return self::get('site_title', defined('APP_NAME') ? APP_NAME : 'Cub Scouts');
  }
}
