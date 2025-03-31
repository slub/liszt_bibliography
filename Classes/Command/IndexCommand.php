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
use Slub\LisztCommon\Common\Collection;
use Slub\LisztCommon\Common\Str;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slub\LisztCommon\Common\ElasticClientBuilder;
use Slub\LisztBibliography\Processing\BibEntryProcessor;
use Slub\LisztBibliography\Processing\BibElasticMapping;
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

    protected string $zoteroApiKey;
    protected Collection $bibliographyItems;
    protected int $bulkSize;
    protected Client $client;
    protected Collection $collectionIds;
    protected Collection $dataSets;
    protected Collection $deletedItems;
    protected array $extConf;
    protected array $collectionToItemTypeMap;
    readonly string $indexName;
    protected InputInterface $input;
    protected SymfonyStyle $io;
    protected Collection $locales;
    protected Collection $localizedCitations;
    protected OutputInterface $output;
    protected Collection $teiDataSets;
    protected int $total;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        // Note the logLevel setting in the Extension Configuration
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
        $this->extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('liszt_bibliography');
        //var_dump($this->extConf['collectionToItemTypeMap']);
        //var_dump(json_decode($this->extConf['collectionToItemTypeMap'], true));
        $this->collectionToItemTypeMap = json_decode($this->extConf['collectionToItemTypeMap'], true);
        $this->indexName = $this->extConf['elasticIndexName'] . '_' . date('Ymd_His');
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


    //  ddev typo3 liszt-bibliography:index -t 100   // index only 100 docs for testing and dev
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
        $this->input = $input;
        $this->output = $output;
        $this->client = ElasticClientBuilder::getClient();
        $this->zoteroApiKey = $this->extConf['zoteroApiKey'];
        $this->io = GeneralUtility::makeInstance(SymfonyStyle::class, $this->input, $this->output);
        $this->io->title($this->getDescription());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bulkSize = (int) $this->extConf['zoteroBulkSize'];
        $version = $this->getVersion();
        $this->getCollectionIdsRecursively();

        if ($version == 0) {
            $this->io->text('Full data synchronization requested.');
            $this->fullSync();
            $this->logger->notice('Full data synchronization successful.');
        } else {
            $this->io->text('Synchronizing all data from version ' . $version);
            $this->collectionIds->
                each( function($collectionId) use ($version) { $this->versionedSync($version, $collectionId); });
            $this->logger->notice('Versioned data synchronization successful.');
        }
        return Command::SUCCESS;
    }

    private function getCollectionIdsRecursively(): void
    {
        $this->collectionIds = new Collection();
        $configuredCollectionIds = Str::of($this->extConf['zoteroCollectionId'])->
            explode(',')->
            map(function ($id) { return trim($id); })->
            each( function($collectionId) { $this->recordSubcollectionRecursiveley($collectionId); });

        if ($this->collectionIds->count() == 0) {
            $this->collectionIds = Collection::wrap([null]);
        }
    }

    private function getSubcollections(string $collectionId): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $response = $client->
            group($this->extConf['zoteroGroupId'])->
            collections($collectionId)->
            collections()->
            send();
        Collection::wrap($response->getBody())->
            recursive()->
            pluck('key')->
            each( function ($collectionId) { $this->recordSubcollectionRecursiveley($collectionId); });
    }

    private function recordSubcollectionRecursiveley(string $collectionId): void
    {
        if ($collectionId) {
            $this->collectionIds->push($collectionId);
            $this->getSubcollections($collectionId);
        }
    }

    protected function fullSync(): void
    {

        // fetch bibliography items bulkwise
        /*
        $collection = new Collection($response->getBody());
        $this->bibliographyItems = $collection->pluck('data');
        */
        $cursor = 0;
        // we are working with alias names to swap indexes from zotero_temp to zotero after successfully indexing
        $tempIndexAlias = $this->extConf['elasticIndexName'].'_temp';
        $tempIndexParams = BibElasticMapping::getMappingParams($this->indexName);

        // add alias name 'zotero_temp to this index
        // and add a wildcard alias to find all zotero_* indices with the alias zotero-index
        $aliasParams = [
            'body' => [
                'actions' => [
                    [
                        'add' => [
                            'index' => $this->indexName,
                            'alias' => $tempIndexAlias,
                        ],
                    ],
                    [
                        'add' => [
                            'index' => $this->extConf['elasticIndexName'].'_*',
                            'alias' => $this->extConf['elasticIndexName'].'-index',
                        ],
                    ]
                ]
            ]
        ];

        try {
                $this->client->indices()->create($tempIndexParams);
                $this->client->indices()->updateAliases($aliasParams);
        } catch (\Exception $e) {
                $this->io->error("Exception: " . $e->getMessage());
                $this->logger->error('Bibliography sync unsuccessful. Error creating elasticsearch index.');
                throw new \Exception('Bibliography sync unsuccessful.');
        }

        $apiCounter = self::API_TRIALS;

        $this->collectionIds->
            each( function($collectionId) {
                $this->io->section('Retrieving items from collection ' . $collectionId);
                $client = GeneralUtility::makeInstance(ZoteroApi::class, $this->zoteroApiKey);
                $client->
                    group($this->extConf['zoteroGroupId']);
                if ($collectionId != null) {
                    $client->
                        collections($collectionId);
                }
                $response = $client->
                    items()->
                    top()->
                    limit(1)->
                    send();
                if ($this->input->getOption('total')) {
                    $total = (int) $this->input->getOption('total');
                } else {
                    $total = (int) $response->getHeaders()['Total-Results'][0];
                }
                $cursor = 0;
                $this->io->progressStart($total);
                while ($cursor < $total) {
                    try {
                        $this->sync($cursor, 0, $collectionId);
                        $apiCounter = self::API_TRIALS;
                        $remainingItems = $total - $cursor;
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
                            throw new \Exception('Bibliography sync unsuccessful.');
                        } else {
                            $this->io->note('Trying again. ' . --$apiCounter . ' trials left.');
                        }
                    }
                }
                $this->io->progressFinish();
            });

        // swap alias for index from zotero_temp to zotero and remove old indexes (keep the last one)
        $this->swapIndexAliases($tempIndexAlias);
        //delete old indexes
        $this->deleteOldIndexes();
    }

    protected function versionedSync(int $version, string $collectionId): void
    {
        if ($collectionId) {
            $this->io->section('Retrieving items from collection ' . $collectionId);
        }

        $apiCounter = self::API_TRIALS;
        while (true) {
            try {
                $this->sync(0, $version, $collectionId);
                $this->io->text('done');
                return;
            } catch (\Exception $e) {
                $this->io->newline(1);
                $this->io->caution($e->getMessage());
                $this->io->newline(1);
                if ($apiCounter == 0) {
                    $this->io->note('Giving up after ' . self::API_TRIALS . ' trials.');
                    $this->logger->warning('Bibliography sync unsuccessful. Zotero API sent {trials} 500 errors.', ['trials' => self::API_TRIALS]);
                    throw new \Exception('Bibliography sync unsuccessful.');
                } else {
                    $this->io->note('Trying again. ' . --$apiCounter . ' trials left.');
                }
            }
        }
    }

    protected function sync(int $cursor = 0, int $version = 0, ?string $collectionId = null): void
    {
        $this->fetchBibliography($cursor, $version, $collectionId);
      //  $this->fetchCitations($cursor, $version);
      //  $this->fetchTeiData($cursor, $version);
        $this->buildDataSets();
        $this->commitBibliography();
    }

    protected function getVersion(): int
    {
        // if -a is specified, perfom a full update
        if ($this->input->getOption('all')) {
            return 0;
        }

        // also set version to 0 for dev tests if the total results are limited
        if ($this->input->getOption('total')) {
            $this->io->text('Total results limited to: '. $this->input->getOption('total'));
            return 0;
        }


        // if a version is manually specified, perform sync from this version
        $argumentVersion = $this->input->getArgument('version');
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
                throw new \Exception('Bibliography sync unsuccessful.');
            }
        }
    }

    protected function fetchBibliography(int $cursor, int $version, ?string $collectionId): void
    {
        $client = GeneralUtility::makeInstance(ZoteroApi::class, $this->zoteroApiKey);
        $client->
            group($this->extConf['zoteroGroupId']);
        if ($collectionId != null) {
            $client->
                collections($collectionId);
        }
        $response = $client->
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
        $client = GeneralUtility::makeInstance(ZoteroApi::class, $this->zoteroApiKey);
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
        $bibEntryProcessor = new BibEntryProcessor($this->logger);

        $this->dataSets = $this->bibliographyItems->
            map(function($bibliographyItem) use ($bibEntryProcessor) {
                return $bibEntryProcessor->process(
                $bibliographyItem,
                $this->collectionToItemTypeMap,
                new Collection(),
                new Collection()
                //   $this->localizedCitations,
                //   $this->teiDataSets
                );
            });
    }

    protected function commitBibliography(): void
    {
        if ($this->dataSets->count() == 0) {
            $this->io->text('no new bibliographic entries');
            return;
        }
        $params = [ 'body' => [] ];
        $bulkCount = 0;
        foreach ($this->dataSets as $document) {
            $params['body'][] = [ 'index' =>
                [
                    '_index' => $this->indexName,
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

    protected function swapIndexAliases(string $tempIndexAlias): void
    {
        // get index with alias = zotero
        try {
            $aliasesRequest = $this->client->indices()->getAlias(['name' => $this->extConf['elasticIndexName']]);
            $aliasesArray = $aliasesRequest->asArray();

            foreach ($aliasesArray as $index => $aliasArray) {
                $this->io->note('Remove alias "' .$this->extConf['elasticIndexName']. '" from index '. $index . ' and add it to ' . $this->indexName );
                // get index name with alias 'zotero'
                if (array_key_exists($this->extConf['elasticIndexName'], $aliasArray['aliases'])) {
                    //swap alias from old to new index
                    $aliasParams = [
                        'body' => [
                            'actions' => [
                                [
                                    'remove' => [
                                        'index' => $index,
                                        'alias' => $this->extConf['elasticIndexName'],
                                    ],
                                ],
                                [
                                    'add' => [
                                        'index' => $this->indexName,
                                        'alias' => $this->extConf['elasticIndexName'],
                                    ],
                                ],
                                [
                                    'remove' => [
                                        'index' => $this->indexName,
                                        'alias' => $tempIndexAlias,
                                    ],
                                ]
                            ]
                        ]
                    ];
                    $this->client->indices()->updateAliases($aliasParams);
                }
            }
        }
        catch (\Exception $e) {
            // other versions return a Message object
            if ($e->getCode() === 404) {
                $this->io->note("Alias: " . $this->extConf['elasticIndexName'] . " does not exist. Move alias to ".$this->indexName);
                // rename alias name from temp index to zotero
                $aliasParams = [
                    'body' => [
                        'actions' => [
                            [
                                'remove' => [
                                    'index' => $this->indexName,
                                    'alias' => $tempIndexAlias,
                                ],
                            ],
                            [
                                'add' => [
                                    'index' => $this->indexName,
                                    'alias' => $this->extConf['elasticIndexName'],
                                ],
                            ]
                        ]
                    ]
                ];
                $this->client->indices()->updateAliases($aliasParams);

            } else {
                $this->io->error("Exception: " . $e->getMessage());
                $this->logger->error('Bibliography sync unsuccessful. Error getting alias: ' . $this->extConf['elasticIndexName']);
                throw new \Exception('Bibliography sync unsuccessful.', 0, $e);
            }
        }
    }

    protected function deleteOldIndexes(): void
    {
        try {
            $aliasesRequest = $this->client->indices()->getAlias(['name' => $this->extConf['elasticIndexName'].'-index']);
            $aliasesArray = $aliasesRequest->asArray();

            // sort $aliasesArray by key name
            ksort($aliasesArray);

            // remove current key $indexName from array
            unset($aliasesArray[$this->indexName]);

            // remove the last key (we keep the last two indexes)
            array_pop($aliasesArray);

            foreach ($aliasesArray as $index => $aliasArray) {
                $this->io->note("Delete index " . $index);
                $this->client->indices()->delete(['index' => $index]);
            }

        }
        catch (\Exception $e) {
            // other versions return a Message object
            if ($e->getCode() === 404) {
                $this->io->note("Nothing to remove, there are no indexes with alias " . $this->extConf['elasticIndexName'].'-index');
            } else {
                $this->io->error("Exception: " . $e->getMessage());
                $this->logger->error('Bibliography sync unsuccessful. Error getting alias: ' . $this->extConf['elasticIndexName'].'-index');
                throw new \Exception('Bibliography sync unsuccessful.', 0, $e);
            }
        }


    }

/*    protected function commitLocales(): void
    {
        $localeIndex = $this->extConf['elasticLocaleIndexName'];
        $this->io->text('Committing the ' . $localeIndex . ' index');

        if ($this->client->indices()->exists(['index' => $localeIndex])) {
            $this->client->indices()->delete(['index' => $localeIndex]);
            $this->client->indices()->create(['index' => $localeIndex]);
        }

        $params = [ 'body' => [] ];
        foreach ($this->locales as $key => $locale) {
            $params['body'][] = [ 'index' =>
                [
                    '_index' => $localeIndex,
                    '_id' => $key
                ]
            ];
            $params['body'][] = json_encode($locale);

        }
        $this->client->bulk($params);

        $this->io->text('done');
    }*/
}
