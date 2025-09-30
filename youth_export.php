<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_once __DIR__.'/lib/UserManagement.php';
require_login();

$u = current_user();

// Use the same parameter handling as youth.php
$q = trim($_GET['q'] ?? '');
$gLabel = trim($_GET['g'] ?? ''); // grade filter: K,0,1..5
$g = $gLabel !== '' ? GradeCalculator::parseGradeLabel($gLabel) : null;
$onlyRegSib = array_key_exists('only_reg_sib', $_GET) ? (!empty($_GET['only_reg_sib'])) : true;
$includeUnreg = !$onlyRegSib;

// Get the same data as youth.php
$ctx = UserContext::getLoggedInUserContext();
$rows = YouthManagement::searchRoster($ctx, $q, $g, $includeUnreg);
$youthIds = array_map(static function($r){ return (int)$r['id']; }, $rows);
$parentsByYouth = !empty($youthIds) ? UserManagement::listParentsForYouthIds($ctx, $youthIds) : [];

// Sort by grade, then last name, then first name
usort($rows, function($a, $b) {
    $gradeA = GradeCalculator::gradeForClassOf((int)$a['class_of']);
    $gradeB = GradeCalculator::gradeForClassOf((int)$b['class_of']);
    
    if ($gradeA !== $gradeB) return $gradeA <=> $gradeB;
    if ($a['last_name'] !== $b['last_name']) return $a['last_name'] <=> $b['last_name'];
    return $a['first_name'] <=> $b['first_name'];
});

// Set CSV headers
$filename = 'youth_roster_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV header row
fputcsv($output, [
    'Grade',
    'Last Name', 
    'First Name',
    'Parent 1 Name',
    'Parent 1 Email',
    'Parent 1 Phone',
    'Parent 2 Name',
    'Parent 2 Email', 
    'Parent 2 Phone'
]);

// Process each youth
foreach ($rows as $y) {
    // Calculate current grade
    $grade = GradeCalculator::gradeForClassOf((int)$y['class_of']);
    $gradeLabel = ($grade < 0) ? 'Pre-K' : (($grade === 0) ? 'K' : (string)$grade);
    
    // Get youth name
    $firstName = trim($y['first_name'] ?? '');
    $lastName = trim($y['last_name'] ?? '');
    
    // Get parents for this youth
    $parents = $parentsByYouth[(int)$y['id']] ?? [];
    
    // Prepare parent data (up to 2 parents)
    $parent1Name = '';
    $parent1Email = '';
    $parent1Phone = '';
    $parent2Name = '';
    $parent2Email = '';
    $parent2Phone = '';
    
    $isAdminView = !empty($u['is_admin']);
    
    if (!empty($parents)) {
        // First parent
        if (isset($parents[0])) {
            $p1 = $parents[0];
            $parent1Name = trim(($p1['first_name'] ?? '') . ' ' . ($p1['last_name'] ?? ''));
            
            // Email (respect privacy settings)
            $rawEmail1 = $p1['email'] ?? '';
            $showEmail1 = $isAdminView || empty($p1['suppress_email_directory']);
            $parent1Email = ($showEmail1 && !empty($rawEmail1)) ? $rawEmail1 : '';
            
            // Phone (respect privacy settings, prefer cell over home)
            $rawPhone1 = !empty($p1['phone_cell']) ? $p1['phone_cell'] : ($p1['phone_home'] ?? '');
            $showPhone1 = $isAdminView || empty($p1['suppress_phone_directory']);
            $parent1Phone = ($showPhone1 && !empty($rawPhone1)) ? $rawPhone1 : '';
        }
        
        // Second parent
        if (isset($parents[1])) {
            $p2 = $parents[1];
            $parent2Name = trim(($p2['first_name'] ?? '') . ' ' . ($p2['last_name'] ?? ''));
            
            // Email (respect privacy settings)
            $rawEmail2 = $p2['email'] ?? '';
            $showEmail2 = $isAdminView || empty($p2['suppress_email_directory']);
            $parent2Email = ($showEmail2 && !empty($rawEmail2)) ? $rawEmail2 : '';
            
            // Phone (respect privacy settings, prefer cell over home)
            $rawPhone2 = !empty($p2['phone_cell']) ? $p2['phone_cell'] : ($p2['phone_home'] ?? '');
            $showPhone2 = $isAdminView || empty($p2['suppress_phone_directory']);
            $parent2Phone = ($showPhone2 && !empty($rawPhone2)) ? $rawPhone2 : '';
        }
    }
    
    // Write CSV row
    fputcsv($output, [
        $gradeLabel,
        $lastName,
        $firstName,
        $parent1Name,
        $parent1Email,
        $parent1Phone,
        $parent2Name,
        $parent2Email,
        $parent2Phone
    ]);
}

// Close output stream
fclose($output);
exit;
