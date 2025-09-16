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

if ($eventId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event ID']);
    exit;
}

try {
    $emails = [];
    
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
            
            // Get the adult's email
            $adultData = UserManagement::findById($adultId);
            if ($adultData && !empty($adultData['email'])) {
                $emails[] = trim($adultData['email']);
            }
            
            // Get all parents of children in this family
            $children = ParentRelationships::listChildrenForAdult($adultId);
            foreach ($children as $child) {
                $childId = (int)$child['id'];
                $parents = ParentRelationships::listParentsForChild($childId);
                
                foreach ($parents as $parent) {
                    $parentId = (int)$parent['id'];
                    if ($parentId !== $adultId) { // Don't duplicate the original adult
                        $parentData = UserManagement::findById($parentId);
                        if ($parentData && !empty($parentData['email'])) {
                            $emails[] = trim($parentData['email']);
                        }
                    }
                }
            }
        }
        
        // Get youth who RSVP'd with this answer and their parents
        // We need to query the rsvp_members table directly since there's no listYouthRsvpsByAnswer method
        $pdo = pdo();
        $stmt = $pdo->prepare("
            SELECT DISTINCT rm.youth_id
            FROM rsvp_members rm
            JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer = ?
            WHERE rm.event_id = ? AND rm.participant_type = 'youth' AND rm.youth_id IS NOT NULL
        ");
        $stmt->execute([$answer, $eventId]);
        
        while ($row = $stmt->fetch()) {
            $youthId = (int)$row['youth_id'];
            $parents = ParentRelationships::listParentsForChild($youthId);
            
            foreach ($parents as $parent) {
                $parentId = (int)$parent['id'];
                $parentData = UserManagement::findById($parentId);
                if ($parentData && !empty($parentData['email'])) {
                    $emails[] = trim($parentData['email']);
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
