<?php

declare(strict_types = 1);

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

define('RACE_ID', 302);

require_once('vendor/autoload.php');
require_once('ProCyclingStatsFetcher.php');
require_once('ScoritoFormatter.php');

$scorito = new ScoritoClassicsGame(
    RACE_ID,
    [
        'Omloop Nieuwsblad',
        'Kuurne - Brussel - Kuurne',
        'Paris-Nice',
        'Tirreno-Adriatico',
        'Strade Bianche',
        'Milano-Sanremo',
        'Ronde Van Brugge - Tour of Bruges',
        'E3 Saxo Classic',
        'In Flanders Fields - From Middelkerke to Wevelgem',
        'Dwars door Vlaanderen - A travers la Flandre',
        'Ronde van Vlaanderen',
        'Scheldeprijs',
        'Paris-Roubaix Hauts-de-France',
        'De Brabantse Pijl - La Flèche Brabançonne',
        'Amstel Gold Race',
        'La Flèche Wallonne',
        'Liège-Bastogne-Liège',
    ]
);

$scoritoData = $scorito->fetch();

$out = fopen('classics.csv', 'w');
fputcsv($out, array_keys($scoritoData[0]), ",", '"', "\\");

foreach ($scoritoData as $row) {
    fputcsv(
        $out,
        array_map(function ($col) {
            if (is_array($col)) {
                return print_r($col, true);
            }
            return $col;
        }, $row),
        ",",
        '"',
        "\\",
    );
}
fclose($out);

class ScoritoClassicsGame {
    private HttpClientInterface $client;
    private int $raceId;
    private ProCyclingStatsFetcher $fetcher;

    public function __construct(int $raceId, array $filterRaces)
    {
        $this->raceId = $raceId;
        $this->client = HttpClient::create();
        $this->fetcher = new ProCyclingStatsFetcher($this->client, $filterRaces);
    }

    public function fetchTeams(): array
    {
        $response = $this->client->request('GET', 'https://cycling.scorito.com/cycling/v2.0/team');
        $scoritoData = $response->toArray();

        return $scoritoData['Content'];
    }

    public function fetch(): array
    {
        $response = $this->client->request('GET', 'https://cycling.scorito.com/cyclingteammanager/v2.0/marketrider/' . $this->raceId);
        $scoritoData = $response->toArray();

        $teams = $this->fetchTeams();

        $filtered = $scoritoData['Content'];

        $filtered = array_map(['ScoritoFormatter', 'formatQualities'], $filtered);
        $filtered = array_map(['ScoritoFormatter', 'formatType'], $filtered);
        $filtered = array_map(fn (array $rider) => ScoritoFormatter::formatTeam($rider, $teams), $filtered);
        $filtered = array_map(['ScoritoFormatter', 'filterColumns'], $filtered);

        return $this->fetcher->fetchRiders($filtered, true, true, true);
    }
}
