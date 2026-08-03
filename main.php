<?php

require_once 'MatchManager.php';
require_once 'CSVDatabaseManager.php';

try {
    $db = new CSVDatabaseManager('data/player.csv', 'data/match.csv');
    
    // For SQL (future):
    // $db = new SQLDatabaseManager('localhost', 'chess_db', 'username', 'password');
    
    // Initialize match manager
    $manager = new MatchManager($db);
    $manager->initialize();

    // Play matches with existing and new players
    $result = $manager->play('David', 'Bob', MatchManager::WHITE_WIN, 'https://example.com/analysis/123');
    $manager->showRankings();
    $manager->showHistory();
    $manager->cleanup();

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
