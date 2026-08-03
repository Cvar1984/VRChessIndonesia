<?php
require_once './CsvDatabase.php';
class MatchManager
{
    const WHITE_WIN = 1;
    const DRAW      = 0;
    const BLACK_WIN = -1;

    private static $playersFile = 'data/player.csv';
    private static $matchesFile = 'data/match.csv';

    public static function play($white, $black, $result, $analysis = null)
    {
        $players = CsvDatabase::read(self::$playersFile);
        print_r($players);

        //CsvDatabase::write(self::$playersFile, $players);

        $matches = CsvDatabase::read(self::$matchesFile);
        $matches[] = [
            'id'       => uniqid(),
            'time'     => date('c'),
            'white'    => $white,
            'black'    => $black,
            'result'   => $result,
            'analysis' => $analysis,
        ];
        CsvDatabase::write(self::$matchesFile, $matches);

        return [
            'white' => [
                'name' => $white,
                'rating' => 1512,
                'change' => +12,
            ],
            'black' => [
                'name' => $black,
                'rating' => 1488,
                'change' => -12,
            ],
        ];
    }
}