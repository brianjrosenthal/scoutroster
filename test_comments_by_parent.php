<?php
require_once 'config.php';
require_once 'lib/RSVPManagement.php';

// Test script for getCommentsByParentForEvent function
echo "Testing getCommentsByParentForEvent function\n";
echo "==========================================\n\n";

// Test with a sample event ID (you can change this to an actual event ID in your database)
$eventId = 1;

try {
    echo "Testing getCommentsByCreatorForEvent (original function):\n";
    $commentsByCreator = RSVPManagement::getCommentsByCreatorForEvent($eventId);
    echo "Results: " . json_encode($commentsByCreator, JSON_PRETTY_PRINT) . "\n\n";

    echo "Testing getCommentsByParentForEvent (new function):\n";
    $commentsByParent = RSVPManagement::getCommentsByParentForEvent($eventId);
    echo "Results: " . json_encode($commentsByParent, JSON_PRETTY_PRINT) . "\n\n";

    echo "Comparison:\n";
    echo "- Original function returned " . count($commentsByCreator) . " entries\n";
    echo "- New function returned " . count($commentsByParent) . " entries\n";
    
    if (count($commentsByParent) >= count($commentsByCreator)) {
        echo "✓ New function returned same or more entries (expected behavior)\n";
    } else {
        echo "⚠ New function returned fewer entries than original (unexpected)\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "This is likely because event ID $eventId doesn't exist or there are no RSVPs with comments.\n";
    echo "Try changing the \$eventId variable to an actual event ID in your database.\n";
}

echo "\nTest completed.\n";
?>
