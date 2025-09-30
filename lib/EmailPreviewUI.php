<?php

require_once __DIR__ . '/RSVPManagement.php';
require_once __DIR__ . '/Text.php';
require_once __DIR__ . '/../settings.php';

class EmailPreviewUI {
    
    /**
     * Renders the complete email preview UI with RSVP radio buttons and dynamic JavaScript
     */
    public static function renderEmailPreview(
        array $event, 
        int $eventId, 
        string $baseUrl, 
        string $siteTitle,
        string $defaultDescription = '',
        string $defaultEmailType = 'none'
    ): void {
        // Generate static preview data
        $whenText = Settings::formatDateTimeRange((string)$event['starts_at'], !empty($event['ends_at']) ? (string)$event['ends_at'] : null);
        $locName = trim((string)($event['location'] ?? ''));
        $locAddr = trim((string)($event['location_address'] ?? ''));
        $locCombined = ($locName !== '' && $locAddr !== '') ? ($locName . "\n" . $locAddr) : ($locAddr !== '' ? $locAddr : $locName);
        $whereHtml = $locCombined !== '' ? nl2br(htmlspecialchars($locCombined, ENT_QUOTES, 'UTF-8')) : '';
        
        // Use placeholder links for preview
        $previewDeepLink = $baseUrl . '/event.php?id=' . $eventId;
        $googleLink = '#';
        $outlookLink = '#';
        $icsDownloadLink = '#';
        
        // Generate initial preview HTML (default: never RSVP'd)
        $previewHtml = self::generateEmailHTML($event, $siteTitle, $baseUrl, $previewDeepLink, $whenText, $whereHtml, $defaultDescription, $googleLink, $outlookLink, $icsDownloadLink, $defaultEmailType, $eventId, 0, false);
        
        ?>
        <div class="card">
          <h3>Email Preview</h3>
          
          <div style="margin-bottom: 16px; padding: 12px; background-color: #f8f9fa; border-radius: 6px;">
            <label><strong>Preview as:</strong></label>
            <div style="margin-left: 16px; margin-top: 8px;">
              <label class="inline">
                <input type="radio" name="preview_rsvp_status" value="never" checked> Never RSVP'd
              </label>
              <label class="inline">
                <input type="radio" name="preview_rsvp_status" value="yes"> RSVP'd previously Yes
              </label>
            </div>
          </div>
          
          <div id="emailPreviewContent">
            <?= $previewHtml ?>
          </div>
        </div>

        <script>
        (function() {
            // Email preview functionality
            const previewRsvpRadios = document.querySelectorAll('input[name="preview_rsvp_status"]');
            const previewContent = document.getElementById('emailPreviewContent');
            
            function updateEmailPreview() {
                if (!previewContent) return;
                
                const selectedRsvpStatus = document.querySelector('input[name="preview_rsvp_status"]:checked')?.value || 'never';
                const currentEmailType = document.querySelector('input[name="email_type"]:checked')?.value || '<?= addslashes($defaultEmailType) ?>';
                const currentDescription = document.querySelector('textarea[name="description"]')?.value || '<?= addslashes($defaultDescription) ?>';
                
                // Generate introduction text based on email type and mock RSVP status
                let introText = '';
                if (currentEmailType === 'invitation') {
                    introText = "You're Invited to...";
                } else if (currentEmailType === 'reminder') {
                    introText = selectedRsvpStatus === 'yes' ? 'Reminder:' : 'Reminder to RSVP for...';
                }
                
                // Generate button HTML based on mock RSVP status
                let buttonHtml = '';
                const baseUrl = '<?= addslashes($baseUrl) ?>';
                const eventId = <?= $eventId ?>;
                
                if (selectedRsvpStatus === 'yes') {
                    buttonHtml = `<p style="margin:0 0 10px;color:#222;font-size:16px;font-weight:bold;">You RSVP'd Yes</p>
                                 <p style="margin:0 0 16px;">
                                   <a href="${baseUrl}/event.php?id=${eventId}" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View Details</a>
                                 </p>`;
                } else {
                    buttonHtml = `<p style="margin:0 0 16px;">
                                   <a href="${baseUrl}/event.php?id=${eventId}" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View & RSVP</a>
                                 </p>`;
                }
                
                // Build the complete preview HTML
                const eventName = '<?= addslashes((string)$event['name']) ?>';
                const siteTitle = '<?= addslashes($siteTitle) ?>';
                const introHtml = introText ? `<p style="margin:0 0 8px;color:#666;font-size:14px;">${introText}</p>` : '';
                const descriptionHtml = currentDescription ? `<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fff;">
                                                                <div>${currentDescription.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\*(.*?)\*/g, '<em>$1</em>')}</div>
                                                              </div>` : '';
                
                const previewHtml = `
                <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;margin:0 auto;padding:16px;color:#222;">
                  <div style="text-align:center;">
                    ${introHtml}
                    <h2 style="margin:0 0 8px;">${eventName}</h2>
                    <p style="margin:0 0 16px;color:#444;">${siteTitle}</p>
                    ${buttonHtml}
                  </div>
                  <div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fafafa;">
                    <div><strong>When:</strong> <?= h($whenText) ?></div>
                    <?php if ($whereHtml): ?>
                    <div><strong>Where:</strong> <?= $whereHtml ?></div>
                    <?php endif; ?>
                  </div>
                  ${descriptionHtml}
                  <p style="font-size:12px;color:#666;text-align:center;margin:12px 0 0;">
                    If the button does not work, open this link: <br><a href="${baseUrl}/event.php?id=${eventId}">${baseUrl}/event.php?id=${eventId}</a>
                  </p>
                </div>`;
                
                previewContent.innerHTML = previewHtml;
            }
            
            // Add event listeners for RSVP radio buttons
            previewRsvpRadios.forEach(radio => {
                radio.addEventListener('change', updateEmailPreview);
            });
            
            // Add event listeners for email type radio buttons if they exist
            const emailTypeRadios = document.querySelectorAll('input[name="email_type"]');
            emailTypeRadios.forEach(radio => {
                radio.addEventListener('change', updateEmailPreview);
            });
            
            // Add event listener for description field if it exists
            const descriptionField = document.querySelector('textarea[name="description"]');
            if (descriptionField) {
                descriptionField.addEventListener('input', updateEmailPreview);
            }
            
            // Initialize preview
            updateEmailPreview();
        })();
        </script>
        <?php
    }

    /**
     * Get RSVP status for a user and event
     */
    private static function getRsvpStatus(int $eventId, int $userId): ?string {
        return RSVPManagement::getAnswerForUserEvent($eventId, $userId);
    }

    /**
     * Get email introduction text based on type and user's RSVP status
     */
    private static function getEmailIntroduction(string $emailType, int $eventId, int $userId): string {
        if ($emailType === 'none') {
            return '';
        }
        
        if ($emailType === 'invitation') {
            return "You're Invited to...";
        }
        
        if ($emailType === 'reminder') {
            // Check if user has RSVP'd to this event
            $rsvpStatus = self::getRsvpStatus($eventId, $userId);
            return $rsvpStatus ? 'Reminder:' : 'Reminder to RSVP for...';
        }
        
        return '';
    }

    /**
     * Generate RSVP button HTML based on user's RSVP status
     */
    private static function generateRsvpButtonHtml(int $eventId, int $userId, string $deepLink): string {
        $safeDeep = htmlspecialchars($deepLink, ENT_QUOTES, 'UTF-8');
        $rsvpStatus = self::getRsvpStatus($eventId, $userId);
        
        if ($rsvpStatus) {
            // User has RSVP'd - show bold text status + separate "View Details" button
            $statusText = ucfirst($rsvpStatus); // 'yes' -> 'Yes', 'maybe' -> 'Maybe', 'no' -> 'No'
            return '<p style="margin:0 0 10px;color:#222;font-size:16px;font-weight:bold;">You RSVP\'d '. htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') .'</p>
                    <p style="margin:0 0 16px;">
                      <a href="'. $safeDeep .'" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View Details</a>
                    </p>';
        } else {
            // User hasn't RSVP'd - show standard button
            return '<p style="margin:0 0 16px;">
              <a href="'. $safeDeep .'" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View & RSVP</a>
            </p>';
        }
    }

    /**
     * Generate complete email HTML
     */
    private static function generateEmailHTML(array $event, string $siteTitle, string $baseUrl, string $deepLink, string $whenText, string $whereHtml, string $description, string $googleLink, string $outlookLink, string $icsDownloadLink, string $emailType = 'none', int $eventId = 0, int $userId = 0, bool $includeCalendarLinks = true): string {
        $safeSite = htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8');
        $safeEvent = htmlspecialchars((string)$event['name'], ENT_QUOTES, 'UTF-8');
        $safeWhen = htmlspecialchars($whenText, ENT_QUOTES, 'UTF-8');
        $safeDeep = htmlspecialchars($deepLink, ENT_QUOTES, 'UTF-8');
        $safeGoogle = htmlspecialchars($googleLink, ENT_QUOTES, 'UTF-8');
        $safeOutlook = htmlspecialchars($outlookLink, ENT_QUOTES, 'UTF-8');
        $safeIcs = htmlspecialchars($icsDownloadLink, ENT_QUOTES, 'UTF-8');
        
        // Get introduction text
        $introduction = self::getEmailIntroduction($emailType, $eventId, $userId);
        $introHtml = '';
        if ($introduction !== '') {
            $safeIntro = htmlspecialchars($introduction, ENT_QUOTES, 'UTF-8');
            $introHtml = '<p style="margin:0 0 8px;color:#666;font-size:14px;">'. $safeIntro .'</p>';
        }
        
        $calendarLinksHtml = '';
        if ($includeCalendarLinks) {
            $calendarLinksHtml = '<div style="text-align:center;margin:0 0 12px;">
              <a href="'. $safeGoogle .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Add to Google</a>
              <a href="'. $safeOutlook .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Add to Outlook</a>
              <a href="'. $safeIcs .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Download .ics</a>
            </div>';
        }
        
        // Generate context-aware button HTML
        $buttonHtml = self::generateRsvpButtonHtml($eventId, $userId, $deepLink);
        
        return '
        <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;margin:0 auto;padding:16px;color:#222;">
          <div style="text-align:center;">
            '. $introHtml .'
            <h2 style="margin:0 0 8px;">'. $safeEvent .'</h2>
            <p style="margin:0 0 16px;color:#444;">'. $safeSite .'</p>
            '. $buttonHtml .'
          </div>
          <div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fafafa;">
            <div><strong>When:</strong> '. $safeWhen .'</div>'.
            ($whereHtml !== '' ? '<div><strong>Where:</strong> '. $whereHtml .'</div>' : '') .'
          </div>'
          . ($description !== '' ? ('<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fff;">
            <div>'. Text::renderMarkup($description) .'</div>
          </div>') : '')
          . $calendarLinksHtml
          . '<p style="font-size:12px;color:#666;text-align:center;margin:12px 0 0;">
            If the button does not work, open this link: <br><a href="'. $safeDeep .'">'. $safeDeep .'</a>
          </p>
        </div>';
    }
}
