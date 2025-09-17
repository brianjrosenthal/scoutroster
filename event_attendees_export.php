<?php
require_once __DIR__.'/partials.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/RSVPManagement.php';
require_once __DIR__ . '/lib/GradeCalculator.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

if (!$isAdmin) {
    http_response_code(403);
    exit('Access denied');
}

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($eventId <= 0) {
    http_response_code(400);
    exit('Missing event_id');
}

// Verify event exists
$event = EventManagement::findById($eventId);
if (!$event) {
    http_response_code(404);
    exit('Event not found');
}

try {
    $pdo = pdo();
    
    // Get youth attendees (RSVP'd yes)
    $youthQuery = "
        SELECT DISTINCT 
            y.last_name,
            y.first_name, 
            y.bsa_registration_number,
            y.class_of
        FROM rsvp_members rm
        JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer = 'yes'
        JOIN youth y ON y.id = rm.youth_id
        WHERE rm.event_id = ? AND rm.participant_type = 'youth' AND rm.youth_id IS NOT NULL
        ORDER BY y.last_name, y.first_name
    ";
    
    $youthStmt = $pdo->prepare($youthQuery);
    $youthStmt->execute([$eventId]);
    $youthAttendees = $youthStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get adult attendees (RSVP'd yes)
    $adultQuery = "
        SELECT DISTINCT 
            u.last_name,
            u.first_name,
            u.bsa_membership_number,
            u.id as adult_id
        FROM rsvp_members rm
        JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer = 'yes'
        JOIN users u ON u.id = rm.adult_id
        WHERE rm.event_id = ? AND rm.participant_type = 'adult' AND rm.adult_id IS NOT NULL
        ORDER BY u.last_name, u.first_name
    ";
    
    $adultStmt = $pdo->prepare($adultQuery);
    $adultStmt->execute([$eventId]);
    $adultAttendees = $adultStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get leadership positions for adults
    $leadershipQuery = "
        SELECT adult_id, GROUP_CONCAT(position ORDER BY position SEPARATOR ', ') as positions
        FROM adult_leadership_positions 
        WHERE adult_id IN (" . str_repeat('?,', count($adultAttendees) - 1) . "?)
        GROUP BY adult_id
    ";
    
    $leadershipPositions = [];
    if (!empty($adultAttendees)) {
        $adultIds = array_column($adultAttendees, 'adult_id');
        $leadershipStmt = $pdo->prepare($leadershipQuery);
        $leadershipStmt->execute($adultIds);
        $leadershipResults = $leadershipStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($leadershipResults as $row) {
            $leadershipPositions[(int)$row['adult_id']] = $row['positions'];
        }
    }
    
    // Build CSV data
    $csvData = [];
    $csvData[] = ['Last name', 'First name', 'BSA Registration ID', 'Grade', 'Leadership Position'];
    
    // Add adults first
    foreach ($adultAttendees as $adult) {
        $adultId = (int)$adult['adult_id'];
        $leadership = isset($leadershipPositions[$adultId]) ? $leadershipPositions[$adultId] : '';
        
        $csvData[] = [
            $adult['last_name'] ?: '',
            $adult['first_name'] ?: '',
            $adult['bsa_membership_number'] ?: '',
            'Adult',
            $leadership
        ];
    }
    
    // Add youth, sorted by grade (descending) then name
    $youthWithGrades = [];
    foreach ($youthAttendees as $youth) {
        $grade = GradeCalculator::gradeForClassOf((int)$youth['class_of']);
        $youthWithGrades[] = array_merge($youth, ['calculated_grade' => $grade]);
    }
    
    // Sort youth by grade (descending), then by last name, first name
    usort($youthWithGrades, function($a, $b) {
        $gradeCompare = $b['calculated_grade'] - $a['calculated_grade']; // Descending grade
        if ($gradeCompare !== 0) return $gradeCompare;
        
        $lastNameCompare = strcasecmp($a['last_name'], $b['last_name']);
        if ($lastNameCompare !== 0) return $lastNameCompare;
        
        return strcasecmp($a['first_name'], $b['first_name']);
    });
    
    foreach ($youthWithGrades as $youth) {
        $gradeLabel = GradeCalculator::gradeLabel($youth['calculated_grade']);
        
        $csvData[] = [
            $youth['last_name'] ?: '',
            $youth['first_name'] ?: '',
            $youth['bsa_registration_number'] ?: '',
            $gradeLabel,
            '' // No leadership position for youth
        ];
    }
    
    // Convert to CSV string
    $csvString = '';
    foreach ($csvData as $row) {
        $escapedRow = array_map(function($field) {
            // Escape quotes and wrap in quotes if needed
            if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                return '"' . str_replace('"', '""', $field) . '"';
            }
            return $field;
        }, $row);
        $csvString .= implode(',', $escapedRow) . "\n";
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'event_name' => $event['name'],
        'adult_count' => count($adultAttendees),
        'youth_count' => count($youthAttendees),
        'csv_data' => $csvString
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate export: ' . $e->getMessage()
    ]);
}
