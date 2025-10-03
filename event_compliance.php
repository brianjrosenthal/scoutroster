<?php
require_once __DIR__.'/partials.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/EventUIManager.php';
require_once __DIR__ . '/lib/GradeCalculator.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

// Check if user has required role (Cubmaster, Treasurer, or Committee Chair)
$hasRequiredRole = false;
if ($isAdmin) {
  try {
    $stPos = pdo()->prepare("SELECT LOWER(alp.name) AS p 
                             FROM adult_leadership_position_assignments alpa
                             JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
                             WHERE alpa.adult_id = ?");
    $stPos->execute([(int)($me['id'] ?? 0)]);
    $rowsPos = $stPos->fetchAll();
    if (is_array($rowsPos)) {
      foreach ($rowsPos as $pr) {
        $p = trim((string)($pr['p'] ?? ''));
        if ($p === 'cubmaster' || $p === 'treasurer' || $p === 'committee chair') { 
          $hasRequiredRole = true; 
          break; 
        }
      }
    }
  } catch (Throwable $e) {
    $hasRequiredRole = false;
  }
}

if (!$hasRequiredRole) {
  http_response_code(403);
  exit('Access denied. This page is only available to Cubmaster, Treasurer, or Committee Chair.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing event id'); }

/* Load event */
$e = EventManagement::findById($id);
if (!$e) { http_response_code(404); exit('Event not found'); }

/**
 * Get compliance data for all people who RSVP'd "yes" to this event
 */
function getEventComplianceData(int $eventId): array {
  $pdo = pdo();
  
  // Get all adults and youth who RSVP'd "yes" for this event
  $sql = "
    SELECT 
      r.id as rsvp_id,
      rm.participant_type,
      rm.adult_id,
      rm.youth_id,
      -- Adult fields
      u.first_name as adult_first_name,
      u.last_name as adult_last_name,
      u.email as adult_email,
      u.phone_home as adult_phone_home,
      u.phone_cell as adult_phone_cell,
      u.medical_forms_expiration_date as adult_medical_expiration,
      u.medical_form_in_person_opt_in as adult_medical_opt_in,
      -- Youth fields
      y.first_name as youth_first_name,
      y.last_name as youth_last_name,
      y.class_of as youth_class_of,
      y.date_paid_until as youth_date_paid_until,
      y.sibling as youth_sibling,
      y.medical_forms_expiration_date as youth_medical_expiration,
      y.medical_form_in_person_opt_in as youth_medical_opt_in,
      -- Check for payment notifications (non-deleted)
      (SELECT COUNT(*) FROM payment_notifications_from_users pnfu 
       WHERE pnfu.youth_id = y.id AND pnfu.status != 'deleted') as payment_notification_count,
      -- Check for pending registrations (non-deleted)
      (SELECT COUNT(*) FROM pending_registrations pr 
       WHERE pr.youth_id = y.id AND pr.status != 'deleted') as pending_registration_count
    FROM rsvps r
    JOIN rsvp_members rm ON rm.rsvp_id = r.id AND rm.event_id = r.event_id
    LEFT JOIN users u ON u.id = rm.adult_id AND rm.participant_type = 'adult'
    LEFT JOIN youth y ON y.id = rm.youth_id AND rm.participant_type = 'youth'
    WHERE r.event_id = ? AND r.answer = 'yes'
    ORDER BY r.id, 
             CASE WHEN rm.participant_type = 'adult' THEN 0 ELSE 1 END,
             COALESCE(u.last_name, y.last_name),
             COALESCE(u.first_name, y.first_name)
  ";
  
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$eventId]);
  $rows = $stmt->fetchAll();
  
  $complianceData = [];
  $now = new DateTime('now', new DateTimeZone(Settings::timezoneId()));
  
  foreach ($rows as $row) {
    $isAdult = $row['participant_type'] === 'adult';
    
    if ($isAdult && $row['adult_id']) {
      // Process adult
      $needsMedicalForm = false;
      $medicalExpiration = $row['adult_medical_expiration'];
      $medicalOptIn = (int)($row['adult_medical_opt_in'] ?? 0);
      
      if ($medicalOptIn === 0) {
        if ($medicalExpiration === null) {
          $needsMedicalForm = true;
        } else {
          $expirationDate = new DateTime($medicalExpiration);
          if ($expirationDate < $now) {
            $needsMedicalForm = true;
          }
        }
      }
      
      $complianceData[] = [
        'rsvp_id' => (int)$row['rsvp_id'],
        'type' => 'adult',
        'first_name' => $row['adult_first_name'],
        'last_name' => $row['adult_last_name'],
        'email' => $row['adult_email'],
        'phone_home' => $row['adult_phone_home'],
        'phone_cell' => $row['adult_phone_cell'],
        'needs_medical_form' => $needsMedicalForm,
        'needs_dues_renewal' => false, // Adults don't need dues renewal
      ];
      
    } elseif (!$isAdult && $row['youth_id']) {
      // Process youth
      $needsMedicalForm = false;
      $medicalExpiration = $row['youth_medical_expiration'];
      $medicalOptIn = (int)($row['youth_medical_opt_in'] ?? 0);
      
      if ($medicalOptIn === 0) {
        if ($medicalExpiration === null) {
          $needsMedicalForm = true;
        } else {
          $expirationDate = new DateTime($medicalExpiration);
          if ($expirationDate < $now) {
            $needsMedicalForm = true;
          }
        }
      }
      
      // Check if youth needs dues renewal
      $needsDuesRenewal = false;
      $classOf = (int)($row['youth_class_of'] ?? 0);
      $datePaidUntil = $row['youth_date_paid_until'];
      $isSibling = (int)($row['youth_sibling'] ?? 0) === 1;
      $paymentNotificationCount = (int)($row['payment_notification_count'] ?? 0);
      $pendingRegistrationCount = (int)($row['pending_registration_count'] ?? 0);
      
      if (!$isSibling && $classOf > 0) {
        // Calculate current grade
        $grade = GradeCalculator::gradeForClassOf($classOf, $now);
        
        // Check if grade is K, 1, 2, 3, 4, or 5
        if ($grade >= 0 && $grade <= 5) {
          // Check if date_paid_until is in the past or null
          $paymentExpired = false;
          if ($datePaidUntil === null) {
            $paymentExpired = true;
          } else {
            $paidUntilDate = new DateTime($datePaidUntil);
            if ($paidUntilDate < $now) {
              $paymentExpired = true;
            }
          }
          
          // If payment expired and no active payment notification or pending registration
          if ($paymentExpired && $paymentNotificationCount === 0 && $pendingRegistrationCount === 0) {
            $needsDuesRenewal = true;
          }
        }
      }
      
      // Get parent email and phone for youth (find first parent)
      $parentEmail = '';
      $parentPhoneHome = '';
      $parentPhoneCell = '';
      try {
        $parentStmt = $pdo->prepare("
          SELECT u.email, u.phone_home, u.phone_cell
          FROM parent_relationships pr 
          JOIN users u ON u.id = pr.adult_id 
          WHERE pr.youth_id = ? AND u.email IS NOT NULL 
          ORDER BY pr.id 
          LIMIT 1
        ");
        $parentStmt->execute([$row['youth_id']]);
        $parentRow = $parentStmt->fetch();
        if ($parentRow) {
          $parentEmail = $parentRow['email'];
          $parentPhoneHome = $parentRow['phone_home'];
          $parentPhoneCell = $parentRow['phone_cell'];
        }
      } catch (Throwable $e) {
        // Ignore error, leave email and phone empty
      }
      
      $complianceData[] = [
        'rsvp_id' => (int)$row['rsvp_id'],
        'type' => 'youth',
        'first_name' => $row['youth_first_name'],
        'last_name' => $row['youth_last_name'],
        'email' => $parentEmail,
        'phone_home' => $parentPhoneHome,
        'phone_cell' => $parentPhoneCell,
        'needs_medical_form' => $needsMedicalForm,
        'needs_dues_renewal' => $needsDuesRenewal,
      ];
    }
  }
  
  return $complianceData;
}

$complianceData = getEventComplianceData($id);

header_html('Event Compliance');
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Event Compliance: <?=h($e['name'])?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">Back to Event</a>
    <?= EventUIManager::renderAdminMenu((int)$e['id'], 'compliance') ?>
  </div>
</div>

<div class="card">
  <h3>Compliance Status for Event Attendees</h3>
  <p class="small">
    This table shows all people who have RSVP'd "yes" for this event and their compliance status.
  </p>
  
  <?php if (empty($complianceData)): ?>
    <p>No attendees found for this event.</p>
  <?php else: ?>
    <div style="overflow-x: auto;">
      <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <thead>
          <tr style="background-color: #f5f5f5; border-bottom: 2px solid #ddd;">
            <th style="padding: 12px; text-align: left; border-right: 1px solid #ddd;">Type</th>
            <th style="padding: 12px; text-align: left; border-right: 1px solid #ddd;">Last Name</th>
            <th style="padding: 12px; text-align: left; border-right: 1px solid #ddd;">First Name</th>
            <th style="padding: 12px; text-align: left; border-right: 1px solid #ddd;">Email</th>
            <th style="padding: 12px; text-align: left; border-right: 1px solid #ddd;">Phone</th>
            <th style="padding: 12px; text-align: center; border-right: 1px solid #ddd;">Needs Medical Form</th>
            <th style="padding: 12px; text-align: center;">Needs Dues Renewal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($complianceData as $person): ?>
            <tr style="border-bottom: 1px solid #eee;">
              <td style="padding: 8px; border-right: 1px solid #eee;">
                <span style="
                  display: inline-block;
                  padding: 2px 6px;
                  border-radius: 3px;
                  font-size: 12px;
                  font-weight: bold;
                  color: white;
                  background-color: <?= $person['type'] === 'adult' ? '#2563eb' : '#16a34a' ?>;
                ">
                  <?= h(ucfirst($person['type'])) ?>
                </span>
              </td>
              <td style="padding: 8px; border-right: 1px solid #eee;"><?= h($person['last_name']) ?></td>
              <td style="padding: 8px; border-right: 1px solid #eee;"><?= h($person['first_name']) ?></td>
              <td style="padding: 8px; border-right: 1px solid #eee;">
                <?php if (!empty($person['email'])): ?>
                  <a href="mailto:<?= h($person['email']) ?>"><?= h($person['email']) ?></a>
                <?php else: ?>
                  <span style="color: #999; font-style: italic;">No email</span>
                <?php endif; ?>
              </td>
              <td style="padding: 8px; border-right: 1px solid #eee;">
                <?php 
                  $phones = array_filter([$person['phone_cell'] ?? '', $person['phone_home'] ?? '']);
                  if (!empty($phones)): 
                ?>
                  <?= h(implode(', ', $phones)) ?>
                <?php else: ?>
                  <span style="color: #999; font-style: italic;">No phone</span>
                <?php endif; ?>
              </td>
              <td style="padding: 8px; text-align: center; border-right: 1px solid #eee;">
                <?php if ($person['needs_medical_form']): ?>
                  <span style="
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: bold;
                    color: white;
                    background-color: #dc2626;
                  ">
                    YES
                  </span>
                <?php else: ?>
                  <span style="
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: bold;
                    color: white;
                    background-color: #16a34a;
                  ">
                    NO
                  </span>
                <?php endif; ?>
              </td>
              <td style="padding: 8px; text-align: center;">
                <?php if ($person['type'] === 'adult'): ?>
                  <span style="color: #999; font-style: italic;">N/A</span>
                <?php elseif ($person['needs_dues_renewal']): ?>
                  <span style="
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: bold;
                    color: white;
                    background-color: #dc2626;
                  ">
                    YES
                  </span>
                <?php else: ?>
                  <span style="
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: bold;
                    color: white;
                    background-color: #16a34a;
                  ">
                    NO
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    
    <div style="margin-top: 16px;">
      <h4>Summary</h4>
      <?php
        $totalPeople = count($complianceData);
        $adultsCount = count(array_filter($complianceData, fn($p) => $p['type'] === 'adult'));
        $youthCount = count(array_filter($complianceData, fn($p) => $p['type'] === 'youth'));
        $needsMedicalForm = count(array_filter($complianceData, fn($p) => $p['needs_medical_form']));
        $needsDuesRenewal = count(array_filter($complianceData, fn($p) => $p['needs_dues_renewal']));
      ?>
      <p class="small">
        <strong>Total Attendees:</strong> <?= $totalPeople ?> 
        (<?= $adultsCount ?> adults, <?= $youthCount ?> youth)<br>
        <strong>Need Medical Form:</strong> <?= $needsMedicalForm ?> people<br>
        <strong>Need Dues Renewal:</strong> <?= $needsDuesRenewal ?> youth
      </p>
    </div>
  <?php endif; ?>
</div>

<?php if ($hasRequiredRole): ?>
  <?= EventUIManager::renderAdminModals((int)$e['id']) ?>
  <?= EventUIManager::renderAdminMenuScript((int)$e['id']) ?>
<?php endif; ?>

<?php footer_html(); ?>
