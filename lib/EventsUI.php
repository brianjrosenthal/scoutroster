<?php

class EventsUI {
    
    /**
     * Load RSVP data for an event (shared logic between event_public.php and event_invite.php)
     */
    public static function loadRsvpData(int $eventId): array {
        require_once __DIR__ . '/RSVPManagement.php';
        require_once __DIR__ . '/RsvpsLoggedOutManagement.php';
        
        $guestsTotal = RSVPManagement::sumGuestsByAnswer($eventId, 'yes');
        
        $adultEntries = RSVPManagement::listAdultEntriesByAnswer($eventId, 'yes');
        $youthNames = RSVPManagement::listYouthNamesByAnswer($eventId, 'yes');
        
        // Public RSVPs (logged-out) - list YES only
        $publicRsvps = RsvpsLoggedOutManagement::listByAnswer($eventId, 'yes');
        
        // Public YES totals
        $_pubYesTotals = RsvpsLoggedOutManagement::totalsByAnswer($eventId, 'yes');
        $pubAdultsYes = (int)($_pubYesTotals['adults'] ?? 0);
        $pubKidsYes = (int)($_pubYesTotals['kids'] ?? 0);
        
        $rsvpCommentsByAdult = RSVPManagement::getCommentsByParentForEvent($eventId);
        sort($youthNames);
        usort($adultEntries, function($a,$b){ return strcmp($a['name'], $b['name']); });
        
        // Counts (YES)
        $youthCount = count($youthNames);
        $adultCount = count($adultEntries);
        $adultCountCombined = $adultCount + $pubAdultsYes;
        $youthCountCombined = $youthCount + $pubKidsYes;
        
        $_maybeCounts = RSVPManagement::countDistinctParticipantsByAnswer($eventId, 'maybe');
        $maybeAdultsIn = (int)($_maybeCounts['adults'] ?? 0);
        $maybeYouthIn  = (int)($_maybeCounts['youth'] ?? 0);
        $maybeGuestsIn = RSVPManagement::sumGuestsByAnswer($eventId, 'maybe');
        
        // Public MAYBE totals
        $_pubMaybeTotals = RsvpsLoggedOutManagement::totalsByAnswer($eventId, 'maybe');
        $pubAdultsMaybe = (int)($_pubMaybeTotals['adults'] ?? 0);
        $pubKidsMaybe = (int)($_pubMaybeTotals['kids'] ?? 0);
        
        // Combine MAYBE totals
        $maybeAdultsTotal = $maybeAdultsIn + $pubAdultsMaybe;
        $maybeYouthTotal  = $maybeYouthIn  + $pubKidsMaybe;
        $maybeGuestsTotal = $maybeGuestsIn;
        
        return [
            'guestsTotal' => $guestsTotal,
            'adultEntries' => $adultEntries,
            'youthNames' => $youthNames,
            'publicRsvps' => $publicRsvps,
            'pubAdultsYes' => $pubAdultsYes,
            'pubKidsYes' => $pubKidsYes,
            'rsvpCommentsByAdult' => $rsvpCommentsByAdult,
            'youthCount' => $youthCount,
            'adultCount' => $adultCount,
            'adultCountCombined' => $adultCountCombined,
            'youthCountCombined' => $youthCountCombined,
            'maybeAdultsTotal' => $maybeAdultsTotal,
            'maybeYouthTotal' => $maybeYouthTotal,
            'maybeGuestsTotal' => $maybeGuestsTotal
        ];
    }
    
    /**
     * Render the event details card (shared between event_public.php and event_invite.php)
     */
    public static function renderEventDetailsCard(array $event): string {
        require_once __DIR__ . '/Files.php';
        require_once __DIR__ . '/Text.php';
        
        $heroUrl = Files::eventPhotoUrl($event['photo_public_file_id'] ?? null);
        $locName = trim((string)($event['location'] ?? ''));
        $locAddr = trim((string)($event['location_address'] ?? ''));
        $mapsUrl = trim((string)($event['google_maps_url'] ?? ''));
        $mapHref = $mapsUrl !== '' ? $mapsUrl : ($locAddr !== '' ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($locAddr) : '');
        
        ob_start();
        ?>
        <div class="card">
          <?php if ($heroUrl !== ''): ?>
            <img src="<?= h($heroUrl) ?>" alt="<?= h($event['name']) ?> image" class="event-hero" width="220">
          <?php endif; ?>
          <p><strong>When:</strong> <?= h(Settings::formatDateTimeRange($event['starts_at'], !empty($event['ends_at']) ? $event['ends_at'] : null)) ?></p>
          <?php if ($locName !== '' || $locAddr !== ''): ?>
            <p><strong>Where:</strong>
              <?php if ($locName !== ''): ?>
                <?= h($locName) ?><?php if ($mapHref !== ''): ?>
                  <a class="small" href="<?= h($mapHref) ?>" target="_blank" rel="noopener">map</a><br>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($locAddr !== ''): ?>
                <?= nl2br(h($locAddr)) ?>
              <?php endif; ?>
            </p>
          <?php endif; ?>
          <?php if (!empty($event['description'])): ?>
            <div><?= Text::renderMarkup((string)$event['description']) ?></div>
          <?php endif; ?>
          <?php if (!empty($event['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$event['max_cub_scouts'] ?></p><?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render the Current RSVPs section (shared between event_public.php and event_invite.php)
     */
    public static function renderCurrentRsvpsSection(int $eventId, array $event, string $eviteUrl = ''): string {
        if ($eviteUrl !== '') {
            return ''; // Don't show RSVPs section for Evite events
        }
        
        $rsvpData = self::loadRsvpData($eventId);
        
        ob_start();
        ?>
        <!-- Current RSVPs -->
        <div class="card">
          <h3>Current RSVPs</h3>
          <p class="small">
            Adults: <?= (int)$rsvpData['adultCountCombined'] ?> &nbsp;&nbsp; | &nbsp;&nbsp;
            Cub Scouts: <?= (int)$rsvpData['youthCountCombined'] ?><?= !empty($event['max_cub_scouts']) ? ' / '.(int)$event['max_cub_scouts'] : '' ?> &nbsp;&nbsp; | &nbsp;&nbsp;
            Other Guests: <?= (int)$rsvpData['guestsTotal'] ?>
            <?php if ($rsvpData['maybeAdultsTotal'] + $rsvpData['maybeYouthTotal'] + $rsvpData['maybeGuestsTotal'] > 0): ?>
              &nbsp;&nbsp; | &nbsp;&nbsp; <em>(<?= (int)$rsvpData['maybeAdultsTotal'] ?> adults, <?= (int)$rsvpData['maybeYouthTotal'] ?> cub scouts, and <?= (int)$rsvpData['maybeGuestsTotal'] ?> other guests RSVP'd maybe)</em>
            <?php endif; ?>
          </p>

          <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
            <div>
              <h4>Adults</h4>
              <?php if (empty($rsvpData['adultEntries'])): ?>
                <p class="small">No adults yet.</p>
              <?php else: ?>
                <ul>
                  <?php foreach ($rsvpData['adultEntries'] as $a): ?>
                    <li>
                      <?= h($a['name']) ?>
                      <?php if (!empty($rsvpData['rsvpCommentsByAdult'][(int)$a['id']] ?? '')): ?>
                        <div class="small" style="font-style:italic;"><?= nl2br(h($rsvpData['rsvpCommentsByAdult'][(int)$a['id']])) ?></div>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
            <div>
              <h4>Cub Scouts</h4>
              <?php if (empty($rsvpData['youthNames'])): ?>
                <p class="small">No cub scouts yet.</p>
              <?php else: ?>
                <ul>
                  <?php foreach ($rsvpData['youthNames'] as $n): ?><li><?=h($n)?></li><?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
            <div>
              <h4>Public RSVPs</h4>
              <?php if (empty($rsvpData['publicRsvps'])): ?>
                <p class="small">No public RSVPs yet.</p>
              <?php else: ?>
                <ul>
                  <?php foreach ($rsvpData['publicRsvps'] as $pr): ?>
                    <li>
                      <?= h(trim(($pr['last_name'] ?? '').', '.($pr['first_name'] ?? ''))) ?>
                      — <?= (int)($pr['total_adults'] ?? 0) ?> adult<?= ((int)($pr['total_adults'] ?? 0) === 1 ? '' : 's') ?>,
                      <?= (int)($pr['total_kids'] ?? 0) ?> kid<?= ((int)($pr['total_kids'] ?? 0) === 1 ? '' : 's') ?>
                      <?php $pc = trim((string)($pr['comment'] ?? '')); if ($pc !== ''): ?>
                        <div class="small" style="font-style:italic;"><?= nl2br(h($pc)) ?></div>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render the Evite card (shared between event_public.php and event_invite.php)
     */
    public static function renderEviteCard(string $eviteUrl, ?string $displayName = null): string {
        if ($eviteUrl === '') {
            return '';
        }
        
        ob_start();
        ?>
        <div class="card">
          <?php if ($displayName !== null): ?>
            <p><strong>Hello <?= h($displayName) ?>!</strong></p>
          <?php else: ?>
            <p><strong>Hello!</strong></p>
          <?php endif; ?>
          <p>RSVPs for this event are handled on Evite.</p>
          <a class="button primary" target="_blank" rel="noopener" href="<?= h($eviteUrl) ?>">RSVP TO EVITE</a>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render RSVP buttons card with context-specific handling
     */
    public static function renderRsvpButtonsCard(string $context, array $options = []): string {
        $displayName = $options['displayName'] ?? null;
        $hasExistingRsvp = $options['hasExistingRsvp'] ?? false;
        $buttonIdPrefix = $options['buttonIdPrefix'] ?? 'rsvp';
        $editButtonId = $options['editButtonId'] ?? null;
        
        ob_start();
        ?>
        <div class="card">
          <?php if ($displayName !== null): ?>
            <p><strong>Hello <?= h($displayName) ?>!</strong></p>
          <?php endif; ?>
          <?php if ($hasExistingRsvp): ?>
            <p>You have an RSVP on file. You can edit your selections below.</p>
            <div class="actions"><button class="button" id="<?= h($editButtonId) ?>">Edit</button></div>
          <?php else: ?>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-start;">
              <strong>RSVP:</strong>
              <button class="primary" id="<?= h($buttonIdPrefix) ?>YesBtn">Yes</button>
              <button id="<?= h($buttonIdPrefix) ?>MaybeBtn">Maybe</button>
              <button id="<?= h($buttonIdPrefix) ?>NoBtn">No</button>
            </div>
          <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
