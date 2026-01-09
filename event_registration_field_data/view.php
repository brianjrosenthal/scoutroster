<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EventManagement.php';
require_once __DIR__ . '/../lib/EventRegistrationFieldDataManagement.php';
require_once __DIR__ . '/../lib/EventUIManager.php';
require_admin();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

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

// Get registration data for this event
$data = EventRegistrationFieldDataManagement::getRegistrationDataForEvent($eventId);
$participants = $data['participants'];
$fields = $data['fields'];

$pageTitle = h($event['name']) . ': Registration Data';
header_html($pageTitle);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;"><?= h($event['name']) ?>: Registration Data</h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Back to Event</a>
    <?= EventUIManager::renderAdminMenu((int)$eventId, null) ?>
  </div>
</div>

<div class="card">
  <?php if (empty($participants)): ?>
    <p>No registration data yet. Participants will appear here after they RSVP "Yes" and complete their registration information.</p>
  <?php else: ?>
    <p class="small" style="margin-bottom: 16px;">
      <strong><?= count($participants) ?></strong> participant<?= count($participants) === 1 ? '' : 's' ?> with registration data
    </p>
    
    <div style="overflow-x: auto;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="background-color: #f5f5f5;">
            <th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Last Name</th>
            <th style="text-align: left; padding: 8px; border: 1px solid #ddd;">First Name</th>
            <th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Phone</th>
            <th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Email</th>
            <?php foreach ($fields as $field): ?>
              <th style="text-align: left; padding: 8px; border: 1px solid #ddd;"><?= h($field['name']) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($participants as $participant): ?>
            <tr>
              <td style="padding: 8px; border: 1px solid #ddd;"><?= h($participant['last_name']) ?></td>
              <td style="padding: 8px; border: 1px solid #ddd;"><?= h($participant['first_name']) ?></td>
              <td style="padding: 8px; border: 1px solid #ddd;"><?= h($participant['phone']) ?></td>
              <td style="padding: 8px; border: 1px solid #ddd;"><?= h($participant['email']) ?></td>
              <?php foreach ($fields as $field): ?>
                <?php $fieldId = (int)$field['id']; ?>
                <td style="padding: 8px; border: 1px solid #ddd;">
                  <?= h($participant['field_data'][$fieldId] ?? '') ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    
    <div style="margin-top: 16px;">
      <a class="button primary" href="/event_registration_field_data/export.php?event_id=<?= (int)$eventId ?>">Export to CSV</a>
    </div>
  <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
  <?= EventUIManager::renderAdminModals((int)$eventId) ?>
  <?= EventUIManager::renderAdminMenuScript((int)$eventId) ?>
<?php endif; ?>

<?php footer_html(); ?>
