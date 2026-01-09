<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EventManagement.php';
require_once __DIR__ . '/../lib/EventRegistrationFieldDefinitionManagement.php';
require_once __DIR__ . '/../lib/EventRegistrationFieldDataManagement.php';
require_once __DIR__ . '/../lib/RSVPManagement.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/ParentRelationships.php';
require_login();

$me = current_user();
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

if ($eventId <= 0) {
  http_response_code(400);
  exit('Missing event_id');
}

// Load event
$event = EventManagement::findById($eventId);
if (!$event) {
  http_response_code(404);
  exit('Event not found');
}

// Get field definitions for this event
$fieldDefs = EventRegistrationFieldDefinitionManagement::listForEvent($eventId);
if (empty($fieldDefs)) {
  // No fields for this event, redirect back to event page
  header('Location: /event.php?id=' . $eventId);
  exit;
}

// Get user's RSVP
$rsvp = RSVPManagement::getRSVPForFamilyByAdultID($eventId, (int)$me['id']);
if (!$rsvp || strtolower((string)($rsvp['answer'] ?? '')) !== 'yes') {
  // No RSVP or not yes, redirect back to event page
  header('Location: /event.php?id=' . $eventId);
  exit;
}

// Get participants from RSVP
$rsvpId = (int)$rsvp['id'];
$memberIds = RSVPManagement::getMemberIdsByType($rsvpId);

// Build list of participants with their details
$participants = [];

// Get adult details
foreach ($memberIds['adult_ids'] ?? [] as $adultId) {
  $adult = UserManagement::findById($adultId);
  if ($adult) {
    $participants[] = [
      'type' => 'adult',
      'id' => $adultId,
      'name' => trim(($adult['first_name'] ?? '') . ' ' . ($adult['last_name'] ?? ''))
    ];
  }
}

// Get youth details
foreach ($memberIds['youth_ids'] ?? [] as $youthId) {
  $youth = UserManagement::findYouthById($youthId);
  if ($youth) {
    $participants[] = [
      'type' => 'youth',
      'id' => $youthId,
      'name' => trim(($youth['first_name'] ?? '') . ' ' . ($youth['last_name'] ?? ''))
    ];
  }
}

if (empty($participants)) {
  // No participants, redirect back
  header('Location: /event.php?id=' . $eventId);
  exit;
}

// Get existing field data for all participants
$existingData = EventRegistrationFieldDataManagement::getFieldDataForParticipants(
  array_map(function($p) {
    return ['type' => $p['type'], 'id' => $p['id']];
  }, $participants)
);

$pageTitle = h($event['name']) . ' Registration Data';
header_html($pageTitle);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;"><?= h($event['name']) ?> Registration Data</h2>
  <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Back to Event</a>
</div>

<div class="card">
  <p class="small">Please provide the following information for each person attending this event.</p>
  
  <form method="post" action="/event_registration_field_data/edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
    
    <?php foreach ($participants as $participant): ?>
      <?php
        $participantType = $participant['type'];
        $participantId = $participant['id'];
        $participantName = $participant['name'];
        
        // Filter fields relevant to this participant
        $relevantFields = array_filter($fieldDefs, function($field) use ($participantType) {
          $scope = $field['scope'];
          if ($scope === 'per_person') return true;
          if ($scope === 'per_youth' && $participantType === 'youth') return true;
          return false;
        });
        
        if (empty($relevantFields)) continue;
      ?>
      
      <div style="border: 1px solid #ddd; padding: 16px; border-radius: 4px; margin-bottom: 16px;">
        <h3 style="margin-top: 0;"><?= h($participantName) ?></h3>
        
        <?php foreach ($relevantFields as $field): ?>
          <?php
            $fieldId = (int)$field['id'];
            $fieldName = $field['name'];
            $fieldDescription = $field['description'] ?? '';
            $fieldType = $field['field_type'];
            $required = (int)$field['required'] === 1;
            $optionList = $field['option_list'] ? json_decode($field['option_list'], true) : [];
            
            $inputName = "field_{$fieldId}_{$participantType}_{$participantId}";
            $dataKey = "{$fieldId}_{$participantType}_{$participantId}";
            $currentValue = $existingData[$dataKey] ?? '';
          ?>
          
          <div style="margin-bottom: 16px;">
            <label>
              <strong><?= h($fieldName) ?></strong>
              <?php if ($required): ?>
                <span style="color: red;">(required)</span>
              <?php endif; ?>
              
              <?php if ($fieldDescription !== ''): ?>
                <p class="small" style="margin: 4px 0 8px 0; color: #666;"><?= h($fieldDescription) ?></p>
              <?php endif; ?>
              
              <?php if ($fieldType === 'text'): ?>
                <input type="text" name="<?= h($inputName) ?>" value="<?= h($currentValue) ?>" <?= $required ? 'required' : '' ?>>
              
              <?php elseif ($fieldType === 'numeric'): ?>
                <input type="number" name="<?= h($inputName) ?>" value="<?= h($currentValue) ?>" <?= $required ? 'required' : '' ?>>
              
              <?php elseif ($fieldType === 'select'): ?>
                <select name="<?= h($inputName) ?>" <?= $required ? 'required' : '' ?>>
                  <option value="">-- Select --</option>
                  <?php foreach ($optionList as $option): ?>
                    <option value="<?= h($option) ?>" <?= $currentValue === $option ? 'selected' : '' ?>>
                      <?= h($option) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              
              <?php elseif ($fieldType === 'boolean'): ?>
                <div style="margin-top: 4px;">
                  <label class="inline">
                    <input type="checkbox" name="<?= h($inputName) ?>" value="1" <?= $currentValue === '1' ? 'checked' : '' ?>>
                    Yes
                  </label>
                </div>
              
              <?php endif; ?>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    
    <div class="actions">
      <button type="submit" class="primary">Save Registration Data</button>
      <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
