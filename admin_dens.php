<?php
// This feature has been removed as part of deprecating "dens" from the application.
require_once __DIR__.'/partials.php';
http_response_code(410);
header_html('Dens (Removed)');
?>
<div class="card">
  <h3>Dens Removed</h3>
  <p class="small">The concept of dens has been removed from the system. Adult den leadership is now tracked by position title and grade-derived class_of on the leadership record.</p>
  <div class="actions" style="margin-top:8px;">
    <a class="button" href="/admin_adults.php">Manage Adults</a>
    <a class="button" href="/youth.php">View Youth</a>
  </div>
</div>
<?php footer_html(); ?>
