<?php

declare(strict_types=1);

/*
 * This file is part of the Liszt Catalog Raisonne project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 */

namespace Slub\LisztBibliography\Command;

use Elastic\Elasticsearch\Client;
use Hedii\ZoteroApi\ZoteroApi;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slub\LisztCommon\Common\ElasticClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class IndexCommand extends Command
{

    const API_TRIALS = 3;

    protected string $apiKey;
    protected Collection $bibliographyItems;
    protected Collection $deletedItems;
    protected Collection $teiDataSets;
    protected Collection $dataSets;
    protected Client $client;
    protected array $extConf;
    protected SymfonyStyle $io;
    protected int $bulkSize;
    protected int $total;
    protected Collection $locales;
    protected Collection $localizedCitations;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
        $this->initLocales();
    }

    private function initLocales(): void
    {
        $this->locales = Collection::wrap($this->siteFinder->getAllSites())
            ->map(function (Site $site): array { return $site->getLanguages(); })
            ->flatten()
            ->map(function (SiteLanguage $language): string { return $language->getHreflang(); });
    }

    protected function getRequest(): ServerRequestInterface
    {
        return $GLOBALS['TYPO3_REQUEST'];
    }

    protected function configure(): void
    {
        $this->setDescription('Create elasticsearch index from zotero bibliography')->
            addArgument(
                'version',
                InputArgument::OPTIONAL,
                'The version number of the most recently updated data set.'
            )->
            addOption(
            'total',
            't',
            InputOption::VALUE_REQUIRED,
            'Limit the total number of results for dev purposes and force fullSync.'
        )->
            addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Set all if all data sets should be updated.'
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('liszt_bibliography');
        $this->client = ElasticClientBuilder::getClient();
        $this->apiKey = $this->extConf['zoteroApiKey'];
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getDescription());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bulkSize = (int) $this->extConf['zoteroBulkSize'];
        $version = $this->getVersion($input);
        if ($version == 0) {
            $this->io->text('Full data synchronization requested.');
            $this->fullSync($input);
            $this->logger->info('Full data synchronization successful.');
        } else {
            $this->io->text('Synchronizing all data from version ' . $version);
            $this->versionedSync($version);
            $this->logger->info('Versioned data synchronization successful.');
        }
        return Command::SUCCESS;
    }

    protected function fullSync(InputInterface $input): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $response = $client->
            group($this->extConf['zoteroGroupId'])->
            items()->
            top()->
            limit(1)->
            send();
        if ($input->getOption('total')) {
            $this->total = (int) $input->getOption('total');
        } else {
            $this->total = (int) $response->getHeaders()['Total-Results'][0];
        }

        // fetch bibliography items bulkwise
        $this->io->progressStart($this->total);
        $collection = new Collection($response->getBody());
        $this->bibliographyItems = $collection->pluck('data');
        $cursor = 0; // set Cursor to 0, not to bulk size
        $index = $this->extConf['elasticIndexName'];
        try {
            // in older Elasticsearch versions (until 7) exists returns a bool
            if ($this->client->indices()->exists(['index' => $index])) {
                $this->client->indices()->delete(['index' => $index]);
                $this->client->indices()->create(['index' => $index]);
            }
        } catch (\Exception $e) {
            // other versions return a Message object
            if ($e->getCode() === 404) {
                $this->io->note("Index: " . $index . " does not exist. Trying to create new index.");
                $this->client->indices()->create(['index' => $index]);
            } else {
                $this->io->error("Exception: " . $e->getMessage());
                $this->logger->error('Bibliography sync unsuccessful. Error creating elasticsearch index.');
                die;
            }
        }

        $apiCounter = self::API_TRIALS;

        while ($cursor < $this->total) {
            try {
                $this->sync($cursor, 0);

                $apiCounter = self::API_TRIALS;
                $remainingItems = $this->total - $cursor;
                $advanceBy = min($remainingItems, $this->bulkSize);
                $this->io->progressAdvance($advanceBy);
                $cursor += $this->bulkSize;
            } catch (\Exception $e) {
                $this->io->newline(1);
                $this->io->caution($e->getMessage());
                $this->io->newline(1);
                if ($apiCounter == 0) {
                    $this->io->note('Giving up after ' . self::API_TRIALS . ' trials.');
                    $this->logger->error('Bibliography sync unsuccessful. Zotero API sent {trials} 500 errors.', ['trials' => self::API_TRIALS]);
                    die;
                } else {
                    $this->io->note('Trying again. ' . --$apiCounter . ' trials left.');
                }
            }
        }
        $this->io->progressFinish();
    }

    protected function versionedSync(int $version): void
    {
        $apiCounter = self::API_TRIALS;
        while (true) {
            try {
                $this->sync(0, $version);
                $this->io->text('done');
                return;
            } catch (\Exception $e) {
                $this->io->newline(1);
                $this->io->caution($e->getMessage());
                $this->io->newline(1);
                if ($apiCounter == 0) {
                    $this->io->note('Giving up after ' . self::API_TRIALS . ' trials.');
                    $this->logger->warning('Bibliography sync unseccessful. Zotero API sent {trials} 500 errors.', ['trials' => self::API_TRIALS]);
                    die; // Todo: die ist not recommended, better throw an exception?
                } else {
                    $this->io->note('Trying again. ' . --$apiCounter . ' trials left.');
                }
            }
        }
    }

    protected function sync(int $cursor = 0, int $version = 0): void
    {
        $this->fetchBibliography($cursor, $version);
        $this->fetchCitations($cursor, $version);
        $this->fetchTeiData($cursor, $version);
        $this->buildDataSets();
        $this->commitBibliography();
    }

    protected function getVersion(InputInterface $input): int
    {
        // if -a is specified, perfom a full update
        if ($input->getOption('all')) {
            return 0;
        }

        // also set version to 0 for dev tests if the total results are limited
        if ($input->getOption('total')) {
            $this->io->text('Total results limited to: '. $input->getOption('total'));
            return 0;
        }


        // if a version is manually specified, perform sync from this version
        $argumentVersion = $input->getArgument('version');
        if ($argumentVersion > 0) {
            return (int) $argumentVersion;
        }

        // get most recent version from stored data
        $params = [
            'index' => $this->extConf['elasticIndexName'],
            'body' => [
                'aggs' => [
                    'max_version' => [
                        'max' => [
                            'field' => 'version'
                        ]
                    ]
                ],
                'size' => 0
            ]
        ];

        try {
            $response = $this->client->search($params);
            return (int) $response['aggregations']['max_version']['value'];
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                // Index not found, return 0
                $this->io->note('No Index with name: ' . $this->extConf['elasticIndexName'] . ' found. Return 0 as Version, create new index in next steps...');
                return 0;
            } else {
                $this->io->error("Exception: " . $e->getMessage());
                die; // Todo: die ist not recommended, better throw an exception?
            }
        }
    }

    protected function fetchBibliography(int $cursor, int $version): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $response = $client->
            group($this->extConf['zoteroGroupId'])->
            items()->
            top()->
            start($cursor)->
            limit($this->bulkSize)->
            setSince($version)->
            send();

        $this->bibliographyItems = Collection::wrap($response->getBody())->
            pluck('data');
    }

    protected function fetchCitations(int $cursor, int $version): void
    {
        $this->localizedCitations = new Collection();
        $this->locales->each(function($locale) use($cursor, $version) { $this->fetchCitationLocale($locale, $cursor, $version); });
    }

    protected function fetchCitationLocale(string $locale, int $cursor, int $version): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $style = $this->extConf['zoteroStyle'];
        $response = $client->
            group($this->extConf['zoteroGroupId'])->
            items()->
            top()->
            start($cursor)->
            limit($this->bulkSize)->
            setSince($version)->
            setInclude('citation')->
            setStyle($style)->
            setLinkwrap()->
            setLocale($locale)->
            send();

        $this->localizedCitations = new Collection([ $locale =>
                Collection::wrap($response->getBody())->
                    keyBy('key')
            ]);
    }

    protected function fetchTeiData(int $cursor, int $version): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $response = $client->
            group($this->extConf['zoteroGroupId'])->
            items()->
            top()->
            start($cursor)->
            limit($this->bulkSize)->
            setSince($version)->
            setInclude('tei')->
            send();
        $collection = new Collection($response->getBody());

        $this->teiDataSets = Collection::wrap($response->getBody())->
            keyBy('key');
    }

    protected function buildDataSets(): void
    {
        $this->dataSets = $this->bibliographyItems->
            map(function($bibliographyItem) {
                return self::buildDataSet($bibliographyItem, $this->localizedCitations, $this->teiDataSets);
            });
    }

    protected static function buildDataSet(
        array $bibliographyItem,
        Collection $localizedCitations,
        Collection $teiDataSets
    )
    {
        $key = $bibliographyItem['key'];
        $bibliographyItem['localizedCitations'] = [];
        foreach ($localizedCitations as $locale => $localizedCitation) {
            $bibliographyItem['localizedCitations'][$locale] = $localizedCitation->get($key)['citation'];
        }
        $bibliographyItem['tei'] = $teiDataSets->get($key);

        return $bibliographyItem;
    }

    protected function commitBibliography(): void
    {
        if ($this->dataSets->count() == 0) {
            $this->io->text('no new bibliographic entries');
            return;
        }
        $index = $this->extConf['elasticIndexName'];

        $params = [ 'body' => [] ];
        $bulkCount = 0;
        foreach ($this->dataSets as $document) {
            $params['body'][] = [ 'index' =>
                [
                    '_index' => $index,
                    '_id' => $document['key']
                ]
            ];
            $params['body'][] = json_encode($document);

            if (!(++$bulkCount % $this->extConf['elasticBulkSize'])) {
                $this->client->bulk($params);
                $params = [ 'body' => [] ];
            }
        }
        $this->client->bulk($params);
    }

}
