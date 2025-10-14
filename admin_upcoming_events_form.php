<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/UserContext.php';
require_once __DIR__ . '/settings.php';

// Check if user is an approver (key 3 position holder)
$ctx = UserContext::getLoggedInUserContext();
if (!$ctx || !UserManagement::isApprover((int)$ctx->id)) {
  http_response_code(403);
  exit('Access restricted to key 3 positions (Cubmaster, Committee Chair, Treasurer)');
}

$siteTitle = Settings::siteTitle();
$error = isset($_GET['error']) ? trim($_GET['error']) : null;

$me = current_user();
$isAdmin = !empty($me['is_admin']);

header_html('Upcoming Events Email');
?>

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

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Send Upcoming Events Email</h2>
  <div style="display: flex; gap: 8px;">
    <a class="button" href="/events.php">Back to Events</a>
  </div>
</div>

<div class="card">
  <p class="small" style="margin-top:0;">Send a list of upcoming events to selected recipients. Each recipient will receive personalized RSVP links for each event.</p>
  
  <?php if ($error): ?>
    <p class="error"><?= h($error) ?></p>
  <?php endif; ?>
  
  <form method="post" action="admin_upcoming_events_preview.php" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <fieldset>
      <legend>Filter adults by:</legend>
      <?php
        // Read from GET params first (when coming back from preview), then POST, then defaults
        $regStatus = $_GET['registration_status'] ?? $_POST['registration_status'] ?? 'registered_plus_leads';
        $selectedGrades = $_GET['grades'] ?? $_POST['grades'] ?? [];
        $specificAdultIds = $_GET['specific_adult_ids'] ?? $_POST['specific_adult_ids'] ?? [];
        
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
        <label><strong>Core Group:</strong></label>
        <div style="margin-left: 16px;">
          <label class="inline"><input type="radio" name="registration_status" value="registered_plus_leads" <?= $regStatus==='registered_plus_leads'?'checked':'' ?>> Registered + Active Leads</label>
          <label class="inline"><input type="radio" name="registration_status" value="registered" <?= $regStatus==='registered'?'checked':'' ?>> Registered only</label>
          <label class="inline"><input type="radio" name="registration_status" value="all" <?= $regStatus==='all'?'checked':'' ?>> All</label>
          <label class="inline"><input type="radio" name="registration_status" value="leadership" <?= $regStatus==='leadership'?'checked':'' ?>> Leadership</label>
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
        <label><strong>Suppress duplicate policy:</strong></label>
        <div style="margin-left: 16px;">
          <?php $suppressPolicy = $_GET['suppress_policy'] ?? $_POST['suppress_policy'] ?? 'last_24_hours'; ?>
          <label class="inline"><input type="radio" name="suppress_policy" value="last_24_hours" <?= $suppressPolicy==='last_24_hours'?'checked':'' ?>> Don't send if sent in last 24 hours</label>
          <label class="inline"><input type="radio" name="suppress_policy" value="none" <?= $suppressPolicy==='none'?'checked':'' ?>> No suppression (send to everyone)</label>
          <br><span class="small">Prevent duplicate emails within 24 hours, or send regardless of previous sends</span>
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
    </fieldset>

    <label>Subject
      <input type="text" name="subject" value="<?= h($_GET['subject'] ?? $_POST['subject'] ?? 'Cub Scout Upcoming Events') ?>" readonly>
      <span class="small">Fixed subject line for upcoming events emails</span>
    </label>

    <?php
      $defaultBody = "Loading upcoming events...";
      $currentBody = $_GET['description'] ?? $_POST['description'] ?? $defaultBody;
    ?>
    <label>Email Body
      <textarea name="description" id="emailBody" rows="12" placeholder="Loading upcoming events..."><?= h($currentBody) ?></textarea>
      <span class="small">Edit the list of events above. Use {link_event_X} tokens which will be replaced with personalized RSVP links for each recipient.</span>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Preview Email to (<span id="recipientCount">0</span>) Recipients</button>
      <a class="button" href="/events.php">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Email Preview</h3>
  <div id="emailPreviewContent" style="border: 1px solid #ddd; border-radius: 4px; padding: 16px; background: #f8f9fa;">
    <p style="text-align: center; color: #666;">Preview will appear here...</p>
  </div>
</div>

<script>
(function() {
  const form = document.querySelector('form');
  const recipientCountSpan = document.getElementById('recipientCount');
  const emailBody = document.getElementById('emailBody');
  const previewContent = document.getElementById('emailPreviewContent');
  let countTimeout = null;

  // Fetch upcoming events template on page load
  if (emailBody && emailBody.value === 'Loading upcoming events...') {
    fetch('/admin_get_upcoming_events_template.php')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          emailBody.value = data.template;
          updateEmailPreview();
        } else {
          emailBody.value = 'Error loading upcoming events: ' + (data.error || 'Unknown error');
        }
      })
      .catch(error => {
        console.error('Error fetching upcoming events:', error);
        emailBody.value = 'Error loading upcoming events. Please try again.';
      });
  }

  // Real-time recipient counting
  function updateRecipientCount() {
    if (!form || !recipientCountSpan) return;
    
    clearTimeout(countTimeout);
    countTimeout = setTimeout(() => {
      const formData = new FormData(form);
      
      fetch('admin_upcoming_events_count_recipients.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          recipientCountSpan.textContent = data.count;
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

  // Update email preview (just body content to match actual email)
  function updateEmailPreview() {
    if (!emailBody || !previewContent) return;
    
    const content = emailBody.value;
    
    // First apply basic markdown formatting (before replacing link tokens)
    let previewHtml = content.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                             .replace(/\*(.*?)\*/g, '<em>$1</em>')
                             .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" style="color:#0b5ed7;text-decoration:none;">$1</a>')
                             .replace(/\n/g, '<br>');
    
    // Then replace {link_event_X} tokens in the already-rendered HTML
    previewHtml = previewHtml.replace(/\{link_event_(\d+)\}/g, function(match, eventId) {
      return '/event.php?id=' + eventId;
    });
    
    previewContent.innerHTML = previewHtml;
  }

  // Add event listeners for filter changes (including suppression policy)
  if (form) {
    const filterInputs = form.querySelectorAll('input[name="registration_status"], input[name="grades[]"], input[name="suppress_policy"]');
    filterInputs.forEach(input => {
      input.addEventListener('change', updateRecipientCount);
    });
    updateRecipientCount();
  }

  // Update preview when body changes
  if (emailBody) {
    emailBody.addEventListener('input', updateEmailPreview);
    updateEmailPreview();
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
})();
</script>

<?php footer_html(); ?>
