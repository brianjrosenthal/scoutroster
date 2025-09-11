<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/Recommendations.php';
require_login();

$me = current_user();
$ctx = UserContext::getLoggedInUserContext();
$msg = null;
$err = null;

function esc_mail($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  $parent_name = trim($_POST['parent_name'] ?? '');
  $child_name  = trim($_POST['child_name'] ?? '');
  $email       = trim($_POST['email'] ?? '');
  $phone       = trim($_POST['phone'] ?? '');
  $notes       = trim($_POST['notes'] ?? '');
  $grade_raw   = trim($_POST['grade'] ?? '');
  $grade       = in_array($grade_raw, ['K','1','2','3','4','5'], true) ? $grade_raw : null;

  $errors = [];
  if ($parent_name === '') $errors[] = 'Parent name is required.';
  if ($child_name === '')  $errors[] = 'Child name is required.';

  if (empty($errors)) {
    try {
      // Create recommendation via domain
      $recId = Recommendations::create($ctx, [
        'parent_name' => $parent_name,
        'child_name'  => $child_name,
        'email'       => $email,
        'phone'       => $phone,
        'grade'       => $grade,
        'notes'       => $notes,
      ]);

      // Send notification email to Cubmaster (best-effort; do not block success)
      $toName = Settings::get('cubmaster_name', '');
      $toEmail = Settings::get('cubmaster_email', '');

      // Fallback to leader with "Cubmaster" position if settings not present
      if ($toEmail === '' || $toName === '') {
        try {
          $stL = pdo()->prepare("
            SELECT u.first_name, u.last_name, u.email
            FROM adult_leadership_positions alp
            JOIN users u ON u.id = alp.adult_id
            WHERE LOWER(alp.position) = 'cubmaster'
            ORDER BY u.id ASC
            LIMIT 1
          ");
          $stL->execute();
          if ($r = $stL->fetch()) {
            if ($toName === '') $toName = trim((string)($r['first_name'] ?? '').' '.(string)($r['last_name'] ?? '')) ?: 'Cubmaster';
            if ($toEmail === '') $toEmail = trim((string)($r['email'] ?? ''));
          }
        } catch (Throwable $e) {
          // noop
        }
      }
      if ($toName === '') $toName = 'Cubmaster';

      // If still no email configured, fallback to SMTP_FROM or SMTP_USER if available
      if ($toEmail === '' && defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL) {
        $toEmail = SMTP_FROM_EMAIL;
      }
      if ($toEmail === '' && defined('SMTP_USER') && SMTP_USER) {
        $toEmail = SMTP_USER;
      }

      // Compose message
      $submitterName = trim((string)($me['first_name'] ?? '').' '.(string)($me['last_name'] ?? ''));
      if ($submitterName === '') $submitterName = (string)($me['email'] ?? 'A pack member');
      $subject = $submitterName . ' has recommended ' . $parent_name . ' for Cub Scouts';

      $html = ''
        . '<p>Hi '.esc_mail($toName).' - '.esc_mail($submitterName).' has recommended '.esc_mail($parent_name)
        . ' and their child '.esc_mail($child_name).' for Cub Scouts. Details:</p>'
        . '<p>'
        . 'Name of parent: '.esc_mail($parent_name).'<br>'
        . 'Name of child: '.esc_mail($child_name).'<br>'
        . 'Email Address: '.esc_mail($email).'<br>'
        . 'Phone Number: '.esc_mail($phone).'<br>'
        . 'Notes: '.nl2br(esc_mail($notes)).'<br>'
        . '</p>';

      if ($toEmail !== '') {
        @send_email($toEmail, $subject, $html, $toName);
      }

      header('Location: /index.php?recommended=1');
      exit;
    } catch (Throwable $e) {
      $err = 'Unable to submit your recommendation. Please try again.';
    }
  } else {
    $err = implode(' ', $errors);
  }
}

header_html('Recommend a friend');
?>
<div class="card">
  <h2>Recommend a friend</h2>
  <p class="small">Recommend someone who might enjoy Cub Scouts.</p>
  <?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>
  <form method="post" class="stack" style="max-width:520px;">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Name of parent:
      <input type="text" name="parent_name" value="<?= h($_POST['parent_name'] ?? '') ?>" required>
    </label>
    <label>Name of child:
      <input type="text" name="child_name" value="<?= h($_POST['child_name'] ?? '') ?>" required>
    </label>
    <label>Email address:
      <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>">
    </label>
    <label>Phone number:
      <input type="text" name="phone" value="<?= h($_POST['phone'] ?? '') ?>">
    </label>
    <label>Grade:
      <select name="grade">
        <option value="">Unknown</option>
        <option value="K" <?= (($_POST['grade'] ?? '')==='K')?'selected':'' ?>>K</option>
        <option value="1" <?= (($_POST['grade'] ?? '')==='1')?'selected':'' ?>>1</option>
        <option value="2" <?= (($_POST['grade'] ?? '')==='2')?'selected':'' ?>>2</option>
        <option value="3" <?= (($_POST['grade'] ?? '')==='3')?'selected':'' ?>>3</option>
        <option value="4" <?= (($_POST['grade'] ?? '')==='4')?'selected':'' ?>>4</option>
        <option value="5" <?= (($_POST['grade'] ?? '')==='5')?'selected':'' ?>>5</option>
      </select>
    </label>
    <label>Notes (how do you know the child or the family? what do you think they'd like most about scouts?):
      <textarea name="notes" rows="4"><?= h($_POST['notes'] ?? '') ?></textarea>
    </label>
    <div class="actions">
      <button class="primary">Submit</button>
      <a class="button" href="/index.php">Cancel</a>
    </div>
  </form>
</div>
<?php footer_html(); ?>
