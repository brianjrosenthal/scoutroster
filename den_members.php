<?php
// This feature has been removed as part of deprecating "dens" from the application.
require_once __DIR__.'/partials.php';
http_response_code(410);
header_html('Den Members (Removed)');
?>
<div class="card">
  <h3>Den Members Removed</h3>
  <p class="small">The dens feature has been removed. Youth are grouped by grade only; adult den leadership is tracked by role with an optional grade (class_of) on the leadership record.</p>
  <div class="actions" style="margin-top:8px;">
    <a class="button" href="/youth.php">View Youth</a>
    <a class="button" href="/admin_adults.php">Manage Adults</a>
  </div>
</div>
<?php footer_html(); ?>
