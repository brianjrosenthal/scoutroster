<?php

require_once __DIR__ . '/EventManagement.php';

class EventUIManager {
    
    /**
     * Check if the current user has Key 3 permissions
     * 
     * @return bool Whether the user is a Key 3 member
     */
    private static function isKey3User(): bool {
        $me = current_user();
        if (!$me) {
            return false;
        }
        
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
                        return true;
                    }
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        
        return false;
    }
    
    /**
     * Render the admin menu dropdown for event management pages
     * 
     * @param int $eventId The event ID
     * @param string|null $currentPage The current page identifier for highlighting
     * @return string HTML content for the admin menu
     */
    public static function renderAdminMenu(int $eventId, ?string $currentPage = null): string {
        $me = current_user();
        $isAdmin = !empty($me['is_admin']);
        
        if (!$isAdmin) {
            return '';
        }
        
        // Load event to check if public RSVP is allowed
        $event = null;
        try {
            $event = EventManagement::findById($eventId);
        } catch (Throwable $e) {
            // Handle error gracefully
        }
        $allowPublic = $event ? ((int)($event['allow_non_user_rsvp'] ?? 1) === 1) : true;
        $rsvpUrl = $event ? trim((string)($event['rsvp_url'] ?? '')) : '';
        $rsvpLabel = $event ? trim((string)($event['rsvp_url_label'] ?? '')) : '';
        
        // Check if user has required role for Event Compliance and Dietary Needs
        $showCompliance = false;
        $showDietaryNeeds = false;
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
                        $showCompliance = true; 
                        $showDietaryNeeds = true;
                        break; 
                    }
                }
            }
        } catch (Throwable $e) {
            $showCompliance = false;
            $showDietaryNeeds = false;
        }
        
        $html = '
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
                <a href="/admin_event_edit.php?id=' . $eventId . '" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;' . ($currentPage === 'edit' ? ' background-color: #f5f5f5;' : '') . '">Edit Event</a>
                <a href="/admin_event_edit.php" style="display: block; padding: 8px 12px; text-decoration: none; color: #0b5ed7; border-bottom: 1px solid #ddd;">Create New Event</a>';
        
        if ($allowPublic) {
            $html .= '
                <a href="/event_public.php?event_id=' . $eventId . '" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;' . ($currentPage === 'public' ? ' background-color: #f5f5f5;' : '') . '">Public RSVP Link</a>';
        }
        
        $html .= '
                <a href="/admin_event_invite.php?event_id=' . $eventId . '" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;' . ($currentPage === 'invite' ? ' background-color: #f5f5f5;' : '') . '">Invite</a>
                <a href="/admin_event_volunteers.php?event_id=' . $eventId . '" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;' . ($currentPage === 'volunteers' ? ' background-color: #f5f5f5;' : '') . '">Manage Volunteers</a>
                <a href="/event_registration_field_definitions/list.php?event_id=' . $eventId . '" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Manage Registration Fields</a>';
        
        // Add View Registration Data link if event has registration field definitions
        require_once __DIR__ . '/EventRegistrationFieldDefinitionManagement.php';
        $fieldDefs = EventRegistrationFieldDefinitionManagement::listForEvent($eventId);
        if (!empty($fieldDefs)) {
            $html .= '
                <a href="/event_registration_field_data/view.php?event_id=' . $eventId . '" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;' . ($currentPage === 'registration_data' ? ' background-color: #f5f5f5;' : '') . '">View Registration Data</a>';
        }
        
        if ($rsvpUrl === '') {
            $html .= '
                <a href="#" id="adminCopyEmailsBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Copy Emails</a>
                <a href="#" id="adminCopyEventDetailsBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Copy Event Details</a>
                <a href="#" id="adminManageRsvpBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Manage RSVPs</a>
                <a href="#" id="adminExportAttendeesBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Export Attendees</a>';
            
        }
        
        // Add Key 3 (approvers) links section at the bottom
        if ($showCompliance || $showDietaryNeeds) {
            $html .= '
                <div style="margin-top: 8px; padding: 4px 12px; font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase; border-top: 1px solid #ddd; background-color: #f8f9fa;">Key 3 Links</div>';
            
            if ($showDietaryNeeds) {
                $html .= '
                <a href="/event_dietary_needs.php?id=' . $eventId . '" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;' . ($currentPage === 'dietary' ? ' background-color: #f5f5f5;' : '') . '">Dietary Needs</a>';
            }
            
            if ($showCompliance) {
                $html .= '
                <a href="/event_compliance.php?id=' . $eventId . '" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;' . ($currentPage === 'compliance' ? ' background-color: #f5f5f5;' : '') . '">Event Compliance</a>';
            }
        }
        
        $html .= '
                <a href="#" id="adminDeleteEventBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #dc2626; border-top: 1px solid #ddd;">Delete Event</a>
            </div>
        </div>';
        
        return $html;
    }
    
    /**
     * Render the JavaScript functionality for the admin menu
     * 
     * @param int $eventId The event ID
     * @param array $roles Optional array of volunteer roles for Key 3 signup functionality
     * @return string JavaScript code for admin menu functionality
     */
    public static function renderAdminMenuScript(int $eventId, array $roles = []): string {
        $me = current_user();
        $csrfToken = csrf_token();
        
        // Pre-render role descriptions with markdown for Key 3 signup modal
        require_once __DIR__ . '/Text.php';
        $rolesWithHtml = array_map(function($role) {
            $role['description_html'] = !empty($role['description']) ? Text::renderMarkup((string)$role['description']) : '';
            return $role;
        }, $roles);
        $rolesJson = json_encode($rolesWithHtml);
        
        return '
<script>
(function(){
    // Admin Links Dropdown
    const adminLinksBtn = document.getElementById("adminLinksBtn");
    const adminLinksDropdown = document.getElementById("adminLinksDropdown");
    
    if (adminLinksBtn && adminLinksDropdown) {
        adminLinksBtn.addEventListener("click", function(e) {
            e.preventDefault();
            e.stopPropagation();
            const isVisible = adminLinksDropdown.style.display === "block";
            adminLinksDropdown.style.display = isVisible ? "none" : "block";
        });
        
        // Close dropdown when clicking outside
        document.addEventListener("click", function(e) {
            if (!adminLinksBtn.contains(e.target) && !adminLinksDropdown.contains(e.target)) {
                adminLinksDropdown.style.display = "none";
            }
        });
        
        // Close dropdown when pressing Escape
        document.addEventListener("keydown", function(e) {
            if (e.key === "Escape") {
                adminLinksDropdown.style.display = "none";
            }
        });
        
        // Add hover effects
        const dropdownLinks = adminLinksDropdown.querySelectorAll("a");
        dropdownLinks.forEach(link => {
            link.addEventListener("mouseenter", function() {
                if (!this.style.backgroundColor || this.style.backgroundColor === "white") {
                    this.style.backgroundColor = "#f5f5f5";
                }
            });
            link.addEventListener("mouseleave", function() {
                if (this.style.backgroundColor === "rgb(245, 245, 245)") {
                    this.style.backgroundColor = "white";
                }
            });
        });
    }
    
    // Delete Event functionality
    const deleteEventBtn = document.getElementById("adminDeleteEventBtn");
    if (deleteEventBtn) {
        deleteEventBtn.addEventListener("click", function(e) {
            e.preventDefault();
            if (confirm("Are you sure you want to delete this event? This action cannot be undone.")) {
                const form = document.createElement("form");
                form.method = "POST";
                form.action = "/admin_event_delete.php";
                form.style.display = "none";
                
                const csrfInput = document.createElement("input");
                csrfInput.type = "hidden";
                csrfInput.name = "csrf";
                csrfInput.value = "' . h($csrfToken) . '";
                form.appendChild(csrfInput);
                
                const actionInput = document.createElement("input");
                actionInput.type = "hidden";
                actionInput.name = "action";
                actionInput.value = "delete";
                form.appendChild(actionInput);
                
                const idInput = document.createElement("input");
                idInput.type = "hidden";
                idInput.name = "id";
                idInput.value = "' . $eventId . '";
                form.appendChild(idInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    
    // Export Attendees modal functionality
    const exportAttendeesBtn = document.getElementById("adminExportAttendeesBtn");
    const exportAttendeesModal = document.getElementById("exportAttendeesModal");
    const exportAttendeesModalClose = document.getElementById("exportAttendeesModalClose");
    const copyAttendeesBtnInModal = document.getElementById("copyAttendeesBtn");

    if (exportAttendeesBtn) {
        exportAttendeesBtn.addEventListener("click", function(e) {
            e.preventDefault();
            const modal = document.getElementById("exportAttendeesModal");
            if (modal) {
                modal.classList.remove("hidden");
                modal.setAttribute("aria-hidden", "false");
                loadAttendeesForEvent(); // Load attendee data
            }
        });
    }

    if (exportAttendeesModalClose) {
        exportAttendeesModalClose.addEventListener("click", function() {
            const modal = document.getElementById("exportAttendeesModal");
            if (modal) {
                modal.classList.add("hidden");
                modal.setAttribute("aria-hidden", "true");
            }
        });
    }

    if (copyAttendeesBtnInModal) {
        copyAttendeesBtnInModal.addEventListener("click", function() {
            const attendeesCSV = document.getElementById("attendeesCSV");
            const copyStatus = document.getElementById("copyAttendeesStatus");
            
            if (attendeesCSV) {
                attendeesCSV.select();
                attendeesCSV.setSelectionRange(0, 99999); // For mobile devices
                
                try {
                    navigator.clipboard.writeText(attendeesCSV.value).then(() => {
                        if (copyStatus) {
                            copyStatus.style.display = "inline";
                            setTimeout(() => {
                                copyStatus.style.display = "none";
                            }, 2000);
                        }
                    }).catch(() => {
                        // Fallback for older browsers
                        document.execCommand("copy");
                        if (copyStatus) {
                            copyStatus.style.display = "inline";
                            setTimeout(() => {
                                copyStatus.style.display = "none";
                            }, 2000);
                        }
                    });
                } catch (err) {
                    console.error("Failed to copy attendees:", err);
                }
            }
        });
    }

    // Function to load attendees for the event
    function loadAttendeesForEvent() {
        const attendeesSummary = document.getElementById("attendeesSummary");
        const attendeesCSV = document.getElementById("attendeesCSV");
        
        if (attendeesSummary) {
            attendeesSummary.innerHTML = "<p>Loading attendee data...</p>";
        }
        if (attendeesCSV) {
            attendeesCSV.value = "Loading...";
        }
        
        fetch("/event_attendees_export.php?event_id=' . $eventId . '", {
            headers: { "Accept": "application/json" }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (attendeesSummary) {
                    attendeesSummary.innerHTML = `
                        <p><strong>${data.event_name || "Event"}</strong></p>
                        <p>Adults: ${data.adult_count || 0} | Cub Scouts: ${data.youth_count || 0}</p>
                    `;
                }
                if (attendeesCSV) {
                    attendeesCSV.value = data.csv_data || "";
                }
            } else {
                if (attendeesSummary) {
                    attendeesSummary.innerHTML = "<p class=\"error\">Error loading attendee data: " + (data.error || "Unknown error") + "</p>";
                }
                if (attendeesCSV) {
                    attendeesCSV.value = "Error loading attendee data";
                }
            }
        })
        .catch(error => {
            console.error("Error loading attendees:", error);
            if (attendeesSummary) {
                attendeesSummary.innerHTML = "<p class=\"error\">Error loading attendee data</p>";
            }
            if (attendeesCSV) {
                attendeesCSV.value = "Error loading attendee data";
            }
        });
    }

    // Close export attendees modal on outside click or Escape
    if (exportAttendeesModal) {
        exportAttendeesModal.addEventListener("click", function(e) {
            if (e.target === exportAttendeesModal) {
                exportAttendeesModal.classList.add("hidden");
                exportAttendeesModal.setAttribute("aria-hidden", "true");
            }
        });
    }

    // Copy Emails modal functionality
    const copyEmailsBtn = document.getElementById("adminCopyEmailsBtn");
    const copyEmailsModal = document.getElementById("copyEmailsModal");
    const copyEmailsModalClose = document.getElementById("copyEmailsModalClose");
    const copyEmailsBtnInModal = document.getElementById("copyEmailsBtn");
    const emailFilterRadios = document.querySelectorAll("input[name=\"email_filter\"]");

    if (copyEmailsBtn) {
        copyEmailsBtn.addEventListener("click", function(e) {
            e.preventDefault();
            const modal = document.getElementById("copyEmailsModal");
            if (modal) {
                modal.classList.remove("hidden");
                modal.setAttribute("aria-hidden", "false");
                loadEmailsForEvent(); // Load initial emails
            }
        });
    }

    if (copyEmailsModalClose) {
        copyEmailsModalClose.addEventListener("click", function() {
            const modal = document.getElementById("copyEmailsModal");
            if (modal) {
                modal.classList.add("hidden");
                modal.setAttribute("aria-hidden", "true");
            }
        });
    }

    if (copyEmailsBtnInModal) {
        copyEmailsBtnInModal.addEventListener("click", function() {
            const emailsList = document.getElementById("emailsList");
            const copyStatus = document.getElementById("copyStatus");
            
            if (emailsList) {
                emailsList.select();
                emailsList.setSelectionRange(0, 99999); // For mobile devices
                
                try {
                    navigator.clipboard.writeText(emailsList.value).then(() => {
                        if (copyStatus) {
                            copyStatus.style.display = "inline";
                            setTimeout(() => {
                                copyStatus.style.display = "none";
                            }, 2000);
                        }
                    }).catch(() => {
                        // Fallback for older browsers
                        document.execCommand("copy");
                        if (copyStatus) {
                            copyStatus.style.display = "inline";
                            setTimeout(() => {
                                copyStatus.style.display = "none";
                            }, 2000);
                        }
                    });
                } catch (err) {
                    console.error("Failed to copy emails:", err);
                }
            }
        });
    }

    // Function to load emails for the event
    function loadEmailsForEvent() {
        const filterRadios = document.querySelectorAll("input[name=\"email_filter\"]");
        let filter = "yes";
        
        for (const radio of filterRadios) {
            if (radio.checked) {
                filter = radio.value;
                break;
            }
        }
        
        // Check medical form filter
        const medicalFormCheckbox = document.querySelector("input[name=\"medical_form_filter\"]");
        const medicalFormOnly = medicalFormCheckbox && medicalFormCheckbox.checked ? "1" : "0";
        
        const emailsList = document.getElementById("emailsList");
        if (emailsList) {
            emailsList.value = "Loading...";
        }
        
        fetch("/admin_event_emails.php?event_id=' . $eventId . '&filter=" + filter + "&medical_form_only=" + medicalFormOnly, {
            headers: { "Accept": "application/json" }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && emailsList) {
                emailsList.value = data.emails.join("\\n");
            } else {
                if (emailsList) {
                    emailsList.value = "Error loading emails: " + (data.error || "Unknown error");
                }
            }
        })
        .catch(error => {
            console.error("Error loading emails:", error);
            if (emailsList) {
                emailsList.value = "Error loading emails";
            }
        });
    }

    // Listen for filter changes
    emailFilterRadios.forEach(radio => {
        radio.addEventListener("change", loadEmailsForEvent);
    });

    // Listen for medical form filter changes
    const medicalFormCheckbox = document.querySelector("input[name=\"medical_form_filter\"]");
    if (medicalFormCheckbox) {
        medicalFormCheckbox.addEventListener("change", loadEmailsForEvent);
    }

    // Close modal on outside click or Escape
    if (copyEmailsModal) {
        copyEmailsModal.addEventListener("click", function(e) {
            if (e.target === copyEmailsModal) {
                copyEmailsModal.classList.add("hidden");
                copyEmailsModal.setAttribute("aria-hidden", "true");
            }
        });
    }

    // Manage RSVP modal functionality
    const manageRsvpBtn = document.getElementById("adminManageRsvpBtn");
    const adminRsvpModal = document.getElementById("adminRsvpModal");
    const adminRsvpModalClose = document.getElementById("adminRsvpModalClose");

    if (manageRsvpBtn) {
        manageRsvpBtn.addEventListener("click", function(e) {
            e.preventDefault();
            const modal = document.getElementById("adminRsvpModal");
            if (modal) {
                modal.classList.remove("hidden");
                modal.setAttribute("aria-hidden", "false");
                // Reset to step 1
                document.getElementById("adminRsvpStep1").style.display = "block";
                document.getElementById("adminRsvpStep2").style.display = "none";
                document.getElementById("adminFamilySearch").value = "";
                document.getElementById("adminFamilySearchResults").style.display = "none";
            }
        });
    }

    if (adminRsvpModalClose) {
        adminRsvpModalClose.addEventListener("click", function() {
            const modal = document.getElementById("adminRsvpModal");
            if (modal) {
                modal.classList.add("hidden");
                modal.setAttribute("aria-hidden", "true");
            }
        });
    }

    // Family search functionality for Manage RSVP modal
    const familySearchInput = document.getElementById("adminFamilySearch");
    const familySearchResults = document.getElementById("adminFamilySearchResults");
    const rsvpBackBtn = document.getElementById("adminRsvpBackBtn");

    if (familySearchInput && familySearchResults) {
        let searchTimeout = null;

        familySearchInput.addEventListener("input", function() {
            const query = this.value.trim();
            
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            if (query.length < 2) {
                familySearchResults.style.display = "none";
                return;
            }

            searchTimeout = setTimeout(() => {
                fetch("/admin_family_search.php?q=" + encodeURIComponent(query) + "&limit=20", {
                    headers: { "Accept": "application/json" }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.ok && data.items) {
                        displayFamilySearchResults(data.items);
                    } else {
                        familySearchResults.innerHTML = "<div class=\\"search-result\\">No results found</div>";
                        familySearchResults.style.display = "block";
                    }
                })
                .catch(error => {
                    console.error("Family search error:", error);
                    familySearchResults.innerHTML = "<div class=\\"search-result\\">Search failed</div>";
                    familySearchResults.style.display = "block";
                });
            }, 300);
        });

        function displayFamilySearchResults(items) {
            if (items.length === 0) {
                familySearchResults.innerHTML = "<div class=\\"search-result\\">No results found</div>";
            } else {
                let html = "";
                items.forEach(item => {
                    html += `<div class="search-result" data-person-type="${item.type}" data-person-id="${item.id}" style="padding: 8px; cursor: pointer; border-bottom: 1px solid #eee;">${item.label}</div>`;
                });
                familySearchResults.innerHTML = html;

                // Add click handlers to results
                familySearchResults.querySelectorAll(".search-result").forEach(result => {
                    result.addEventListener("click", function() {
                        const personType = this.getAttribute("data-person-type");
                        const personId = this.getAttribute("data-person-id");
                        if (personType && personId) {
                            loadFamilyRsvpData(personType, personId);
                        }
                    });
                });
            }
            familySearchResults.style.display = "block";
        }

        function loadFamilyRsvpData(personType, personId) {
            // Hide search results and show loading
            familySearchResults.style.display = "none";
            
            fetch(`/admin_rsvp_edit.php?ajax=1&event_id=' . $eventId . '&person_type=${personType}&person_id=${personId}`, {
                headers: { "Accept": "application/json" }
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    populateRsvpForm(data);
                    // Switch to step 2
                    document.getElementById("adminRsvpStep1").style.display = "none";
                    document.getElementById("adminRsvpStep2").style.display = "block";
                } else {
                    alert("Error loading family data: " + (data.error || "Unknown error"));
                }
            })
            .catch(error => {
                console.error("Error loading family RSVP data:", error);
                alert("Error loading family data");
            });
        }

        function populateRsvpForm(data) {
            // Set context adult ID
            document.getElementById("adminContextAdultId").value = data.context_adult_id || "";
            
            // Set selected person name (use first adult or first youth)
            let selectedName = "Unknown";
            if (data.family_adults && data.family_adults.length > 0) {
                const adult = data.family_adults[0];
                selectedName = `${adult.first_name || ""} ${adult.last_name || ""}`.trim();
            } else if (data.family_youth && data.family_youth.length > 0) {
                const youth = data.family_youth[0];
                selectedName = `${youth.first_name || ""} ${youth.last_name || ""}`.trim();
            }
            document.getElementById("adminSelectedPersonName").textContent = selectedName;

            // Set answer radio buttons
            const answerRadios = document.querySelectorAll("input[name=\\"answer_radio\\"]");
            answerRadios.forEach(radio => {
                radio.checked = radio.value === (data.answer || "yes");
            });
            document.getElementById("adminRsvpAnswerInput").value = data.answer || "yes";

            // Populate adults list
            const adultsList = document.getElementById("adminAdultsList");
            if (adultsList && data.family_adults) {
                let adultsHtml = "";
                data.family_adults.forEach(adult => {
                    const checked = data.selected_adults && data.selected_adults.includes(adult.id) ? "checked" : "";
                    const name = `${adult.first_name || ""} ${adult.last_name || ""}`.trim();
                    adultsHtml += `<label class="inline"><input type="checkbox" name="adults[]" value="${adult.id}" ${checked}> ${name}</label>`;
                });
                adultsList.innerHTML = adultsHtml;
            }

            // Populate youth list
            const youthList = document.getElementById("adminYouthList");
            if (youthList && data.family_youth) {
                let youthHtml = "";
                data.family_youth.forEach(youth => {
                    const checked = data.selected_youth && data.selected_youth.includes(youth.id) ? "checked" : "";
                    const name = `${youth.first_name || ""} ${youth.last_name || ""}`.trim();
                    youthHtml += `<label class="inline"><input type="checkbox" name="youth[]" value="${youth.id}" ${checked}> ${name}</label>`;
                });
                youthList.innerHTML = youthHtml;
            }

            // Set other fields
            document.getElementById("adminNGuests").value = data.n_guests || 0;
            document.getElementById("adminComments").value = data.comments || "";

            // Update answer input when radio buttons change
            answerRadios.forEach(radio => {
                radio.addEventListener("change", function() {
                    if (this.checked) {
                        document.getElementById("adminRsvpAnswerInput").value = this.value;
                    }
                });
            });
        }
    }

    if (rsvpBackBtn) {
        rsvpBackBtn.addEventListener("click", function() {
            // Switch back to step 1
            document.getElementById("adminRsvpStep1").style.display = "block";
            document.getElementById("adminRsvpStep2").style.display = "none";
            document.getElementById("adminFamilySearch").value = "";
            document.getElementById("adminFamilySearchResults").style.display = "none";
        });
    }

    // Copy Event Details modal functionality
    const copyEventDetailsBtn = document.getElementById("adminCopyEventDetailsBtn");
    const copyEventDetailsModal = document.getElementById("copyEventDetailsModal");
    const copyEventDetailsModalClose = document.getElementById("copyEventDetailsModalClose");
    const copyEventDetailsCloseBtn = document.getElementById("copyEventDetailsCloseBtn");
    const copyEventDetailsTextBtn = document.getElementById("copyEventDetailsTextBtn");
    const eventDetailsText = document.getElementById("eventDetailsText");
    const copyEventDetailsStatus = document.getElementById("copyEventDetailsStatus");

    if (copyEventDetailsBtn) {
        copyEventDetailsBtn.addEventListener("click", function(e) {
            e.preventDefault();
            const modal = document.getElementById("copyEventDetailsModal");
            if (modal) {
                modal.classList.remove("hidden");
                modal.setAttribute("aria-hidden", "false");
                if (eventDetailsText) eventDetailsText.focus();
            }
        });
    }

    if (copyEventDetailsModalClose) {
        copyEventDetailsModalClose.addEventListener("click", function() {
            const modal = document.getElementById("copyEventDetailsModal");
            if (modal) {
                modal.classList.add("hidden");
                modal.setAttribute("aria-hidden", "true");
                if (copyEventDetailsStatus) copyEventDetailsStatus.style.display = "none";
            }
        });
    }

    if (copyEventDetailsCloseBtn) {
        copyEventDetailsCloseBtn.addEventListener("click", function() {
            const modal = document.getElementById("copyEventDetailsModal");
            if (modal) {
                modal.classList.add("hidden");
                modal.setAttribute("aria-hidden", "true");
                if (copyEventDetailsStatus) copyEventDetailsStatus.style.display = "none";
            }
        });
    }

    if (copyEventDetailsTextBtn && eventDetailsText) {
        copyEventDetailsTextBtn.addEventListener("click", function() {
            try {
                // Modern approach
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(eventDetailsText.value).then(function() {
                        showEventDetailsCopySuccess();
                    }).catch(function() {
                        fallbackEventDetailsCopy();
                    });
                } else {
                    fallbackEventDetailsCopy();
                }
            } catch (e) {
                fallbackEventDetailsCopy();
            }
        });
    }

    function fallbackEventDetailsCopy() {
        try {
            eventDetailsText.select();
            eventDetailsText.setSelectionRange(0, 99999); // For mobile devices
            document.execCommand("copy");
            showEventDetailsCopySuccess();
        } catch (e) {
            alert("Copy failed. Please manually select and copy the text.");
        }
    }

    function showEventDetailsCopySuccess() {
        if (copyEventDetailsStatus) {
            copyEventDetailsStatus.style.display = "block";
            setTimeout(function() {
                if (copyEventDetailsStatus) copyEventDetailsStatus.style.display = "none";
            }, 3000);
        }
    }

    // Close event details modal on outside click
    if (copyEventDetailsModal) {
        copyEventDetailsModal.addEventListener("click", function(e) {
            if (e.target === copyEventDetailsModal) {
                copyEventDetailsModal.classList.add("hidden");
                copyEventDetailsModal.setAttribute("aria-hidden", "true");
                if (copyEventDetailsStatus) copyEventDetailsStatus.style.display = "none";
            }
        });
    }

    // Close modals on outside click or Escape
    document.addEventListener("keydown", function(e) {
        if (e.key === "Escape") {
            if (copyEmailsModal && !copyEmailsModal.classList.contains("hidden")) {
                copyEmailsModal.classList.add("hidden");
                copyEmailsModal.setAttribute("aria-hidden", "true");
            }
            if (exportAttendeesModal && !exportAttendeesModal.classList.contains("hidden")) {
                exportAttendeesModal.classList.add("hidden");
                exportAttendeesModal.setAttribute("aria-hidden", "true");
            }
            if (adminRsvpModal && !adminRsvpModal.classList.contains("hidden")) {
                adminRsvpModal.classList.add("hidden");
                adminRsvpModal.setAttribute("aria-hidden", "true");
            }
            if (copyEventDetailsModal && !copyEventDetailsModal.classList.contains("hidden")) {
                copyEventDetailsModal.classList.add("hidden");
                copyEventDetailsModal.setAttribute("aria-hidden", "true");
                if (copyEventDetailsStatus) copyEventDetailsStatus.style.display = "none";
            }
        }
    });
    
    // Key 3 Admin Volunteer Signup modal functionality
    const key3SignupModal = document.getElementById("key3SignupModal");
    const key3SignupModalClose = document.getElementById("key3SignupModalClose");
    const key3SignupCancelBtn = document.getElementById("key3SignupCancelBtn");
    const key3SignupConfirmBtn = document.getElementById("key3SignupConfirmBtn");
    const key3AdultSearch = document.getElementById("key3AdultSearch");
    const key3AdultSearchResults = document.getElementById("key3AdultSearchResults");
    const key3SignupComment = document.getElementById("key3SignupComment");
    const key3SignupStatus = document.getElementById("key3SignupStatus");
    
    let selectedRoleId = null;
    let selectedAdultId = null;
    let isEditMode = false;
    
    // Function to check if selected adult is already signed up for the selected role
    function checkExistingSignup() {
        if (!selectedRoleId || !selectedAdultId) {
            // Reset to signup mode if either is missing
            isEditMode = false;
            updateButtonState();
            return;
        }
        
        // Make AJAX request to check for existing signup
        const fd = new FormData();
        fd.append("csrf", "' . h($csrfToken) . '");
        fd.append("event_id", "' . $eventId . '");
        fd.append("role_id", selectedRoleId);
        fd.append("user_id", selectedAdultId);
        
        fetch("/volunteer_check_existing_signup.php", {
            method: "POST",
            body: fd,
            credentials: "same-origin"
        })
        .then(response => response.json())
        .then(data => {
            if (data.ok) {
                if (data.exists) {
                    // Signup exists - switch to edit mode
                    isEditMode = true;
                    key3SignupComment.value = data.comment || "";
                    updateButtonState();
                } else {
                    // No signup - stay in signup mode
                    isEditMode = false;
                    key3SignupComment.value = "";
                    updateButtonState();
                }
            }
        })
        .catch(error => {
            console.error("Error checking existing signup:", error);
        });
    }
    
    // Function to update button text based on mode
    function updateButtonState() {
        const confirmBtn = document.getElementById("key3SignupConfirmBtn");
        if (confirmBtn) {
            confirmBtn.textContent = isEditMode ? "Edit Signup" : "Confirm";
        }
        
        // Also update remove button visibility
        updateRemoveButtonVisibility();
    }
    
    // Handle clicking on Key 3 signup links
    document.addEventListener("click", function(e) {
        if (e.target.classList.contains("key3-signup-link")) {
            e.preventDefault();
            selectedRoleId = e.target.getAttribute("data-role-id");
            const roleTitle = e.target.getAttribute("data-role-title");
            const roleDescription = e.target.getAttribute("data-role-description");
            
            // Update modal title
            document.getElementById("key3SignupModalTitle").textContent = "Sign up someone for " + roleTitle;
            
            // Render description with markdown support
            const descDiv = document.getElementById("key3RoleDescription");
            if (roleDescription && roleDescription.trim() !== "") {
                // Find the role in the roles data to get the pre-rendered HTML
                const rolesData = ' . $rolesJson . ';
                
                const roleData = rolesData.find(r => r.id == selectedRoleId);
                if (roleData && roleData.description_html) {
                    descDiv.innerHTML = roleData.description_html;
                    descDiv.style.display = "block";
                } else if (roleDescription) {
                    descDiv.textContent = roleDescription;
                    descDiv.style.display = "block";
                } else {
                    descDiv.style.display = "none";
                }
            } else {
                descDiv.style.display = "none";
            }
            
            // Reset form
            key3AdultSearch.value = "";
            key3AdultSearchResults.style.display = "none";
            key3SignupComment.value = "";
            key3SignupStatus.innerHTML = "";
            selectedAdultId = null;
            isEditMode = false;
            updateButtonState();
            
            // Show modal
            key3SignupModal.classList.remove("hidden");
            key3SignupModal.setAttribute("aria-hidden", "false");
            key3AdultSearch.focus();
        }
    });
    
    // Close modal handlers
    if (key3SignupModalClose) {
        key3SignupModalClose.addEventListener("click", function() {
            key3SignupModal.classList.add("hidden");
            key3SignupModal.setAttribute("aria-hidden", "true");
        });
    }
    
    if (key3SignupCancelBtn) {
        key3SignupCancelBtn.addEventListener("click", function() {
            key3SignupModal.classList.add("hidden");
            key3SignupModal.setAttribute("aria-hidden", "true");
        });
    }
    
    // Function to check if selected adult is already signed up for the selected role
    function updateRemoveButtonVisibility() {
        const removeBtn = document.getElementById("key3SignupRemoveBtn");
        if (!removeBtn) return;
        
        if (!selectedRoleId || !selectedAdultId) {
            removeBtn.style.display = "none";
            return;
        }
        
        // Find the role in roles data
        const rolesData = ' . $rolesJson . ';
        const roleData = rolesData.find(r => r.id == selectedRoleId);
        
        if (!roleData || !roleData.volunteers) {
            removeBtn.style.display = "none";
            return;
        }
        
        // Check if selectedAdultId is in the volunteers list
        const isSignedUp = roleData.volunteers.some(v => v.user_id == selectedAdultId);
        
        if (isSignedUp) {
            removeBtn.style.display = "block";
        } else {
            removeBtn.style.display = "none";
        }
    }
    
    // Adult search functionality
    if (key3AdultSearch && key3AdultSearchResults) {
        let searchTimeout = null;
        
        key3AdultSearch.addEventListener("input", function() {
            const query = this.value.trim();
            
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            if (query.length < 2) {
                key3AdultSearchResults.style.display = "none";
                selectedAdultId = null;
                updateRemoveButtonVisibility();
                return;
            }
            
            searchTimeout = setTimeout(() => {
                fetch("/ajax_search_adults.php?q=" + encodeURIComponent(query), {
                    headers: { "Accept": "application/json" }
                })
                .then(response => response.json())
                .then(data => {
                    // ajax_search_adults.php returns an array directly
                    if (Array.isArray(data) && data.length > 0) {
                        displayKey3AdultSearchResults(data);
                    } else {
                        key3AdultSearchResults.innerHTML = "<div class=\\"search-result\\">No results found</div>";
                        key3AdultSearchResults.style.display = "block";
                    }
                })
                .catch(error => {
                    console.error("Adult search error:", error);
                    key3AdultSearchResults.innerHTML = "<div class=\\"search-result\\">Search failed</div>";
                    key3AdultSearchResults.style.display = "block";
                });
            }, 300);
        });
        
        function displayKey3AdultSearchResults(items) {
            if (items.length === 0) {
                key3AdultSearchResults.innerHTML = "<div class=\\"search-result\\">No results found</div>";
            } else {
                let html = "";
                items.forEach(item => {
                    // Format the display name
                    const name = `${item.first_name || ""} ${item.last_name || ""}`.trim();
                    const email = item.email || "";
                    const label = email ? `${name} (${email})` : name;
                    
                    html += `<div class="search-result" data-adult-id="${item.id}" style="padding: 8px; cursor: pointer; border-bottom: 1px solid #eee;">${label}</div>`;
                });
                key3AdultSearchResults.innerHTML = html;
                
                // Add click handlers to results
                key3AdultSearchResults.querySelectorAll(".search-result").forEach(result => {
                    result.addEventListener("click", function() {
                        selectedAdultId = this.getAttribute("data-adult-id");
                        key3AdultSearch.value = this.textContent;
                        key3AdultSearchResults.style.display = "none";
                        
                        // Check for existing signup when user is selected
                        checkExistingSignup();
                    });
                });
            }
            key3AdultSearchResults.style.display = "block";
        }
    }
    
    // Remove button handler
    const key3SignupRemoveBtn = document.getElementById("key3SignupRemoveBtn");
    if (key3SignupRemoveBtn) {
        key3SignupRemoveBtn.addEventListener("click", function() {
            if (!selectedRoleId || !selectedAdultId) {
                key3SignupStatus.innerHTML = "<p class=\\"error\\">Please select a user.</p>";
                return;
            }
            
            // Get the selected user name from the search input
            const userName = key3AdultSearch.value;
            
            if (!confirm("Are you sure you want to remove " + userName + " from this role?")) {
                return;
            }
            
            // Disable button during request
            key3SignupRemoveBtn.disabled = true;
            key3SignupStatus.innerHTML = "<p>Processing...</p>";
            
            // Send AJAX request
            const fd = new FormData();
            fd.append("csrf", "' . h($csrfToken) . '");
            fd.append("event_id", "' . $eventId . '");
            fd.append("role_id", selectedRoleId);
            fd.append("user_id", selectedAdultId);
            fd.append("action", "remove");
            
            fetch("/volunteer_admin_signup.php", {
                method: "POST",
                body: fd,
                credentials: "same-origin"
            })
            .then(response => response.json())
            .then(data => {
                key3SignupRemoveBtn.disabled = false;
                
                if (data.ok) {
                    key3SignupStatus.innerHTML = "<p style=\\"color: green;\\">" + (data.message || "Successfully removed!") + "</p>";
                    
                    // Reload page after a short delay to show updated volunteers
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    key3SignupStatus.innerHTML = "<p class=\\"error\\">" + (data.error || "Failed to remove.") + "</p>";
                }
            })
            .catch(error => {
                console.error("Key 3 remove error:", error);
                key3SignupRemoveBtn.disabled = false;
                key3SignupStatus.innerHTML = "<p class=\\"error\\">Network error. Please try again.</p>";
            });
        });
    }
    
    // Confirm button handler
    if (key3SignupConfirmBtn) {
        key3SignupConfirmBtn.addEventListener("click", function() {
            if (!selectedRoleId) {
                key3SignupStatus.innerHTML = "<p class=\\"error\\">No role selected.</p>";
                return;
            }
            
            if (!selectedAdultId) {
                key3SignupStatus.innerHTML = "<p class=\\"error\\">Please select an adult from the search results.</p>";
                return;
            }
            
            const comment = key3SignupComment.value.trim();
            
            // Disable button during request
            key3SignupConfirmBtn.disabled = true;
            key3SignupStatus.innerHTML = "<p>Processing...</p>";
            
            // Send AJAX request
            const fd = new FormData();
            fd.append("csrf", "' . h($csrfToken) . '");
            fd.append("event_id", "' . $eventId . '");
            fd.append("role_id", selectedRoleId);
            fd.append("user_id", selectedAdultId);
            fd.append("action", isEditMode ? "update_comment" : "signup");
            if (comment !== "") {
                fd.append("comment", comment);
            }
            
            fetch("/volunteer_admin_signup.php", {
                method: "POST",
                body: fd,
                credentials: "same-origin"
            })
            .then(response => response.json())
            .then(data => {
                key3SignupConfirmBtn.disabled = false;
                
                if (data.ok) {
                    key3SignupStatus.innerHTML = "<p style=\\"color: green;\\">" + (data.message || "Successfully signed up!") + "</p>";
                    
                    // Reload page after a short delay to show updated volunteers
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    key3SignupStatus.innerHTML = "<p class=\\"error\\">" + (data.error || "Failed to sign up.") + "</p>";
                }
            })
            .catch(error => {
                console.error("Key 3 signup error:", error);
                key3SignupConfirmBtn.disabled = false;
                key3SignupStatus.innerHTML = "<p class=\\"error\\">Network error. Please try again.</p>";
            });
        });
    }
    
    // Close modal on outside click
    if (key3SignupModal) {
        key3SignupModal.addEventListener("click", function(e) {
            if (e.target === key3SignupModal) {
                key3SignupModal.classList.add("hidden");
                key3SignupModal.setAttribute("aria-hidden", "true");
            }
        });
    }
})();
</script>';
    }
    
    /**
     * Render the modal HTML structures needed for admin functionality
     * 
     * @param int $eventId The event ID
     * @return string HTML content for modals
     */
    public static function renderAdminModals(int $eventId): string {
        $me = current_user();
        $csrfToken = csrf_token();
        
        // Load event for modal content
        $event = null;
        try {
            $event = EventManagement::findById($eventId);
        } catch (Throwable $e) {
            // Handle error gracefully
        }
        
        $eventName = $event ? h($event['name']) : 'Event';
        $needsMedicalForm = !empty($event['needs_medical_form']);
        
        return '
<!-- Copy Event Details Modal -->
<div id="copyEventDetailsModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
        <button class="close" type="button" id="copyEventDetailsModalClose" aria-label="Close">&times;</button>
        <h3>Event Details for Email</h3>
        <div style="margin-bottom: 16px;">
            <textarea id="eventDetailsText" rows="12" style="width: 100%; font-family: monospace; font-size: 14px;" readonly>' .
                ($event ? self::generateEventDetailsText($event) : '') .
            '</textarea>
        </div>
        <div class="actions">
            <button type="button" class="button primary" id="copyEventDetailsTextBtn">Copy to Clipboard</button>
            <button type="button" class="button" id="copyEventDetailsCloseBtn">Close</button>
        </div>
        <div id="copyEventDetailsStatus" style="margin-top: 8px; font-size: 14px; color: #28a745; display: none;">
            ✓ Copied to clipboard!
        </div>
    </div>
</div>

<!-- Copy Emails modal -->
<div id="copyEmailsModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
        <button class="close" type="button" id="copyEmailsModalClose" aria-label="Close">&times;</button>
        <h3>Copy Emails for ' . $eventName . '</h3>
        
        <div class="stack">
            <p>Select which RSVPs to include:</p>
            <div>
                <label class="inline">
                    <input type="radio" name="email_filter" value="yes" checked>
                    Yes only
                </label>
                <label class="inline">
                    <input type="radio" name="email_filter" value="yes_maybe">
                    Yes and Maybe
                </label>
            </div>
            
            ' . ($needsMedicalForm ? '
            <div style="margin-top: 12px;">
                <label class="inline">
                    <input type="checkbox" name="medical_form_filter" value="1">
                    Needs Medical Form Only
                </label>
                <p class="small">Only include families where at least one member needs a medical form</p>
            </div>
            ' : '') . '
            
            <div style="margin-top: 16px;">
                <label>Email addresses (one per line):
                    <textarea id="emailsList" rows="10" readonly style="font-family: monospace; font-size: 12px;"></textarea>
                </label>
                <div style="margin-top: 8px;">
                    <button type="button" id="copyEmailsBtn" class="button primary">Copy to Clipboard</button>
                    <span id="copyStatus" style="margin-left: 8px; color: green; display: none;">Copied!</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Export Attendees modal -->
<div id="exportAttendeesModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
        <button class="close" type="button" id="exportAttendeesModalClose" aria-label="Close">&times;</button>
        <h3>Attendees</h3>
        
        <div class="stack">
            <div id="attendeesSummary" style="margin-bottom: 16px;">
                <p>Loading attendee data...</p>
            </div>
            
            <div>
                <label>CSV Export Data:
                    <textarea id="attendeesCSV" rows="15" readonly style="font-family: monospace; font-size: 12px; width: 100%;"></textarea>
                </label>
                <div style="margin-top: 8px;">
                    <button type="button" id="copyAttendeesBtn" class="button primary">Copy to Clipboard</button>
                    <span id="copyAttendeesStatus" style="margin-left: 8px; color: green; display: none;">Copied!</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Admin RSVP Management modal -->
<div id="adminRsvpModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
        <button class="close" type="button" id="adminRsvpModalClose" aria-label="Close">&times;</button>
        <h3>Manage RSVP for ' . $eventName . '</h3>
        
        <!-- Step 1: Search for family member -->
        <div id="adminRsvpStep1" class="stack">
            <label>Search for adult or child:
                <input type="text" id="adminFamilySearch" placeholder="Type name to search adults and children" autocomplete="off">
                <div id="adminFamilySearchResults" class="typeahead-results" style="display:none;"></div>
            </label>
        </div>
        
        <!-- Step 2: RSVP form (hidden initially) -->
        <div id="adminRsvpStep2" class="stack" style="display:none;">
            <form method="post" class="stack" action="/admin_rsvp_edit.php">
                <input type="hidden" name="csrf" value="' . h($csrfToken) . '">
                <input type="hidden" name="event_id" value="' . $eventId . '">
                <input type="hidden" name="context_adult_id" id="adminContextAdultId" value="">
                <input type="hidden" name="answer" id="adminRsvpAnswerInput" value="yes">

                <div style="margin-bottom: 16px;">
                    <strong>RSVP for: <span id="adminSelectedPersonName"></span></strong>
                    <div style="margin-top: 8px;">
                        <label class="inline">
                            <input type="radio" name="answer_radio" value="yes" checked>
                            Yes
                        </label>
                        <label class="inline">
                            <input type="radio" name="answer_radio" value="maybe">
                            Maybe
                        </label>
                        <label class="inline">
                            <input type="radio" name="answer_radio" value="no">
                            No
                        </label>
                    </div>
                </div>

                <h4>Adults</h4>
                <div id="adminAdultsList"></div>

                <h4>Children</h4>
                <div id="adminYouthList"></div>

                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;align-items:start;">
                    <label>Number of other guests
                        <input type="number" name="n_guests" id="adminNGuests" value="0" min="0">
                    </label>
                </div>

                <label>Comments
                    <textarea name="comments" id="adminComments" rows="3"></textarea>
                </label>

                <div class="actions">
                    <button type="submit" class="primary">Save RSVP</button>
                    <button type="button" id="adminRsvpBackBtn" class="button">Back to Search</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Key 3 Admin Volunteer Signup modal -->
<div id="key3SignupModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
        <button class="close" type="button" id="key3SignupModalClose" aria-label="Close">&times;</button>
        <h3 id="key3SignupModalTitle">Sign up someone for Role</h3>
        
        <div class="stack">
            <div id="key3RoleDescription" style="margin-bottom: 16px;"></div>
            
            <label>Select an adult:
                <input type="text" id="key3AdultSearch" placeholder="Type name to search" autocomplete="off">
                <div id="key3AdultSearchResults" class="typeahead-results" style="display:none;"></div>
            </label>
            
            <label>Would you like to add a comment about the sign-up?
                <textarea id="key3SignupComment" rows="3" placeholder="Optional comment..."></textarea>
            </label>
            
            <div class="actions" style="display: flex; justify-content: space-between;">
                <div style="display: flex; gap: 8px;">
                    <button type="button" id="key3SignupConfirmBtn" class="button primary">Confirm</button>
                    <button type="button" id="key3SignupCancelBtn" class="button">Cancel</button>
                </div>
                <button type="button" id="key3SignupRemoveBtn" class="button" style="background-color: #dc2626; color: white; display: none;">Remove</button>
            </div>
            
            <div id="key3SignupStatus" style="margin-top: 8px;"></div>
        </div>
    </div>
</div>';
    }
    
    /**
     * Render the complete volunteers card HTML (including heading and success messages)
     * 
     * @param array $roles Array of volunteer roles with counts
     * @param bool $hasYes Whether the user has RSVP'd yes
     * @param int $actingUserId The current user's ID
     * @param int $eventId The event ID
     * @param bool $isAdmin Whether the user is an admin
     * @param string|null $successMessage Optional success message to display
     * @param int|null $inviteUid Optional invite user ID for email token auth
     * @param string|null $inviteSig Optional invite signature for email token auth
     * @return string HTML content for complete volunteers card
     */
    public static function renderVolunteersCard(array $roles, bool $hasYes, int $actingUserId, int $eventId, bool $isAdmin, ?string $successMessage = null, ?int $inviteUid = null, ?string $inviteSig = null): string {
        if (!$isAdmin && empty($roles)) {
            return '';
        }
        
        $html = '<div class="card" id="volunteersCard">';
        $html .= '<h3>Event Volunteers</h3>';
        
        // Add success message if provided
        if ($successMessage !== null) {
            $html .= '<div class="flash" style="margin-bottom:16px;">' . h($successMessage) . '</div>';
        }
        
        $html .= self::renderVolunteersSection($roles, $hasYes, $actingUserId, $eventId, $isAdmin, null, $inviteUid, $inviteSig);
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render the volunteers section HTML (without card wrapper)
     * 
     * @param array $roles Array of volunteer roles with counts
     * @param bool $hasYes Whether the user has RSVP'd yes
     * @param int $actingUserId The current user's ID
     * @param int $eventId The event ID
     * @param bool $isAdmin Whether the user is an admin
     * @param string|null $successMessage Optional success message to display (deprecated, use renderVolunteersCard)
     * @param int|null $inviteUid Optional invite user ID for email token auth
     * @param string|null $inviteSig Optional invite signature for email token auth
     * @return string HTML content for volunteers section
     */
    public static function renderVolunteersSection(array $roles, bool $hasYes, int $actingUserId, int $eventId, bool $isAdmin, ?string $successMessage = null, ?int $inviteUid = null, ?string $inviteSig = null): string {
        require_once __DIR__ . '/Text.php';
        
        if (!$isAdmin && empty($roles)) {
            return '';
        }
        
        $html = '<div class="volunteers">';
        
        if (empty($roles)) {
            $html .= '<p class="small">No volunteer roles have been defined for this event.</p>';
            if ($isAdmin) {
                $html .= '<a class="button" href="/admin_event_volunteers.php?event_id=' . (int)$eventId . '">Manager volunteer roles</a>';
            }
        } else {
            foreach ($roles as $r) {
                $html .= '<div class="role" style="margin-bottom:10px;">';
                
                // Check if user is already signed up for this role
                $amIn = false;
                if ($hasYes) {
                    foreach ($r['volunteers'] as $v) {
                        if ((int)$v['user_id'] === (int)$actingUserId) {
                            $amIn = true;
                            break;
                        }
                    }
                }
                
                // Title line with sign-up button on the right
                $html .= '<div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">';
                $html .= '<div>';
                $html .= '<strong>' . h($r['title']) . '</strong> ';
                
                if (!empty($r['is_unlimited'])) {
                    $html .= '<span class="remaining small">(no limit)</span>';
                } elseif ((int)$r['open_count'] > 0) {
                    $html .= '<span class="remaining small">(' . (int)$r['open_count'] . ' people still needed)</span>';
                } else {
                    $html .= '<span class="filled small">Filled</span>';
                }
                
                $html .= '</div>';
                
                // Right side: Admin Signup link + Sign-up button
                $html .= '<div style="display: flex; align-items: center; gap: 8px;">';
                
                // Add Key 3 admin signup link if user has Key 3 permissions (event.php only, no invite params)
                if ($inviteUid === null && $inviteSig === null && self::isKey3User()) {
                    $html .= '<a href="#" class="button primary key3-signup-link" data-role-id="' . (int)$r['id'] . '" data-role-title="' . h($r['title']) . '" data-role-description="' . h($r['description']) . '" style="white-space: nowrap;">Admin Signup</a>';
                }
                
                // Add sign-up button if applicable
                if ($hasYes && !$amIn) {
                    $html .= '<form method="post" action="/volunteer_actions.php" class="inline" style="margin: 0;">';
                    $html .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                    $html .= '<input type="hidden" name="event_id" value="' . (int)$eventId . '">';
                    $html .= '<input type="hidden" name="role_id" value="' . (int)$r['id'] . '">';
                    // Add invite auth params if present
                    if ($inviteUid !== null && $inviteSig !== null) {
                        $html .= '<input type="hidden" name="uid" value="' . (int)$inviteUid . '">';
                        $html .= '<input type="hidden" name="sig" value="' . h($inviteSig) . '">';
                    }
                    
                    if (!empty($r['is_unlimited']) || (int)$r['open_count'] > 0) {
                        $html .= '<input type="hidden" name="action" value="signup">';
                        $html .= '<button class="button primary" style="white-space: nowrap;">Sign up</button>';
                    } else {
                        $html .= '<button class="button" disabled style="white-space: nowrap;">Filled</button>';
                    }
                    
                    $html .= '</form>';
                }
                
                $html .= '</div>'; // close right side container
                $html .= '</div>'; // close main flex container
                
                if (trim((string)($r['description'] ?? '')) !== '') {
                    $html .= '<div style="margin-top:4px;">' . Text::renderMarkup((string)$r['description']) . '</div>';
                }
                
                if (!empty($r['volunteers'])) {
                    $html .= '<ul style="margin:6px 0 0 16px;">';
                    foreach ($r['volunteers'] as $v) {
                        $html .= '<li>';
                        $html .= h($v['name']);
                        
                        if (!empty($v['comment'])) {
                            $html .= '<span class="small" style="font-style:italic;"> "' . h($v['comment']) . '"</span>';
                        }
                        
                        if ((int)($v['user_id'] ?? 0) === (int)$actingUserId) {
                            $html .= '<a href="#" class="volunteer-edit-comment-link small" data-role-id="' . (int)$r['id'] . '" data-role-title="' . h($r['title']) . '" data-comment="' . h($v['comment']) . '">(edit comment)</a>';
                            $html .= '<form method="post" action="/volunteer_actions.php" class="inline" style="display:inline;">';
                            $html .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                            $html .= '<input type="hidden" name="event_id" value="' . (int)$eventId . '">';
                            $html .= '<input type="hidden" name="role_id" value="' . (int)$r['id'] . '">';
                            $html .= '<input type="hidden" name="action" value="remove">';
                            // Add invite auth params if present
                            if ($inviteUid !== null && $inviteSig !== null) {
                                $html .= '<input type="hidden" name="uid" value="' . (int)$inviteUid . '">';
                                $html .= '<input type="hidden" name="sig" value="' . h($inviteSig) . '">';
                            }
                            $html .= '<a href="#" class="volunteer-remove-link small">(remove)</a>';
                            $html .= '</form>';
                        }
                        
                        $html .= '</li>';
                    }
                    $html .= '</ul>';
                } else {
                    $html .= '<ul style="margin:6px 0 0 16px;"><li>No one yet.</li></ul>';
                }
                
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Render the volunteer prompt modal for event pages
     * 
     * @param array $roles Array of volunteer roles with counts
     * @param int $actingUserId The current user's ID
     * @param int $eventId The event ID
     * @param bool $showModal Whether to show the modal on page load
     * @param int|null $inviteUid Optional invite user ID for email token auth
     * @param string|null $inviteSig Optional invite signature for email token auth
     * @return string HTML content for volunteer modal
     */
    public static function renderVolunteerModal(array $roles, int $actingUserId, int $eventId, bool $showModal = false, ?int $inviteUid = null, ?string $inviteSig = null): string {
        require_once __DIR__ . '/Text.php';
        
        $html = '<!-- Volunteer prompt modal -->';
        $html .= '<div id="volunteerModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">';
        $html .= '<div class="modal-content">';
        $html .= '<button class="close" type="button" id="volunteerModalClose" aria-label="Close">&times;</button>';
        $html .= '<h3>Volunteer to help at this event?</h3>';
        $html .= '<div id="volRoles" class="stack">';
        
        foreach ($roles as $r) {
            $amIn = false;
            foreach ($r['volunteers'] as $v) {
                if ((int)$v['user_id'] === (int)$actingUserId) {
                    $amIn = true;
                    break;
                }
            }
            
            $html .= '<div class="role" style="margin-bottom:14px;">';
            
            // Title line with sign-up button on the right
            $html .= '<div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">';
            $html .= '<div>';
            $html .= '<strong>' . h($r['title']) . '</strong> ';
            
            if (!empty($r['is_unlimited'])) {
                $html .= '<span class="remaining">(no limit)</span>';
            } elseif ((int)$r['open_count'] > 0) {
                $html .= '<span class="remaining">(' . (int)$r['open_count'] . ' people still needed)</span>';
            } else {
                $html .= '<span class="filled">Filled</span>';
            }
            
            $html .= '</div>';
            
            // Add sign-up button
            if (!$amIn) {
                $html .= '<form method="post" action="/volunteer_actions.php" class="inline" style="margin: 0;">';
                $html .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                $html .= '<input type="hidden" name="event_id" value="' . (int)$eventId . '">';
                $html .= '<input type="hidden" name="role_id" value="' . (int)$r['id'] . '">';
                
                // Add invite auth params if present
                if ($inviteUid !== null && $inviteSig !== null) {
                    $html .= '<input type="hidden" name="uid" value="' . (int)$inviteUid . '">';
                    $html .= '<input type="hidden" name="sig" value="' . h($inviteSig) . '">';
                }
                
                if (!empty($r['is_unlimited']) || (int)$r['open_count'] > 0) {
                    $html .= '<input type="hidden" name="action" value="signup">';
                    $html .= '<button class="button primary" style="white-space: nowrap;">Sign up</button>';
                } else {
                    $html .= '<button class="button" disabled style="white-space: nowrap;">Filled</button>';
                }
                
                $html .= '</form>';
            }
            
            $html .= '</div>';
            
            // Render description with markdown
            if (trim((string)($r['description'] ?? '')) !== '') {
                $html .= '<div style="margin-top:4px;">' . Text::renderMarkup((string)$r['description']) . '</div>';
            }
            
            // Render volunteers list
            if (!empty($r['volunteers'])) {
                $html .= '<ul style="margin:6px 0 0 16px;">';
                foreach ($r['volunteers'] as $v) {
                    $html .= '<li>';
                    $html .= h($v['name']);
                    
                    if (!empty($v['comment'])) {
                        $html .= '<span class="small" style="font-style:italic;"> "' . h($v['comment']) . '"</span>';
                    }
                    
                    if ((int)($v['user_id'] ?? 0) === (int)$actingUserId) {
                        $html .= '<form method="post" action="/volunteer_actions.php" class="inline" style="display:inline;">';
                        $html .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                        $html .= '<input type="hidden" name="event_id" value="' . (int)$eventId . '">';
                        $html .= '<input type="hidden" name="role_id" value="' . (int)$r['id'] . '">';
                        $html .= '<input type="hidden" name="action" value="remove">';
                        
                        // Add invite auth params if present
                        if ($inviteUid !== null && $inviteSig !== null) {
                            $html .= '<input type="hidden" name="uid" value="' . (int)$inviteUid . '">';
                            $html .= '<input type="hidden" name="sig" value="' . h($inviteSig) . '">';
                        }
                        
                        $html .= '<a href="#" class="small" onclick="this.closest(\'form\').requestSubmit(); return false;">(remove)</a>';
                        $html .= '</form>';
                    }
                    
                    $html .= '</li>';
                }
                $html .= '</ul>';
            } else {
                $html .= '<ul style="margin:6px 0 0 16px;"><li>No one yet.</li></ul>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>'; // close volRoles
        
        $html .= '<div class="actions" style="margin-top:10px;">';
        $html .= '<button class="button" id="volunteerMaybeLater">Return to Event</button>';
        $html .= '</div>';
        
        $html .= '</div>'; // close modal-content
        $html .= '</div>'; // close modal
        
        // Add JavaScript for modal functionality
        $html .= self::renderVolunteerModalScript($actingUserId, $eventId, $showModal, $inviteUid, $inviteSig);
        
        return $html;
    }
    
    /**
     * Render the JavaScript for the volunteer modal
     * 
     * @param int $actingUserId The current user's ID
     * @param int $eventId The event ID
     * @param bool $showModal Whether to show the modal on page load
     * @param int|null $inviteUid Optional invite user ID for email token auth
     * @param string|null $inviteSig Optional invite signature for email token auth
     * @return string JavaScript code for volunteer modal
     */
    private static function renderVolunteerModalScript(int $actingUserId, int $eventId, bool $showModal, ?int $inviteUid, ?string $inviteSig): string {
        $sigJs = $inviteSig !== null ? json_encode($inviteSig) : 'null';
        
        $script = '<script>' . "\n";
        $script .= '(function(){' . "\n";
        $script .= 'const modal = document.getElementById("volunteerModal");' . "\n";
        $script .= 'const closeBtn = document.getElementById("volunteerModalClose");' . "\n";
        $script .= 'const laterBtn = document.getElementById("volunteerMaybeLater");' . "\n";
        $script .= 'const openModal = () => { if (modal) { modal.classList.remove("hidden"); modal.setAttribute("aria-hidden","false"); } };' . "\n";
        $script .= 'const closeModal = () => { if (modal) { modal.classList.add("hidden"); modal.setAttribute("aria-hidden","true"); } };' . "\n";
        $script .= 'if (closeBtn) closeBtn.addEventListener("click", closeModal);' . "\n";
        $script .= 'if (laterBtn) laterBtn.addEventListener("click", function(e){ e.preventDefault(); closeModal(); ' . "\n";
        
        // For invite flow, redirect to clean URL
        if ($inviteUid !== null && $inviteSig !== null) {
            $script .= 'const cleanUrl = "/event_invite.php?uid=' . (int)$inviteUid . '&event_id=' . (int)$eventId . '&sig=" + encodeURIComponent(' . json_encode($inviteSig) . ');' . "\n";
            $script .= 'window.location.href = cleanUrl;' . "\n";
        }
        
        $script .= '});' . "\n";
        $script .= 'document.addEventListener("keydown", function(e){ if (e.key === "Escape") closeModal(); });' . "\n";
        
        if ($showModal) {
            $script .= 'openModal();' . "\n";
        }
        
        $script .= 'const rolesWrap = document.getElementById("volRoles");' . "\n";
        $script .= 'function esc(s) {' . "\n";
        $script .= 'return String(s).replace(/[&<>"\']/g, function(c){' . "\n";
        $script .= 'return {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;","\'":"&#39;"}[c];' . "\n";
        $script .= '});' . "\n";
        $script .= '}' . "\n";
        
        $script .= 'function renderRoles(json) {' . "\n";
        $script .= 'if (!rolesWrap) return;' . "\n";
        $script .= 'var roles = json.roles || [];' . "\n";
        $script .= 'var uid = parseInt(json.user_id, 10);' . "\n";
        $script .= 'var html = "";' . "\n";
        $script .= 'for (var i=0;i<roles.length;i++) {' . "\n";
        $script .= 'var r = roles[i] || {};' . "\n";
        $script .= 'var volunteers = r.volunteers || [];' . "\n";
        $script .= 'var signed = false;' . "\n";
        $script .= 'for (var j=0;j<volunteers.length;j++) {' . "\n";
        $script .= 'var v = volunteers[j] || {};' . "\n";
        $script .= 'if (parseInt(v.user_id, 10) === uid) { signed = true; break; }' . "\n";
        $script .= '}' . "\n";
        $script .= 'var open = parseInt(r.open_count, 10) || 0;' . "\n";
        $script .= 'var unlimited = !!r.is_unlimited;' . "\n";
        $script .= 'html += \'<div class="role" style="margin-bottom:14px;">\';' . "\n";
        $script .= 'html += \'<div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">\';' . "\n";
        $script .= 'html += \'<div>\';' . "\n";
        $script .= 'html += \'<strong>\'+esc(r.title||\'\')+\'</strong> \';' . "\n";
        $script .= 'html += (unlimited ? \'<span class="remaining">(no limit)</span>\' : (open > 0 ? \'<span class="remaining">(\'+open+\' people still needed)</span>\' : \'<span class="filled">Filled</span>\'));' . "\n";
        $script .= 'html += \'</div>\';' . "\n";
        $script .= 'if (!signed) {' . "\n";
        $script .= 'html += \'<form method="post" action="/volunteer_actions.php" class="inline" style="margin: 0;">\';' . "\n";
        $script .= 'html += \'<input type="hidden" name="csrf" value="\'+esc(json.csrf)+\'">\';' . "\n";
        $script .= 'html += \'<input type="hidden" name="event_id" value="\'+esc(json.event_id)+\'">\';' . "\n";
        $script .= 'html += \'<input type="hidden" name="role_id" value="\'+esc(r.id)+\'">\';' . "\n";
        
        if ($inviteUid !== null && $inviteSig !== null) {
            $script .= 'html += \'<input type="hidden" name="uid" value="' . (int)$inviteUid . '">\';' . "\n";
            $script .= 'html += \'<input type="hidden" name="sig" value="\'+esc(' . $sigJs . ')+\'">\';' . "\n";
        }
        
        $script .= 'if (unlimited || open > 0) {' . "\n";
        $script .= 'html += \'<input type="hidden" name="action" value="signup">\';' . "\n";
        $script .= 'html += \'<button class="button primary" style="white-space: nowrap;">Sign up</button>\';' . "\n";
        $script .= '} else {' . "\n";
        $script .= 'html += \'<button class="button" disabled style="white-space: nowrap;">Filled</button>\';' . "\n";
        $script .= '}' . "\n";
        $script .= 'html += \'</form>\';' . "\n";
        $script .= '}' . "\n";
        $script .= 'html += \'</div>\';' . "\n";
        $script .= 'if (r.description_html) {' . "\n";
        $script .= 'html += \'<div style="margin-top:4px;">\'+r.description_html+\'</div>\';' . "\n";
        $script .= '}' . "\n";
        $script .= 'if (volunteers.length > 0) {' . "\n";
        $script .= 'html += \'<ul style="margin:6px 0 0 16px;">\';' . "\n";
        $script .= 'for (var k=0;k<volunteers.length;k++) {' . "\n";
        $script .= 'var vn = volunteers[k] || {};' . "\n";
        $script .= 'var isMe = parseInt(vn.user_id, 10) === uid;' . "\n";
        $script .= 'html += \'<li>\'+esc(vn.name||\'\');' . "\n";
        $script .= 'if (isMe) {' . "\n";
        $script .= 'html += \' <form method="post" action="/volunteer_actions.php" class="inline" style="display:inline;">\';' . "\n";
        $script .= 'html += \'<input type="hidden" name="csrf" value="\'+esc(json.csrf)+\'">\';' . "\n";
        $script .= 'html += \'<input type="hidden" name="event_id" value="\'+esc(json.event_id)+\'">\';' . "\n";
        $script .= 'html += \'<input type="hidden" name="role_id" value="\'+esc(r.id)+\'">\';' . "\n";
        $script .= 'html += \'<input type="hidden" name="action" value="remove">\';' . "\n";
        
        if ($inviteUid !== null && $inviteSig !== null) {
            $script .= 'html += \'<input type="hidden" name="uid" value="' . (int)$inviteUid . '">\';' . "\n";
            $script .= 'html += \'<input type="hidden" name="sig" value="\'+esc(' . $sigJs . ')+\'">\';' . "\n";
        }
        
        $script .= 'html += \'<a href="#" class="small" onclick="this.closest(\\\'form\\\').requestSubmit(); return false;">(remove)</a>\';' . "\n";
        $script .= 'html += \'</form>\';' . "\n";
        $script .= '}' . "\n";
        $script .= 'html += \'</li>\';' . "\n";
        $script .= '}' . "\n";
        $script .= 'html += \'</ul>\';' . "\n";
        $script .= '} else {' . "\n";
        $script .= 'html += \'<ul style="margin:6px 0 0 16px;"><li>No one yet.</li></ul>\';' . "\n";
        $script .= '}' . "\n";
        $script .= 'html += \'</div>\';' . "\n";
        $script .= '}' . "\n";
        $script .= 'rolesWrap.innerHTML = html;' . "\n";
        $script .= '}' . "\n";
        
        $script .= 'function showError(msg) {' . "\n";
        $script .= 'if (!rolesWrap) return;' . "\n";
        $script .= 'const p = document.createElement("p");' . "\n";
        $script .= 'p.className = "error small";' . "\n";
        $script .= 'p.textContent = msg || "Action failed.";' . "\n";
        $script .= 'rolesWrap.insertBefore(p, rolesWrap.firstChild);' . "\n";
        $script .= '}' . "\n";
        
        $script .= 'if (modal) {' . "\n";
        $script .= 'modal.addEventListener("submit", function(e){' . "\n";
        $script .= 'const form = e.target.closest("form");' . "\n";
        $script .= 'if (!form || form.getAttribute("action") !== "/volunteer_actions.php") return;' . "\n";
        $script .= 'const action = form.querySelector("input[name=\\"action\\"]");' . "\n";
        $script .= 'if (action && action.value === "signup") {' . "\n";
        $script .= 'e.preventDefault();' . "\n";
        $script .= 'const roleId = form.querySelector("input[name=\\"role_id\\"]").value;' . "\n";
        $script .= 'if (window.showVolunteerSignupConfirmation) {' . "\n";
        $script .= 'window.showVolunteerSignupConfirmation(roleId);' . "\n";
        $script .= '}' . "\n";
        $script .= 'return;' . "\n";
        $script .= '}' . "\n";
        $script .= 'e.preventDefault();' . "\n";
        $script .= 'const fd = new FormData(form);' . "\n";
        $script .= 'fd.set("ajax","1");' . "\n";
        $script .= 'fetch("/volunteer_actions.php", { method:"POST", body: fd, credentials:"same-origin" })' . "\n";
        $script .= '.then(function(res){ return res.json(); })' . "\n";
        $script .= '.then(function(json){' . "\n";
        $script .= 'if (json && json.ok) { renderRoles(json); }' . "\n";
        $script .= 'else { showError((json && json.error) ? json.error : "Action failed."); }' . "\n";
        $script .= '})' . "\n";
        $script .= '.catch(function(){ showError("Network error."); });' . "\n";
        $script .= '});' . "\n";
        $script .= '}' . "\n";
        
        $script .= 'if (modal) modal.addEventListener("click", function(e){ if (e.target === modal) closeModal(); });' . "\n";
        $script .= '})();' . "\n";
        $script .= '</script>';
        
        return $script;
    }
    
    /**
     * Generate formatted event details text for email
     * 
     * @param array $event Event data array
     * @return string Formatted event details text
     */
    private static function generateEventDetailsText(array $event): string {
        $details = '';
        
        // WHAT
        $details .= "WHAT: " . trim((string)$event['name']) . "\n";
        
        // WHEN
        $details .= "WHEN: " . Settings::formatDateTimeRange($event['starts_at'], !empty($event['ends_at']) ? $event['ends_at'] : null) . "\n";
        
        // WHERE - combine name and address, convert newlines to commas
        $locName = trim((string)($event['location'] ?? ''));
        $locAddr = trim((string)($event['location_address'] ?? ''));
        if ($locName !== '' || $locAddr !== '') {
            $details .= "WHERE: ";
            $locParts = [];
            if ($locName !== '') $locParts[] = $locName;
            if ($locAddr !== '') {
                // Convert newlines in address to commas
                $locParts[] = preg_replace("/\r\n|\r|\n/", ", ", $locAddr);
            }
            $details .= implode(", ", $locParts) . "\n";
        }
        
        // DETAILS
        $details .= "DETAILS:\n";
        if (!empty($event['description'])) {
            $details .= trim((string)$event['description']);
        }
        
        return $details;
    }
}
