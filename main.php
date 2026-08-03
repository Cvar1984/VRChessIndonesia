<?php
// main.php
require_once './Rating.php';
require_once './CsvDatabase.php';
require_once './MatchManager.php';

MatchManager::play('Alice', 'Bob', MatchManager::WHITE_WIN, 'https://example.com/analysis/123');