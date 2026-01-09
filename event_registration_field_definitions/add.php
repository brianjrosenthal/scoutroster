<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EventManagement.php';
require_once __DIR__ . '/../lib/EventRegistrationFieldDefinitionManagement.php';
require_once __DIR__ . '/../lib/EventUIManager.php';
require_admin();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

// Get event_id from query string
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($eventId <= 0) {
  http_response_code(400);
  exit('Missing or invalid event_id');
}

// Load event
$event = EventManagement::findById($eventId);
if (!$event) {
  http_response_code(404);
  exit('Event not found');
}

// Get max sequence number for default
$maxSeq = EventRegistrationFieldDefinitionManagement::getMaxSequenceNumber($eventId);
$defaultSeq = $maxSeq + 1;

$pageTitle = 'Add Registration Field';
header_html($pageTitle);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Add Registration Field: <?= h($event['name']) ?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/event_registration_field_definitions/list.php?event_id=<?= (int)$eventId ?>">Back to List</a>
    <?= EventUIManager::renderAdminMenu((int)$eventId, null) ?>
  </div>
</div>

<div class="card">
  <form method="post" action="/event_registration_field_definitions/add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">

    <label>Field Name <span style="color: red;">*</span>
      <input type="text" name="name" required placeholder="e.g., Ski Ability Level">
      <p class="small">The label that will be shown to users (e.g., "Confirmation Number", "Age", "Ability Level")</p>
    </label>

    <label>Description
      <textarea name="description" rows="3" placeholder="Optional description to help users understand what information to provide"></textarea>
      <p class="small">Provide context or instructions for this field (e.g., "Please enter your child's current skiing ability")</p>
    </label>

    <fieldset style="border: 1px solid #ddd; padding: 12px; border-radius: 4px;">
      <legend style="font-weight: bold;">Scope <span style="color: red;">*</span></legend>
      <p class="small" style="margin-top: 0;">Who should provide this information?</p>
      <label class="inline">
        <input type="radio" name="scope" value="per_person" checked>
        Per Person (each adult and child)
      </label>
      <label class="inline">
        <input type="radio" name="scope" value="per_youth">
        Per Youth (each child only)
      </label>
    </fieldset>

    <label>Field Type <span style="color: red;">*</span>
      <select name="field_type" id="field_type" required>
        <option value="">-- Select Type --</option>
        <option value="text">Text (single line input)</option>
        <option value="numeric">Numeric (numbers only)</option>
        <option value="select">Select (dropdown menu)</option>
        <option value="boolean">Boolean (checkbox yes/no)</option>
      </select>
    </label>

    <div id="options_container" style="display: none;">
      <label>Options <span style="color: red;">*</span>
        <textarea name="option_list" id="option_list" rows="6" placeholder='["Beginner", "Intermediate", "Advanced"]'></textarea>
        <p class="small">Enter options as a JSON array. Example: ["Option 1", "Option 2", "Option 3"]</p>
      </label>
    </div>

    <label class="inline">
      <input type="checkbox" name="required" value="1">
      Required field (users must provide this information)
    </label>

    <label>Sequence Number
      <input type="number" name="sequence_number" value="<?= (int)$defaultSeq ?>" min="0" step="1">
      <p class="small">Controls the order fields are displayed (lower numbers appear first)</p>
    </label>

    <div class="actions">
      <button type="submit" class="primary">Create Field</button>
      <a class="button" href="/event_registration_field_definitions/list.php?event_id=<?= (int)$eventId ?>">Cancel</a>
    </div>
  </form>
</div>

<script>
(function() {
  const fieldTypeSelect = document.getElementById('field_type');
  const optionsContainer = document.getElementById('options_container');
  const optionListTextarea = document.getElementById('option_list');

  if (fieldTypeSelect && optionsContainer && optionListTextarea) {
    fieldTypeSelect.addEventListener('change', function() {
      if (this.value === 'select') {
        optionsContainer.style.display = 'block';
        optionListTextarea.required = true;
      } else {
        optionsContainer.style.display = 'none';
        optionListTextarea.required = false;
        optionListTextarea.value = '';
      }
    });

    // Trigger on page load in case there's a default selection
    const event = new Event('change');
    fieldTypeSelect.dispatchEvent(event);
  }
})();
</script>

<?php if ($isAdmin): ?>
  <?= EventUIManager::renderAdminModals((int)$eventId) ?>
  <?= EventUIManager::renderAdminMenuScript((int)$eventId) ?>
<?php endif; ?>

<?php footer_html(); ?>
