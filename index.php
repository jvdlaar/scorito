<?php

declare(strict_types = 1);

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;

require_once('vendor/autoload.php');

$scorito = new ScoritoKlassier(
    173,
    [
        'Omloop Het Nieuwsblad Elite',
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
dump($scoritoData);

// write to csv

class ScoritoKlassier {
    private HttpClientInterface $client;
    private int $raceId;
    private array $filterRaces;
    private AdapterInterface $cache;

    public function __construct(int $raceId, array $filterRaces, string $cacheDir = './cache/')
    {
        $this->raceId = $raceId;
        $this->filterRaces = $filterRaces;
        $this->client = HttpClient::create();
        $this->cache = new FilesystemAdapter();
    }

    public function fetch(): array
    {
        $response = $this->client->request('GET', 'https://cycling.scorito.com/cyclingteammanager/v2.0/marketrider/' . $this->raceId);
        $scoritoData = $response->toArray();


        return $this->fetchRaces($scoritoData['Content']);
    }

    public static function formatRiderName(array $rider): string
    {
        return mb_strtolower($rider['FirstName']) . '-' . mb_strtolower($rider['LastName']);
    }

    protected function fetchRaces(array $riders): array
    {
        $chunks = array_chunk($riders, 50, true);

        foreach ($chunks as $chunk) {
            $responses = [];
            foreach ($chunk as $index => $rider) {
                $cacheItem = $this->cache->getItem(self::formatRiderName($rider));
                if ($cacheItem->isHit()) {
                    $riders[$index]['Races'] = $cacheItem->get();

                    echo "CACHE HIT: " . self::formatRiderName($riders[$index]) . PHP_EOL;
                }
                else {
                    $responses[] = $this->fetchProcyclingstats($rider, $index, $cacheItem);
                }
            }

            $didFetches = false;
            foreach ($this->client->stream($responses) as $response => $chunk) {
                $didFetches = true;
                if ($chunk->isLast()) {
                    $races = $this->processProcyclingstats($response);
                    $index = $response->getInfo('user_data')['index'];
                    $cacheItem = $response->getInfo('user_data')['cache_item'];

                    $cacheItem->set($races);
                    $cacheItem->expiresAfter(24*60*60); // 1 day

                    $this->cache->save($cacheItem);
    
                    $riders[$index]['Races'] = $races;

                    echo "FETCHED: " . self::formatRiderName($riders[$index]) . PHP_EOL;
                }
            }

            $didFetches && sleep(5);
        }

        return $riders;
    }

    protected function fetchProcyclingstats(array $rider, int $index, CacheItem $cacheItem): ResponseInterface
    {
        $url = 'https://www.procyclingstats.com/rider/' . self::formatRiderName($rider);

        return $this->client->request('GET', $url, ['user_data' => ['index' => $index, 'cache_item' => $cacheItem]]);
    }

    protected function processProcyclingstats(ResponseInterface $response): array
    {
        $crawler = new Crawler($response->getContent());

        $races = $crawler->filterXPath('//h3[text()="Upcoming participations"]/following-sibling::ul//div[contains(@class, "ellipsis")]')->each(
            function (Crawler $node, $i) {
                return $node->text();
            }
        );

        $races = array_filter($races, function($race) {
            return in_array($race, $this->filterRaces);
        });
        
        return $races;
    }
}