<?php
require_once __DIR__.'/partials.php';
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/RSVPManagement.php';
require_once __DIR__ . '/lib/RsvpsLoggedOutManagement.php';
require_once __DIR__ . '/lib/ParentRelationships.php';
require_login();

require_once __DIR__ . '/lib/Text.php';
require_once __DIR__ . '/lib/Volunteers.php';

$me = current_user();
$isAdmin = !empty($me['is_admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

/* Load event */
$e = EventManagement::findById($id);
if (!$e) { http_response_code(404); exit('Event not found'); }
$allowPublic = ((int)($e['allow_non_user_rsvp'] ?? 1) === 1);
$eviteUrl = trim((string)($e['evite_rsvp_url'] ?? ''));

// Flash after RSVP save
$flashSaved = !empty($_GET['rsvp']);

 // Load my RSVP (if any) - creator-preferred; else membership-based
 $myRsvp = RSVPManagement::findMyRsvpForEvent((int)$id, (int)$me['id']);

// Build my RSVP summary
$mySummaryParts = [];
$myGuestsCount = 0;
if ($myRsvp) {
  $myGuestsCount = (int)($myRsvp['n_guests'] ?? 0);
  $names = RSVPManagement::getRsvpMemberDisplayNames((int)$myRsvp['id'], (int)$me['id']);
  $mySummaryParts = array_merge($names['youth'] ?? [], $names['adults'] ?? []);
}

/**
 * Build selectable participants for modal (logged-in editor)
 * - Children of current user
 * - Co-parents (other adults linked to same children) + self
 */
$myChildren = ParentRelationships::listChildrenForAdult((int)$me['id']);

$coParents = ParentRelationships::listCoParentsForAdult((int)$me['id']);

/**
 * Load overall RSVP lists (YES only)
 */
$youthNames = RSVPManagement::listYouthNamesByAnswer((int)$id, 'yes');
$adultEntries = RSVPManagement::listAdultEntriesByAnswer((int)$id, 'yes');

// Public RSVPs (logged-out) - list YES only
$publicRsvps = RsvpsLoggedOutManagement::listByAnswer((int)$id, 'yes');

// Public YES totals
$_pubYesTotals = RsvpsLoggedOutManagement::totalsByAnswer((int)$id, 'yes');
$pubAdultsYes = (int)($_pubYesTotals['adults'] ?? 0);
$pubKidsYes = (int)($_pubYesTotals['kids'] ?? 0);

// Public MAYBE totals
$_pubMaybeTotals = RsvpsLoggedOutManagement::totalsByAnswer((int)$id, 'maybe');
$pubAdultsMaybe = (int)($_pubMaybeTotals['adults'] ?? 0);
$pubKidsMaybe = (int)($_pubMaybeTotals['kids'] ?? 0);

$rsvpCommentsByAdult = RSVPManagement::getCommentsByCreatorForEvent((int)$id);
sort($youthNames);
usort($adultEntries, function($a,$b){ return strcmp($a['name'], $b['name']); });

$guestsTotal = RSVPManagement::sumGuestsByAnswer((int)$id, 'yes');

// Counts (YES)
$youthCount = count($youthNames);
$adultCount = count($adultEntries);
$adultCountCombined = $adultCount + $pubAdultsYes;
$youthCountCombined = $youthCount + $pubKidsYes;

$_maybeIn = RSVPManagement::countDistinctParticipantsByAnswer((int)$id, 'maybe');
$maybeAdultsIn = (int)($_maybeIn['adults'] ?? 0);
$maybeYouthIn  = (int)($_maybeIn['youth'] ?? 0);

$maybeAdultsTotal = $maybeAdultsIn + $pubAdultsMaybe;
$maybeYouthTotal  = $maybeYouthIn + $pubKidsMaybe;

$maybeGuestsTotal = RSVPManagement::sumGuestsByAnswer((int)$id, 'maybe');

// Volunteers
$roles = Volunteers::rolesWithCounts((int)$id);
$hasYes = ($myRsvp && strtolower((string)($myRsvp['answer'] ?? '')) === 'yes');
$openVolunteerRoles = Volunteers::openRolesExist((int)$id);
$showVolunteerModal = $hasYes && $openVolunteerRoles && !empty($_GET['vol']);

header_html('Event');
?>
<h2><?=h($e['name'])?></h2>

<?php if ($flashSaved): ?>
  <p class="flash">Your RSVP has been saved.</p>
<?php endif; ?>

<?php
$myAnswer = strtolower((string)($myRsvp['answer'] ?? 'yes'));
if (!in_array($myAnswer, ['yes','maybe','no'], true)) $myAnswer = 'yes';
?>
<div class="card">
  <?php if ($eviteUrl !== ''): ?>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <a class="button primary" target="_blank" rel="noopener" href="<?= h($eviteUrl) ?>">RSVP TO EVITE</a>
    </div>
  <?php elseif ($myRsvp): ?>
    <div class="rsvp-status rsvp-<?= h($myAnswer) ?>">
      You RSVP’d <strong><?= h(ucfirst($myAnswer)) ?></strong><?= !empty($mySummaryParts) ? ' with '.h(implode(', ', $mySummaryParts)) : '' ?>
      <?= $myGuestsCount > 0 ? ' and '.(int)$myGuestsCount.' guest'.($myGuestsCount === 1 ? '' : 's') : '' ?>.
      <?php
        $creatorId = (int)($myRsvp['created_by_user_id'] ?? 0);
        $enteredById = (int)($myRsvp['entered_by'] ?? 0);
        
        if ($creatorId && $creatorId !== (int)$me['id']) {
          $nameBy = UserManagement::getFullName($creatorId);
          if ($nameBy !== null) {
            echo ' <span class="small">(by ' . h($nameBy) . ')</span>';
          }
        }
        
        if ($enteredById && $enteredById !== $creatorId) {
          $enteredByName = UserManagement::getFullName($enteredById);
          if ($enteredByName !== null) {
            echo ' <span class="small">(entered by ' . h($enteredByName) . ')</span>';
          }
        }
      ?>
      <a class="button" id="rsvpEditBtn">Edit</a>
    </div>
  <?php else: ?>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <strong>RSVP:</strong>
        <button class="primary" id="rsvpYesBtn">Yes</button>
        <button id="rsvpMaybeBtn" class="primary">Maybe</button>
        <button id="rsvpNoBtn">No</button>
      </div>
  <?php endif; ?>
</div>

<div class="card">
  <?php $imgUrl = Files::eventPhotoUrl($e['photo_public_file_id'] ?? null); ?>
  <?php if ($imgUrl !== ''): ?>
    <img src="<?= h($imgUrl) ?>" alt="<?= h($e['name']) ?> image" class="event-hero" width="220">
  <?php endif; ?>
  <p><strong>When:</strong> <?=h(Settings::formatDateTime($e['starts_at']))?><?php if(!empty($e['ends_at'])): ?> &ndash; <?=h(Settings::formatDateTime($e['ends_at']))?><?php endif; ?></p>
  <?php
    $locName = trim((string)($e['location'] ?? ''));
    $locAddr = trim((string)($e['location_address'] ?? ''));
    $mapsUrl = trim((string)($e['google_maps_url'] ?? ''));
    $mapHref = $mapsUrl !== '' ? $mapsUrl : ($locAddr !== '' ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($locAddr) : '');
    if ($locName !== '' || $locAddr !== ''):
  ?>
    <p><strong>Where:</strong>
      <?php if ($locName !== ''): ?>
        <?= h($locName) ?>
        <?php if ($mapHref !== ''): ?>
          <a class="small" href="<?= h($mapHref) ?>" target="_blank" rel="noopener">map</a><br>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($locAddr !== ''): ?>
        <?= nl2br(h($locAddr)) ?>
      <?php endif; ?>
    </p>
  <?php endif; ?>
  <?php if (!empty($e['description'])): ?>
    <div class="description"><?= Text::renderMarkup((string)$e['description']) ?></div>
  <?php endif; ?>
  <?php if (!empty($e['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$e['max_cub_scouts'] ?></p><?php endif; ?>
  <div class="actions">
    <a class="button" href="/events.php">Back to Events</a>
    <?php if ($isAdmin): ?>
      <a class="button" href="/admin_events.php?id=<?= (int)$e['id'] ?>">Edit Event</a>
      <?php if ($allowPublic): ?>
        <a class="button" href="/event_public.php?event_id=<?= (int)$e['id'] ?>">Public RSVP Link</a>
      <?php endif; ?>
      <a class="button" href="/admin_event_invite.php?event_id=<?= (int)$e['id'] ?>">Invite</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($eviteUrl === ''): ?>
<div class="card">
  <h3>RSVPs</h3>
  <p class="small">
    Adults: <?= (int)$adultCountCombined ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Cub Scouts: <?= (int)$youthCountCombined ?><?= !empty($e['max_cub_scouts']) ? ' / '.(int)$e['max_cub_scouts'] : '' ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Other Guests: <?= (int)$guestsTotal ?>
    <?php if ($maybeAdultsTotal + $maybeYouthTotal + $maybeGuestsTotal > 0): ?>
      &nbsp;&nbsp; | &nbsp;&nbsp; <em>(<?= (int)$maybeAdultsTotal ?> adults, <?= (int)$maybeYouthTotal ?> cub scouts, and <?= (int)$maybeGuestsTotal ?> other guests RSVP'd maybe)</em>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      &nbsp;&nbsp; | &nbsp;&nbsp; <button class="button" style="font-size: 12px;" id="adminManageRsvpBtn">Manage RSVPs</button>
    <?php endif; ?>
  </p>

  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
    <div>
      <h4>Adults</h4>
      <?php if (empty($adultEntries)): ?>
        <p class="small">No adults yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($adultEntries as $a): ?>
            <li>
              <?= h($a['name']) ?>
              <?php if (!empty($rsvpCommentsByAdult[(int)$a['id']] ?? '')): ?>
                <div class="small" style="font-style:italic;"><?= nl2br(h($rsvpCommentsByAdult[(int)$a['id']])) ?></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div>
      <h4>Cub Scouts</h4>
      <?php if (empty($youthNames)): ?>
        <p class="small">No cub scouts yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($youthNames as $n): ?><li><?=h($n)?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div>
      <h4>Public RSVPs</h4>
      <?php if (empty($publicRsvps)): ?>
        <p class="small">No public RSVPs yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($publicRsvps as $pr): ?>
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

<?php endif; ?>

<?php if ($isAdmin || !empty($roles)): ?>
<div class="card">
  <h3>Event Volunteers</h3>
  <?php if (empty($roles)): ?>
    <p class="small">No volunteer roles have been defined for this event.</p>
    <?php if ($isAdmin): ?>
      <p class="small"><a class="button" href="/admin_event_volunteers.php?event_id=<?= (int)$e['id'] ?>">Manage Volunteers</a></p>
    <?php endif; ?>
  <?php else: ?>
    <div class="volunteers">
      <?php foreach ($roles as $r): ?>
        <div class="role" style="margin-bottom:10px;">
          <div>
            <strong><?= h($r['title']) ?></strong>
            <?php if (!empty($r['is_unlimited'])): ?>
              <span class="remaining small">(no limit)</span>
            <?php elseif ((int)$r['open_count'] > 0): ?>
              <span class="remaining small">(<?= (int)$r['open_count'] ?> people still needed)</span>
            <?php else: ?>
              <span class="filled small">Filled</span>
            <?php endif; ?>
          </div>
          <?php if (trim((string)($r['description'] ?? '')) !== ''): ?>
            <div style="margin-top:4px; white-space:pre-wrap;"><?= h((string)$r['description']) ?></div>
          <?php endif; ?>

          <?php if (!empty($r['volunteers'])): ?>
            <ul style="margin:6px 0 0 16px;">
              <?php foreach ($r['volunteers'] as $v): ?>
                <li>
                  <?= h($v['name']) ?>
                  <?php if ((int)($v['user_id'] ?? 0) === (int)$me['id']): ?>
                    <form method="post" action="/volunteer_actions.php" class="inline" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                      <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="remove">
                      <a href="#" class="small" onclick="this.closest('form').requestSubmit(); return false;">(remove)</a>
                    </form>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <ul style="margin:6px 0 0 16px;">
              <li>No one yet.</li>
            </ul>
          <?php endif; ?>

          <?php if ($hasYes): ?>
            <?php
              $amIn = false;
              foreach ($r['volunteers'] as $v) { if ((int)$v['user_id'] === (int)$me['id']) { $amIn = true; break; } }
            ?>
            <?php if (!$amIn): ?>
              <form method="post" action="/volunteer_actions.php" class="inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                <?php if (!empty($r['is_unlimited']) || (int)$r['open_count'] > 0): ?>
                  <input type="hidden" name="action" value="signup">
                  <button style="margin-top:6px;" class="button primary">Sign up</button>
                <?php else: ?>
                  <button style="margin-top:6px;" class="button" disabled>Filled</button>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($hasYes && $openVolunteerRoles): ?>
  <!-- Volunteer prompt modal -->
  <div id="volunteerModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
      <button class="close" type="button" id="volunteerModalClose" aria-label="Close">&times;</button>
      <h3>Volunteer to help at this event?</h3>
      <div id="volRoles" class="stack">
      <?php foreach ($roles as $r): ?>
        <div class="role" style="margin-bottom:14px;">
          <div>
            <strong><?= h($r['title']) ?></strong>
            <?php if (!empty($r['is_unlimited'])): ?>
              <span class="remaining">(no limit)</span>
            <?php elseif ((int)$r['open_count'] > 0): ?>
              <span class="remaining">(<?= (int)$r['open_count'] ?> people still needed)</span>
            <?php else: ?>
              <span class="filled">Filled</span>
            <?php endif; ?>
          </div>
          <?php if (trim((string)($r['description'] ?? '')) !== ''): ?>
            <div  style="margin-top:4px; white-space:pre-wrap;"><?= h((string)$r['description']) ?></div>
          <?php endif; ?>

          <?php if (!empty($r['volunteers'])): ?>
            <ul style="margin:6px 0 0 16px;">
              <?php foreach ($r['volunteers'] as $v): ?>
                <li>
                  <?= h($v['name']) ?>
                  <?php if ((int)($v['user_id'] ?? 0) === (int)$me['id']): ?>
                    <form method="post" action="/volunteer_actions.php" class="inline" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                      <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="remove">
                      <a href="#" class="small" onclick="this.closest('form').requestSubmit(); return false;">(remove)</a>
                    </form>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <ul style="margin:6px 0 0 16px;">
              <li>No one yet.</li>
            </ul>
          <?php endif; ?>

          <?php
            $amIn = false;
            foreach ($r['volunteers'] as $v) { if ((int)$v['user_id'] === (int)$me['id']) { $amIn = true; break; } }
          ?>
          <?php if (!$amIn): ?>
          <form method="post" action="/volunteer_actions.php" class="inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
            <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
            <?php if (!empty($r['is_unlimited']) || (int)$r['open_count'] > 0): ?>
              <input type="hidden" name="action" value="signup">
              <button  style="margin-top:6px;" class="button primary">Sign up</button>
            <?php else: ?>
              <button  style="margin-top:6px;" class="button" disabled>Filled</button>
            <?php endif; ?>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>

      <div class="actions" style="margin-top:10px;">
        <button class="button" id="volunteerMaybeLater">Return to Event</button>
      </div>
    </div>
  </div>
  <script>
    (function(){
      const modal = document.getElementById('volunteerModal');
      const closeBtn = document.getElementById('volunteerModalClose');
      const laterBtn = document.getElementById('volunteerMaybeLater');
      const openModal = () => { if (modal) { modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); } };
      const closeModal = () => { if (modal) { modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); } };
      if (closeBtn) closeBtn.addEventListener('click', closeModal);
      if (laterBtn) laterBtn.addEventListener('click', function(e){ e.preventDefault(); closeModal(); });
      document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
      <?php if ($showVolunteerModal): ?>
        openModal();
      <?php endif; ?>

      // AJAX handling for volunteer actions to keep modal open and refresh roles
      const rolesWrap = document.getElementById('volRoles');

      function esc(s) {
        return String(s).replace(/[&<>"']/g, function(c){
          return {'&':'&','<':'<','>':'>','"':'"', "'":'&#39;'}[c];
        });
      }

      function renderRoles(json) {
        if (!rolesWrap) return;
        var roles = json.roles || [];
        var uid = parseInt(json.user_id, 10);
        var html = '';
        for (var i=0;i<roles.length;i++) {
          var r = roles[i] || {};
          var volunteers = r.volunteers || [];
          var signed = false;
          for (var j=0;j<volunteers.length;j++) {
            var v = volunteers[j] || {};
            if (parseInt(v.user_id, 10) === uid) { signed = true; break; }
          }
          var open = parseInt(r.open_count, 10) || 0;
          var unlimited = !!r.is_unlimited;
          html += '<div class="role" style="margin-bottom:8px;">'
                +   '<div>'
                +     '<strong>'+esc(r.title||'')+'</strong> '
                +     (unlimited ? '<span class="remaining">(no limit)</span>' : (open > 0 ? '<span class="remaining">('+open+' people still needed)</span>' : '<span class="filled">Filled</span>'))
                +   '</div>';
          if (r.description) {
            html += '<div class="small" style="margin-top:4px; white-space:pre-wrap;">'+esc(r.description)+'</div>';
          }
          if (volunteers.length > 0) {
            html += '<ul style="margin:6px 0 0 16px;">';
            for (var k=0;k<volunteers.length;k++) {
              var vn = volunteers[k] || {};
              var isMe = parseInt(vn.user_id, 10) === uid;
              html += '<li>'+esc(vn.name||'');
              if (isMe) {
                html += ' <form method="post" action="/volunteer_actions.php" class="inline" style="display:inline;">'
                      +   '<input type="hidden" name="csrf" value="'+esc(json.csrf)+'">'
                      +   '<input type="hidden" name="event_id" value="'+esc(json.event_id)+'">'
                      +   '<input type="hidden" name="role_id" value="'+esc(r.id)+'">'
                      +   '<input type="hidden" name="action" value="remove">'
                      +   '<a href="#" class="small" onclick="this.closest(\'form\').requestSubmit(); return false;">(remove)</a>'
                      + '</form>';
              }
              html += '</li>';
            }
            html += '</ul>';
          } else {
            html += '<ul style="margin:6px 0 0 16px;"><li>No one yet.</li></ul>';
          }
          if (!signed) {
            if (unlimited || open > 0) {
              html += '<form method="post" action="/volunteer_actions.php" class="inline">'
                    +   '<input type="hidden" name="csrf" value="'+esc(json.csrf)+'">'
                    +   '<input type="hidden" name="event_id" value="'+esc(json.event_id)+'">'
                    +   '<input type="hidden" name="role_id" value="'+esc(r.id)+'">'
                    +   '<input type="hidden" name="action" value="signup">'
                    +   '<button style="margin-top:6px;" class="button primary">Sign up</button>'
                    + '</form>';
            } else {
              html += '<button style="margin-top:6px;" class="button" disabled>Filled</button>';
            }
          }
          html += '</div>';
        }
        rolesWrap.innerHTML = html;
      }

      function showError(msg) {
        if (!rolesWrap) return;
        const p = document.createElement('p');
        p.className = 'error small';
        p.textContent = msg || 'Action failed.';
        rolesWrap.insertBefore(p, rolesWrap.firstChild);
      }

      if (modal) {
        modal.addEventListener('submit', function(e){
          const form = e.target.closest('form');
          if (!form || form.getAttribute('action') !== '/volunteer_actions.php') return;
          e.preventDefault();
          const fd = new FormData(form);
          fd.set('ajax','1');
          fetch('/volunteer_actions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(res){ return res.json(); })
            .then(function(json){
              if (json && json.ok) { renderRoles(json); }
              else { showError((json && json.error) ? json.error : 'Action failed.'); }
            })
            .catch(function(){ showError('Network error.'); });
        });
      }

      if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
    })();
  </script>
<?php endif; ?>

<?php if ($eviteUrl === '' && $isAdmin): ?>
<!-- Admin RSVP Management modal -->
<div id="adminRsvpModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content">
    <button class="close" type="button" id="adminRsvpModalClose" aria-label="Close">&times;</button>
    <h3>Manage RSVP for <?= h($e['name']) ?></h3>
    
    <!-- Step 1: Search for family member -->
    <div id="adminRsvpStep1" class="stack">
      <label>Search for adult or child:
        <input type="text" id="adminFamilySearch" placeholder="Type name to search adults and children" autocomplete="off">
        <div id="adminFamilySearchResults" class="typeahead-results" style="display:none;"></div>
      </label>
    </div>
    
    <!-- Step 2: RSVP form (hidden initially) -->
    <div id="adminRsvpStep2" class="stack" style="display:none;">
      <form method="post" class="stack" action="/admin_rsvp_edit.php">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
        <input type="hidden" name="context_adult_id" id="adminContextAdultId" value="">
        <input type="hidden" name="answer" id="adminRsvpAnswerInput" value="yes">

        <div style="margin-bottom: 16px;">
          <strong>RSVP for: <span id="adminSelectedPersonName"></span></strong>
          <div style="margin-top: 8px;">
            <label class="inline">
              <input type="radio" name="answer_radio" value="yes" checked>
              Yes
            </label>
            <label class="inline">
              <input type="radio" name="answer_radio" value="maybe">
              Maybe
            </label>
            <label class="inline">
              <input type="radio" name="answer_radio" value="no">
              No
            </label>
          </div>
        </div>

        <h4>Adults</h4>
        <div id="adminAdultsList"></div>

        <h4>Children</h4>
        <div id="adminYouthList"></div>

        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;align-items:start;">
          <label>Number of other guests
            <input type="number" name="n_guests" id="adminNGuests" value="0" min="0">
          </label>
        </div>

        <label>Comments
          <textarea name="comments" id="adminComments" rows="3"></textarea>
        </label>

        <div class="actions">
          <button type="submit" class="primary">Save RSVP</button>
          <button type="button" id="adminRsvpBackBtn" class="button">Back to Search</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($eviteUrl === ''): ?>
<!-- RSVP modal (posts to rsvp_edit.php) -->
<div id="rsvpModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content">
    <button class="close" type="button" id="rsvpModalClose" aria-label="Close">&times;</button>
    <h3>RSVP <strong id="rsvpAnswerHeading"><?= h(strtoupper($myAnswer)) ?></strong> to <?= h($e['name']) ?></h3>
    <form method="post" class="stack" action="/rsvp_edit.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
      <input type="hidden" name="answer" id="rsvpAnswerInput" value="<?= h($myAnswer) ?>">

      <h4>Adults</h4>
      <?php
        $selAdults = [];
        if ($myRsvp) {
          $ids = RSVPManagement::getMemberIdsByType((int)$myRsvp['id']);
          $selAdults = $ids['adult_ids'] ?? [];
        }
      ?>
      <label class="inline">
        <input type="checkbox" name="adults[]" value="<?= (int)$me['id'] ?>" <?= in_array((int)$me['id'], $selAdults, true) ? 'checked' : '' ?>>
        You (<?= h($me['first_name'].' '.$me['last_name']) ?>)
      </label>
      <?php foreach ($coParents as $a): ?>
        <?php $aid = (int)$a['id']; $an = trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')); ?>
        <label class="inline">
          <input type="checkbox" name="adults[]" value="<?= $aid ?>" <?= in_array($aid, $selAdults, true) ? 'checked' : '' ?>>
          <?= h($an) ?>
        </label>
      <?php endforeach; ?>

      <h4>Children</h4>
      <?php
        $selYouth = [];
        if ($myRsvp) {
          $ids = RSVPManagement::getMemberIdsByType((int)$myRsvp['id']);
          $selYouth = $ids['youth_ids'] ?? [];
        }
      ?>
      <?php if (empty($myChildren)): ?>
        <p class="small">You have no children on file.</p>
      <?php else: ?>
        <?php foreach ($myChildren as $c): ?>
          <?php $cid = (int)$c['id']; $cn = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')); ?>
          <label class="inline"><input type="checkbox" name="youth[]" value="<?= $cid ?>" <?= in_array($cid, $selYouth, true) ? 'checked' : '' ?>> <?= h($cn) ?></label>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;align-items:start;">
        <label>Number of other guests
          <input type="number" name="n_guests" value="<?= (int)($myRsvp['n_guests'] ?? 0) ?>" min="0">
        </label>
      </div>

      <label>Comments
        <textarea name="comments" rows="3"><?= h((string)($myRsvp['comments'] ?? '')) ?></textarea>
      </label>

      <div class="actions">
        <button class="primary">Save RSVP</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($eviteUrl === ''): ?>
<script>
  (function(){
    const modal = document.getElementById('rsvpModal');
    const closeBtn = document.getElementById('rsvpModalClose');
    const yesBtn = document.getElementById('rsvpYesBtn');
    const maybeBtn = document.getElementById('rsvpMaybeBtn');
    const noBtn = document.getElementById('rsvpNoBtn');
    const editBtn = document.getElementById('rsvpEditBtn');
    const answerInput = document.getElementById('rsvpAnswerInput');
    const heading = document.getElementById('rsvpAnswerHeading');

    const openModal = () => { if (modal) { modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); } };
    const closeModal = () => { if (modal) { modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); } };

    if (yesBtn) yesBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value = 'yes'; if (heading) heading.textContent = 'YES'; openModal(); });
    if (maybeBtn) maybeBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value = 'maybe'; if (heading) heading.textContent = 'MAYBE'; openModal(); });
    if (noBtn) noBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value = 'no'; if (heading) heading.textContent = 'NO'; openModal(); });
    if (editBtn) editBtn.addEventListener('click', function(e){
      e.preventDefault();
      if (heading && answerInput) heading.textContent = (answerInput.value || 'yes').toUpperCase();
      openModal();
    });

    if (closeBtn) closeBtn.addEventListener('click', function(){ closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
<?php if (!empty($_GET['open_rsvp'])): ?>
    if (heading && answerInput) heading.textContent = (answerInput.value || 'yes').toUpperCase();
    openModal();
<?php endif; ?>
    if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
  })();
</script>
<?php endif; ?>

<?php footer_html(); ?>
