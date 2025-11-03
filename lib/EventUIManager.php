<?php

require_once __DIR__ . '/EventManagement.php';

class EventUIManager {
    
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
                <a href="/admin_event_volunteers.php?event_id=' . $eventId . '" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;' . ($currentPage === 'volunteers' ? ' background-color: #f5f5f5;' : '') . '">Manage Volunteers</a>';
        
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
     * @return string JavaScript code for admin menu functionality
     */
    public static function renderAdminMenuScript(int $eventId): string {
        $me = current_user();
        $csrfToken = csrf_token();
        
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
     * @return string HTML content for complete volunteers card
     */
    public static function renderVolunteersCard(array $roles, bool $hasYes, int $actingUserId, int $eventId, bool $isAdmin, ?string $successMessage = null): string {
        if (!$isAdmin && empty($roles)) {
            return '';
        }
        
        $html = '<div class="card" id="volunteersCard">';
        $html .= '<h3>Event Volunteers</h3>';
        
        // Add success message if provided
        if ($successMessage !== null) {
            $html .= '<div class="flash" style="margin-bottom:16px;">' . h($successMessage) . '</div>';
        }
        
        $html .= self::renderVolunteersSection($roles, $hasYes, $actingUserId, $eventId, $isAdmin, null);
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
     * @return string HTML content for volunteers section
     */
    public static function renderVolunteersSection(array $roles, bool $hasYes, int $actingUserId, int $eventId, bool $isAdmin, ?string $successMessage = null): string {
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
                $html .= '<div>';
                $html .= '<strong>' . h($r['title']) . '</strong>';
                
                if (!empty($r['is_unlimited'])) {
                    $html .= '<span class="remaining small">(no limit)</span>';
                } elseif ((int)$r['open_count'] > 0) {
                    $html .= '<span class="remaining small">(' . (int)$r['open_count'] . ' people still needed)</span>';
                } else {
                    $html .= '<span class="filled small">Filled</span>';
                }
                
                $html .= '</div>';
                
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
                            $html .= '<form method="post" action="/volunteer_actions.php" class="inline" style="display:inline;">';
                            $html .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                            $html .= '<input type="hidden" name="event_id" value="' . (int)$eventId . '">';
                            $html .= '<input type="hidden" name="role_id" value="' . (int)$r['id'] . '">';
                            $html .= '<input type="hidden" name="action" value="remove">';
                            $html .= '<a href="#" class="volunteer-remove-link small">(remove)</a>';
                            $html .= '</form>';
                        }
                        
                        $html .= '</li>';
                    }
                    $html .= '</ul>';
                } else {
                    $html .= '<ul style="margin:6px 0 0 16px;"><li>No one yet.</li></ul>';
                }
                
                if ($hasYes) {
                    $amIn = false;
                    foreach ($r['volunteers'] as $v) {
                        if ((int)$v['user_id'] === (int)$actingUserId) {
                            $amIn = true;
                            break;
                        }
                    }
                    
                    if (!$amIn) {
                        $html .= '<form method="post" action="/volunteer_actions.php" class="inline">';
                        $html .= '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                        $html .= '<input type="hidden" name="event_id" value="' . (int)$eventId . '">';
                        $html .= '<input type="hidden" name="role_id" value="' . (int)$r['id'] . '">';
                        
                        if (!empty($r['is_unlimited']) || (int)$r['open_count'] > 0) {
                            $html .= '<input type="hidden" name="action" value="signup">';
                            $html .= '<button style="margin-top:6px;" class="button primary">Sign up</button>';
                        } else {
                            $html .= '<button style="margin-top:6px;" class="button" disabled>Filled</button>';
                        }
                        
                        $html .= '</form>';
                    }
                }
                
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
        return $html;
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
