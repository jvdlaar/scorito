<?php

declare(strict_types = 1);

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

define('RACE_ID', 171);

require_once('vendor/autoload.php');
require_once('ProCyclingStatsFetcher.php');
require_once('ScoritoFormatter.php');

$scorito = new ScoritoGrandTourGame(
    RACE_ID
);

$scoritoData = $scorito->fetch();

$out = fopen('grand-tour.csv', 'w');
fputcsv($out, array_keys($scoritoData[0]));

foreach ($scoritoData as $row) {
    fputcsv($out, array_map(function ($col) {
        if (is_array($col)) {
            return print_r($col, true);
        }
        return $col;
    }, $row));
}
fclose($out);

class ScoritoGrandTourGame {
    private HttpClientInterface $client;
    private int $raceId;
    private ProCyclingStatsFetcher $fetcher;

    public function __construct(int $raceId)
    {
        $this->raceId = $raceId;
        $this->client = HttpClient::create();
        $this->fetcher = new ProCyclingStatsFetcher($this->client);
    }

    public function fetchTeams(): array
    {
        $response = $this->client->request('GET', 'https://cycling.scorito.com/cycling/v2.0/team');
        $scoritoData = $response->toArray();

        return $scoritoData['Content'];
    }

    public function fetch(): array
    {
        $response = $this->client->request('GET', 'https://cycling.scorito.com/cyclingmanager/v1.0/eventriderenriched/' . $this->raceId);
        $scoritoData = $response->toArray();

        $teams = $this->fetchTeams();

        $filtered = $this->filterRidersOnStatus($scoritoData['Content']);

        $filtered = array_map(['ScoritoFormatter', 'formatQualities'], $filtered);
        $filtered = array_map(['ScoritoFormatter', 'formatType'], $filtered);
        $filtered = array_map(fn (array $rider) => ScoritoFormatter::formatTeam($rider, $teams), $filtered);
        $filtered = array_map(['ScoritoFormatter', 'filterColumns'], $filtered);

        return $this->fetcher->fetchRiders($filtered, false, true, true);
    }

    private function filterRidersOnStatus(array $riders): array
    {
        return array_values(array_filter($riders, fn($rider): bool => $rider['Status'] === 1));
    }
}
