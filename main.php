<?php

require __DIR__ . '/vendor/autoload.php';

use VRchessIndo\Logic\MatchManager;
use VRchessIndo\Connection\CSVDatabaseManager;

try {
    // Choose database implementation
    $db = new CSVDatabaseManager('data/player.csv', 'data/match.csv');
    // Initialize match manager
    $manager = new MatchManager($db);
    // Play matches
    $result1 = $manager->play('Alice', 'Bob', MatchManager::WHITE_WIN, 'https://example.com/analysis/123');
    $result2 = $manager->play('Charlie', 'David', MatchManager::DRAW, 'https://example.com/analysis/456');
    $result3 = $manager->play('Eve', 'Frank', MatchManager::BLACK_WIN, 'https://example.com/analysis/789');
    $result4 = $manager->play('Alice', 'George', MatchManager::WHITE_WIN, 'https://example.com/analysis/101');
    $result5 = $manager->play('Bob', 'Charlie', MatchManager::DRAW, 'https://example.com/analysis/202');
    $result6 = $manager->play('Alice', 'Charlie', MatchManager::BLACK_WIN, 'https://example.com/analysis/303');
    print_r($manager->getPlayers());
    print_r($manager->getMatches());
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
