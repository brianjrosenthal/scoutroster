<?php
require_once __DIR__ . '/partials.php';
require_login();

header_html('Forms and Links');
?>
<h2>Forms and Links</h2>

<div class="card">
  <h3>Medical Form</h3>
  <p>
    <a class="button" target="_blank" rel="noopener" href="https://filestore.scouting.org/filestore/HealthSafety/pdf/680-001_AB.pdf">Open Medical Form A&B</a>
  </p>
  <p class="small">The standard Medical Form A&B required for basic Scouting activities such as local tours and weekend camping trips less than 72 hours in duration.</p>
</div>

<div class="card">
  <h3>Youth Application Form</h3>
  <p>
    <a class="button" target="_blank" rel="noopener" href="https://filestore.scouting.org/filestore/pdf/524-406.pdf">Open Youth Application</a>
  </p>
  <p class="small">Required to register for Cub Scouts.</p>
</div>

<div class="card">
  <h3>Adult Application Form</h3>
  <p>
    <a class="button" target="_blank" rel="noopener" href="https://filestore.scouting.org/filestore/pdf/524-501.pdf">Open Adult Application</a>
  </p>
  <p class="small">Required to become a registered Adult Leader in Cub Scouts.</p>
</div>

<div class="card">
  <h3>Greater Hudson Valley Training Dates</h3>
  <p>
    <a class="button" target="_blank" rel="noopener" href="https://www.ghvscouting.org/training/">Open Training Dates</a>
  </p>
  <p class="small">Training dates for den leader training, BALOO training, Wood Badge Training, Rangemaster Training, etc. Note that the page can take a long time to load.</p>
</div>

<div class="card">
  <h3>Pack 440 Public Web Site</h3>
  <p>
    <a class="button" target="_blank" rel="noopener" href="https://www.scarsdalepack440.com">Visit Pack 440 Website</a>
  </p>
  <p class="small">Our public web site.</p>
</div>

<?php footer_html(); ?>
