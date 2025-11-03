<?php
require_once __DIR__.'/partials.php';
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/RSVPManagement.php';
require_once __DIR__ . '/lib/RsvpsLoggedOutManagement.php';
require_once __DIR__ . '/lib/ParentRelationships.php';
require_once __DIR__ . '/lib/EventUIManager.php';
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
$rsvpUrl = trim((string)($e['rsvp_url'] ?? ''));
$rsvpLabel = trim((string)($e['rsvp_url_label'] ?? ''));

// Flash after RSVP save
$flashSaved = !empty($_GET['rsvp']);

 // Load my RSVP (if any) - uses family-aware lookup to find RSVPs from any family member
 $myRsvp = RSVPManagement::getRSVPForFamilyByAdultID((int)$id, (int)$me['id']);

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

// Check if current user is a Key 3 approver (for detailed public RSVP viewing)
$isKey3 = UserManagement::isApprover((int)$me['id']);

// Public YES totals
$_pubYesTotals = RsvpsLoggedOutManagement::totalsByAnswer((int)$id, 'yes');
$pubAdultsYes = (int)($_pubYesTotals['adults'] ?? 0);
$pubKidsYes = (int)($_pubYesTotals['kids'] ?? 0);

// Public MAYBE totals
$_pubMaybeTotals = RsvpsLoggedOutManagement::totalsByAnswer((int)$id, 'maybe');
$pubAdultsMaybe = (int)($_pubMaybeTotals['adults'] ?? 0);
$pubKidsMaybe = (int)($_pubMaybeTotals['kids'] ?? 0);

$rsvpCommentsByAdult = RSVPManagement::getCommentsByParentForEvent((int)$id);
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
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;"><?=h($e['name'])?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/events.php">Back to Events</a>
    <?= EventUIManager::renderAdminMenu((int)$e['id']) ?>
  </div>
</div>

<?php if ($flashSaved): ?>
  <p class="flash">Your RSVP has been saved.</p>
<?php endif; ?>

<?php
$myAnswer = strtolower((string)($myRsvp['answer'] ?? 'yes'));
if (!in_array($myAnswer, ['yes','maybe','no'], true)) $myAnswer = 'yes';
?>
<div class="card">
  <?php if ($rsvpUrl !== ''): ?>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <a class="button primary" target="_blank" rel="noopener" href="<?= h($rsvpUrl) ?>"><?= h($rsvpLabel !== '' ? $rsvpLabel : 'RSVP HERE') ?></a>
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
  <p><strong>When:</strong> <?= h(Settings::formatDateTimeRange($e['starts_at'], !empty($e['ends_at']) ? $e['ends_at'] : null)) ?></p>
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
</div>

<?php if ($rsvpUrl === ''): ?>
<div class="card">
  <h3>RSVPs</h3>
  <p class="small">
    Adults: <?= (int)$adultCountCombined ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Cub Scouts: <?= (int)$youthCountCombined ?><?= !empty($e['max_cub_scouts']) ? ' / '.(int)$e['max_cub_scouts'] : '' ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Other Guests: <?= (int)$guestsTotal ?>
    <?php if ($maybeAdultsTotal + $maybeYouthTotal + $maybeGuestsTotal > 0): ?>
      &nbsp;&nbsp; | &nbsp;&nbsp; <em>(<?= (int)$maybeAdultsTotal ?> adults, <?= (int)$maybeYouthTotal ?> cub scouts, and <?= (int)$maybeGuestsTotal ?> other guests RSVP'd maybe)</em>
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
      <?php if ($pubAdultsYes + $pubKidsYes === 0): ?>
        <p class="small">No public RSVPs yet.</p>
      <?php else: ?>
        <p class="small">
          <?= (int)$pubAdultsYes ?> adult<?= $pubAdultsYes === 1 ? '' : 's' ?>, 
          <?= (int)$pubKidsYes ?> kid<?= $pubKidsYes === 1 ? '' : 's' ?>
        </p>
        
        <?php if ($isKey3 && !empty($publicRsvps)): ?>
          <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #ddd;">
            <p class="small" style="color: #666; font-style: italic; margin-bottom: 8px;">
              <strong>Detailed RSVPs (visible only to key 3 leaders):</strong>
            </p>
            <ul style="font-size: 14px;">
              <?php foreach ($publicRsvps as $rsvp): ?>
                <li style="margin-bottom: 6px;">
                  <?php
                    $name = trim(($rsvp['first_name'] ?? '') . ' ' . ($rsvp['last_name'] ?? ''));
                    $adults = (int)($rsvp['total_adults'] ?? 0);
                    $kids = (int)($rsvp['total_kids'] ?? 0);
                    $comment = trim((string)($rsvp['comment'] ?? ''));
                  ?>
                  <strong><?= h($name) ?></strong>
                  <span class="small">
                    (<?= $adults ?> adult<?= $adults === 1 ? '' : 's' ?>, <?= $kids ?> kid<?= $kids === 1 ? '' : 's' ?>)
                  </span>
                  <?php if ($comment !== ''): ?>
                    <div class="small" style="font-style:italic; margin-top: 2px; color: #555;">
                      <?= nl2br(h($comment)) ?>
                    </div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php endif; ?>

<?php if ($isAdmin || !empty($roles)): ?>
<div class="card" id="volunteersCard">
  <h3>Event Volunteers</h3>
  <?php if (empty($roles)): ?>
    <p class="small">No volunteer roles have been defined for this event.</p>
      <?php if ($isAdmin): ?>
        <a class="button" href="/admin_event_volunteers.php?event_id=<?= (int)$e['id'] ?>">Manager volunteer roles</a>
      <?php endif; ?>
  <?php else: ?>
    <div class="volunteers">
      <?php foreach ($roles as $r): ?>
        <div class="role" style="margin-bottom:10px;">
          <?php
            $amIn = false;
            if ($hasYes) {
              foreach ($r['volunteers'] as $v) { if ((int)$v['user_id'] === (int)$me['id']) { $amIn = true; break; } }
            }
          ?>
          
          <!-- Title line with sign-up button on the right -->
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">
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
            
            <?php if ($hasYes && !$amIn): ?>
              <form method="post" action="/volunteer_actions.php" class="inline" style="margin: 0;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                <?php if (!empty($r['is_unlimited']) || (int)$r['open_count'] > 0): ?>
                  <input type="hidden" name="action" value="signup">
                  <button class="button primary" style="white-space: nowrap;">Sign up</button>
                <?php else: ?>
                  <button class="button" disabled style="white-space: nowrap;">Filled</button>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          </div>
          
          <?php if (trim((string)($r['description'] ?? '')) !== ''): ?>
            <div style="margin-top:4px;"><?= Text::renderMarkup((string)$r['description']) ?></div>
          <?php endif; ?>

          <?php if (!empty($r['volunteers'])): ?>
            <ul style="margin:6px 0 0 16px;">
              <?php foreach ($r['volunteers'] as $v): ?>
                <li>
                  <?= h($v['name']) ?>
                  <?php if (!empty($v['comment'])): ?>
                    <span class="small" style="font-style:italic;"> "<?= h($v['comment']) ?>"</span>
                  <?php endif; ?>
                  <?php if ((int)($v['user_id'] ?? 0) === (int)$me['id']): ?>
                    <a href="#" class="volunteer-edit-comment-link small" data-role-id="<?= (int)$r['id'] ?>" data-role-title="<?= h($r['title']) ?>" data-comment="<?= h($v['comment']) ?>">(edit comment)</a>
                    <form method="post" action="/volunteer_actions.php" class="inline" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                      <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="remove">
                      <a href="#" class="volunteer-remove-link small">(remove)</a>
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
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($hasYes): ?>
  <script>
    // Handle remove actions via AJAX on main page
    (function(){
      // Find all remove links on page load
      document.addEventListener('click', function(e){
        const removeLink = e.target.closest('a.volunteer-remove-link');
        if (!removeLink) return;
        
        const form = removeLink.closest('form');
        if (!form || form.getAttribute('action') !== '/volunteer_actions.php') return;
        if (!form.querySelector('input[name="action"][value="remove"]')) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const fd = new FormData(form);
        fd.set('ajax', '1');
        
        fetch('/volunteer_actions.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        })
        .then(function(res){
          if (!res.ok) throw new Error('Server error: ' + res.status);
          return res.json();
        })
        .then(function(json){
          if (json && json.ok) {
            // Replace entire volunteers card with updated HTML (includes success message from server)
            if (json.volunteers_card_html) {
              const volunteersCard = document.getElementById('volunteersCard');
              if (volunteersCard) {
                volunteersCard.outerHTML = json.volunteers_card_html;
              }
            }
          } else {
            alert((json && json.error) ? json.error : 'Failed to remove signup.');
          }
        })
        .catch(function(err){
          alert(err.message || 'Network error.');
        });
      });
    })();
  </script>
<?php endif; ?>

<?php if ($hasYes): ?>
  <!-- Volunteer signup confirmation modal -->
  <div id="volunteerSignupModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
      <button class="close" type="button" id="volunteerSignupModalClose" aria-label="Close">&times;</button>
      <h3>Confirm Volunteer Signup</h3>
      <form id="volunteerSignupForm" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
        <input type="hidden" name="role_id" id="signupRoleId" value="">
        <input type="hidden" name="action" value="signup">
        
        <p>Please confirm you wish to sign up for <strong id="signupRoleTitle"></strong>.</p>
        
        <div id="signupRoleDescription" style="margin-top:8px;"></div>
        
        <label style="margin-top:16px;">
          Would you like to add a comment about your sign-up?
          <textarea name="comment" id="signupComment" rows="3" placeholder="e.g., I'm bringing chips and salsa"></textarea>
        </label>
        
        <div class="actions">
          <button type="submit" class="primary">Confirm</button>
          <button type="button" class="button" id="volunteerSignupCancel">Cancel</button>
        </div>
      </form>
      <div id="signupError" class="error" style="display:none;margin-top:12px;"></div>
    </div>
  </div>
  
  <script>
    // Signup confirmation modal handling
    (function(){
      const signupModal = document.getElementById('volunteerSignupModal');
      const signupForm = document.getElementById('volunteerSignupForm');
      const signupCloseBtn = document.getElementById('volunteerSignupModalClose');
      const signupCancelBtn = document.getElementById('volunteerSignupCancel');
      const signupError = document.getElementById('signupError');
      const signupRoleId = document.getElementById('signupRoleId');
      const signupRoleTitle = document.getElementById('signupRoleTitle');
      const signupRoleDescription = document.getElementById('signupRoleDescription');
  const signupComment = document.getElementById('signupComment');
  
  // Pre-render role descriptions with markdown/link formatting
  const rolesData = <?= json_encode(array_map(function($role) {
    $role['description_html'] = !empty($role['description']) ? Text::renderMarkup((string)$role['description']) : '';
    return $role;
  }, $roles)) ?>;
  
  const openSignupModal = () => {
        if (signupModal) { 
          signupModal.classList.remove('hidden'); 
          signupModal.setAttribute('aria-hidden','false');
          if (signupError) signupError.style.display = 'none';
        } 
      };
      const closeSignupModal = () => { 
        if (signupModal) { 
          signupModal.classList.add('hidden'); 
          signupModal.setAttribute('aria-hidden','true');
          if (signupComment) signupComment.value = '';
          if (signupError) signupError.style.display = 'none';
        } 
      };
      
      if (signupCloseBtn) signupCloseBtn.addEventListener('click', closeSignupModal);
      if (signupCancelBtn) signupCancelBtn.addEventListener('click', closeSignupModal);
      
      // Global function to show signup confirmation
      window.showVolunteerSignupConfirmation = function(roleId) {
        const role = rolesData.find(r => r.id == roleId);
        if (!role) return false;
        
        // Hide the volunteer modal if it's open
        const volModal = document.getElementById('volunteerModal');
        if (volModal && !volModal.classList.contains('hidden')) {
          volModal.classList.add('hidden');
          volModal.setAttribute('aria-hidden', 'true');
        }
        
        if (signupRoleId) signupRoleId.value = roleId;
        if (signupRoleTitle) signupRoleTitle.textContent = role.title;
        if (signupRoleDescription) {
          if (role.description_html) {
            signupRoleDescription.innerHTML = role.description_html;
            signupRoleDescription.style.display = 'block';
          } else {
            signupRoleDescription.style.display = 'none';
          }
        }
        
        openSignupModal();
        return true;
      };
      
      // Intercept signup button clicks on main page
      document.addEventListener('click', function(e){
        // Check if clicked element is a button (with or without explicit type="submit")
        const btn = e.target.closest('button');
        if (!btn) return;
        
        const form = btn.closest('form');
        if (!form || form.getAttribute('action') !== '/volunteer_actions.php') return;
        if (!form.querySelector('input[name="action"][value="signup"]')) return;
        
        // Skip if this is in the volunteer modal (handled separately)
        if (form.closest('#volunteerModal')) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const roleId = form.querySelector('input[name="role_id"]').value;
        window.showVolunteerSignupConfirmation(roleId);
      }, true);
      
      // Handle signup form submission
      if (signupForm) {
        signupForm.addEventListener('submit', function(e){
          e.preventDefault();
          
          const fd = new FormData(signupForm);
          fd.set('ajax', '1'); // Tell the server to return JSON
          
          fetch('/volunteer_actions.php', { 
            method:'POST', 
            body: fd, 
            credentials:'same-origin' 
          })
          .then(function(res){ 
            if (!res.ok) {
              throw new Error('Server error: ' + res.status);
            }
            return res.json(); 
          })
          .then(function(json){
            if (json && json.ok) {
              // Success - close modal and refresh volunteers card (includes success message from server)
              closeSignupModal();
              
              // Replace entire volunteers card with updated HTML
              if (json.volunteers_card_html) {
                const volunteersCard = document.getElementById('volunteersCard');
                if (volunteersCard) {
                  volunteersCard.outerHTML = json.volunteers_card_html;
                }
              }
            } else {
              // Show error
              if (signupError) {
                signupError.textContent = (json && json.error) ? json.error : 'Signup failed.';
                signupError.style.display = 'block';
              }
            }
          })
          .catch(function(err){
            if (signupError) {
              signupError.textContent = err.message || 'Network error.';
              signupError.style.display = 'block';
            }
          });
        });
      }
      
      if (signupModal) signupModal.addEventListener('click', function(e){ if (e.target === signupModal) closeSignupModal(); });
    })();
  </script>
<?php endif; ?>

<?php if ($hasYes): ?>
  <!-- Edit comment modal -->
  <div id="volunteerEditCommentModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
      <button class="close" type="button" id="editCommentModalClose" aria-label="Close">&times;</button>
      <h3>Edit Volunteer Comment</h3>
      <form id="editCommentForm" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
        <input type="hidden" name="role_id" id="editCommentRoleId" value="">
        <input type="hidden" name="action" value="edit_comment">
        
        <p>Editing comment for <strong id="editCommentRoleTitle"></strong></p>
        
        <label>
          Comment:
          <textarea name="comment" id="editCommentText" rows="3" placeholder="e.g., I'm bringing chips and salsa"></textarea>
        </label>
        
        <div class="actions">
          <button type="submit" class="primary">Save</button>
          <button type="button" class="button" id="editCommentCancel">Cancel</button>
        </div>
      </form>
      <div id="editCommentError" class="error" style="display:none;margin-top:12px;"></div>
    </div>
  </div>
  
  <script>
    // Edit comment modal handling
    (function(){
      const editModal = document.getElementById('volunteerEditCommentModal');
      const editForm = document.getElementById('editCommentForm');
      const editCloseBtn = document.getElementById('editCommentModalClose');
      const editCancelBtn = document.getElementById('editCommentCancel');
      const editError = document.getElementById('editCommentError');
      const editRoleId = document.getElementById('editCommentRoleId');
      const editRoleTitle = document.getElementById('editCommentRoleTitle');
      const editCommentText = document.getElementById('editCommentText');
      
      const openEditModal = () => {
        if (editModal) {
          editModal.classList.remove('hidden');
          editModal.setAttribute('aria-hidden', 'false');
          if (editError) editError.style.display = 'none';
        }
      };
      
      const closeEditModal = () => {
        if (editModal) {
          editModal.classList.add('hidden');
          editModal.setAttribute('aria-hidden', 'true');
          if (editCommentText) editCommentText.value = '';
          if (editError) editError.style.display = 'none';
        }
      };
      
      if (editCloseBtn) editCloseBtn.addEventListener('click', closeEditModal);
      if (editCancelBtn) editCancelBtn.addEventListener('click', closeEditModal);
      
      // Handle edit comment link clicks
      document.addEventListener('click', function(e){
        const editLink = e.target.closest('a.volunteer-edit-comment-link');
        if (!editLink) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const roleId = editLink.getAttribute('data-role-id');
        const roleTitle = editLink.getAttribute('data-role-title');
        const currentComment = editLink.getAttribute('data-comment');
        
        if (editRoleId) editRoleId.value = roleId;
        if (editRoleTitle) editRoleTitle.textContent = roleTitle;
        if (editCommentText) editCommentText.value = currentComment || '';
        
        openEditModal();
      });
      
      // Handle form submission
      if (editForm) {
        editForm.addEventListener('submit', function(e){
          e.preventDefault();
          
          const fd = new FormData(editForm);
          fd.set('ajax', '1');
          
          fetch('/volunteer_actions.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
          })
          .then(function(res){
            if (!res.ok) throw new Error('Server error: ' + res.status);
            return res.json();
          })
          .then(function(json){
            if (json && json.ok) {
              closeEditModal();
              
              // Replace entire volunteers card with updated HTML
              if (json.volunteers_card_html) {
                const volunteersCard = document.getElementById('volunteersCard');
                if (volunteersCard) {
                  volunteersCard.outerHTML = json.volunteers_card_html;
                }
              }
            } else {
              if (editError) {
                editError.textContent = (json && json.error) ? json.error : 'Failed to update comment.';
                editError.style.display = 'block';
              }
            }
          })
          .catch(function(err){
            if (editError) {
              editError.textContent = err.message || 'Network error.';
              editError.style.display = 'block';
            }
          });
        });
      }
      
      if (editModal) editModal.addEventListener('click', function(e){ if (e.target === editModal) closeEditModal(); });
    })();
  </script>
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
          <?php
            $amIn = false;
            foreach ($r['volunteers'] as $v) { if ((int)$v['user_id'] === (int)$me['id']) { $amIn = true; break; } }
          ?>
          
          <!-- Title line with sign-up button on the right -->
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">
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
            
            <?php if (!$amIn): ?>
              <form method="post" action="/volunteer_actions.php" class="inline" style="margin: 0;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                <?php if (!empty($r['is_unlimited']) || (int)$r['open_count'] > 0): ?>
                  <input type="hidden" name="action" value="signup">
                  <button class="button primary" style="white-space: nowrap;">Sign up</button>
                <?php else: ?>
                  <button class="button" disabled style="white-space: nowrap;">Filled</button>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          </div>
          
          <?php if (trim((string)($r['description'] ?? '')) !== ''): ?>
            <div style="margin-top:4px;"><?= Text::renderMarkup((string)$r['description']) ?></div>
          <?php endif; ?>

          <?php if (!empty($r['volunteers'])): ?>
            <ul style="margin:6px 0 0 16px;">
              <?php foreach ($r['volunteers'] as $v): ?>
                <li>
                  <?= h($v['name']) ?>
                  <?php if (!empty($v['comment'])): ?>
                    <span class="small" style="font-style:italic;"> "<?= h($v['comment']) ?>"</span>
                  <?php endif; ?>
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
          
          html += '<div class="role" style="margin-bottom:14px;">';
          
          // Title line with sign-up button on the right
          html += '<div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">';
          html += '<div>';
          html += '<strong>'+esc(r.title||'')+'</strong> ';
          html += (unlimited ? '<span class="remaining">(no limit)</span>' : (open > 0 ? '<span class="remaining">('+open+' people still needed)</span>' : '<span class="filled">Filled</span>'));
          html += '</div>';
          
          // Add sign-up button on the same line if not signed up
          if (!signed) {
            html += '<form method="post" action="/volunteer_actions.php" class="inline" style="margin: 0;">';
            html += '<input type="hidden" name="csrf" value="'+esc(json.csrf)+'">';
            html += '<input type="hidden" name="event_id" value="'+esc(json.event_id)+'">';
            html += '<input type="hidden" name="role_id" value="'+esc(r.id)+'">';
            if (unlimited || open > 0) {
              html += '<input type="hidden" name="action" value="signup">';
              html += '<button class="button primary" style="white-space: nowrap;">Sign up</button>';
            } else {
              html += '<button class="button" disabled style="white-space: nowrap;">Filled</button>';
            }
            html += '</form>';
          }
          
          html += '</div>';
          
          if (r.description_html) {
            html += '<div style="margin-top:4px;">'+r.description_html+'</div>';
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
          
          const action = form.querySelector('input[name="action"]');
          if (action && action.value === 'signup') {
            // For signups, show confirmation modal instead
            e.preventDefault();
            const roleId = form.querySelector('input[name="role_id"]').value;
            if (window.showVolunteerSignupConfirmation) {
              window.showVolunteerSignupConfirmation(roleId);
            }
            return;
          }
          
          // For other actions (like remove), proceed with AJAX
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

<?php if ($rsvpUrl === '' && $isAdmin): ?>
  <?= EventUIManager::renderAdminModals((int)$e['id']) ?>
<?php endif; ?>

<?php if ($rsvpUrl === ''): ?>
<!-- RSVP modal (posts to rsvp_edit.php) -->
<div id="rsvpModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content">
    <button class="close" type="button" id="rsvpModalClose" aria-label="Close">&times;</button>
    <h3>RSVP <strong id="rsvpAnswerHeading"><?= h(strtoupper($myAnswer)) ?></strong> to <?= h($e['name']) ?></h3>
    <form method="post" class="stack" action="/rsvp_edit.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
      <input type="hidden" name="answer" id="rsvpAnswerInput" value="<?= h($myAnswer) ?>">

      <?php if ($myRsvp): ?>
      <div style="margin-bottom: 16px;">
        <strong>Change your RSVP:</strong>
        <div style="margin-top: 8px;">
          <label class="inline">
            <input type="radio" name="answer_radio" value="yes" <?= $myAnswer === 'yes' ? 'checked' : '' ?>>
            Yes
          </label>
          <label class="inline">
            <input type="radio" name="answer_radio" value="maybe" <?= $myAnswer === 'maybe' ? 'checked' : '' ?>>
            Maybe
          </label>
          <label class="inline">
            <input type="radio" name="answer_radio" value="no" <?= $myAnswer === 'no' ? 'checked' : '' ?>>
            No
          </label>
        </div>
      </div>
      <?php endif; ?>

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

<?php if ($isAdmin): ?>
  <?= EventUIManager::renderAdminMenuScript((int)$e['id']) ?>
<?php endif; ?>

<?php if ($rsvpUrl === ''): ?>
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

    // Handle radio button changes in the modal
    const answerRadios = document.querySelectorAll('input[name="answer_radio"]');
    answerRadios.forEach(radio => {
      radio.addEventListener('change', function() {
        if (this.checked) {
          if (answerInput) answerInput.value = this.value;
          if (heading) heading.textContent = this.value.toUpperCase();
        }
      });
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
