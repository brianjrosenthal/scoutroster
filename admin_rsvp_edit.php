<?php
require_once __DIR__.'/partials.php';
require_admin();
require_once __DIR__ . '/lib/Volunteers.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/RSVPManagement.php';
require_once __DIR__ . '/lib/UserContext.php';
require_once __DIR__ . '/lib/ParentRelationships.php';

$me = current_user();
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }

// Load event
$event = EventManagement::findById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

// Handle AJAX request to get family RSVP data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['ajax']) && !empty($_GET['person_type']) && !empty($_GET['person_id'])) {
  header('Content-Type: application/json; charset=utf-8');
  
  try {
    $personType = (string)$_GET['person_type']; // 'adult' or 'youth'
    $personId = (int)$_GET['person_id'];
    
    if (!in_array($personType, ['adult', 'youth'], true) || $personId <= 0) {
      throw new Exception('Invalid person selection');
    }
    
    // Determine the family context
    $contextAdultId = null;
    $familyAdults = [];
    $familyYouth = [];
    
    if ($personType === 'adult') {
      $contextAdultId = $personId;
      // Get this adult's children
      $familyYouth = ParentRelationships::listChildrenForAdult($personId);
      // Get co-parents (other adults linked to same children)
      $coParents = ParentRelationships::listCoParentsForAdult($personId);
      $familyAdults = [$personId => UserManagement::findById($personId)];
      foreach ($coParents as $cp) {
        $familyAdults[(int)$cp['id']] = $cp;
      }
    } else {
      // Youth selected - find their parents
      $parents = ParentRelationships::listParentsForYouth($personId);
      if (empty($parents)) {
        throw new Exception('No parents found for this child');
      }
      
      // Use first parent as context
      $contextAdultId = (int)$parents[0]['id'];
      
      // Get all children of these parents (siblings)
      $allChildrenIds = [];
      foreach ($parents as $parent) {
        $children = ParentRelationships::listChildrenForAdult((int)$parent['id']);
        foreach ($children as $child) {
          $allChildrenIds[(int)$child['id']] = $child;
        }
      }
      $familyYouth = array_values($allChildrenIds);
      
      // Get all co-parents
      $allAdultIds = [];
      foreach ($familyYouth as $child) {
        $childParents = ParentRelationships::listParentsForYouth((int)$child['id']);
        foreach ($childParents as $parent) {
          $allAdultIds[(int)$parent['id']] = $parent;
        }
      }
      $familyAdults = $allAdultIds;
    }
    
    if (!$contextAdultId || empty($familyAdults)) {
      throw new Exception('Could not determine family context');
    }
    
    // Look for existing RSVP using the same logic as regular users
    $existingRsvp = RSVPManagement::findMyRsvpForEvent($eventId, $contextAdultId);
    
    $selectedAdults = [];
    $selectedYouth = [];
    $comments = '';
    $nGuests = 0;
    $answer = 'yes';
    
    if ($existingRsvp) {
      $comments = (string)($existingRsvp['comments'] ?? '');
      $nGuests = (int)($existingRsvp['n_guests'] ?? 0);
      $answer = (string)($existingRsvp['answer'] ?? 'yes');
      $ids = RSVPManagement::getMemberIdsByType((int)$existingRsvp['id']);
      $selectedAdults = $ids['adult_ids'] ?? [];
      $selectedYouth = $ids['youth_ids'] ?? [];
    }
    
    echo json_encode([
      'ok' => true,
      'context_adult_id' => $contextAdultId,
      'family_adults' => array_values($familyAdults),
      'family_youth' => $familyYouth,
      'selected_adults' => $selectedAdults,
      'selected_youth' => $selectedYouth,
      'comments' => $comments,
      'n_guests' => $nGuests,
      'answer' => $answer,
      'has_existing_rsvp' => $existingRsvp !== null
    ], JSON_UNESCAPED_SLASHES);
    exit;
    
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    exit;
  }
}

// Handle POST save
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  
  $contextAdultId = (int)($_POST['context_adult_id'] ?? 0);
  $adults = $_POST['adults'] ?? [];
  $youths = $_POST['youth'] ?? [];
  $comments = trim($_POST['comments'] ?? '');
  $nGuests = (int)($_POST['n_guests'] ?? 0);
  if ($nGuests < 0) $nGuests = 0;

  $answer = strtolower(trim((string)($_POST['answer'] ?? 'yes')));
  if (!in_array($answer, ['yes','maybe','no'], true)) {
    $answer = 'yes';
  }

  if ($contextAdultId <= 0) {
    $error = 'Invalid family context.';
  }

  // Normalize to ints
  if (!$error) {
    $adults = array_values(array_unique(array_map('intval', (array)$adults)));
    $youths = array_values(array_unique(array_map('intval', (array)$youths)));
  }

  // Validate that the context adult exists and we can manage their RSVP
  if (!$error) {
    $contextUser = UserManagement::findById($contextAdultId);
    if (!$contextUser) {
      $error = 'Invalid family context user.';
    }
  }

  // Enforce event youth cap if set
  if (!$error && !empty($event['max_cub_scouts'])) {
    $max = (int)$event['max_cub_scouts'];
    $currentYouth = RSVPManagement::countYouthForEvent($eventId);
    $existingRsvp = RSVPManagement::findMyRsvpForEvent($eventId, $contextAdultId);
    $myCurrentYouth = $existingRsvp ? RSVPManagement::countYouthForRsvp((int)$existingRsvp['id']) : 0;
    $newTotalYouth = $currentYouth - $myCurrentYouth + count($youths);
    if ($newTotalYouth > $max) {
      $error = 'This event has reached its maximum number of Cub Scouts.';
    }
  }

  if (!$error) {
    try {
      $ctx = \UserContext::getLoggedInUserContext();
      RSVPManagement::setFamilyRSVP(
        $ctx, 
        $contextAdultId, 
        $eventId, 
        $answer, 
        $adults, 
        $youths, 
        ($comments !== '' ? $comments : null), 
        $nGuests,
        (int)$me['id'] // entered_by = current admin user
      );
      
      $vol = (strtolower($answer) === 'yes' && Volunteers::openRolesExist($eventId)) ? '&vol=1' : '';
      header('Location: /event.php?id='.$eventId.'&rsvp=1'.$vol); 
      exit;
    } catch (Throwable $e) {
      $error = 'Failed to save RSVP: ' . $e->getMessage();
    }
  }
}

// This should not be accessed directly via GET without AJAX
http_response_code(400);
exit('Invalid request');
