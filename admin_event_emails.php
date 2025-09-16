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
    
    foreach ($answers as $answer) {
        // Get adult entries who RSVP'd with this answer
        $adultEntries = RSVPManagement::listAdultEntriesByAnswer($eventId, $answer);
        
        foreach ($adultEntries as $adult) {
            $adultId = (int)$adult['id'];
            $familyEmails = [];
            $familyNeedsMedicalForm = false;
            
            // Check if this adult needs a medical form
            $adultData = UserManagement::findById($adultId);
            if ($adultData) {
                if (needsMedicalForm($adultData['medical_forms_expiration_date'] ?? null)) {
                    $familyNeedsMedicalForm = true;
                }
                if (!empty($adultData['email'])) {
                    $familyEmails[] = trim($adultData['email']);
                }
            }
            
            // Get all parents of children in this family and check their medical forms
            $children = ParentRelationships::listChildrenForAdult($adultId);
            foreach ($children as $child) {
                $childId = (int)$child['id'];
                
                // Check if this child needs a medical form
                if (needsMedicalForm($child['medical_forms_expiration_date'] ?? null)) {
                    $familyNeedsMedicalForm = true;
                }
                
                // Get parents for this child
                $parents = ParentRelationships::listParentsForChild($childId);
                foreach ($parents as $parent) {
                    $parentId = (int)$parent['id'];
                    if ($parentId !== $adultId) { // Don't duplicate the original adult
                        // Check if this parent needs a medical form
                        if (needsMedicalForm($parent['medical_forms_expiration_date'] ?? null)) {
                            $familyNeedsMedicalForm = true;
                        }
                        if (!empty($parent['email'])) {
                            $familyEmails[] = trim($parent['email']);
                        }
                    }
                }
            }
            
            // Add family emails if medical form filter is off OR family needs medical forms
            if ($medicalFormOnly !== '1' || $familyNeedsMedicalForm) {
                $emails = array_merge($emails, $familyEmails);
            }
        }
        
        // Get youth who RSVP'd with this answer and their parents
        $youthIds = RSVPManagement::listYouthIdsByAnswer($eventId, $answer);
        
        foreach ($youthIds as $youthId) {
            $familyEmails = [];
            $familyNeedsMedicalForm = false;
            
            // Get youth data to check medical form
            $pdo = pdo();
            $youthStmt = $pdo->prepare("SELECT medical_forms_expiration_date FROM youth WHERE id = ?");
            $youthStmt->execute([$youthId]);
            $youthData = $youthStmt->fetch();
            
            if ($youthData && needsMedicalForm($youthData['medical_forms_expiration_date'] ?? null)) {
                $familyNeedsMedicalForm = true;
            }
            
            // Get parents for this youth
            $parents = ParentRelationships::listParentsForChild($youthId);
            foreach ($parents as $parent) {
                // Check if this parent needs a medical form
                if (needsMedicalForm($parent['medical_forms_expiration_date'] ?? null)) {
                    $familyNeedsMedicalForm = true;
                }
                if (!empty($parent['email'])) {
                    $familyEmails[] = trim($parent['email']);
                }
            }
            
            // Add family emails if medical form filter is off OR family needs medical forms
            if ($medicalFormOnly !== '1' || $familyNeedsMedicalForm) {
                $emails = array_merge($emails, $familyEmails);
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
