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

// Check for success/error messages
$msg = null;
$err = null;
if (isset($_GET['msg'])) {
  $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
  $err = $_GET['err'];
}

// Load field definitions for this event
$fields = EventRegistrationFieldDefinitionManagement::listForEvent($eventId);

$pageTitle = 'Event Registration Fields';
header_html($pageTitle);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Event Registration Fields: <?= h($event['name']) ?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Back to Event</a>
    <?= EventUIManager::renderAdminMenu((int)$eventId, null) ?>
  </div>
</div>

<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<div class="card">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <h3 style="margin: 0;">Registration Field Definitions</h3>
    <a class="button primary" href="/event_registration_field_definitions/add.php?event_id=<?= (int)$eventId ?>">Add Field Definition</a>
  </div>

  <?php if (empty($fields)): ?>
    <p class="small">No registration fields have been defined for this event.</p>
    <p class="small">Registration fields allow you to collect additional information from attendees when they RSVP to this event.</p>
  <?php else: ?>
    <table style="width: 100%; border-collapse: collapse;">
      <thead>
        <tr style="border-bottom: 2px solid #ddd;">
          <th style="text-align: left; padding: 8px;">Seq</th>
          <th style="text-align: left; padding: 8px;">Name</th>
          <th style="text-align: left; padding: 8px;">Scope</th>
          <th style="text-align: left; padding: 8px;">Type</th>
          <th style="text-align: left; padding: 8px;">Required</th>
          <th style="text-align: left; padding: 8px;">Options</th>
          <th style="text-align: right; padding: 8px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fields as $field): ?>
          <tr style="border-bottom: 1px solid #eee;">
            <td style="padding: 8px;"><?= (int)$field['sequence_number'] ?></td>
            <td style="padding: 8px;"><strong><?= h($field['name']) ?></strong></td>
            <td style="padding: 8px;">
              <?php
                $scopeLabel = '';
                switch ($field['scope']) {
                  case 'per_person':
                    $scopeLabel = 'Per Person';
                    break;
                  case 'per_youth':
                    $scopeLabel = 'Per Youth';
                    break;
                  case 'per_family':
                    $scopeLabel = 'Per Family';
                    break;
                }
                echo h($scopeLabel);
              ?>
            </td>
            <td style="padding: 8px;">
              <?php
                $typeLabel = '';
                switch ($field['field_type']) {
                  case 'text':
                    $typeLabel = 'Text';
                    break;
                  case 'select':
                    $typeLabel = 'Select';
                    break;
                  case 'boolean':
                    $typeLabel = 'Boolean';
                    break;
                }
                echo h($typeLabel);
              ?>
            </td>
            <td style="padding: 8px;">
              <?= ((int)$field['required'] === 1) ? 'Yes' : 'No' ?>
            </td>
            <td style="padding: 8px; max-width: 300px; overflow: hidden; text-overflow: ellipsis;">
              <?php
                if ($field['field_type'] === 'select' && $field['option_list']) {
                  $options = json_decode($field['option_list'], true);
                  if (is_array($options)) {
                    echo '<span class="small">' . h(implode(', ', $options)) . '</span>';
                  }
                } else {
                  echo '<span class="small">—</span>';
                }
              ?>
            </td>
            <td style="padding: 8px; text-align: right; white-space: nowrap;">
              <a href="/event_registration_field_definitions/edit.php?id=<?= (int)$field['id'] ?>" class="button" style="font-size: 14px; padding: 4px 8px;">Edit</a>
              <form method="post" action="/event_registration_field_definitions/delete_eval.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this field definition? This action cannot be undone.');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$field['id'] ?>">
                <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
                <button type="submit" class="button" style="font-size: 14px; padding: 4px 8px; background-color: #dc2626; color: white;">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top: 16px;">
      <p class="small"><strong>Scope explanation:</strong></p>
      <ul class="small" style="margin-left: 20px;">
        <li><strong>Per Person:</strong> Collect this information once for each person (adult or child) attending</li>
        <li><strong>Per Youth:</strong> Collect this information once for each youth attending</li>
      </ul>
    </div>
  <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
  <?= EventUIManager::renderAdminModals((int)$eventId) ?>
  <?= EventUIManager::renderAdminMenuScript((int)$eventId) ?>
<?php endif; ?>

<?php footer_html(); ?>
