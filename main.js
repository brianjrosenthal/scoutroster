// Minimal JS placeholder for Cub Scouts app.
// Used for small UX touches and future enhancements.
(function() {
  // Dismissible flash messages
  document.addEventListener('click', function(e) {
    const t = e.target;
    if (t && t.matches('.flash .close')) {
      const p = t.closest('.flash');
      if (p) p.remove();
    }
  });

  // Auto-submit filters on Mailing List page with debounce for search input
  document.addEventListener('DOMContentLoaded', function () {
    const path = (window.location && window.location.pathname) || '';

    const findForm = () => document.querySelector('form');
    const submitFormFor = (form) => {
      if (!form) return;
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    };
    const debounce = (fn, delay) => {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(null, args), delay);
      };
    };

    // Admin Mailing List (existing)
    if (path.endsWith('/admin_mailing_list.php')) {
      const form = findForm();
      if (!form) return;

      const submitForm = () => submitFormFor(form);

      const q = form.querySelector('input[name="q"]');
      const g = form.querySelector('select[name="g"]');
      const reg = form.querySelector('select[name="registered"]');

      if (q) q.addEventListener('input', debounce(submitForm, 500));
      if (g) g.addEventListener('change', submitForm);
      if (reg) reg.addEventListener('change', submitForm);
    }

    // Adults roster: live search with 600ms debounce on text; immediate on selects
    if (path.endsWith('/adults.php')) {
      const form = findForm();
      if (form) {
        const submitForm = () => submitFormFor(form);
        const q = form.querySelector('input[name="q"]');
        const g = form.querySelector('select[name="g"]');
        if (q) q.addEventListener('input', debounce(submitForm, 600));
        if (g) g.addEventListener('change', submitForm);
      }
    }

    // Manage Adults: live search with 600ms debounce on text
    if (path.endsWith('/admin_adults.php')) {
      const form = findForm();
      if (form) {
        const submitForm = () => submitFormFor(form);
        const q = form.querySelector('input[name="q"]');
        if (q) q.addEventListener('input', debounce(submitForm, 600));
      }
    }

    // Activity Log: admin user typeahead for filtering
    if (path.endsWith('/admin/activity_log.php')) {
      const form = findForm();
      const input = document.getElementById('userTypeahead');
      const hidden = document.getElementById('userId');
      const results = document.getElementById('userTypeaheadResults');
      const clearBtn = document.getElementById('clearUserBtn');

      if (clearBtn && hidden && input && results) {
        clearBtn.addEventListener('click', function() {
          hidden.value = '';
          input.value = '';
          results.innerHTML = '';
          results.style.display = 'none';
        });
      }

      if (input && hidden && results) {
        let seq = 0;

        const hideResults = () => {
          results.style.display = 'none';
          results.innerHTML = '';
        };

        const render = (items) => {
          if (!Array.isArray(items) || items.length === 0) {
            hideResults();
            return;
          }
          results.innerHTML = '';
          items.forEach(item => {
            const div = document.createElement('div');
            div.className = 'item';
            div.setAttribute('role', 'option');
            div.dataset.id = String(item.id || '');
            div.textContent = String(item.label || '');
            div.addEventListener('click', function() {
              hidden.value = this.dataset.id || '';
              input.value = this.textContent || '';
              hideResults();
            });
            results.appendChild(div);
          });
          results.style.display = 'block';
        };

        const doSearch = (q, mySeq) => {
          fetch('/admin_adult_search.php?q=' + encodeURIComponent(q) + '&limit=20', { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(json => {
              if (mySeq !== seq) return; // ignore out-of-order responses
              const items = (json && json.items) ? json.items : [];
              render(items);
            })
            .catch(() => {
              if (mySeq === seq) hideResults();
            });
        };

        const onInput = () => {
          const q = input.value.trim();
          // Any typing invalidates any previous selection until a suggestion is chosen again
          hidden.value = '';
          seq++;
          const mySeq = seq;
          if (q.length < 2) {
            hideResults();
            hidden.value = '';
            return;
          }
          doSearch(q, mySeq);
        };

        input.addEventListener('input', debounce(onInput, 350));
        // Guard on submit: if text box is empty, ensure hidden user_id is cleared
        if (form) {
          form.addEventListener('submit', function() {
            if (input.value.trim().length === 0) {
              hidden.value = '';
            }
          });
        }

        // Dismiss suggestions on outside click or Escape
        document.addEventListener('click', function(e) {
          if (!results || !input) return;
          const within = results.contains(e.target) || input.contains(e.target);
          if (!within) hideResults();
        });
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape') hideResults();
        });
      }
    }
    // Admin Event Invite: "One adult" typeahead for selecting a single adult
    if (path.endsWith('/admin_event_invite.php')) {
      const form = findForm();
      const input = document.getElementById('userTypeahead');
      const hidden = document.getElementById('userId');
      const results = document.getElementById('userTypeaheadResults');
      const clearBtn = document.getElementById('clearUserBtn');

      if (clearBtn && hidden && input && results) {
        clearBtn.addEventListener('click', function() {
          hidden.value = '';
          input.value = '';
          results.innerHTML = '';
          results.style.display = 'none';
        });
      }

      if (input && hidden && results) {
        let seq = 0;

        const hideResults = () => {
          results.style.display = 'none';
          results.innerHTML = '';
        };

        const render = (items) => {
          if (!Array.isArray(items) || items.length === 0) {
            hideResults();
            return;
          }
          results.innerHTML = '';
          items.forEach(item => {
            const div = document.createElement('div');
            div.className = 'item';
            div.setAttribute('role', 'option');
            div.dataset.id = String(item.id || '');
            div.textContent = String(item.label || '');
            div.addEventListener('click', function() {
              hidden.value = this.dataset.id || '';
              input.value = this.textContent || '';
              hideResults();
            });
            results.appendChild(div);
          });
          results.style.display = 'block';
        };

        const doSearch = (q, mySeq) => {
          fetch('/admin_adult_search.php?q=' + encodeURIComponent(q) + '&limit=20', { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(json => {
              if (mySeq !== seq) return; // ignore out-of-order responses
              const items = (json && json.items) ? json.items : [];
              render(items);
            })
            .catch(() => {
              if (mySeq === seq) hideResults();
            });
        };

        const onInput = () => {
          const q = input.value.trim();
          // Any typing invalidates any previous selection until a suggestion is chosen again
          hidden.value = '';
          seq++;
          const mySeq = seq;
          if (q.length < 2) {
            hideResults();
            hidden.value = '';
            return;
          }
          doSearch(q, mySeq);
        };

        input.addEventListener('input', debounce(onInput, 350));
        // Guard on submit: if text box is empty, ensure hidden user_id is cleared
        if (form) {
          form.addEventListener('submit', function() {
            if (input.value.trim().length === 0) {
              hidden.value = '';
            }
          });
        }

        // Dismiss suggestions on outside click or Escape
        document.addEventListener('click', function(e) {
          if (!results || !input) return;
          const within = results.contains(e.target) || input.contains(e.target);
          if (!within) hideResults();
        });
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape') hideResults();
        });
      }
    }

    // Admin RSVP Management: event.php admin modal
    if (path.endsWith('/event.php')) {
      const adminModal = document.getElementById('adminRsvpModal');
      const adminManageBtn = document.getElementById('adminManageRsvpBtn');
      const adminModalClose = document.getElementById('adminRsvpModalClose');
      const adminStep1 = document.getElementById('adminRsvpStep1');
      const adminStep2 = document.getElementById('adminRsvpStep2');
      const adminBackBtn = document.getElementById('adminRsvpBackBtn');
      const adminFamilySearch = document.getElementById('adminFamilySearch');
      const adminFamilySearchResults = document.getElementById('adminFamilySearchResults');
      const adminContextAdultId = document.getElementById('adminContextAdultId');
      const adminSelectedPersonName = document.getElementById('adminSelectedPersonName');
      const adminRsvpAnswerInput = document.getElementById('adminRsvpAnswerInput');
      const adminRsvpYesBtn = document.getElementById('adminRsvpYesBtn');
      const adminRsvpMaybeBtn = document.getElementById('adminRsvpMaybeBtn');
      const adminRsvpNoBtn = document.getElementById('adminRsvpNoBtn');
      const adminAdultsList = document.getElementById('adminAdultsList');
      const adminYouthList = document.getElementById('adminYouthList');
      const adminNGuests = document.getElementById('adminNGuests');
      const adminComments = document.getElementById('adminComments');

      if (adminModal && adminManageBtn) {
        let searchSeq = 0;

        const openAdminModal = () => {
          adminModal.classList.remove('hidden');
          adminModal.setAttribute('aria-hidden', 'false');
          showStep1();
        };

        const closeAdminModal = () => {
          adminModal.classList.add('hidden');
          adminModal.setAttribute('aria-hidden', 'true');
          resetModal();
        };

        const showStep1 = () => {
          if (adminStep1) adminStep1.style.display = 'block';
          if (adminStep2) adminStep2.style.display = 'none';
        };

        const showStep2 = () => {
          if (adminStep1) adminStep1.style.display = 'none';
          if (adminStep2) adminStep2.style.display = 'block';
        };

        const resetModal = () => {
          if (adminFamilySearch) adminFamilySearch.value = '';
          if (adminFamilySearchResults) {
            adminFamilySearchResults.innerHTML = '';
            adminFamilySearchResults.style.display = 'none';
          }
          showStep1();
        };

        const hideSearchResults = () => {
          if (adminFamilySearchResults) {
            adminFamilySearchResults.style.display = 'none';
            adminFamilySearchResults.innerHTML = '';
          }
        };

        const renderSearchResults = (items) => {
          if (!adminFamilySearchResults) return;
          if (!Array.isArray(items) || items.length === 0) {
            hideSearchResults();
            return;
          }
          adminFamilySearchResults.innerHTML = '';
          items.forEach(item => {
            const div = document.createElement('div');
            div.className = 'item';
            div.setAttribute('role', 'option');
            div.dataset.id = String(item.id || '');
            div.dataset.type = String(item.type || '');
            div.textContent = String(item.label || '');
            div.addEventListener('click', function() {
              selectPerson(item);
            });
            adminFamilySearchResults.appendChild(div);
          });
          adminFamilySearchResults.style.display = 'block';
        };

        const selectPerson = (person) => {
          hideSearchResults();
          if (adminFamilySearch) adminFamilySearch.value = person.label || '';
          
          // Load family RSVP data
          const eventId = new URLSearchParams(window.location.search).get('id');
          if (!eventId) return;
          
          fetch(`/admin_rsvp_edit.php?ajax=1&event_id=${eventId}&person_type=${person.type}&person_id=${person.id}`, {
            headers: { 'Accept': 'application/json' }
          })
          .then(r => r.ok ? r.json() : Promise.reject())
          .then(json => {
            if (json && json.ok) {
              loadFamilyRsvpData(json, person.label);
            } else {
              alert('Failed to load family data: ' + (json.error || 'Unknown error'));
            }
          })
          .catch(() => {
            alert('Failed to load family data');
          });
        };

        const loadFamilyRsvpData = (data, personLabel) => {
          if (adminContextAdultId) adminContextAdultId.value = data.context_adult_id || '';
          if (adminSelectedPersonName) adminSelectedPersonName.textContent = personLabel || '';
          if (adminRsvpAnswerInput) adminRsvpAnswerInput.value = data.answer || 'yes';
          if (adminNGuests) adminNGuests.value = data.n_guests || 0;
          if (adminComments) adminComments.value = data.comments || '';

          // Set the correct radio button
          const answerRadios = adminModal ? adminModal.querySelectorAll('input[name="answer_radio"]') : [];
          answerRadios.forEach(radio => {
            radio.checked = radio.value === (data.answer || 'yes');
          });

          // Render adults
          if (adminAdultsList && data.family_adults) {
            adminAdultsList.innerHTML = '';
            data.family_adults.forEach(adult => {
              const label = document.createElement('label');
              label.className = 'inline';
              const checkbox = document.createElement('input');
              checkbox.type = 'checkbox';
              checkbox.name = 'adults[]';
              checkbox.value = adult.id;
              checkbox.checked = (data.selected_adults || []).includes(adult.id);
              const name = `${adult.first_name || ''} ${adult.last_name || ''}`.trim();
              label.appendChild(checkbox);
              label.appendChild(document.createTextNode(' ' + name));
              adminAdultsList.appendChild(label);
            });
          }

          // Render youth
          if (adminYouthList && data.family_youth) {
            if (data.family_youth.length === 0) {
              adminYouthList.innerHTML = '<p class="small">No children on file.</p>';
            } else {
              adminYouthList.innerHTML = '';
              data.family_youth.forEach(youth => {
                const label = document.createElement('label');
                label.className = 'inline';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'youth[]';
                checkbox.value = youth.id;
                checkbox.checked = (data.selected_youth || []).includes(youth.id);
                const name = `${youth.first_name || ''} ${youth.last_name || ''}`.trim();
                label.appendChild(checkbox);
                label.appendChild(document.createTextNode(' ' + name));
                adminYouthList.appendChild(label);
              });
            }
          }

          showStep2();
        };

        const doFamilySearch = (q, mySeq) => {
          fetch('/admin_family_search.php?q=' + encodeURIComponent(q) + '&limit=20', {
            headers: { 'Accept': 'application/json' }
          })
          .then(r => r.ok ? r.json() : Promise.reject())
          .then(json => {
            if (mySeq !== searchSeq) return;
            const items = (json && json.items) ? json.items : [];
            renderSearchResults(items);
          })
          .catch(() => {
            if (mySeq === searchSeq) hideSearchResults();
          });
        };

        const onFamilySearchInput = () => {
          const q = adminFamilySearch ? adminFamilySearch.value.trim() : '';
          searchSeq++;
          const mySeq = searchSeq;
          if (q.length < 2) {
            hideSearchResults();
            return;
          }
          doFamilySearch(q, mySeq);
        };

        // Event listeners
        if (adminManageBtn) {
          adminManageBtn.addEventListener('click', openAdminModal);
        }

        if (adminModalClose) {
          adminModalClose.addEventListener('click', closeAdminModal);
        }

        if (adminBackBtn) {
          adminBackBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showStep1();
          });
        }

        if (adminFamilySearch) {
          adminFamilySearch.addEventListener('input', debounce(onFamilySearchInput, 350));
        }

        // RSVP answer radio buttons
        const answerRadios = adminModal ? adminModal.querySelectorAll('input[name="answer_radio"]') : [];
        answerRadios.forEach(radio => {
          radio.addEventListener('change', function() {
            if (adminRsvpAnswerInput) adminRsvpAnswerInput.value = this.value;
          });
        });

        // Close on outside click or Escape
        if (adminModal) {
          adminModal.addEventListener('click', function(e) {
            if (e.target === adminModal) closeAdminModal();
          });
        }

        document.addEventListener('click', function(e) {
          if (!adminFamilySearchResults || !adminFamilySearch) return;
          const within = adminFamilySearchResults.contains(e.target) || adminFamilySearch.contains(e.target);
          if (!within) hideSearchResults();
        });

        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape') {
            hideSearchResults();
            if (adminModal && !adminModal.classList.contains('hidden')) {
              closeAdminModal();
            }
          }
        });
      }
    }
  });
})();

// Copy Emails functionality
function openCopyEmailsModal() {
    const modal = document.getElementById('copyEmailsModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        loadEmails(); // Load initial emails
    }
}

function closeCopyEmailsModal() {
    const modal = document.getElementById('copyEmailsModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    }
}

function loadEmails() {
    const eventId = new URLSearchParams(window.location.search).get('id');
    const filterRadios = document.querySelectorAll('input[name="email_filter"]');
    let filter = 'yes';
    
    for (const radio of filterRadios) {
        if (radio.checked) {
            filter = radio.value;
            break;
        }
    }
    
    const emailsList = document.getElementById('emailsList');
    if (emailsList) {
        emailsList.value = 'Loading...';
    }
    
    fetch(`/admin_event_emails.php?event_id=${eventId}&filter=${filter}`, {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && emailsList) {
            emailsList.value = data.emails.join('\n');
        } else {
            if (emailsList) {
                emailsList.value = 'Error loading emails: ' + (data.error || 'Unknown error');
            }
        }
    })
    .catch(error => {
        console.error('Error loading emails:', error);
        if (emailsList) {
            emailsList.value = 'Error loading emails';
        }
    });
}

function copyEmailsToClipboard() {
    const emailsList = document.getElementById('emailsList');
    const copyStatus = document.getElementById('copyStatus');
    
    if (emailsList) {
        emailsList.select();
        emailsList.setSelectionRange(0, 99999); // For mobile devices
        
        try {
            navigator.clipboard.writeText(emailsList.value).then(() => {
                if (copyStatus) {
                    copyStatus.style.display = 'inline';
                    setTimeout(() => {
                        copyStatus.style.display = 'none';
                    }, 2000);
                }
            }).catch(() => {
                // Fallback for older browsers
                document.execCommand('copy');
                if (copyStatus) {
                    copyStatus.style.display = 'inline';
                    setTimeout(() => {
                        copyStatus.style.display = 'none';
                    }, 2000);
                }
            });
        } catch (err) {
            console.error('Failed to copy emails:', err);
        }
    }
}

// Admin RSVP submission function
function submitAdminRSVP(eventId) {
    const form = document.getElementById('adminRSVPForm');
    const formData = new FormData(form);
    
    fetch('admin_rsvp_edit.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Get the selected person's name for the success message
            const selectedPersonName = document.getElementById('adminSelectedPersonName').textContent;
            
            // Show step 1 again with success message
            const adminStep1 = document.getElementById('adminRsvpStep1');
            const adminStep2 = document.getElementById('adminRsvpStep2');
            
            if (adminStep1) adminStep1.style.display = 'block';
            if (adminStep2) adminStep2.style.display = 'none';
            
            // Clear the search field and results
            const adminFamilySearch = document.getElementById('adminFamilySearch');
            const adminFamilySearchResults = document.getElementById('adminFamilySearchResults');
            
            if (adminFamilySearch) adminFamilySearch.value = '';
            if (adminFamilySearchResults) {
                adminFamilySearchResults.innerHTML = '';
                adminFamilySearchResults.style.display = 'none';
            }
            
            // Show success message
            const successDiv = document.createElement('div');
            successDiv.className = 'alert alert-success';
            successDiv.textContent = `You have successfully RSVP'd for ${selectedPersonName}`;
            
            // Insert success message at the top of step 1
            if (adminStep1) {
                adminStep1.insertBefore(successDiv, adminStep1.firstChild);
                
                // Remove success message after 5 seconds
                setTimeout(() => {
                    if (successDiv.parentNode) {
                        successDiv.parentNode.removeChild(successDiv);
                    }
                }, 5000);
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while saving the RSVP');
    });
}
