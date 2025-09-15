<?php
require_once __DIR__.'/partials.php';
require_login();

$announcement = Settings::get('announcement', '');
$siteTitle = Settings::siteTitle();

require_once __DIR__ . '/lib/Reimbursements.php';
require_once __DIR__ . '/lib/GradeCalculator.php';
require_once __DIR__ . '/lib/Volunteers.php';
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/RSVPManagement.php';
require_once __DIR__ . '/lib/ParentRelationships.php';
require_once __DIR__ . '/lib/PaymentNotifications.php';
require_once __DIR__ . '/lib/PendingRegistrations.php';
$ctx = UserContext::getLoggedInUserContext();
$isApprover = Reimbursements::isApprover($ctx);
$pending = [];
if ($isApprover) {
  $pending = Reimbursements::listPendingForApprover(5);
}

$me = current_user();

/* "I've paid" now uses a modal + AJAX (see Renew Your Membership section JS).
   Email to leadership is sent server-side from payment_notifications_actions.php upon creation. */

header_html('Home');
?>
<?php if (!empty($_GET['recommended'])): ?>
  <p class="flash">Thank you for your recommendation!</p>
<?php endif; ?>
<?php if (!empty($_GET['renewed'])): ?>
  <p class="flash">Thanks! We've notified pack leadership.</p>
<?php endif; ?>
<?php if (!empty($_GET['registered'])): ?>
  <p class="flash">Thank you for sending in your application and payment.</p>
<?php endif; ?>
<?php if (trim($announcement) !== ''): ?>
  <p class="announcement"><?=h($announcement)?></p>
<?php endif; ?>


<div class="card">
  <h2>Welcome back, <?= h($me['first_name'] ?? '') ?> to <?= h($siteTitle) ?></h2>
  <p class="small">Questions?  Contact brian.rosenthal@gmail.com</p>
</div>

<?php
  // Register your child section logic:
  // Show if user has at least one K–5 unregistered child AND no registered children at all.
  $me = current_user();
  $showRegisterSection = false;
  try {
    $kids = ParentRelationships::listChildrenForAdult((int)$me['id']);

    $hasAnyRegistered = false;
    $hasUnregisteredKto5 = false;

    foreach ($kids as $k) {
      $reg = trim((string)($k['bsa_registration_number'] ?? ''));
      if ($reg !== '') {
        $hasAnyRegistered = true;
      } else {
        $classOf = (int)($k['class_of'] ?? 0);
        if ($classOf > 0) {
          $grade = GradeCalculator::gradeForClassOf($classOf);
          if ($grade >= 0 && $grade <= 5) {
            $hasUnregisteredKto5 = true;
          }
        }
      }
    }

    $showRegisterSection = ($hasUnregisteredKto5 && !$hasAnyRegistered);
  } catch (Throwable $e) {
    $showRegisterSection = false; // fail safe: do not show if query fails
  }

  // Resolve leader names
  $cubmasterName = UserManagement::findLeaderNameByPosition('Cubmaster') ?? '';
  $committeeChairName = UserManagement::findLeaderNameByPosition('Committee Chair') ?? '';
  $cubmasterLabel = $cubmasterName !== '' ? $cubmasterName : 'the Cubmaster';
  $committeeChairLabel = $committeeChairName !== '' ? $committeeChairName : 'the Committee Chair';
?>

<div class="card" style="margin-top:16px;">
  <h3>My Family</h3>

  <?php
    // Build unified family set (children + co-parents)
    $children = is_array($kids ?? null) ? $kids : [];
    $isAdmin = ((int)($me['is_admin'] ?? 0) === 1);

    // Fetch co-parents and include photo_public_file_id for avatar
    $coParents = [];
    try {
      $childIds = array_map(function($k){ return (int)($k['id'] ?? 0); }, $children);
      $childIds = array_values(array_filter($childIds));
      if (!empty($childIds)) {
        $coParents = UserManagement::listCoParentsForYouthIds((int)($me['id'] ?? 0), $childIds);
      }
    } catch (Throwable $e) {
      $coParents = [];
    }

    // Normalize to a single array for rendering
    $family = [];

    // Add logged-in user as first card
    $myPositions = '';
    try {
      $posRows = UserManagement::listLeadershipPositions($ctx, (int)($me['id'] ?? 0));
      $posTitles = [];
      foreach ($posRows as $pr) {
        $t = trim((string)($pr['position'] ?? ''));
        if ($t !== '') $posTitles[] = $t;
      }
      $posTitles = array_values(array_unique($posTitles));
      $myPositions = implode(', ', $posTitles);
    } catch (Throwable $e) {
      $myPositions = '';
    }
    $family[] = [
      'type' => 'parent',
      'first_name' => (string)($me['first_name'] ?? ''),
      'last_name' => (string)($me['last_name'] ?? ''),
      'adult_id' => (int)($me['id'] ?? 0),
      'positions' => $myPositions,
      'bsa_membership_number' => trim((string)($me['bsa_membership_number'] ?? '')),
      'photo_public_file_id' => (int)($me['photo_public_file_id'] ?? 0),
    ];

    foreach ($children as $c) {
      $family[] = [
        'type' => 'child',
        'first_name' => (string)($c['first_name'] ?? ''),
        'last_name' => (string)($c['last_name'] ?? ''),
        'youth_id' => (int)($c['id'] ?? 0),
        'class_of' => (int)($c['class_of'] ?? 0),
        'bsa_registration_number' => trim((string)($c['bsa_registration_number'] ?? '')),
        'photo_public_file_id' => (int)($c['photo_public_file_id'] ?? 0),
        'sibling' => (int)($c['sibling'] ?? 0),
        'date_paid_until' => (string)($c['date_paid_until'] ?? ''),
      ];
    }

    foreach ($coParents as $p) {
      $family[] = [
        'type' => 'parent',
        'first_name' => (string)($p['first_name'] ?? ''),
        'last_name' => (string)($p['last_name'] ?? ''),
        'adult_id' => (int)($p['id'] ?? 0),
        'positions' => trim((string)($p['positions'] ?? '')),
        'bsa_membership_number' => trim((string)($p['bsa_membership_number'] ?? '')),
        'photo_public_file_id' => (int)($p['photo_public_file_id'] ?? 0),
      ];
    }
  ?>

  <?php if (empty($family)): ?>
    <p class="small">No family members on file yet. You can manage family from your <a href="/my_profile.php">My Profile</a> page.</p>
  <?php else: ?>
    <div class="family-grid">
      <?php foreach ($family as $m): ?>
        <?php
          $fname = (string)($m['first_name'] ?? '');
          $lname = (string)($m['last_name'] ?? '');
          $name = trim($fname.' '.$lname);
          $initials = strtoupper((string)substr($fname, 0, 1) . (string)substr($lname, 0, 1));
          $badge = ($m['type'] === 'child') ? 'Child' : 'Parent';
          $badgeClass = ($m['type'] === 'child') ? 'child' : 'parent';

          $editUrl = null; $canEdit = false;
          if ($m['type'] === 'child') {
            $editUrl = '/youth_edit.php?id='.(int)($m['youth_id'] ?? 0);
            $canEdit = true;
          } else {
            if ((int)($m['adult_id'] ?? 0) === (int)($me['id'] ?? 0)) {
              $editUrl = '/my_profile.php';
              $canEdit = true;
            } elseif ($isAdmin) {
              $editUrl = '/adult_edit.php?id='.(int)($m['adult_id'] ?? 0);
              $canEdit = true;
            }
          }
        ?>
        <div class="person-card">
          <div class="person-header">
            <div class="person-header-left">
              <?php
              $href = ($m['type'] === 'child')
                ? '/youth_edit.php?id='.(int)($m['youth_id'] ?? 0)
                : ((int)($m['adult_id'] ?? 0) === (int)($me['id'] ?? 0)
                    ? '/my_profile.php'
                    : '/adult_edit.php?id='.(int)($m['adult_id'] ?? 0));
                $avatarUrl = Files::profilePhotoUrl($m['photo_public_file_id'] ?? null);
              ?>
              <a href="<?= h($href) ?>" class="avatar-link" title="Edit">
                <?php if ($avatarUrl !== ''): ?>
                  <img class="avatar" src="<?= h($avatarUrl) ?>" alt="<?= h($name) ?>">
                <?php else: ?>
                  <div class="avatar avatar-initials" aria-hidden="true"><?= h($initials) ?></div>
                <?php endif; ?>
              </a>
              <div class="person-name"><?= h($name) ?></div>
            </div>
            <span class="badge <?= h($badgeClass) ?>"><?= h($badge) ?></span>
          </div>

          <div class="person-meta">
            <?php if ($m['type'] === 'child'): ?>
              <?php
                $classOf = (int)($m['class_of'] ?? 0);
                $grade = $classOf > 0 ? GradeCalculator::gradeForClassOf($classOf) : null;
                $gradeLabel = ($grade !== null) ? GradeCalculator::gradeLabel($grade) : null;
                $yReg = trim((string)($m['bsa_registration_number'] ?? ''));
                $paidUntilRaw = trim((string)($m['date_paid_until'] ?? ''));
                $needsRenewal = false;
                if ($yReg !== '' && empty($m['sibling']) && $grade !== null && $grade >= 0 && $grade <= 5) {
                  $ts = $paidUntilRaw !== '' ? strtotime($paidUntilRaw . ' 23:59:59') : false;
                  $needsRenewal = ($paidUntilRaw === '' || ($ts !== false && $ts < time()));
                }
                // Processing/eligibility indicators
                $processingRenewal = ($yReg !== '' && $grade !== null && $grade >= 0 && $grade <= 5) ? PaymentNotifications::hasRecentForYouth((int)($m['youth_id'] ?? 0), false) : false;
                $eligibleRegistration = ($yReg === '' && $grade !== null && $grade >= 0 && $grade <= 5 && !($hasAnyRegistered ?? false));
                $processingRegistration = ($yReg === '' && $grade !== null && $grade >= 0 && $grade <= 5) ? PendingRegistrations::hasNewForYouth((int)($m['youth_id'] ?? 0)) : false;
              ?>
              <?php if ($gradeLabel !== null): ?><div>Grade <?= h($gradeLabel) ?></div><?php endif; ?>
              <?php if ($yReg !== ''): ?>
                <div>BSA Registration ID: <?= h($yReg) ?></div>
                <?php
                  $paidUntilFmt = ($paidUntilRaw !== '' ? date('n/j/Y', strtotime($paidUntilRaw)) : '');
                ?>
                <?php if (!empty($processingRenewal)): ?>
                  <div>Renewal Processing</div>
                <?php elseif ($needsRenewal): ?>
                  <div style="color:#c00;">Needs Renewal</div>
                <?php elseif ($paidUntilFmt !== ''): ?>
                  <div>Paid until <?= h($paidUntilFmt) ?></div>
                <?php endif; ?>
              <?php elseif (!empty($m['sibling'])): ?>
                <div>Sibling</div>
              <?php else: ?>
                <div>BSA Registration ID: unregistered</div>
                <?php if (!empty($processingRegistration)): ?>
                  <div>Registration Processing</div>
                <?php elseif (!empty($eligibleRegistration)): ?>
                  <div style="color:#c00;">Needs Registration</div>
                <?php endif; ?>
              <?php endif; ?>
            <?php else: ?>
              <?php $aReg = trim((string)($m['bsa_membership_number'] ?? '')); ?>
              <div>BSA Registration ID: <?= $aReg !== '' ? h($aReg) : 'N/A' ?></div>
              <?php $positions = trim((string)($m['positions'] ?? '')); ?>
              <?php if ($positions !== ''): ?><div><?= h($positions) ?></div><?php endif; ?>
            <?php endif; ?>
          </div>

          <?php if ($canEdit && $editUrl): ?>
          <div class="person-actions">
            <a class="small" href="<?= h($editUrl) ?>">Edit</a>
            <?php if (($m['type'] ?? '') === 'child'): ?>
              <?php
                $yReg = trim((string)($m['bsa_registration_number'] ?? ''));
                $classOf = (int)($m['class_of'] ?? 0);
                $grade = $classOf > 0 ? GradeCalculator::gradeForClassOf($classOf) : null;
                $paidUntilRaw = trim((string)($m['date_paid_until'] ?? ''));
                $needsRenewalLink = false;
                if ($yReg !== '' && empty($m['sibling']) && $grade !== null && $grade >= 0 && $grade <= 5) {
                  $ts = $paidUntilRaw !== '' ? strtotime($paidUntilRaw . ' 23:59:59') : false;
                  $needsRenewalLink = ($paidUntilRaw === '' || ($ts !== false && $ts < time()));
                }
              ?>
              <?php if (!empty($eligibleRegistration) && empty($processingRegistration)): ?>
                <span class="small" aria-hidden="true"> · </span>
                <a class="small open-register-modal" href="#" data-youth-id="<?= (int)($m['youth_id'] ?? 0) ?>">Register</a>
              <?php endif; ?>
              <?php if ($needsRenewalLink && empty($processingRenewal)): ?>
                <span class="small" aria-hidden="true"> · </span>
                <a class="small open-paid-modal" href="#" data-youth-id="<?= (int)($m['youth_id'] ?? 0) ?>">How to renew</a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php
  // Renew Your Membership section
  // Conditions per youth: has BSA ID, not sibling, grade K..5 (0..5), and date_paid_until is NULL or past
  // Suppressed if cookie present (7-day suppression after marking paid)
  $suppressRenew = false;
  try {
    if (isset($_COOKIE['renew_prompt_dismiss_until'])) {
      $dt = (int)$_COOKIE['renew_prompt_dismiss_until'];
      if ($dt > time()) $suppressRenew = true;
    }
  } catch (Throwable $e) {
    $suppressRenew = false;
  }

  // Collect qualifying youths
  $dues = '';
  try { $dues = (string)Settings::get('dues_amount', ''); } catch (Throwable $e) { $dues = ''; }
  $duesText = trim($dues) !== '' ? ' ('.h($dues).')' : '';

  $qualifying = [];
  if (!$suppressRenew && is_array($kids ?? null)) {
    foreach ($kids as $k) {
      $bsa = trim((string)($k['bsa_registration_number'] ?? ''));
      $sibling = (int)($k['sibling'] ?? 0);
      $classOf = (int)($k['class_of'] ?? 0);
      $grade = $classOf > 0 ? GradeCalculator::gradeForClassOf($classOf) : null;
      $paidUntilRaw = trim((string)($k['date_paid_until'] ?? ''));
      $paidExpired = true;
      if ($paidUntilRaw !== '') {
        $ts = strtotime($paidUntilRaw . ' 23:59:59');
        $paidExpired = ($ts === false) ? true : ($ts < time());
      }
      if ($bsa !== '' && $sibling === 0 && $grade !== null && $grade >= 0 && $grade <= 5 && $paidExpired) {
        $qualifying[] = $k;
      }
    }
  }
?>

<?php if (false): ?>
<div class="card" style="margin-top:16px;">
  <h3>Renew Your Membership</h3>
  <p>
    Welcome back to Cub Scouts. Please renew your memebership this year by paying your dues<?= $duesText ?> through any of the payment options here:
    <a href="https://www.scarsdalepack440.com/join" target="_blank" rel="noopener">https://www.scarsdalepack440.com/join</a>.
  </p>
  <p>
    And afterwards, please click the button below to let us know you sent in your dues so that we can process them.
  </p>

  <div class="stack">
    <?php foreach ($qualifying as $q): ?>
      <?php
        $childName = trim((string)($q['first_name'] ?? '') . ' ' . (string)($q['last_name'] ?? ''));
        $qClass = (int)($q['class_of'] ?? 0);
        $qGrade = $qClass > 0 ? GradeCalculator::gradeForClassOf($qClass) : null;
        $qGradeLabel = ($qGrade !== null) ? GradeCalculator::gradeLabel($qGrade) : null;
        // Avatar for Renew card
        $avatarUrl = Files::profilePhotoUrl($q['photo_public_file_id'] ?? null);
        $initials = strtoupper(
          substr((string)($q['first_name'] ?? ''), 0, 1) .
          substr((string)($q['last_name'] ?? ''), 0, 1)
        );
      ?>
      <div class="stack" style="border:1px solid #e8e8ef;border-radius:8px;padding:12px;max-width:560px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:10px;">
            <?php if ($avatarUrl !== ''): ?>
              <img class="avatar" src="<?= h($avatarUrl) ?>" alt="<?= h($childName) ?>" style="width:40px;height:40px">
            <?php else: ?>
              <div class="avatar avatar-initials" aria-hidden="true" style="width:40px;height:40px;"><?= h($initials) ?></div>
            <?php endif; ?>
            <div>
              <strong><?= h($childName) ?></strong>
              <?php if ($qGradeLabel !== null): ?>
                <span class="small">(Grade <?= h($qGradeLabel) ?>)</span>
              <?php endif; ?>
            </div>
          </div>
          <button class="button open-paid-modal"
                  data-youth-id="<?= (int)($q['id'] ?? 0) ?>"
                  data-child-name="<?= h($childName) ?>">
            I’ve paid
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Renew: Paid Modal -->
<div id="renewPaidModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:520px;">
    <button class="close" type="button" id="renewPaidClose" aria-label="Close">&times;</button>
    <h3>How to renew your cub scout membership</h3>
    <div id="renewPaidErr" class="error small" style="display:none;"></div>
    <p>1. Pay your dues ($450, or $200 for additional siblings) via Paypal to "Scarsdale Cubs" (scarsdalecubs@gmail.com) or by delivering a check to Takford Mau (contact: takfordmau@gmail.com)</p>
    <p>2. Mark that you paid here:</p>
    <form id="renewPaidForm" class="stack" method="post" action="/payment_notifications_actions.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="youth_id" id="renewPaidYouthId" value="">
      <label>Payment Method
        <select name="payment_method" id="renewPaidMethod" required>
          <option value="">-- Select --</option>
          <option value="Paypal">Paypal</option>
          <option value="Check">Check</option>
        </select>
      </label>
      <div class="actions">
        <button class="button primary" type="submit">I’ve paid</button>
        <button class="button" type="button" id="renewPaidCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  var modal = document.getElementById('renewPaidModal');
  var closeBtn = document.getElementById('renewPaidClose');
  var cancelBtn = document.getElementById('renewPaidCancel');
  var err = document.getElementById('renewPaidErr');
  var form = document.getElementById('renewPaidForm');
  var youthIdInp = document.getElementById('renewPaidYouthId');

  function showErr(msg){ if(err){ err.style.display=''; err.textContent = msg || 'Operation failed.'; } }
  function clearErr(){ if(err){ err.style.display='none'; err.textContent=''; } }
  function open(id){
    if (!modal) return;
    clearErr();
    if (youthIdInp) youthIdInp.value = id || '';
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden','false');
  }
  function hide(){
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden','true');
  }

  // Open buttons
  var btns = document.querySelectorAll('.open-paid-modal');
  for (var i=0;i<btns.length;i++){
    btns[i].addEventListener('click', function(e){
      e.preventDefault();
      var id = this.getAttribute('data-youth-id');
      open(id);
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', function(){ hide(); });
  if (cancelBtn) cancelBtn.addEventListener('click', function(){ hide(); });
  if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) hide(); });

  function suppressRenewFor(days){
    try {
      var maxAge = days*24*60*60;
      var now = Math.floor(Date.now()/1000);
      var until = now + maxAge;
      document.cookie = 'renew_prompt_dismiss_until=' + until + '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax';
    } catch (e) {}
  }

  if (form) {
    form.addEventListener('submit', function(e){
      e.preventDefault();
      clearErr();
      
      // Double-click protection
      var submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn && submitBtn.disabled) return;
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Processing...';
      }
      
      var fd = new FormData(form);
      fetch(form.getAttribute('action') || '/payment_notifications_actions.php', { method:'POST', body: fd, credentials:'same-origin' })
        .then(function(res){ return res.json().catch(function(){ throw new Error('Invalid response'); }); })
        .then(function(json){
          if (json && json.ok) {
            suppressRenewFor(7);
            window.location = '/index.php?renewed=1';
          } else {
            // Re-enable button on error
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.textContent = "I've paid";
            }
            showErr((json && json.error) ? json.error : 'Operation failed.');
          }
        })
        .catch(function(){ 
          // Re-enable button on error
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = "I've paid";
          }
          showErr('Network error.'); 
        });
    });
  }
})();
</script>

<?php
  // Complete Your Profile section (single next step)
  // Flash after uploading via homepage section
  $showProfileUploadFlash = !empty($_GET['uploaded']) && !empty($_GET['after_profile_upload']);

  // Dismiss for 30 days via cookie
  $suppressCompletePrompt = false;
  try {
    if (isset($_COOKIE['complete_profile_prompt_dismiss_until'])) {
      $dt = (int)$_COOKIE['complete_profile_prompt_dismiss_until'];
      if ($dt > time()) $suppressCompletePrompt = true;
    }
  } catch (Throwable $e) {
    $suppressCompletePrompt = false;
  }

  // Suppress child prompt on the immediate load after adult upload
  $suppressChildOnce = !empty($_GET['after_profile_upload']);

  $mePhotoUrl = Files::profilePhotoUrl($me['photo_public_file_id'] ?? null);
  $firstChildNoPhoto = null;
  if ($mePhotoUrl !== '' && is_array($kids ?? null)) {
    foreach ($kids as $k) {
      $kPhotoUrl = Files::profilePhotoUrl($k['photo_public_file_id'] ?? null);
      if ($kPhotoUrl === '') { $firstChildNoPhoto = $k; break; }
    }
  }
?>

<?php if ($showProfileUploadFlash): ?>
  <p class="flash">Your profile photo upload was successful.</p>
<?php endif; ?>

<script>
(function(){
  function setCpDismissCookie(days){
    var maxAge = days*24*60*60;
    var now = Math.floor(Date.now()/1000);
    var until = now + maxAge;
    document.cookie = 'complete_profile_prompt_dismiss_until=' + until + '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax';
  }
  function initCpClose(){
    var btn = document.getElementById('cpCloseBtn');
    var card = document.getElementById('cpCard');
    if (btn && card) {
      btn.addEventListener('click', function(e){
        e.preventDefault();
        setCpDismissCookie(30);
        card.style.display = 'none';
      });
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCpClose);
  } else {
    initCpClose();
  }
})();
</script>

<?php if ($mePhotoUrl === '' && !$suppressCompletePrompt): ?>
<div id="cpCard" class="card" style="margin-top:16px;">
  <button class="close" id="cpCloseBtn" aria-label="Close" style="float:right;font-size:18px;background:none;border:none;cursor:pointer">&times;</button>
  <h3>Complete Your Profile</h3>
  <p>Add a profile photo to complete your profile.</p>
  <form method="post" action="/upload_photo.php?type=adult&adult_id=<?= (int)($me['id'] ?? 0) ?>&return_to=<?= h('/index.php?after_profile_upload=1') ?>" enctype="multipart/form-data" class="stack" style="max-width:520px">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Upload photo
      <input type="file" name="photo" accept="image/*" required>
    </label>
    <div class="actions">
      <button class="button primary">Submit</button>
    </div>
  </form>
</div>
<?php elseif (!empty($firstChildNoPhoto) && !$suppressCompletePrompt && !$suppressChildOnce): ?>
<?php
  $childFullName = trim((string)($firstChildNoPhoto['first_name'] ?? '').' '.(string)($firstChildNoPhoto['last_name'] ?? ''));
  $childFirst = (string)($firstChildNoPhoto['first_name'] ?? '');
?>
<div id="cpCard" class="card" style="margin-top:16px;">
  <button class="close" id="cpCloseBtn" aria-label="Close" style="float:right;font-size:18px;background:none;border:none;cursor:pointer">&times;</button>
  <h3>Complete <?= h($childFullName) ?>'s profile</h3>
  <p>Add a profile photo to complete <?= h($childFirst) ?>'s profile.</p>
  <form method="post" action="/upload_photo.php?type=youth&youth_id=<?= (int)($firstChildNoPhoto['id'] ?? 0) ?>&return_to=<?= h('/index.php') ?>" enctype="multipart/form-data" class="stack" style="max-width:520px">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Upload photo
      <input type="file" name="photo" accept="image/*" required>
    </label>
    <div class="actions">
      <button class="button primary">Submit</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php
  // Volunteer CTA (only if not dismissed for 60 days)
  $suppressVolunteer = false;
  try {
    if (isset($_COOKIE['volunteer_prompt_dismiss_until'])) {
      $dt = (int)$_COOKIE['volunteer_prompt_dismiss_until'];
      if ($dt > time()) $suppressVolunteer = true;
    }
  } catch (Throwable $e) {
    $suppressVolunteer = false;
  }

  $volCandidate = null;
  $volRolesOpen = [];
  if (!$suppressVolunteer) {
    try {
      $yesIds = RSVPManagement::listEventIdsWithYesRsvpForUser((int)$me['id']);
      $cands = [];
      $upcoming = EventManagement::listUpcoming(500);
      if (!empty($yesIds)) {
        $yesSet = array_flip(array_map('intval', $yesIds));
        foreach ($upcoming as $ev) {
          $eid = (int)($ev['id'] ?? 0);
          if ($eid > 0 && isset($yesSet[$eid])) {
            $cands[] = $ev;
          }
        }
        usort($cands, function($a,$b){ return strcmp((string)($a['starts_at'] ?? ''), (string)($b['starts_at'] ?? '')); });
      }
      foreach ($cands as $ev) {
        $eid = (int)$ev['id'];
        // Must have open roles
        if (!Volunteers::openRolesExist($eid)) continue;
        // Skip if the user already has any signup for this event
        $chk = pdo()->prepare("SELECT 1 FROM volunteer_signups WHERE event_id=? AND user_id=? LIMIT 1");
        $chk->execute([$eid, (int)$me['id']]);
        if ((bool)$chk->fetchColumn()) continue;

        $roles = Volunteers::rolesWithCounts($eid);
        $open = [];
        foreach ($roles as $r) {
          if (!empty($r['is_unlimited']) || (int)($r['open_count'] ?? 0) > 0) $open[] = $r;
        }
        if (empty($open)) continue;

        $volCandidate = $ev;
        $volRolesOpen = $open;
        break;
      }
    } catch (Throwable $e) {
      $volCandidate = null;
      $volRolesOpen = [];
    }
  }
?>

<?php if ($volCandidate): ?>
  <?php
    $roleSummaries = [];
    foreach ($volRolesOpen as $r) {
      $roleSummaries[] = h((string)$r['title']) . ' (' . (!empty($r['is_unlimited']) ? 'no limit' : (int)$r['open_count']) . ')';
    }
    $roleSummaryText = implode(', ', $roleSummaries);
  ?>
  <div id="volCta" class="card" style="margin-top:16px;">
    <h3>Volunteer at <?= h($volCandidate['name'] ?? '') ?></h3>
    <p>
      The event "<?= h($volCandidate['name'] ?? '') ?>" needs people to volunteer to help with the following:
      <?= $roleSummaryText ?>. Would you be able to help?
    </p>
    <div class="actions">
      <button class="button primary" id="volOpenBtn">Sign-up</button>
    </div>
  </div>

  <div id="volModal" class="modal hidden" aria-hidden="true">
    <div class="modal-content">
      <button class="close" id="volCloseBtn" aria-label="Close">&times;</button>
      <h3>Volunteer at <?= h($volCandidate['name'] ?? '') ?></h3>
      <div id="volRoles" class="stack">
        <?php foreach ($volRolesOpen as $r): ?>
          <form method="post" action="/volunteer_actions.php" class="stack" style="border:1px solid #e8e8ef;border-radius:8px;padding:8px;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="signup">
            <input type="hidden" name="event_id" value="<?= (int)($volCandidate['id'] ?? 0) ?>">
            <input type="hidden" name="role_id" value="<?= (int)($r['id'] ?? 0) ?>">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
              <div>
                <strong><?= h((string)$r['title']) ?></strong>
                <span class="small">(<?= !empty($r['is_unlimited']) ? 'no limit' : ((int)($r['open_count'] ?? 0) . ' remaining') ?>)</span>
                <?php if (trim((string)($r['description'] ?? '')) !== ''): ?>
                  <div class="small" style="margin-top:4px; white-space:pre-wrap;"><?= h((string)$r['description']) ?></div>
                <?php endif; ?>
              </div>
              <button class="button">Sign Up</button>
            </div>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <script>
    (function(){
      var openBtn = document.getElementById('volOpenBtn');
      var modal = document.getElementById('volModal');
      var closeBtn = document.getElementById('volCloseBtn');
      var cta = document.getElementById('volCta');
      function show(){ if(modal){ modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); } }
      function hide(){ if(modal){ modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); } }
      function setDismissCookie(days){
        var maxAge = days*24*60*60;
        var now = Math.floor(Date.now()/1000);
        var until = now + maxAge;
        document.cookie = 'volunteer_prompt_dismiss_until=' + until + '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax';
      }
      if (openBtn) openBtn.addEventListener('click', function(e){ e.preventDefault(); show(); });
      if (closeBtn) closeBtn.addEventListener('click', function(e){
        e.preventDefault(); setDismissCookie(60); hide(); if (cta) cta.style.display='none';
      });
      if (modal) modal.addEventListener('click', function(e){
        if (e.target === modal) { setDismissCookie(60); hide(); if (cta) cta.style.display='none'; }
      });

      // AJAX handling for volunteer actions to keep modal open and refresh roles
      var rolesWrap = document.getElementById('volRoles');

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
          html += '<form method="post" action="/volunteer_actions.php" class="stack" style="border:1px solid #e8e8ef;border-radius:8px;padding:8px;">'
                + '<input type="hidden" name="csrf" value="'+esc(json.csrf)+'">'
                + '<input type="hidden" name="event_id" value="'+esc(json.event_id)+'">'
                + '<input type="hidden" name="role_id" value="'+esc(r.id)+'">'
                + '<input type="hidden" name="action" value="'+(signed ? 'remove' : 'signup')+'">'
                + '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">'
                +   '<div><strong>'+esc(r.title||'')+'</strong> <span class="small">(' + (unlimited ? 'no limit' : (open + ' remaining')) + ')</span>' + (r.description ? '<div class="small" style="margin-top:4px; white-space:pre-wrap;">'+esc(r.description)+'</div>' : '') + '</div>'
                +   '<div>';
          if (signed) {
            html += '<span class="small" style="margin-right:8px;">You’re signed up</span><button class="button danger">Remove</button>';
          } else if (unlimited || open > 0) {
            html += '<button class="button">Sign Up</button>';
          } else {
            html += '<span class="small">Full</span>';
          }
          html +=    '</div></div></form>';
        }
        rolesWrap.innerHTML = html;
      }

      function showError(msg) {
        if (!rolesWrap) return;
        var p = document.createElement('p');
        p.className = 'error small';
        p.textContent = msg;
        rolesWrap.insertBefore(p, rolesWrap.firstChild);
      }

      if (modal) {
        modal.addEventListener('submit', function(e){
          var form = e.target.closest('form');
          if (!form || form.getAttribute('action') !== '/volunteer_actions.php') return;
          e.preventDefault();
          var fd = new FormData(form);
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
    })();
  </script>
<?php endif; ?>

<?php
  // Upcoming Events (next 2 months, max 5)
  if (!function_exists('renderEventWhen')) {
    function renderEventWhen(string $startsAt, ?string $endsAt): string {
      $s = strtotime($startsAt);
      if ($s === false) return $startsAt;
      $base = date('F j, Y gA', $s);
      if (!$endsAt) return $base;

      $e = strtotime($endsAt);
      if ($e === false) return $base;

      if (date('Y-m-d', $s) === date('Y-m-d', $e)) {
        return $base . ' (ends at ' . date('gA', $e) . ')';
      }
      return $base . ' (ends on ' . date('F j, Y', $e) . ' at ' . date('gA', $e) . ')';
    }
  }
  if (!function_exists('truncatePlain')) {
    function truncatePlain(string $text, int $limit = 200): string {
      $text = trim((string)$text);
      if ($text === '') return '';
      if (function_exists('mb_strlen')) {
        if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
        $slice = mb_substr($text, 0, $limit, 'UTF-8');
      } else {
        if (strlen($text) <= $limit) return $text;
        $slice = substr($text, 0, $limit);
      }
      return rtrim($slice) . '…';
    }
  }

  try {
    $now = date('Y-m-d H:i:s');
    $in2mo = date('Y-m-d H:i:s', strtotime('+2 months'));
    $events = EventManagement::listBetween($now, $in2mo);
    $homeEvents = array_slice($events, 0, 5);
  } catch (Throwable $e) {
    $homeEvents = [];
  }
?>

<?php if (!empty($homeEvents)): ?>
<div class="card" style="margin-top:16px;">
  <h3>Upcoming Events</h3>
  <div class="grid">
    <?php foreach ($homeEvents as $e): ?>
      <div class="card">
        <h3><a href="/event.php?id=<?= (int)$e['id'] ?>"><?= h($e['name']) ?></a></h3>
        <?php
          $thumbUrl = Files::eventPhotoUrl($e['photo_public_file_id'] ?? null);
          if ($thumbUrl !== ''):
        ?>
          <img src="<?= h($thumbUrl) ?>" alt="<?= h($e['name']) ?> image" class="event-thumb" width="180">
        <?php endif; ?>
        <p><strong>When:</strong> <?= h(renderEventWhen($e['starts_at'], $e['ends_at'] ?? null)) ?></p>
        <?php
          if (!empty($e['location'])):
            $locAddr = trim((string)($e['location_address'] ?? ''));
            $mapsUrl = trim((string)($e['google_maps_url'] ?? ''));
            $mapHref = $mapsUrl !== '' ? $mapsUrl : ($locAddr !== '' ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($locAddr) : '');
        ?>
          <p><strong>Where:</strong> <?= h($e['location']) ?><?php if ($mapHref !== ''): ?> <a class="small" href="<?= h($mapHref) ?>" target="_blank" rel="noopener">map</a><?php endif; ?></p>
        <?php endif; ?>
        <?php if (!empty($e['description'])): ?>
          <p><?= nl2br(h(truncatePlain((string)$e['description'], 200))) ?></p>
        <?php endif; ?>
        <?php if (!empty($e['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$e['max_cub_scouts'] ?></p><?php endif; ?>

        <?php
          $eviteUrl = trim((string)($e['evite_rsvp_url'] ?? ''));
          if ($eviteUrl !== ''):
        ?>
          <p><a class="button primary" target="_blank" rel="noopener" href="<?= h($eviteUrl) ?>">RSVP TO EVITE</a></p>
        <?php else: ?>
          <?php
            // Show current RSVP summary if user has one; otherwise show RSVP CTA
            $summary = RSVPManagement::getRsvpSummaryForUserEvent((int)$e['id'], (int)$me['id']);

            if ($summary):
              $ad = (int)($summary['adult_count'] ?? 0);
              $kids = (int)($summary['youth_count'] ?? 0);
              $guests = (int)($summary['n_guests'] ?? 0);

              $byText = '';
              $creatorId = (int)($summary['created_by_user_id'] ?? 0);
              if ($creatorId && $creatorId !== (int)$me['id']) {
                $nameBy = UserManagement::getFullName($creatorId);
                if ($nameBy !== null) {
                  $byText = ' <span class="small">(by ' . h($nameBy) . ')</span>';
                }
              }
          ?>
            <p>
              You RSVP’d <?= h(ucfirst((string)$summary['answer'])) ?> for
              <?= (int)$ad ?> adult<?= $ad === 1 ? '' : 's' ?> and
              <?= (int)$kids ?> kid<?= $kids === 1 ? '' : 's' ?>
              <?= $guests > 0 ? ', and '.(int)$guests.' other guest'.($guests === 1 ? '' : 's') : '' ?>.
              <?= $byText ?>
            </p>
            <p>
              <a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">View</a>
              <a class="button" href="/event.php?id=<?= (int)$e['id'] ?>&open_rsvp=1">Edit RSVP</a>
            </p>
          <?php else: ?>
            <p>
              <a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">View</a>
              <a class="button primary" href="/event.php?id=<?= (int)$e['id'] ?>&open_rsvp=1">RSVP</a>
            </p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($showRegisterSection): ?>
<div class="card" style="margin-top:16px;">
  <h3>How to register your child for Cub Scouts</h3>
  <ol>
    <li>
      Fill out this “Youth Application Form” and send it to <?= h($cubmasterLabel) ?> or <?= h($committeeChairLabel) ?>
      <div><a href="https://filestore.scouting.org/filestore/pdf/524-406.pdf" target="_blank" rel="noopener">https://filestore.scouting.org/filestore/pdf/524-406.pdf</a></div>
    </li>
    <li>
      Pay the dues through any payment option here:
      <div><a href="https://www.scarsdalepack440.com/join" target="_blank" rel="noopener">https://www.scarsdalepack440.com/join</a></div>
    </li>
    <li>
      Buy a uniform. Instructions here:
      <div><a href="https://www.scarsdalepack440.com/uniforms" target="_blank" rel="noopener">https://www.scarsdalepack440.com/uniforms</a></div>
    </li>
  </ol>
</div>
<?php endif; ?>

<?php if ($isApprover && !empty($pending)): ?>
<div class="card" style="margin-top:16px;">
  <h3>Pending Reimbursement Requests</h3>
  <table class="list">
    <thead>
      <tr>
        <th>Title</th>
        <th>Submitted By</th>
        <th>Last Modified</th>
        <th>Status</th>
        <th>Latest Note</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pending as $r): ?>
        <tr>
          <td><a href="/reimbursement_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a></td>
          <td><?= h($r['submitter_name'] ?? '') ?></td>
          <td><?= h($r['last_modified_at']) ?></td>
          <td><?= h($r['status']) ?></td>
          <td class="small" style="max-width:360px; white-space:pre-wrap;"><?= h($r['latest_note']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:8px;">
    <a class="button" href="/reimbursements.php?all=1">View all reimbursements</a>
  </div>
</div>
<?php endif; ?>

<!-- Register Modal -->
<div id="registerModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:520px;">
    <button class="close" type="button" id="registerClose" aria-label="Close">&times;</button>
    <h3>How to register your child for Cub Scouts</h3>
    <div id="registerErr" class="error small" style="display:none;"></div>
    <ol>
      <li>
        Fill out this “Youth Application Form” and send it to <?= h($cubmasterLabel) ?> or <?= h($committeeChairLabel) ?>.
        <div><a href="https://filestore.scouting.org/filestore/pdf/524-406.pdf" target="_blank" rel="noopener">https://filestore.scouting.org/filestore/pdf/524-406.pdf</a></div>
      </li>
      <li>
        Pay the dues through any payment option here:
        <div><a href="https://www.scarsdalepack440.com/join" target="_blank" rel="noopener">https://www.scarsdalepack440.com/join</a></div>
      </li>
      <li>
        Buy a uniform. Instructions here:
        <div><a href="https://www.scarsdalepack440.com/uniforms" target="_blank" rel="noopener">https://www.scarsdalepack440.com/uniforms</a></div>
      </li>
    </ol>
    <form id="registerForm" class="stack" method="post" action="/pending_registrations_actions.php" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="youth_id" id="registerYouthId" value="">
      <label>Application (PDF or image)
        <input type="file" name="application" accept="application/pdf,image/*">
      </label>
      <label class="inline">
        <input type="checkbox" name="already_sent" value="1"> I have already sent the application another way
      </label>
      <label>Please tell us how you paid <span style="color:red;">*</span>
        <select name="payment_method" required>
          <option value="">Payment method</option>
          <option value="Paypal">Paypal</option>
          <option value="Check">Check</option>
          <option value="I will pay later">I will pay later</option>
        </select>
      </label>
      <label>Optional comment
        <input type="text" name="comment" placeholder="Any notes for leadership">
      </label>
      <div class="actions">
        <button class="button primary" type="submit">Please process my application</button>
        <button class="button" type="button" id="registerCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  var modal = document.getElementById('registerModal');
  var closeBtn = document.getElementById('registerClose');
  var cancelBtn = document.getElementById('registerCancel');
  var form = document.getElementById('registerForm');
  var err = document.getElementById('registerErr');
  var youthIdInp = document.getElementById('registerYouthId');

  function showErr(msg){ if(err){ err.style.display=''; err.textContent = msg || 'Operation failed.'; } }
  function clearErr(){ if(err){ err.style.display='none'; err.textContent=''; } }
  function open(id){
    if (!modal) return;
    clearErr();
    if (youthIdInp) youthIdInp.value = id || '';
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden','false');
  }
  function hide(){
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden','true');
  }

  var btns = document.querySelectorAll('.open-register-modal');
  for (var i=0;i<btns.length;i++){
    btns[i].addEventListener('click', function(e){
      e.preventDefault();
      var id = this.getAttribute('data-youth-id');
      open(id);
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', function(){ hide(); });
  if (cancelBtn) cancelBtn.addEventListener('click', function(){ hide(); });
  if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) hide(); });

  if (form) {
    form.addEventListener('submit', function(e){
      e.preventDefault();
      clearErr();
      
      // Double-click protection
      var submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn && submitBtn.disabled) return;
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Processing application...';
      }
      
      var fd = new FormData(form);
      fetch(form.getAttribute('action') || '/pending_registrations_actions.php', { method:'POST', body: fd, credentials:'same-origin' })
        .then(function(res){ return res.json().catch(function(){ throw new Error('Invalid response'); }); })
        .then(function(json){
          if (json && json.ok) {
            // Redirect with success message
            window.location = '/index.php?registered=1';
          } else {
            // Re-enable button on error
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Please process my application';
            }
            showErr((json && json.error) ? json.error : 'Operation failed.');
          }
        })
        .catch(function(){ 
          // Re-enable button on error
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Please process my application';
          }
          showErr('Network error.'); 
        });
    });
  }
})();
</script>

<?php footer_html(); ?>
