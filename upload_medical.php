<?php
require_once __DIR__.'/partials.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

$type = $_GET['type'] ?? $_POST['type'] ?? '';
$adultId = isset($_GET['adult_id']) ? (int)$_GET['adult_id'] : (int)($_POST['adult_id'] ?? 0);
$youthId = isset($_GET['youth_id']) ? (int)$_GET['youth_id'] : (int)($_POST['youth_id'] ?? 0);

if (!in_array($type, ['adult','youth'], true)) { http_response_code(400); exit('Invalid type'); }
if ($type === 'adult' && $adultId <= 0) { http_response_code(400); exit('Missing adult_id'); }
if ($type === 'youth' && $youthId <= 0) { http_response_code(400); exit('Missing youth_id'); }

// Authorization
$authorized = false;
if ($isAdmin) {
  $authorized = true;
} elseif ($type === 'adult') {
  $authorized = ($adultId === (int)$me['id']);
} else { // youth
  $st = pdo()->prepare("SELECT 1 FROM parent_relationships WHERE youth_id=? AND adult_id=? LIMIT 1");
  $st->execute([$youthId, (int)$me['id']]);
  $authorized = (bool)$st->fetchColumn();
}
if (!$authorized) { http_response_code(403); exit('Not authorized'); }

$err = null;
$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  if (!isset($_FILES['pdf']) || !is_array($_FILES['pdf'])) {
    $err = 'No file uploaded.';
  } else {
    $f = $_FILES['pdf'];
    if (!empty($f['error'])) {
      $err = 'Upload failed.';
    } else {
      // Validate PDF by mime and extension
      $name = $f['name'] ?? 'file.pdf';
      $tmp  = $f['tmp_name'] ?? '';
      $size = (int)($f['size'] ?? 0);

      if (!is_uploaded_file($tmp)) {
        $err = 'Invalid upload.';
      } elseif ($size <= 0 || $size > 10 * 1024 * 1024) {
        $err = 'File must be PDF up to 10MB.';
      } else {
        // Basic PDF sniff
        $fh = @fopen($tmp, 'rb');
        $sig = $fh ? fread($fh, 4) : '';
        if ($fh) fclose($fh);
        $extOk = (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf');
        if ($sig !== '%PDF' && !$extOk) {
          $err = 'Only PDF files are allowed.';
        } else {
          // Ensure destination directory exists
          $destDir = __DIR__ . '/uploads/medical';
          if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }

          $rand = bin2hex(random_bytes(16));
          $destRel = 'uploads/medical/'.$rand.'.pdf';
          $destAbs = __DIR__ . '/'.$destRel;

          if (!@move_uploaded_file($tmp, $destAbs)) {
            $err = 'Failed to store file.';
          } else {
            // Insert DB record
            try {
              $st = pdo()->prepare("INSERT INTO medical_forms (type, youth_id, adult_id, file_path, original_filename, mime_type) VALUES (?,?,?,?,?,?)");
              $st->execute([
                $type,
                $type === 'youth' ? $youthId : null,
                $type === 'adult' ? $adultId : null,
                $destRel,
                $name,
                'application/pdf'
              ]);
              $mid = (int)pdo()->lastInsertId();
              $msg = 'File uploaded.';
              // Redirect to download link? Stay on page and show success + link.
              header('Location: /my_profile.php'); exit;
            } catch (Throwable $e) {
              @unlink($destAbs);
              $err = 'Failed to record upload.';
            }
          }
        }
      }
    }
  }
}

header_html('Upload Medical Form');
?>
<h2>Upload Medical Form</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="type" value="<?=h($type)?>">
    <?php if ($type === 'adult'): ?>
      <input type="hidden" name="adult_id" value="<?= (int)$adultId ?>">
      <p class="small">Uploading for you.</p>
    <?php else: ?>
      <input type="hidden" name="youth_id" value="<?= (int)$youthId ?>">
      <p class="small">Uploading for your child.</p>
    <?php endif; ?>
    <label>PDF File
      <input type="file" name="pdf" accept="application/pdf" required>
    </label>
    <div class="actions">
      <button class="primary">Upload</button>
      <a class="button" href="/my_profile.php">Cancel</a>
    </div>
    <small class="small">Max size 10 MB. Only PDF files are allowed.</small>
  </form>
</div>

<?php footer_html(); ?>
