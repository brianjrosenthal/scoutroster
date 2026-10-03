<?php
require_once __DIR__.'/partials.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/EventUIManager.php';
require_once __DIR__ . '/lib/LeadershipManagement.php';
require_once __DIR__ . '/lib/GradeCalculator.php';
require_login();

/**
 * Camping roster for the campsite ranger: everyone attending an event (RSVP "yes"),
 * one line per person. Youth show their grade; Membership Info holds pack positions (adults)
 * and the BSA ID where known. The Training column is left blank to be filled in by hand. Guests and public (logged-out)
 * RSVPs are not listed: there are no names for them and they are usually entered by mistake.
 *
 *   /event_ranger_roster.php?event_id=N              display the roster (admins only)
 *   /event_ranger_roster.php?event_id=N&format=csv   download as CSV
 *   PDF: the "Save as PDF" button prints the page with a print stylesheet.
 */

$me = current_user();
if (empty($me['is_admin'])) { http_response_code(403); exit('Access denied'); }

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }

$event = EventManagement::findById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

$format = strtolower(trim((string)($_GET['format'] ?? '')));

$pdo = pdo();

// Adults attending
$st = $pdo->prepare("
  SELECT DISTINCT u.id, u.first_name, u.last_name, u.phone_cell, u.phone_home, u.bsa_membership_number, u.membership_info_note
  FROM rsvps r
  JOIN rsvp_members rm ON rm.rsvp_id = r.id AND rm.event_id = r.event_id
  JOIN users u ON u.id = rm.adult_id
  WHERE r.event_id = ? AND r.answer = 'yes' AND rm.participant_type = 'adult'
");
$st->execute([$eventId]);
$adults = $st->fetchAll();

// Youth attending
$st = $pdo->prepare("
  SELECT DISTINCT y.id, y.first_name, y.last_name, y.class_of, y.bsa_registration_number, y.membership_info_note
  FROM rsvps r
  JOIN rsvp_members rm ON rm.rsvp_id = r.id AND rm.event_id = r.event_id
  JOIN youth y ON y.id = rm.youth_id
  WHERE r.event_id = ? AND r.answer = 'yes' AND rm.participant_type = 'youth'
");
$st->execute([$eventId]);
$youth = $st->fetchAll();

$now = new DateTime('now', new DateTimeZone(Settings::timezoneId()));

$rows = [];
foreach ($adults as $a) {
  $phone = trim((string)($a['phone_cell'] ?? '')) ?: trim((string)($a['phone_home'] ?? ''));
  $rows[] = [
    'last'  => (string)$a['last_name'],
    'first' => (string)$a['first_name'],
    'type'  => 'Adult',
    'id'    => (int)$a['id'],
    'bsa'   => trim((string)($a['bsa_membership_number'] ?? '')),
    'note'  => trim((string)($a['membership_info_note'] ?? '')),
    'grade' => '',
    'position' => LeadershipManagement::getAdultPositionString((int)$a['id']),
    'phone' => $phone,
    'sort_type' => 0,
  ];
}
foreach ($youth as $y) {
  $grade = GradeCalculator::gradeForClassOf((int)$y['class_of'], clone $now);
  $rows[] = [
    'last'  => (string)$y['last_name'],
    'first' => (string)$y['first_name'],
    'type'  => 'Youth',
    'id'    => (int)$y['id'],
    'bsa'   => trim((string)($y['bsa_registration_number'] ?? '')),
    'note'  => trim((string)($y['membership_info_note'] ?? '')),
    'grade' => $grade < 0 ? 'Pre-K' : GradeCalculator::gradeLabel($grade),
    'position' => '',
    'phone' => '',
    'sort_type' => 1,
  ];
}

// Family-friendly order: by last name, adults before youth within a family, then first name
usort($rows, function ($a, $b) {
  $c = strcasecmp($a['last'], $b['last']);
  if ($c !== 0) return $c;
  if ($a['sort_type'] !== $b['sort_type']) return $a['sort_type'] <=> $b['sort_type'];
  return strcasecmp($a['first'], $b['first']);
});

// Guests and public RSVPs are intentionally left off: no names, and they are often entered by mistake.
$summary = count($adults) . ' adult' . (count($adults) === 1 ? '' : 's') . ', ' . count($youth) . ' youth';

// Membership info: pack position(s) for adults, the BSA ID for anyone who has one, and any free-text note
$membershipOf = function (array $r): string {
  $parts = [];
  if ($r['position'] !== '') $parts[] = $r['position'];
  if ($r['bsa'] !== '') $parts[] = 'BSA #' . $r['bsa'];
  if ($r['note'] !== '') $parts[] = $r['note'];
  return implode('; ', $parts);
};
$columns = ['Last Name', 'First Name', 'Type', 'Grade', 'Membership Info', 'Phone', 'Training'];
$cellsOf = fn(array $r) => [$r['last'], $r['first'], $r['type'], $r['grade'], $membershipOf($r), $r['phone'], ''];

/* ---------- CSV download ---------- */
if ($format === 'csv') {
  $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$event['name']), '-')) ?: 'event';
  $eventDate = date('Y-m-d', strtotime((string)$event['starts_at']));
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="pack440-camping-roster-' . $slug . '-' . $eventDate . '.csv"');
  header('Cache-Control: no-store');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens accented names correctly
  $put = fn(array $r) => fputcsv($out, $r, ',', '"', '');
  $put($columns);
  foreach ($rows as $r) { $put($cellsOf($r)); }
  fclose($out);
  exit;
}

/* ---------- Display ---------- */
header_html('Camping Roster');
?>
<style>
  .roster-table{width:100%;border-collapse:collapse;font-size:14px}
  .roster-table th,.roster-table td{border:1px solid #cfd2da;padding:6px 8px;text-align:left;vertical-align:top}
  .roster-table th{background:#f0f1f5;font-weight:600;white-space:nowrap}
  .roster-table td.blank{min-width:200px}
  .roster-table td.nowrap{white-space:nowrap}
  .mi-edit{display:inline-block;margin-left:6px;width:18px;height:18px;line-height:17px;text-align:center;border-radius:50%;background:#e8e8ef;color:#333;font-weight:700;font-size:13px;text-decoration:none;vertical-align:middle}
  .mi-edit:hover{background:#2563eb;color:#fff}
  .mi-editor{display:flex;gap:6px;align-items:center;margin-top:4px}
  .mi-editor input{flex:1;min-width:160px;padding:4px 6px}
  .mi-editor button{padding:4px 8px;font-size:12px}
  .mi-error{color:#7a0000;font-size:12px;margin-top:2px}
  .roster-title{font-size:26px;font-weight:700;margin:0 0 6px}
  .roster-dateline{font-size:16px;margin:10px 0 14px}
  .roster-dateline .line{display:inline-block;min-width:260px;border-bottom:1px solid #333;margin-left:6px;vertical-align:bottom}
  @media print {
    header, .admin-bar, .no-print { display:none !important }
    body{background:#fff}
    main{max-width:none;margin:0;padding:0}
    .card{box-shadow:none;border-radius:0;padding:0;margin:0}
    .roster-table{font-size:12px}
    .roster-table th,.roster-table td{border-color:#000;padding:5px 6px}
    .roster-table thead{display:table-header-group}
    .roster-table tr{page-break-inside:avoid}
    @page{size:letter landscape;margin:0.5in}
  }
</style>

<div class="no-print" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
  <h2 style="margin:0;">Camping Roster: <?= h($event['name']) ?></h2>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <a class="button primary" href="/event_ranger_roster.php?event_id=<?= (int)$eventId ?>&amp;format=csv">Export CSV</a>
    <button type="button" class="button primary" onclick="window.print()">Save as PDF</button>
    <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Back to Event</a>
    <?= EventUIManager::renderAdminMenu((int)$eventId, 'roster') ?>
  </div>
</div>

<div class="card">
  <p class="roster-title">Pack 440 Camping Roster</p>
  <p class="roster-dateline"><strong>Date:</strong><span class="line">&nbsp;</span></p>
  <p style="margin:0 0 4px;"><strong>Event:</strong> <?= h($event['name']) ?></p>
  <p style="margin:0 0 14px;"><strong>Attending:</strong> <?= h($summary) ?></p>

  <?php if (empty($rows)): ?>
    <p>No one has RSVP'd yes for this event yet.</p>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="roster-table">
        <thead>
          <tr><?php foreach ($columns as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): $cells = $cellsOf($r); ?>
            <tr>
              <td><?= h($cells[0]) ?></td>
              <td><?= h($cells[1]) ?></td>
              <td class="nowrap"><?= h($cells[2]) ?></td>
              <td class="nowrap"><?= h($cells[3]) ?></td>
              <td class="mi-cell" data-type="<?= $r['sort_type'] === 0 ? 'adult' : 'youth' ?>" data-id="<?= (int)$r['id'] ?>" data-base="<?= h(implode('; ', array_filter([$r['position'], $r['bsa'] !== '' ? 'BSA #' . $r['bsa'] : '']))) ?>" data-note="<?= h($r['note']) ?>">
                <span class="mi-text"><?= h($cells[4]) ?></span><a href="#" class="mi-edit no-print" title="<?= $r['note'] !== '' ? 'Edit membership note' : 'Add membership note' ?>"><?= $r['note'] !== '' ? '&#9998;' : '+' ?></a>
              </td>
              <td class="nowrap"><?= h($cells[5]) ?></td>
              <td class="blank"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <p class="small no-print" style="margin-top:12px;">"Save as PDF" opens your browser's print dialog; choose "Save as PDF" as the destination. The Training column is left blank to fill in by hand. Use the + next to Membership Info to add a note such as "Parent of ..." or a BSA #; it is saved to the person's record.</p>
</div>

<script>
(function(){
  var csrf = <?= json_encode(csrf_token()) ?>;
  function esc(s){ return String(s); }
  function render(cell){
    var base = cell.getAttribute('data-base') || '', note = cell.getAttribute('data-note') || '';
    var txt = cell.querySelector('.mi-text'), btn = cell.querySelector('.mi-edit');
    txt.textContent = [base, note].filter(Boolean).join('; ');
    btn.innerHTML = note ? '&#9998;' : '+';
    btn.title = note ? 'Edit membership note' : 'Add membership note';
  }
  function closeEditor(cell){ var ed = cell.querySelector('.mi-editor'); if (ed) ed.remove(); var er = cell.querySelector('.mi-error'); if (er) er.remove(); cell.querySelector('.mi-edit').style.display = ''; }
  function openEditor(cell){
    document.querySelectorAll('.mi-cell .mi-editor').forEach(function(ed){ closeEditor(ed.closest('.mi-cell')); });
    cell.querySelector('.mi-edit').style.display = 'none';
    var ed = document.createElement('div'); ed.className = 'mi-editor no-print';
    var inp = document.createElement('input'); inp.type = 'text'; inp.maxLength = 255; inp.placeholder = 'e.g. Parent of Charlie Rosenthal'; inp.value = cell.getAttribute('data-note') || '';
    var save = document.createElement('button'); save.type = 'button'; save.className = 'button primary'; save.textContent = 'Save';
    var cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'button'; cancel.textContent = 'Cancel';
    ed.appendChild(inp); ed.appendChild(save); ed.appendChild(cancel); cell.appendChild(ed); inp.focus();
    cancel.addEventListener('click', function(){ closeEditor(cell); });
    inp.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); save.click(); } if (e.key === 'Escape') { closeEditor(cell); } });
    save.addEventListener('click', function(){
      save.disabled = true;
      var fd = new FormData(); fd.set('csrf', csrf); fd.set('type', cell.getAttribute('data-type')); fd.set('id', cell.getAttribute('data-id')); fd.set('note', inp.value);
      fetch('/membership_note_update.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (!j || !j.ok) throw new Error((j && j.error) || 'Save failed');
          cell.setAttribute('data-note', j.note || ''); closeEditor(cell); render(cell);
        })
        .catch(function(err){ save.disabled = false; var er = cell.querySelector('.mi-error') || document.createElement('div'); er.className = 'mi-error no-print'; er.textContent = err.message || 'Save failed'; cell.appendChild(er); });
    });
  }
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.mi-edit'); if (!btn) return;
    e.preventDefault(); openEditor(btn.closest('.mi-cell'));
  });
})();
</script>

<?= EventUIManager::renderAdminModals((int)$eventId) ?>
<?= EventUIManager::renderAdminMenuScript((int)$eventId) ?>

<?php footer_html(); ?>
