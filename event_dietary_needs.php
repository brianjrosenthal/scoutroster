<?php
require_once __DIR__.'/partials.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/GradeCalculator.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

if (!$isAdmin) {
  http_response_code(403);
  exit('Access denied. This page is only available to administrators.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing event id'); }

/* Load event */
$e = EventManagement::findById($id);
if (!$e) { http_response_code(404); exit('Event not found'); }

/**
 * Get dietary needs data for all people who RSVP'd "yes" to this event
 */
function getEventDietaryNeedsData(int $eventId): array {
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
      u.dietary_vegetarian,
      u.dietary_vegan,
      u.dietary_lactose_free,
      u.dietary_no_pork_shellfish,
      u.dietary_nut_allergy,
      u.dietary_other,
      -- Youth fields
      y.first_name as youth_first_name,
      y.last_name as youth_last_name,
      y.class_of as youth_class_of,
      y.dietary_vegetarian as youth_dietary_vegetarian,
      y.dietary_vegan as youth_dietary_vegan,
      y.dietary_lactose_free as youth_dietary_lactose_free,
      y.dietary_no_pork_shellfish as youth_dietary_no_pork_shellfish,
      y.dietary_nut_allergy as youth_dietary_nut_allergy,
      y.dietary_gluten_free as youth_dietary_gluten_free,
      y.dietary_other as youth_dietary_other
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
  
  $dietaryData = [];
  
  foreach ($rows as $row) {
    $isAdult = $row['participant_type'] === 'adult';
    
    if ($isAdult && $row['adult_id']) {
      // Process adult
      $dietaryNeeds = [];
      if (!empty($row['dietary_vegetarian'])) $dietaryNeeds[] = 'Vegetarian';
      if (!empty($row['dietary_vegan'])) $dietaryNeeds[] = 'Vegan';
      if (!empty($row['dietary_lactose_free'])) $dietaryNeeds[] = 'Lactose-Free';
      if (!empty($row['dietary_no_pork_shellfish'])) $dietaryNeeds[] = 'No pork or shellfish';
      if (!empty($row['dietary_nut_allergy'])) $dietaryNeeds[] = 'Nut allergy';
      if (!empty($row['dietary_other'])) $dietaryNeeds[] = trim($row['dietary_other']);
      
      $dietaryData[] = [
        'rsvp_id' => (int)$row['rsvp_id'],
        'type' => 'adult',
        'first_name' => $row['adult_first_name'],
        'last_name' => $row['adult_last_name'],
        'email' => $row['adult_email'],
        'phone_home' => $row['adult_phone_home'],
        'phone_cell' => $row['adult_phone_cell'],
        'dietary_needs' => $dietaryNeeds,
        'has_dietary_needs' => !empty($dietaryNeeds),
      ];
      
    } elseif (!$isAdult && $row['youth_id']) {
      // Process youth dietary preferences
      $dietaryNeeds = [];
      if (!empty($row['youth_dietary_vegetarian'])) $dietaryNeeds[] = 'Vegetarian';
      if (!empty($row['youth_dietary_vegan'])) $dietaryNeeds[] = 'Vegan';
      if (!empty($row['youth_dietary_lactose_free'])) $dietaryNeeds[] = 'Lactose-Free';
      if (!empty($row['youth_dietary_no_pork_shellfish'])) $dietaryNeeds[] = 'No pork or shellfish';
      if (!empty($row['youth_dietary_nut_allergy'])) $dietaryNeeds[] = 'Nut allergy';
      if (!empty($row['youth_dietary_gluten_free'])) $dietaryNeeds[] = 'Gluten Free';
      if (!empty($row['youth_dietary_other'])) $dietaryNeeds[] = trim($row['youth_dietary_other']);
      
      $dietaryData[] = [
        'rsvp_id' => (int)$row['rsvp_id'],
        'type' => 'youth',
        'first_name' => $row['youth_first_name'],
        'last_name' => $row['youth_last_name'],
        'email' => '', // Youth don't have direct email
        'phone_home' => '',
        'phone_cell' => '',
        'dietary_needs' => $dietaryNeeds,
        'has_dietary_needs' => !empty($dietaryNeeds),
      ];
    }
  }
  
  return $dietaryData;
}

$dietaryData = getEventDietaryNeedsData($id);

// Calculate summary statistics
$totalPeople = count($dietaryData);
$adultsCount = count(array_filter($dietaryData, fn($p) => $p['type'] === 'adult'));
$youthCount = count(array_filter($dietaryData, fn($p) => $p['type'] === 'youth'));

$adultsWithDietaryNeeds = count(array_filter($dietaryData, fn($p) => $p['type'] === 'adult' && $p['has_dietary_needs']));
$youthWithDietaryNeeds = count(array_filter($dietaryData, fn($p) => $p['type'] === 'youth' && $p['has_dietary_needs']));

// Count specific dietary needs
$dietaryCounts = [
  'Vegetarian' => 0,
  'Vegan' => 0,
  'Lactose-Free' => 0,
  'No pork or shellfish' => 0,
  'Nut allergy' => 0,
  'Gluten Free' => 0,
  'Other' => 0,
];

foreach ($dietaryData as $person) {
  foreach ($person['dietary_needs'] as $need) {
    if (array_key_exists($need, $dietaryCounts)) {
      $dietaryCounts[$need]++;
    } else {
      $dietaryCounts['Other']++;
    }
  }
}

header_html('Event Dietary Needs');
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Dietary Needs: <?=h($e['name'])?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">Back to Event</a>
    <?php if ($isAdmin): ?>
      <div style="position: relative;">
        <button class="button" id="adminLinksBtn" style="display: flex; align-items: center; gap: 4px;">
          Admin Links
          <span style="font-size: 12px;">▼</span>
        </button>
        <div id="adminLinksDropdown" style="
          display: none;
          position: absolute;
          top: 100%;
          right: 0;
          background: white;
          border: 1px solid #ddd;
          border-radius: 4px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.1);
          z-index: 1000;
          min-width: 180px;
          margin-top: 4px;
        ">
          <a href="/admin_event_edit.php?id=<?= (int)$e['id'] ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Edit Event</a>
          <a href="/event_public.php?event_id=<?= (int)$e['id'] ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Public RSVP Link</a>
          <a href="/admin_event_invite.php?event_id=<?= (int)$e['id'] ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Invite</a>
          <a href="#" id="adminCopyEmailsBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Copy Emails</a>
          <a href="#" id="adminManageRsvpBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Manage RSVPs</a>
          <a href="#" id="adminExportAttendeesBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Export Attendees</a>
          <a href="/event_dietary_needs.php?id=<?= (int)$e['id'] ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee; background-color: #f5f5f5;">Dietary Needs</a>
          <?php
            // Show Event Compliance only for Cubmaster, Treasurer, or Committee Chair
            $showCompliance = false;
            try {
              $stPos = pdo()->prepare("SELECT LOWER(position) AS p FROM adult_leadership_positions WHERE adult_id=?");
              $stPos->execute([(int)($me['id'] ?? 0)]);
              $rowsPos = $stPos->fetchAll();
              if (is_array($rowsPos)) {
                foreach ($rowsPos as $pr) {
                  $p = trim((string)($pr['p'] ?? ''));
                  if ($p === 'cubmaster' || $p === 'treasurer' || $p === 'committee chair') { 
                    $showCompliance = true; 
                    break; 
                  }
                }
              }
            } catch (Throwable $e) {
              $showCompliance = false;
            }
            if ($showCompliance): ?>
              <a href="/event_compliance.php?id=<?= (int)$e['id'] ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333;">Event Compliance</a>
            <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  // Admin Links Dropdown
  const adminLinksBtn = document.getElementById('adminLinksBtn');
  const adminLinksDropdown = document.getElementById('adminLinksDropdown');
  
  if (adminLinksBtn && adminLinksDropdown) {
    adminLinksBtn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      const isVisible = adminLinksDropdown.style.display === 'block';
      adminLinksDropdown.style.display = isVisible ? 'none' : 'block';
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      if (!adminLinksBtn.contains(e.target) && !adminLinksDropdown.contains(e.target)) {
        adminLinksDropdown.style.display = 'none';
      }
    });
    
    // Close dropdown when pressing Escape
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        adminLinksDropdown.style.display = 'none';
      }
    });
    
    // Add hover effects
    const dropdownLinks = adminLinksDropdown.querySelectorAll('a');
    dropdownLinks.forEach(link => {
      link.addEventListener('mouseenter', function() {
        this.style.backgroundColor = '#f5f5f5';
      });
      link.addEventListener('mouseleave', function() {
        this.style.backgroundColor = 'white';
      });
    });
  }
})();
</script>

<div class="card">
  <h3>Dietary Needs Summary for Event Attendees</h3>
  <p class="small">
    This page shows dietary preferences for all people who have RSVP'd "yes" for this event.
  </p>
  
  <div style="margin-bottom: 24px;">
    <h4>Summary</h4>
    <p>
      <strong>Total Attendees:</strong> <?= $totalPeople ?> 
      (<?= $adultsCount ?> adults, <?= $youthCount ?> youth)<br>
      <strong>People with Dietary Needs:</strong> <?= $adultsWithDietaryNeeds + $youthWithDietaryNeeds ?> 
      (Adults: <?= $adultsWithDietaryNeeds ?>, Youth: <?= $youthWithDietaryNeeds ?>)
    </p>
    
    <?php if (array_sum($dietaryCounts) > 0): ?>
      <h4>Dietary Needs Breakdown</h4>
      <ul>
        <?php foreach ($dietaryCounts as $need => $count): ?>
          <?php if ($count > 0): ?>
            <li><strong><?= h($need) ?>:</strong> <?= $count ?> <?= $count === 1 ? 'person' : 'people' ?></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  
  <?php if (empty($dietaryData)): ?>
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
            <th style="padding: 12px; text-align: left;">Dietary Needs</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dietaryData as $person): ?>
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
                  $phones = array_filter([$person['phone_cell'], $person['phone_home']]);
                  if (!empty($phones)): 
                ?>
                  <?= h(implode(', ', $phones)) ?>
                <?php else: ?>
                  <span style="color: #999; font-style: italic;">No phone</span>
                <?php endif; ?>
              </td>
              <td style="padding: 8px;">
                <?php if (!empty($person['dietary_needs'])): ?>
                  <?= h(implode(', ', $person['dietary_needs'])) ?>
                <?php else: ?>
                  <span style="color: #999; font-style: italic;">None</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
