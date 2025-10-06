<?php
require_once __DIR__.'/partials.php';
require_admin();

header('Content-Type: application/json');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($query === '') {
  echo json_encode([]);
  exit;
}

try {
  $pdo = pdo();
  
  // Search for locations that match the query (case-insensitive)
  // Only return locations that have a non-empty location_address
  // Group by location and get the most recent address/google_maps_url for each
  $sql = "
    SELECT 
      location,
      location_address,
      google_maps_url,
      MAX(created_at) as last_used
    FROM events 
    WHERE location LIKE ? 
      AND location IS NOT NULL 
      AND location != ''
      AND location_address IS NOT NULL
      AND location_address != ''
    GROUP BY location, location_address, google_maps_url
    ORDER BY last_used DESC
    LIMIT 10
  ";
  
  $stmt = $pdo->prepare($sql);
  $stmt->execute(['%' . $query . '%']);
  $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Format results for typeahead
  $locations = [];
  foreach ($results as $row) {
    $locations[] = [
      'location' => $row['location'],
      'location_address' => $row['location_address'] ?? '',
      'google_maps_url' => $row['google_maps_url'] ?? ''
    ];
  }
  
  echo json_encode($locations);
  
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Search failed']);
}
