<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/RSVPManagement.php';
require_once __DIR__.'/lib/RsvpsLoggedOutManagement.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/ParentRelationships.php';
require_admin();

header('Content-Type: application/json');

$eventId = (int)($_GET['event_id'] ?? 0);
$filter = $_GET['filter'] ?? 'yes'; // 'yes' or 'yes_maybe'
$medicalFormOnly = $_GET['medical_form_only'] ?? '0'; // '1' or '0'

if ($eventId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event ID']);
    exit;
}

try {
    // Helper function to check if a person needs a medical form
    function needsMedicalForm($medicalFormsExpirationDate) {
        if ($medicalFormsExpirationDate === null || $medicalFormsExpirationDate === '') {
            return true; // No date means they need one
        }
        $expirationDate = new DateTime($medicalFormsExpirationDate);
        $today = new DateTime();
        return $expirationDate < $today; // Expired means they need a new one
    }
    
    $emails = [];
    $familiesNeedingMedicalForms = []; // Track families that need medical forms
    
    // Get RSVPs based on filter
    if ($filter === 'yes_maybe') {
        $answers = ['yes', 'maybe'];
    } else {
        $answers = ['yes'];
    }
    
    if ($medicalFormOnly === '1') {
        // Medical form filtering logic
        $listA = []; // Adults who are parents of children who RSVP'd and need medical forms
        $listB = []; // Adults in families where an adult RSVP'd and needs medical forms
        
        foreach ($answers as $answer) {
            // Get all adults who RSVP'd with this answer
            $rsvpAdults = RSVPManagement::listAdultEntriesByAnswer($eventId, $answer);
            
            // Get all youth who RSVP'd with this answer
            $rsvpYouthIds = RSVPManagement::listYouthIdsByAnswer($eventId, $answer);
            
            // Process children who RSVP'd -> List A
            foreach ($rsvpYouthIds as $youthId) {
                // Get youth data to check medical form
                $pdo = pdo();
                $youthStmt = $pdo->prepare("SELECT medical_forms_expiration_date FROM youth WHERE id = ?");
                $youthStmt->execute([$youthId]);
                $youthData = $youthStmt->fetch();
                
                // If this child who RSVP'd needs a medical form
                if ($youthData && needsMedicalForm($youthData['medical_forms_expiration_date'] ?? null)) {
                    // Get all parents of this child and add to List A
                    $parents = ParentRelationships::listParentsForChild($youthId);
                    foreach ($parents as $parent) {
                        $listA[] = (int)$parent['id'];
                    }
                }
            }
            
            // Process adults who RSVP'd -> List B
            foreach ($rsvpAdults as $adult) {
                $adultId = (int)$adult['id'];
                
                // Check if this adult who RSVP'd needs a medical form
                $adultData = UserManagement::findById($adultId);
                if ($adultData && needsMedicalForm($adultData['medical_forms_expiration_date'] ?? null)) {
                    // Get all children of this adult
                    $children = ParentRelationships::listChildrenForAdult($adultId);
                    
                    // Get all parents of those children and add to List B
                    foreach ($children as $child) {
                        $childId = (int)$child['id'];
                        $parents = ParentRelationships::listParentsForChild($childId);
                        foreach ($parents as $parent) {
                            $listB[] = (int)$parent['id'];
                        }
                    }
                    
                    // Also add the adult themselves to List B
                    $listB[] = $adultId;
                }
            }
        }
        
        // Merge and deduplicate List A and List B to create List C
        $listC = array_unique(array_merge($listA, $listB));
        
        // Get emails for all adults in List C
        foreach ($listC as $adultId) {
            $adultData = UserManagement::findById($adultId);
            if ($adultData && !empty($adultData['email'])) {
                $emails[] = trim($adultData['email']);
            }
        }
    } else {
        // Regular email collection (no medical form filtering)
        foreach ($answers as $answer) {
            // Get adult entries who RSVP'd with this answer
            $adultEntries = RSVPManagement::listAdultEntriesByAnswer($eventId, $answer);
            
            foreach ($adultEntries as $adult) {
                $adultId = (int)$adult['id'];
                
                // Get the adult's email
                $adultData = UserManagement::findById($adultId);
                if ($adultData && !empty($adultData['email'])) {
                    $emails[] = trim($adultData['email']);
                }
                
                // Get all parents of children in this family
                $children = ParentRelationships::listChildrenForAdult($adultId);
                foreach ($children as $child) {
                    $childId = (int)$child['id'];
                    
                    // Get parents for this child
                    $parents = ParentRelationships::listParentsForChild($childId);
                    foreach ($parents as $parent) {
                        $parentId = (int)$parent['id'];
                        if ($parentId !== $adultId && !empty($parent['email'])) { // Don't duplicate the original adult
                            $emails[] = trim($parent['email']);
                        }
                    }
                }
            }
            
            // Get youth who RSVP'd with this answer and their parents
            $youthIds = RSVPManagement::listYouthIdsByAnswer($eventId, $answer);
            
            foreach ($youthIds as $youthId) {
                // Get parents for this youth
                $parents = ParentRelationships::listParentsForChild($youthId);
                foreach ($parents as $parent) {
                    if (!empty($parent['email'])) {
                        $emails[] = trim($parent['email']);
                    }
                }
            }
        }
    }
    
    // Remove duplicates and empty emails
    $emails = array_unique(array_filter($emails));
    
    // Sort emails alphabetically
    sort($emails);
    
    echo json_encode([
        'success' => true,
        'emails' => $emails
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch emails: ' . $e->getMessage()]);
}
