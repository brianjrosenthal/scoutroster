<?php
declare(strict_types=1);

require_once __DIR__ . '/EventPhotos.php';
require_once __DIR__ . '/PhotoStorage.php';

/**
 * HTML fragments for event photos, shared by pages and the JSON endpoints
 * (which return a refreshed tile so the browser can swap it in place).
 * Rows passed in must already carry 'urls' (EventPhotos::withUrls).
 */
final class EventPhotosUI {

  private static function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
  }

  /** Formatted capture date, with a marker when it is estimated rather than from EXIF. */
  public static function takenAtText(array $photo): string {
    $t = (string)($photo['taken_at'] ?? '');
    if ($t === '') return '';
    $ts = strtotime($t);
    $txt = $ts ? date('M j, Y g:i A', $ts) : $t;
    if (self::isEstimated($photo)) $txt .= ' (estimated)';
    return $txt;
  }

  /** True when the date did not come from the camera (EXIF) or a person. */
  public static function isEstimated(array $photo): bool {
    return !in_array((string)($photo['taken_at_source'] ?? 'upload'), ['exif', 'manual'], true);
  }

  /** "2026-10-03T14:05" for a datetime-local input. */
  public static function takenAtInputValue(array $photo): string {
    $ts = strtotime((string)($photo['taken_at'] ?? ''));
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
  }

  /** One grid tile. $index is the 0-based position in gallery order. */
  public static function renderTile(array $photo, bool $canModify, bool $isAdmin, int $index): string {
    $urls = $photo['urls'] ?? EventPhotos::urlsFor($photo);
    $excluded = !empty($photo['exclude_from_slideshow']);
    $caption = (string)($photo['caption'] ?? '');
    $uploader = EventPhotos::uploaderName($photo);
    $classes = 'photo-tile' . ($excluded ? ' excluded' : '');
    $alt = $caption !== '' ? $caption : 'Photo ' . ($index + 1);

    $html = '<figure class="' . $classes . '"'
      . ' data-photo-id="' . (int)$photo['id'] . '"'
      . ' data-index="' . $index . '"'
      . ' data-thumb-url="' . self::h($urls['thumb']) . '"'
      . ' data-display-url="' . self::h($urls['display']) . '"'
      . ' data-original-url="' . self::h($urls['original']) . '"'
      . ' data-caption="' . self::h($caption) . '"'
      . ' data-excluded="' . ($excluded ? '1' : '0') . '"'
      . ' data-uploader="' . self::h($uploader) . '"'
      . ' data-taken-text="' . self::h(self::takenAtText($photo)) . '"'
      . ' data-taken-input="' . self::h(self::takenAtInputValue($photo)) . '"'
      . ' data-estimated="' . (self::isEstimated($photo) ? '1' : '0') . '"'
      . ' data-can-modify="' . ($canModify ? '1' : '0') . '">';
    $html .= '<a href="' . self::h($urls['display']) . '" class="photo-open" aria-label="' . self::h($alt) . '">'
      . '<img src="' . self::h($urls['thumb']) . '" alt="' . self::h($alt) . '" loading="lazy" width="' . (int)($photo['width'] ?? 0) . '" height="' . (int)($photo['height'] ?? 0) . '">'
      . '</a>';
    if ($excluded) {
      $html .= '<span class="tile-badge" title="This photo will not appear in slideshows">Not in slideshow</span>';
    }
    if (self::isEstimated($photo)) {
      $html .= '<span class="tile-date-est" title="No date in this photo (sent through WhatsApp or similar); the date is estimated. Open it to set the real date.">date?</span>';
    }
    if ($canModify) {
      $html .= '<div class="tile-controls">'
        . '<button type="button" class="tile-btn toggle-slideshow" aria-pressed="' . ($excluded ? 'true' : 'false') . '" title="' . ($excluded ? 'Include in slideshows' : 'Exclude from slideshows') . '">' . ($excluded ? 'Excluded' : 'In slideshow') . '</button>'
        . '<button type="button" class="tile-btn delete" title="Delete this photo">Delete</button>'
        . '</div>';
    }
    if ($isAdmin) {
      $html .= '<span class="tile-check" aria-hidden="true"></span>';
    }
    $html .= '</figure>';
    return $html;
  }

  /** The whole grid for a gallery page. */
  public static function renderGrid(array $photos, ?UserContext $ctx): string {
    $isAdmin = $ctx ? (bool)$ctx->admin : false;
    $html = '';
    $i = 0;
    foreach ($photos as $p) {
      $html .= self::renderTile($p, EventPhotos::canModify($ctx, $p), $isAdmin, $i++);
    }
    return $html;
  }

  /** The card shown on event.php: a strip of thumbnails plus links. */
  public static function renderEventCard(array $event, array $firstPhotos, int $count, bool $configured): string {
    $eventId = (int)$event['id'];
    if ($count === 0 && !$configured) return '';
    $galleryUrl = '/event_photos.php?event_id=' . $eventId;
    $html = '<div class="card" id="eventPhotosCard">';
    $html .= '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">'
      . '<h3 style="margin:0">Photos' . ($count > 0 ? ' <span class="small">(' . $count . ')</span>' : '') . '</h3>'
      . '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
      . ($count > 0 ? '<a class="button" href="' . self::h($galleryUrl) . '">View all ' . $count . ' photo' . ($count === 1 ? '' : 's') . '</a>' : '')
      . ($configured ? '<a class="button primary" href="' . self::h($galleryUrl . '#upload') . '">Add photos</a>' : '')
      . '</div></div>';
    if ($count > 0) {
      $html .= '<div class="photo-strip">';
      foreach ($firstPhotos as $p) {
        $urls = $p['urls'] ?? EventPhotos::urlsFor($p);
        $html .= '<a href="' . self::h($galleryUrl . '#photo-' . (int)$p['id']) . '"><img src="' . self::h($urls['thumb']) . '" alt="" loading="lazy"></a>';
      }
      $html .= '</div>';
    } else {
      $html .= '<p class="small" style="margin:8px 0 0">No photos yet. Were you there? Add some from your phone.</p>';
    }
    $html .= '</div>';
    return $html;
  }
}
