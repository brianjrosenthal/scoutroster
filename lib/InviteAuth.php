<?php
declare(strict_types=1);

require_once __DIR__ . '/../settings.php';

/**
 * Helpers for the email-invite (HMAC-signed link) authentication used by
 * event_invite.php, volunteer_actions.php and event_volunteer.php.
 */
class InviteAuth {
  public static function b64urlEncode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
  }

  public static function b64urlDecode(string $str): string {
    $pad = strlen($str) % 4;
    if ($pad > 0) $str .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($str, '-_', '+/')) ?: '';
  }

  public static function isConfigured(): bool {
    return defined('INVITE_HMAC_KEY') && INVITE_HMAC_KEY !== '';
  }

  public static function signature(int $uid, int $eventId): string {
    if (!self::isConfigured()) return '';
    $payload = $uid . ':' . $eventId;
    return self::b64urlEncode(hash_hmac('sha256', $payload, INVITE_HMAC_KEY, true));
  }

  /** @return string|null Error message, or null when the signature is valid. */
  public static function validate(int $uid, int $eventId, string $sig): ?string {
    if (!self::isConfigured()) return 'Invite system not configured.';
    if ($uid <= 0 || $eventId <= 0 || $sig === '') return 'Invalid link';
    $expected = self::signature($uid, $eventId);
    if (!hash_equals($expected, $sig)) return 'Invalid link';
    return null;
  }

  /** Invite tokens stop working once the event has ended (or 1h after start if no end). */
  public static function eventHasEnded(array $event): bool {
    try {
      $tz = new DateTimeZone(Settings::timezoneId());
      if (!empty($event['ends_at'])) {
        $endRef = new DateTime((string)$event['ends_at'], $tz);
      } else {
        $endRef = new DateTime((string)$event['starts_at'], $tz);
        $endRef->modify('+1 hour');
      }
      $nowTz = new DateTime('now', $tz);
      return $nowTz >= $endRef;
    } catch (Throwable $e) {
      return false;
    }
  }

  /** Build the event_invite.php URL for a given invitee. */
  public static function inviteUrl(int $uid, int $eventId, string $sig): string {
    return '/event_invite.php?uid=' . $uid . '&event_id=' . $eventId . '&sig=' . rawurlencode($sig);
  }

  /** Build the event_volunteer.php URL, with invite params when provided. */
  public static function volunteerPageUrl(int $eventId, ?int $uid = null, ?string $sig = null): string {
    $url = '/event_volunteer.php?event_id=' . $eventId;
    if ($uid !== null && $sig !== null && $uid > 0 && $sig !== '') {
      $url .= '&uid=' . $uid . '&sig=' . rawurlencode($sig);
    }
    return $url;
  }
}
