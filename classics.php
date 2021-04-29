<?php

declare(strict_types = 1);

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

define('RACE_ID', 173);

require_once('vendor/autoload.php');
require_once('ProCyclingStatsFetcher.php');

$scorito = new ScoritoClassicsGame(
    RACE_ID,
    [
        'Omloop Het Nieuwsblad ME',
        'Kuurne - Bruxelles - Kuurne',
        'Gent-Wevelgem in Flanders Fields',
        'Dwars door Vlaanderen - A travers la Flandre',
        'Ronde van Vlaanderen - Tour des Flandres',
        'Paris-Roubaix',
        'E3 Saxo Bank Classic',
        'Oxyclean Classic Brugge-De Panne',
        'Milano-Sanremo',
        'La Flèche Wallonne',
        'Liège-Bastogne-Liège',
        'Strade Bianche',
        'Scheldeprijs',
        'De Brabantse Pijl - La Flèche Brabançonne',
        'Amstel Gold Race',
        'Eschborn-Frankfurt',
    ]
);

$scoritoData = $scorito->fetch();

$out = fopen('classics.csv', 'w');
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

    public function fetch(): array
    {
        $response = $this->client->request('GET', 'https://cycling.scorito.com/cyclingteammanager/v2.0/marketrider/' . $this->raceId);
        $scoritoData = $response->toArray();


        return $this->fetcher->fetchRiders($scoritoData['Content'], true, false);
    }
}
