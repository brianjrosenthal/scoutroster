<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/EventUIManager.php';
require_once __DIR__ . '/lib/Text.php';
require_once __DIR__ . '/lib/RSVPManagement.php';
require_once __DIR__ . '/lib/EmailPreviewUI.php';
require_once __DIR__ . '/settings.php';

if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') {
  header_html('Invitations');
  echo '<h2>Invitations</h2><div class="card"><p class="error">INVITE_HMAC_KEY is not configured. Edit config.local.php.</p></div>';
  echo '<div class="card"><a class="button" href="/events.php">Back</a></div>';
  footer_html();
  exit;
}

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }

// Load event
$event = EventManagement::findById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

$me = current_user();
$defaultOrganizer = trim((string)($me['email'] ?? ''));
$siteTitle = Settings::siteTitle();
$subjectDefault = 'Please RSVP to ' . (string)$event['name'];

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $host;

$error = null;


// Helper functions for email preview
function getRsvpStatus(int $eventId, int $userId): ?string {
  return RSVPManagement::getAnswerForUserEvent($eventId, $userId);
}

function getEmailIntroduction(string $emailType, int $eventId, int $userId): string {
  if ($emailType === 'none') {
    return '';
  }
  
  if ($emailType === 'invitation') {
    return "You're Invited to...";
  }
  
  if ($emailType === 'reminder') {
    // Check if user has RSVP'd to this event
    $rsvpStatus = getRsvpStatus($eventId, $userId);
    return $rsvpStatus ? 'Reminder:' : 'Reminder to RSVP for...';
  }
  
  return '';
}

function generateRsvpButtonHtml(int $eventId, int $userId, string $deepLink): string {
  $safeDeep = htmlspecialchars($deepLink, ENT_QUOTES, 'UTF-8');
  $rsvpStatus = getRsvpStatus($eventId, $userId);
  
  if ($rsvpStatus) {
    // User has RSVP'd - show bold text status + separate "View Details" button
    $statusText = ucfirst($rsvpStatus); // 'yes' -> 'Yes', 'maybe' -> 'Maybe', 'no' -> 'No'
    return '<p style="margin:0 0 10px;color:#222;font-size:16px;font-weight:bold;">You RSVP\'d '. htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') .'</p>
            <p style="margin:0 0 16px;">
              <a href="'. $safeDeep .'" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View Details</a>
            </p>';
  } else {
    // User hasn't RSVP'd - show standard button
    return '<p style="margin:0 0 16px;">
      <a href="'. $safeDeep .'" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View & RSVP</a>
    </p>';
  }
}

function generateEmailHTML(array $event, string $siteTitle, string $baseUrl, string $deepLink, string $whenText, string $whereHtml, string $description, string $googleLink, string $outlookLink, string $icsDownloadLink, string $emailType = 'none', int $eventId = 0, int $userId = 0, bool $includeCalendarLinks = true): string {
  $safeSite = htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8');
  $safeEvent = htmlspecialchars((string)$event['name'], ENT_QUOTES, 'UTF-8');
  $safeWhen = htmlspecialchars($whenText, ENT_QUOTES, 'UTF-8');
  $safeDeep = htmlspecialchars($deepLink, ENT_QUOTES, 'UTF-8');
  $safeGoogle = htmlspecialchars($googleLink, ENT_QUOTES, 'UTF-8');
  $safeOutlook = htmlspecialchars($outlookLink, ENT_QUOTES, 'UTF-8');
  $safeIcs = htmlspecialchars($icsDownloadLink, ENT_QUOTES, 'UTF-8');
  
  // Get introduction text
  $introduction = getEmailIntroduction($emailType, $eventId, $userId);
  $introHtml = '';
  if ($introduction !== '') {
    $safeIntro = htmlspecialchars($introduction, ENT_QUOTES, 'UTF-8');
    $introHtml = '<p style="margin:0 0 8px;color:#666;font-size:14px;">'. $safeIntro .'</p>';
  }
  
  $calendarLinksHtml = '';
  if ($includeCalendarLinks) {
    $calendarLinksHtml = '<div style="text-align:center;margin:0 0 12px;">
      <a href="'. $safeGoogle .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Add to Google</a>
      <a href="'. $safeOutlook .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Add to Outlook</a>
      <a href="'. $safeIcs .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Download .ics</a>
    </div>';
  }
  
  // Generate context-aware button HTML
  $buttonHtml = generateRsvpButtonHtml($eventId, $userId, $deepLink);
  
  return '
  <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;margin:0 auto;padding:16px;color:#222;">
    <div style="text-align:center;">
      '. $introHtml .'
      <h2 style="margin:0 0 8px;">'. $safeEvent .'</h2>
      <p style="margin:0 0 16px;color:#444;">'. $safeSite .'</p>
      '. $buttonHtml .'
    </div>
    <div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fafafa;">
      <div><strong>When:</strong> '. $safeWhen .'</div>'.
      ($whereHtml !== '' ? '<div><strong>Where:</strong> '. $whereHtml .'</div>' : '') .'
    </div>'
    . ($description !== '' ? ('<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fff;">
      <div>'. Text::renderMarkup($description) .'</div>
    </div>') : '')
    . $calendarLinksHtml
    . '<p style="font-size:12px;color:#666;text-align:center;margin:12px 0 0;">
      If the button does not work, open this link: <br><a href="'. $safeDeep .'">'. $safeDeep .'</a>
    </p>
  </div>';
}

$me = current_user();
$isAdmin = !empty($me['is_admin']);

header_html('Send Event Invitations');
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Send Invitations: <?= h($event['name']) ?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Back to Event</a>
    <?= EventUIManager::renderAdminMenu((int)$eventId, 'invite') ?>
  </div>
</div>

<div class="card">
  <p class="small" style="margin-top:0;">Compose and send personalized RSVP invitations for this event. Each email includes a one-click RSVP link and an attached calendar invite (.ics).</p>
  <form method="post" action="admin_event_invite_preview.php" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">

    <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

    <fieldset>
      <legend>Filter adults by:</legend>
      <?php
        $regStatus = $_POST['registration_status'] ?? 'all';
        $selectedGrades = $_POST['grades'] ?? [];
        $rsvpStatus = $_POST['rsvp_status'] ?? 'all';
        $specificAdultIds = $_POST['specific_adult_ids'] ?? [];
        
        // Normalize arrays from form data
        if (is_string($selectedGrades)) {
          $selectedGrades = explode(',', $selectedGrades);
        }
        $selectedGrades = array_filter(array_map('intval', (array)$selectedGrades));
        
        if (is_string($specificAdultIds)) {
          $specificAdultIds = explode(',', $specificAdultIds);
        }
        $specificAdultIds = array_filter(array_map('intval', (array)$specificAdultIds));
      ?>
      
      <div style="margin-bottom: 16px;">
        <label><strong>Registration status:</strong></label>
        <div style="margin-left: 16px;">
          <label class="inline"><input type="radio" name="registration_status" value="all" <?= $regStatus==='all'?'checked':'' ?>> All</label>
          <label class="inline"><input type="radio" name="registration_status" value="registered" <?= $regStatus==='registered'?'checked':'' ?>> Registered only</label>
          <label class="inline"><input type="radio" name="registration_status" value="unregistered" <?= $regStatus==='unregistered'?'checked':'' ?>> Unregistered only</label>
        </div>
      </div>

      <div style="margin-bottom: 16px;">
        <label><strong>Grade:</strong></label>
        <div style="margin-left: 16px;">
          <label style="display: inline-block;"><input type="checkbox" name="grades[]" value="0" <?= in_array(0, $selectedGrades)?'checked':'' ?>> K</label><?php for($i=1;$i<=5;$i++): ?><label style="display: inline-block;"><input type="checkbox" name="grades[]" value="<?= $i ?>" <?= in_array($i, $selectedGrades)?'checked':'' ?>> <?= $i ?></label><?php endfor; ?>
          <br><span class="small">(Select multiple grades to include families with children in any of those grades)</span>
        </div>
      </div>

      <div style="margin-bottom: 16px;">
        <label><strong>RSVP'd:</strong></label>
        <div style="margin-left: 16px;">
          <label class="inline"><input type="radio" name="rsvp_status" value="all" <?= $rsvpStatus==='all'?'checked':'' ?>> All</label>
          <label class="inline"><input type="radio" name="rsvp_status" value="not_rsvped" <?= $rsvpStatus==='not_rsvped'?'checked':'' ?>> People who have not RSVP'd</label>
        </div>
      </div>

      <div style="margin-bottom: 16px;">
        <label><strong>Specific adults:</strong></label>
        <div style="margin-left: 16px;">
          <div id="specificAdultsContainer">
            <?php if (!empty($specificAdultIds)): ?>
              <?php foreach ($specificAdultIds as $adultId): ?>
                <?php 
                  $adult = UserManagement::findBasicForEmailingById($adultId);
                  if ($adult):
                    $name = trim(($adult['first_name'] ?? '') . ' ' . ($adult['last_name'] ?? ''));
                    $displayName = $name ?: 'User #' . $adultId;
                    if (!empty($adult['email'])) $displayName .= ' <' . $adult['email'] . '>';
                ?>
                  <div class="selected-adult" data-adult-id="<?= $adultId ?>">
                    <span><?= h($displayName) ?></span>
                    <button type="button" class="remove-adult" style="margin-left: 8px; padding: 2px 6px; font-size: 12px;">×</button>
                    <input type="hidden" name="specific_adult_ids[]" value="<?= $adultId ?>">
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="typeahead" style="margin-top: 8px;">
            <input type="text" id="adultTypeahead" placeholder="Type name or email to add specific adults" autocomplete="off">
            <div id="adultTypeaheadResults" class="typeahead-results" role="listbox" style="display:none;"></div>
          </div>
          <span class="small">Add specific adults to include regardless of other filters</span>
        </div>
      </div>

      <div style="margin-bottom: 16px;">
        <label><strong>Suppress duplicate policy:</strong></label>
        <div style="margin-left: 16px;">
          <?php $suppressPolicy = $_POST['suppress_policy'] ?? 'last_24_hours'; ?>
          <label class="inline"><input type="radio" name="suppress_policy" value="last_24_hours" <?= $suppressPolicy==='last_24_hours'?'checked':'' ?>> Don't send if invited in last 24 hours</label>
          <label class="inline"><input type="radio" name="suppress_policy" value="ever_invited" <?= $suppressPolicy==='ever_invited'?'checked':'' ?>> Don't send if ever invited</label>
          <label class="inline"><input type="radio" name="suppress_policy" value="none" <?= $suppressPolicy==='none'?'checked':'' ?>> No suppression policy</label>
          <span class="small">Choose how to handle users who have already been invited to this event</span>
        </div>
      </div>
    </fieldset>

    <div style="margin-bottom: 16px;">
      <label><strong>Email Type:</strong></label>
      <div style="margin-left: 16px;">
        <?php $emailType = $_POST['email_type'] ?? 'none'; ?>
        <label class="inline"><input type="radio" name="email_type" value="none" <?= $emailType==='none'?'checked':'' ?>> No introduction (default)</label>
        <label class="inline"><input type="radio" name="email_type" value="invitation" <?= $emailType==='invitation'?'checked':'' ?>> Initial invitation ("You're Invited to...")</label>
        <label class="inline"><input type="radio" name="email_type" value="reminder" <?= $emailType==='reminder'?'checked':'' ?>> Reminder (context-aware based on RSVP status)</label>
        <span class="small">Choose the type of introduction text to appear above the event title</span>
      </div>
    </div>

    <label>Subject
      <input type="text" name="subject" value="<?= h($_POST['subject'] ?? $subjectDefault) ?>">
    </label>

    <label>Organizer Email (optional, used in calendar invite)
      <input type="email" name="organizer" value="<?= h($_POST['organizer'] ?? $defaultOrganizer) ?>">
    </label>

    <?php
      // Default body content with markdown formatting
      $defaultBody = '';
      if (!empty($event['description'])) {
        $defaultBody = '**Description:** ' . trim((string)$event['description']);
      }
      $currentBody = $_POST['description'] ?? $defaultBody;
    ?>
    <label>Email Body (appears below the When/Where box)
      <textarea name="description" rows="6" placeholder="Enter custom body content for this invitation..."><?= h($currentBody) ?></textarea>
      <span class="small">This content will appear in the email body below the When/Where information. Supports markdown formatting (e.g., **bold**, *italic*).</span>
    </label>

    <div class="actions">
      <button class="primary" id="previewEmailsBtn">Preview Invitations to (<span id="recipientCount">0</span>) Recipients</button>
      <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Back to Event</a>
      <a class="button" href="/events.php">Manage Events</a>
    </div>
  </form>
</div>

<?php 
  // Use shared EmailPreviewUI for consistent preview across all pages
  EmailPreviewUI::renderEmailPreview($event, $eventId, $baseUrl, $siteTitle, $currentBody, $_POST['email_type'] ?? 'none');
?>

<?php if ($isAdmin): ?>
  <?= EventUIManager::renderAdminModals((int)$eventId) ?>
  <?= EventUIManager::renderAdminMenuScript((int)$eventId) ?>
<?php endif; ?>

<style>
.selected-adult {
    display: inline-block;
    background: #e9ecef;
    border: 1px solid #ced4da;
    border-radius: 4px;
    padding: 4px 8px;
    margin: 2px;
    font-size: 14px;
}
.selected-adult .remove-adult {
    background: none;
    border: none;
    color: #dc3545;
    cursor: pointer;
    font-weight: bold;
}
.selected-adult .remove-adult:hover {
    color: #a71e2a;
}
.typeahead-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ced4da;
    border-top: none;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
}
.typeahead-result {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #e9ecef;
}
.typeahead-result:hover {
    background: #f8f9fa;
}
.typeahead-result:last-child {
    border-bottom: none;
}
.typeahead {
    position: relative;
}
</style>

<script>
(function() {
    const previewBtn = document.getElementById('previewEmailsBtn');
    const recipientCountSpan = document.getElementById('recipientCount');
    const form = previewBtn ? previewBtn.closest('form') : null;
    let isSubmitting = false;
    let countTimeout = null;

    // Dynamic subject line updating based on email type
    const emailTypeRadios = document.querySelectorAll('input[name="email_type"]');
    const subjectField = document.querySelector('input[name="subject"]');
    const defaultSubject = '<?= addslashes($subjectDefault) ?>';
    const eventName = '<?= addslashes((string)$event['name']) ?>';
    const eventDateTime = '<?= addslashes(date('D n/j \\a\\t g:i A', strtotime((string)$event['starts_at']))) ?>';
    
    function updateSubjectField() {
        if (!subjectField) return;
        
        const selectedType = document.querySelector('input[name="email_type"]:checked')?.value || 'none';
        let newSubject = defaultSubject;
        
        if (selectedType === 'invitation') {
            newSubject = `You're invited to "${eventName}", ${eventDateTime}`;
        } else if (selectedType === 'reminder') {
            newSubject = `Reminder: "${eventName}", ${eventDateTime}`;
        }
        
        // Only update if the current subject matches a generated pattern or is the default
        const currentSubject = subjectField.value.trim();
        const isDefaultSubject = currentSubject === defaultSubject;
        const isGeneratedSubject = currentSubject.startsWith("You're invited to \"") || 
                                 currentSubject.startsWith("Reminder: \"");
        
        if (isDefaultSubject || isGeneratedSubject) {
            subjectField.value = newSubject;
        }
    }
    
    emailTypeRadios.forEach(radio => {
        radio.addEventListener('change', updateSubjectField);
    });
    updateSubjectField();

    // Real-time recipient counting
    function updateRecipientCount() {
        if (!form || !recipientCountSpan) return;
        
        clearTimeout(countTimeout);
        countTimeout = setTimeout(() => {
            const formData = new FormData(form);
            
            fetch('admin_event_invite_count_recipients.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    recipientCountSpan.textContent = data.count;
                    if (previewBtn) {
                        previewBtn.disabled = data.count === 0;
                        if (data.count === 0) {
                            previewBtn.textContent = 'No Recipients Selected';
                        } else {
                            previewBtn.textContent = `Preview Invitations to (${data.count}) Recipients`;
                        }
                    }
                } else {
                    console.error('Error counting recipients:', data.error);
                    recipientCountSpan.textContent = '?';
                }
            })
            .catch(error => {
                console.error('Error counting recipients:', error);
                recipientCountSpan.textContent = '?';
            });
        }, 300);
    }

    if (form) {
        const filterInputs = form.querySelectorAll('input[name="registration_status"], input[name="grades[]"], input[name="rsvp_status"]');
        filterInputs.forEach(input => {
            input.addEventListener('change', updateRecipientCount);
        });
        updateRecipientCount();
    }

    // Specific adults management
    const adultTypeahead = document.getElementById('adultTypeahead');
    const adultResults = document.getElementById('adultTypeaheadResults');
    const adultsContainer = document.getElementById('specificAdultsContainer');
    let searchTimeout = null;

    if (adultTypeahead && adultResults && adultsContainer) {
        adultTypeahead.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                adultResults.style.display = 'none';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                fetch('/ajax_search_adults.php?q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        adultResults.innerHTML = '';
                        
                        if (data.length === 0) {
                            adultResults.innerHTML = '<div class="typeahead-result">No adults found</div>';
                        } else {
                            data.forEach(adult => {
                                const div = document.createElement('div');
                                div.className = 'typeahead-result';
                                div.textContent = adult.last_name + ', ' + adult.first_name + (adult.email ? ' <' + adult.email + '>' : '');
                                div.dataset.adultId = adult.id;
                                div.dataset.adultName = adult.first_name + ' ' + adult.last_name;
                                div.dataset.adultEmail = adult.email || '';
                                
                                div.addEventListener('click', function() {
                                    addSpecificAdult(adult.id, adult.first_name + ' ' + adult.last_name, adult.email);
                                    adultTypeahead.value = '';
                                    adultResults.style.display = 'none';
                                });
                                
                                adultResults.appendChild(div);
                            });
                        }
                        
                        adultResults.style.display = 'block';
                    })
                    .catch(error => {
                        console.error('Error searching adults:', error);
                        adultResults.innerHTML = '<div class="typeahead-result">Error searching</div>';
                        adultResults.style.display = 'block';
                    });
            }, 300);
        });

        document.addEventListener('click', function(e) {
            if (!adultTypeahead.contains(e.target) && !adultResults.contains(e.target)) {
                adultResults.style.display = 'none';
            }
        });

        function addSpecificAdult(id, name, email) {
            if (adultsContainer.querySelector(`[data-adult-id="${id}"]`)) {
                return;
            }

            const displayName = name + (email ? ' <' + email + '>' : '');
            
            const div = document.createElement('div');
            div.className = 'selected-adult';
            div.dataset.adultId = id;
            div.innerHTML = `
                <span>${displayName}</span>
                <button type="button" class="remove-adult" style="margin-left: 8px; padding: 2px 6px; font-size: 12px;">×</button>
                <input type="hidden" name="specific_adult_ids[]" value="${id}">
            `;
            
            div.querySelector('.remove-adult').addEventListener('click', function() {
                div.remove();
                updateRecipientCount();
            });
            
            adultsContainer.appendChild(div);
            updateRecipientCount();
        }

        adultsContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-adult')) {
                e.target.closest('.selected-adult').remove();
                updateRecipientCount();
            }
        });
    }
    
    // Email preview functionality is now handled by EmailPreviewUI class
})();
</script>

<?php footer_html(); ?>
