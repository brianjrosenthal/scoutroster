<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/Search.php';
require_once __DIR__.'/settings.php';
require_once __DIR__.'/lib/Recommendations.php';
require_admin();

$msg = null;
$err = null;

// Inputs
$q = trim($_GET['q'] ?? '');
$sf = trim($_GET['s'] ?? 'new_active'); // new_active|new|active|joined|unsubscribed

// Build query
$rows = Recommendations::list(['q' => $q, 'status' => $sf]);

header_html('Recommendations');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Recommendations</h2>
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <button type="button" class="button" id="exportEmailsBtn">Export</button>
    <a class="button" href="/recommend.php">Recommend a friend!</a>
  </div>
</div>

<div class="card">
  <form id="filterForm" method="get" class="stack">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Search
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Parent, child, email, phone">
      </label>
      <label>Status
        <select name="s">
          <option value="new_active" <?= $sf==='new_active'?'selected':'' ?>>New and Active</option>
          <option value="new" <?= $sf==='new'?'selected':'' ?>>New</option>
          <option value="active" <?= $sf==='active'?'selected':'' ?>>Active</option>
          <option value="joined" <?= $sf==='joined'?'selected':'' ?>>Joined</option>
          <option value="unsubscribed" <?= $sf==='unsubscribed'?'selected':'' ?>>Unsubscribed</option>
        </select>
      </label>
    </div>
  </form>
  <script>
    (function(){
      var f=document.getElementById('filterForm');
      if(!f) return;
      var q=f.querySelector('input[name="q"]');
      var s=f.querySelector('select[name="s"]');
      var t;
      function submitNow(){ if(typeof f.requestSubmit==='function') f.requestSubmit(); else f.submit(); }
      if(q){ q.addEventListener('input', function(){ if(t) clearTimeout(t); t=setTimeout(submitNow,600); }); }
      if(s){ s.addEventListener('change', submitNow); }
    })();
  </script>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="small">No recommendations found.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Parent</th>
          <th>Child</th>
          <th>Contact</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $contact = [];
            if (!empty($r['email'])) $contact[] = h($r['email']);
            if (!empty($r['phone'])) $contact[] = h($r['phone']);
            $statusLabel = isset($r['status']) ? ucfirst((string)$r['status']) : '';
          ?>
          <tr>
            <td><?= h($r['parent_name']) ?></td>
            <td><?= h($r['child_name']) ?></td>
            <td><?= !empty($contact) ? implode('<br>', $contact) : '&mdash;' ?></td>
            <td><?= $statusLabel !== '' ? h($statusLabel) : '&mdash;' ?></td>
            <td class="small">
              <a class="button" href="/admin_recommendation_view.php?id=<?= (int)$r['id'] ?>">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- Export Emails Modal -->
<div id="exportEmailsModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content">
    <button class="close" type="button" id="exportEmailsModalClose" aria-label="Close">&times;</button>
    <h3>Export Recommendation Emails</h3>
    
    <div class="stack">
      <p>Email addresses from current view (<?= h($sf) ?> recommendations):</p>
      
      <div>
        <label>Email addresses (one per line):
          <textarea id="exportEmailsList" rows="10" readonly style="font-family: monospace; font-size: 12px;"></textarea>
        </label>
        <div style="margin-top: 8px;">
          <button type="button" id="copyExportEmailsBtn" class="button primary">Copy to Clipboard</button>
          <span id="copyExportStatus" style="margin-left: 8px; color: green; display: none;">Copied!</span>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // Export Emails modal functionality
  const exportEmailsBtn = document.getElementById("exportEmailsBtn");
  const exportEmailsModal = document.getElementById("exportEmailsModal");
  const exportEmailsModalClose = document.getElementById("exportEmailsModalClose");
  const copyExportEmailsBtn = document.getElementById("copyExportEmailsBtn");

  if (exportEmailsBtn) {
    exportEmailsBtn.addEventListener("click", function(e) {
      e.preventDefault();
      if (exportEmailsModal) {
        exportEmailsModal.classList.remove("hidden");
        exportEmailsModal.setAttribute("aria-hidden", "false");
        loadRecommendationEmails(); // Load email data
      }
    });
  }

  if (exportEmailsModalClose) {
    exportEmailsModalClose.addEventListener("click", function() {
      if (exportEmailsModal) {
        exportEmailsModal.classList.add("hidden");
        exportEmailsModal.setAttribute("aria-hidden", "true");
      }
    });
  }

  if (copyExportEmailsBtn) {
    copyExportEmailsBtn.addEventListener("click", function() {
      const exportEmailsList = document.getElementById("exportEmailsList");
      const copyExportStatus = document.getElementById("copyExportStatus");
      
      if (exportEmailsList) {
        exportEmailsList.select();
        exportEmailsList.setSelectionRange(0, 99999); // For mobile devices
        
        try {
          navigator.clipboard.writeText(exportEmailsList.value).then(() => {
            if (copyExportStatus) {
              copyExportStatus.style.display = "inline";
              setTimeout(() => {
                copyExportStatus.style.display = "none";
              }, 2000);
            }
          }).catch(() => {
            // Fallback for older browsers
            document.execCommand("copy");
            if (copyExportStatus) {
              copyExportStatus.style.display = "inline";
              setTimeout(() => {
                copyExportStatus.style.display = "none";
              }, 2000);
            }
          });
        } catch (err) {
          console.error("Failed to copy emails:", err);
        }
      }
    });
  }

  // Function to load recommendation emails
  function loadRecommendationEmails() {
    const exportEmailsList = document.getElementById("exportEmailsList");
    
    if (exportEmailsList) {
      exportEmailsList.value = "Loading...";
    }
    
    const urlParams = new URLSearchParams(window.location.search);
    const q = urlParams.get('q') || '';
    const s = urlParams.get('s') || 'new_active';
    
    fetch("/admin_recommendations_export.php?q=" + encodeURIComponent(q) + "&s=" + encodeURIComponent(s), {
      headers: { "Accept": "application/json" }
    })
    .then(response => response.json())
    .then(data => {
      if (data.success && exportEmailsList) {
        if (data.emails && data.emails.length > 0) {
          exportEmailsList.value = data.emails.join("\n");
        } else {
          exportEmailsList.value = "No email addresses found in current view.";
        }
      } else {
        if (exportEmailsList) {
          exportEmailsList.value = "Error loading emails: " + (data.error || "Unknown error");
        }
      }
    })
    .catch(error => {
      console.error("Error loading recommendation emails:", error);
      if (exportEmailsList) {
        exportEmailsList.value = "Error loading emails";
      }
    });
  }

  // Close modal on outside click or Escape
  if (exportEmailsModal) {
    exportEmailsModal.addEventListener("click", function(e) {
      if (e.target === exportEmailsModal) {
        exportEmailsModal.classList.add("hidden");
        exportEmailsModal.setAttribute("aria-hidden", "true");
      }
    });
  }

  document.addEventListener("keydown", function(e) {
    if (e.key === "Escape" && exportEmailsModal && !exportEmailsModal.classList.contains("hidden")) {
      exportEmailsModal.classList.add("hidden");
      exportEmailsModal.setAttribute("aria-hidden", "true");
    }
  });
})();
</script>

<?php footer_html(); ?>
