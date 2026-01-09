<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EventManagement.php';
require_once __DIR__ . '/../lib/EventRegistrationFieldDefinitionManagement.php';
require_once __DIR__ . '/../lib/EventUIManager.php';
require_admin();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

// Get field definition ID from query string
$fieldId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fieldId <= 0) {
  http_response_code(400);
  exit('Missing or invalid field definition ID');
}

// Load field definition
$field = EventRegistrationFieldDefinitionManagement::findById($fieldId);
if (!$field) {
  http_response_code(404);
  exit('Field definition not found');
}

$eventId = (int)$field['event_id'];

// Load event
$event = EventManagement::findById($eventId);
if (!$event) {
  http_response_code(404);
  exit('Event not found');
}

$pageTitle = 'Edit Registration Field';
header_html($pageTitle);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Edit Registration Field: <?= h($event['name']) ?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/event_registration_field_definitions/list.php?event_id=<?= (int)$eventId ?>">Back to List</a>
    <?= EventUIManager::renderAdminMenu((int)$eventId, null) ?>
  </div>
</div>

<div class="card">
  <form method="post" action="/event_registration_field_definitions/edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$fieldId ?>">
    <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">

    <label>Field Name <span style="color: red;">*</span>
      <input type="text" name="name" value="<?= h($field['name']) ?>" required placeholder="e.g., Ski Ability Level">
      <p class="small">The label that will be shown to users (e.g., "Confirmation Number", "Age", "Ability Level")</p>
    </label>

    <fieldset style="border: 1px solid #ddd; padding: 12px; border-radius: 4px;">
      <legend style="font-weight: bold;">Scope <span style="color: red;">*</span></legend>
      <p class="small" style="margin-top: 0;">Who should provide this information?</p>
      <label class="inline">
        <input type="radio" name="scope" value="per_person" <?= $field['scope'] === 'per_person' ? 'checked' : '' ?>>
        Per Person (each adult and child)
      </label>
      <label class="inline">
        <input type="radio" name="scope" value="per_youth" <?= $field['scope'] === 'per_youth' ? 'checked' : '' ?>>
        Per Youth (each child only)
      </label>
    </fieldset>

    <label>Field Type <span style="color: red;">*</span>
      <select name="field_type" id="field_type" required>
        <option value="">-- Select Type --</option>
        <option value="text" <?= $field['field_type'] === 'text' ? 'selected' : '' ?>>Text (single line input)</option>
        <option value="select" <?= $field['field_type'] === 'select' ? 'selected' : '' ?>>Select (dropdown menu)</option>
        <option value="boolean" <?= $field['field_type'] === 'boolean' ? 'selected' : '' ?>>Boolean (checkbox yes/no)</option>
      </select>
    </label>

    <div id="options_container" style="display: <?= $field['field_type'] === 'select' ? 'block' : 'none' ?>;">
      <label>Options <span style="color: red;">*</span>
        <textarea name="option_list" id="option_list" rows="6" placeholder='["Beginner", "Intermediate", "Advanced"]'><?= h($field['option_list'] ?? '') ?></textarea>
        <p class="small">Enter options as a JSON array. Example: ["Option 1", "Option 2", "Option 3"]</p>
      </label>
    </div>

    <label class="inline">
      <input type="checkbox" name="required" value="1" <?= ((int)$field['required'] === 1) ? 'checked' : '' ?>>
      Required field (users must provide this information)
    </label>

    <label>Sequence Number
      <input type="number" name="sequence_number" value="<?= (int)$field['sequence_number'] ?>" min="0" step="10">
      <p class="small">Controls the order fields are displayed (lower numbers appear first)</p>
    </label>

    <div class="actions">
      <button type="submit" class="primary">Save Changes</button>
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
      }
    });

    // Trigger on page load to set initial state
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
