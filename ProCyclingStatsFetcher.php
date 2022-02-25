<?php

declare(strict_types = 1);

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ProCyclingStatsFetcher {
    private HttpClientInterface $client;
    private AdapterInterface $cache;
    private array $filterRaces;

    public function __construct(HttpClientInterface $client, array $filterRaces = [])
    {
        $this->client = $client;
        $this->cache = new FilesystemAdapter();
        $this->filterRaces = $filterRaces;
    }

    public static function formatRiderName(array $rider): string
    {
        $slugger = new AsciiSlugger();

        $slug =  (string) $slugger->slug($rider['FirstName'] . ' ' . $rider['LastName'])->lower();

        switch ($slug) {
            case 'daniel-martin':
                $slug = 'dan-martin';
                break;
            case 'omer-goldshtein':
                $slug = 'omer-goldstein';
                break;
            case 'chris-froome':
                $slug = 'christopher-froome';
                break;
            case 'alexey-lutsenko':
                $slug = 'aleksey-lutsenko';
                break;
            case 'soren-kragh':
                $slug = 'soren-kragh-andersen';
                break;
            case 'fred-wright':
                $slug = 'alfred-wright';
                break;
            case 'magnus-cort':
                $slug = 'magnus-cort-nielsen';
                break;
            case 'ivan-garcia':
                $slug = 'ivan-garcia-cortina';
                break;
            case 'georg-zimmerman':
                $slug = 'georg-zimmermann';
                break;
            case 'brandon-rivera':
                $slug = 'brandon-smith-rivera-vargas';
                break;
            case 'einer-rubio':
                $slug = 'einer-augusto-rubio-reyes';
                break;
            case 'diego-camargo':
                $slug = 'diego-andres-camargo';
                break;
            default;
                break;
        }
        return $slug;
    }

    protected function addUpcomingRacesToRider(bool $addUpcomingRaces, array $rider, array $races): array
    {
        if (!$addUpcomingRaces) {
            return $rider;
        }

        $rider['Races'] = count($races);
        foreach ($this->filterRaces as $race) {
            $rider[$race] = in_array($race, $races);
        }
        return $rider;
    }

    protected function addSpecialtiesToRider(bool $addRiderSpecialties, array $rider, array $riderSpecialties): array
    {
        if (!$addRiderSpecialties) {
            return $rider;
        }

        return array_merge($rider, array_combine(
            array_map(fn ($index) => "PCS $index", array_keys($riderSpecialties)),
            $riderSpecialties
        ));
    }

    protected function addResultsToRider(bool $addResults, array $rider, array $results): array
    {
        if (!$addResults) {
            return $rider;
        }

        return array_merge($rider, $results);
    }

    public function fetchRiders(array $riders, bool $addUpcomingRaces, bool $addRiderSpecialties, bool $addResults): array
    {
        $chunks = array_chunk($riders, 50, true);

        foreach ($chunks as $chunk) {
            $responses = [];
            foreach ($chunk as $index => $rider) {
                $cacheItem = $this->cache->getItem(self::formatRiderName($rider));
                if ($cacheItem->isHit()) {
                    $riders[$index] = $this->addUpcomingRacesToRider($addUpcomingRaces, $rider, $cacheItem->get()['upcoming_races']);
                    $riders[$index] = $this->addSpecialtiesToRider($addRiderSpecialties, $riders[$index], $cacheItem->get()['specialties']);

                    echo "CACHE HIT: " . self::formatRiderName($riders[$index]) . PHP_EOL;
                }
                else {
                    $responses[] = $this->fetchProCyclingStats($rider, $index, $cacheItem);
                }
            }

            $didFetches = false;
            foreach ($this->client->stream($responses) as $response => $chunk) {
                $didFetches = true;
                if ($chunk->isLast()) {
                    $index = $response->getInfo('user_data')['index'];
                    $cacheItem = $response->getInfo('user_data')['cache_item'];

                    try {
                        $upcomingRaces = $this->crawlUpcomingRaces($addUpcomingRaces, $response);
                        $specialties = $this->crawlSpecialty($addRiderSpecialties, $response);
                        $results = $this->crawlResults($addResults, $response);
                    } catch (CyclistNotFound $e) {
                        try {
                            $response = $this->fallbackProCyclingStats($riders[$index]);
                            $upcomingRaces = $this->crawlUpcomingRaces($addUpcomingRaces, $response);
                            $specialties = $this->crawlSpecialty($addRiderSpecialties, $response);
                            $results = $this->crawlResults($addResults, $response);
                        } catch (Exception $e) {
                            dump($e);
                            dump($response->getInfo('url'));
                            continue;
                        }
                    }

                    $cacheItem->set(['upcoming_races' => $upcomingRaces, 'specialties' => $specialties, 'results' => $results]);
                    $cacheItem->expiresAfter(24*60*60); // 1 day

                    $riders[$index] = $this->addUpcomingRacesToRider($addUpcomingRaces, $riders[$index], $upcomingRaces);
                    $riders[$index] = $this->addSpecialtiesToRider($addRiderSpecialties, $riders[$index], $specialties);
                    $riders[$index] = $this->addResultsToRider($addResults, $riders[$index], $results);

                    $this->cache->save($cacheItem);

                    echo "FETCHED: " . self::formatRiderName($riders[$index]) . PHP_EOL;
                }

                if (200 !== $response->getStatusCode()) {
                    throw new \Exception($response->getStatusCode() . ' || ' . $response->getInfo('url'));
                }
            }

            $didFetches && sleep(5);
        }

        return $riders;
    }

    protected function fetchProCyclingStats(array $rider, int $index, CacheItem $cacheItem): ResponseInterface
    {
        $url = 'https://www.procyclingstats.com/rider/' . self::formatRiderName($rider);

        return $this->client->request('GET', $url, ['user_data' => ['index' => $index, 'cache_item' => $cacheItem]]);
    }

    protected function crawlUpcomingRaces(bool $addUpcomingRaces, ResponseInterface $response): array
    {
        if (!$addUpcomingRaces) {
            return [];
        }

        $crawler = new Crawler($response->getContent());

        $title = $crawler->filterXPath('//title')->text();
        if (str_contains($title, 'Page not found')) {
            throw new CyclistNotFound($response->getInfo('url'));
        }

        $upcomingRaces = $crawler->filterXPath('//h3[text()="Upcoming participations"]/following-sibling::ul//div[contains(@class, "ellipsis")]')->each(
            function (Crawler $node, $i) {
                return $node->text();
            }
        );

        $upcomingRaces = array_filter($upcomingRaces, function($race) {
            return in_array($race, $this->filterRaces);
        });

        return $upcomingRaces;
    }

    protected function crawlSpecialty(bool $addRiderSpecialties, ResponseInterface $response): array
    {
        if (!$addRiderSpecialties) {
            return [];
        }

        $crawler = new Crawler($response->getContent());

        $title = $crawler->filterXPath('//title')->text();
        if (str_contains($title, 'Page not found')) {
            throw new CyclistNotFound($response->getInfo('url'));
        }

        $specialtiesPoints = $crawler->filterXPath('//h3[text()="Points per specialty"]/following-sibling::ul//div[contains(@class, "pnt")]')->each(
            function (Crawler $node, $i) {
                return $node->text();
            }
        );

        $specialtiesLabels = $crawler->filterXPath('//h3[text()="Points per specialty"]/following-sibling::ul//div[contains(@class, "title")]')->each(
            function (Crawler $node, $i) {
                return $node->text();
            }
        );

        return array_combine($specialtiesLabels, $specialtiesPoints);
    }

    protected function crawlResults(bool $addResults, ResponseInterface $response): array
    {
        if (!$addResults) {
            return [];
        }

        $crawler = new Crawler($response->getContent());

        $title = $crawler->filterXPath('//title')->text();
        if (str_contains($title, 'Page not found')) {
            throw new CyclistNotFound($response->getInfo('url'));
        }

        $normal = 0;
        $itt = 0;
        $normal5 = 0;
        $itt5 = 0;

        $crawler->filterXPath('//div[@id="resultsCont"]//tbody//tr')->each(
            function (Crawler $node) use (&$normal, &$normal5, &$itt, &$itt5) {
                $date = $node->children()->eq(0)->text();
                $result = $node->children()->eq(1)->text();
                $raceName = $node->children()->eq(4)->text();

                if ($date && is_numeric($result) && $raceName) {
                    $result = (int) $result;
                    if (str_contains($raceName, '(ITT)')) {
                        if ($result <= 20) {
                            $itt++;
                        }
                        if ($result <= 5) {
                            $itt5++;
                        }
                    }
                    else {
                        if ($result <= 20) {
                            $normal++;
                        }
                        if ($result <= 5) {
                            $normal5++;
                        }
                    }
                }
            }
        );

        return [
            'ITT Top 20' => $itt,
            'ITT Top 5' => $itt5,
            'Race Top 20' => $normal,
            'Race Top 5' => $normal5,
        ];
    }

    protected function fallbackProCyclingStats(array $rider): ResponseInterface
    {
        $response = $this->client->request('GET', 'https://www.procyclingstats.com/resources/search.php?searchfrom=&term=' . urlencode($rider['FirstName'] . ' ' . $rider['LastName']));
        if ($response->getContent() === 'null') {
            $response = $this->client->request('GET', 'https://www.procyclingstats.com/resources/search.php?searchfrom=&term=' . urlencode($rider['LastName']));
            if ($response->getContent() === 'null') {
                throw new CyclistNotFound($rider['FirstName'] . ' ' . $rider['LastName']);
            }
            dump($response->toArray());
        }

        $autoComplete = $response->toArray();

        return  $this->client->request('GET', 'https://www.procyclingstats.com/rider/' . $autoComplete[0]['id']);
    }
}

class CyclistNotFound extends \RuntimeException {}