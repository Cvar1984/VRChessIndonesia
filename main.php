<?php

require_once 'MatchManager.php';
require_once 'CSVDatabaseManager.php';

try {
    echo "🏁 Chess Rating System\n";
    echo str_repeat('=', 60) . "\n\n";

    // Choose database implementation
    $db = new CSVDatabaseManager('data/player.csv', 'data/match.csv');
    
    // OPTIONAL: Clear existing data for a fresh start (uncomment to reset)
    // $db->clearData();
    
    // Initialize match manager
    $manager = new MatchManager($db);
    $manager->initialize();

    // Play matches
    echo "\n🎯 Match 1: Alice vs Bob\n";
    $result1 = $manager->play('Alice', 'Bob', MatchManager::WHITE_WIN, 'https://example.com/analysis/123');
    
    echo "\n🎯 Match 2: Charlie vs David\n";
    $result2 = $manager->play('Charlie', 'David', MatchManager::DRAW, 'https://example.com/analysis/456');
    
    echo "\n🎯 Match 3: Eve vs Frank\n";
    $result3 = $manager->play('Eve', 'Frank', MatchManager::BLACK_WIN, 'https://example.com/analysis/789');
    
    echo "\n🎯 Match 4: Alice vs George\n";
    $result4 = $manager->play('Alice', 'George', MatchManager::WHITE_WIN, 'https://example.com/analysis/101');

    echo "\n🎯 Match 5: Bob vs Charlie\n";
    $result5 = $manager->play('Bob', 'Charlie', MatchManager::DRAW, 'https://example.com/analysis/202');
    
    echo "\n🎯 Match 6: Alice vs Charlie\n";
    $result6 = $manager->play('Alice', 'Charlie', MatchManager::BLACK_WIN, 'https://example.com/analysis/303');
    
    $manager->debug();
    $manager->showRankings();
    $manager->showHistory();
    $manager->cleanup();

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
