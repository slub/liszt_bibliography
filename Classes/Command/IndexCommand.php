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

use Closure;
// use Elastic\Elasticsearch\ClientInterface; // no longer needed as a hard type
use Hedii\ZoteroApi\ZoteroApi;
use Slub\LisztCommon\Common\Collection;
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
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class IndexCommand extends Command
{

    protected const API_TRIALS = 3;

    // Rate limit handling (for 429 or Backoff/Retry-After headers)
    protected const RATE_LIMIT_MAX_RETRIES = 5;
    protected const RATE_LIMIT_DEFAULT_SECONDS = 5;
    protected const RATE_LIMIT_MAX_SECONDS = 60;

    protected string $zoteroApiKey;
    protected int $zoteroGroupId;
    protected string $elasticIndexName;
    protected string $elasticLocaleIndexName;
    protected int $elasticBulkSize;
    protected string $zoteroStyle;
    protected bool $zoteroLinkwrap;
    protected array $zoteroCollectionIds = [];
    protected array $collectionToItemTypeMap = [];

    protected string $indexName;

    protected Collection $bibliographyItems;
    protected int $bulkSize;
    protected $client; // no type because of inject in test

    protected Collection $collectionIds;
    protected Collection $dataSets;
    protected Collection $deletedItems;
    protected array $extConf = [];
    protected InputInterface $input;
    protected SymfonyStyle $io;
    protected Collection $locales;
    protected Collection $localizedCitations;
    protected OutputInterface $output;
    protected Collection $teiDataSets;
    protected int $total;

    /**
     * Allow tests to override sleeping behavior (e.g. no-op).
     * Defaults to PHP's native sleep() if not set.
     */
    private ?Closure $sleeper = null;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        // Note the logLevel setting in the Extension Configuration
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
        // Note: extConf and locales are initialized in initialize() to make testing easier.
    }

    private function initLocales(): void
    {
        $sites = $this->siteFinder->getAllSites();
        $this->locales = Collection::wrap($sites)
            ->map(function (Site $site): array { return $site->getLanguages(); })
            ->flatten()
            ->map(function (SiteLanguage $language): string { return $language->getHreflang(); });
    }

    protected function getRequest(): ServerRequestInterface
    {
        return $GLOBALS['TYPO3_REQUEST'];
    }

    // ddev typo3 liszt-bibliography:index -t 100   // index only 100 docs for testing and dev
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

    /**
     * Factory for ZoteroApi to make command testable (can be replaced via GeneralUtility::addInstance in tests).
     */
    protected function getZoteroApi(): ZoteroApi
    {
        // Always create via GeneralUtility to allow test overrides.
        return GeneralUtility::makeInstance(ZoteroApi::class, $this->zoteroApiKey);
    }

    /**
     * Factory for Elasticsearch client to enable test-time replacement of ElasticClientBuilder.
     */
    protected function getElasticClient()
    {
        $builder = GeneralUtility::makeInstance(ElasticClientBuilder::class);
        return $builder->getClient();
    }

    /**
     * Test-Hook: inject Elasticsearch-Client injizieren (overwrite instance from initialize()).
     */
    public function setElasticClient($client): void
    {
        $this->client = $client;
    }


    /**
     * Allow tests to inject a custom sleeper (e.g. a no-op).
     */
    public function setSleeper(callable $sleeper): void
    {
        // Contract: function (int $seconds): void
        $this->sleeper = $sleeper instanceof Closure ? $sleeper : Closure::fromCallable($sleeper);
    }

    /**
     * Wrapper around sleep to avoid real waiting in tests.
     */
    protected function sleep(int $seconds): void
    {
        if ($this->sleeper instanceof Closure) {
            ($this->sleeper)(max(0, (int)$seconds));
        } else {
            sleep(max(0, (int)$seconds));
        }
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;

        $this->io = GeneralUtility::makeInstance(SymfonyStyle::class, $this->input, $this->output);
        $this->io->title($this->getDescription());

        // Load extension configuration (done here to make tests easier).
        $this->extConf = (array)GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('liszt_bibliography');

        // Initialize locales here (so SiteFinder can be mocked easily).
        $this->initLocales();

        // Validate and assign required configuration

        // elasticIndexName (required)
        $elasticIndexName = trim((string)($this->extConf['elasticIndexName'] ?? ''));
        if ($elasticIndexName === '') {
            $this->io->error('Missing elasticIndexName in extension configuration. Aborting command.');
            $this->logger->error('Missing elasticIndexName in extension configuration. Command aborted.');
            throw new \RuntimeException('Missing elasticIndexName.');
        }
        $this->elasticIndexName = $elasticIndexName;
        $this->indexName = $this->elasticIndexName . '_' . date('Ymd_His');

        // API key (required)
        $apiKey = trim((string)($this->extConf['zoteroApiKey'] ?? ''));
        if ($apiKey === '') {
            $this->io->error('Missing Zotero API key in extension configuration. Aborting command.');
            $this->logger->error('Missing Zotero API key in extension configuration. Command aborted.');
            throw new \RuntimeException('Missing Zotero API key.');
        }
        $this->zoteroApiKey = $apiKey;

        // Group id must be positive int
        $groupIdRaw = $this->extConf['zoteroGroupId'] ?? null;
        $groupId = is_numeric($groupIdRaw) ? (int)$groupIdRaw : 0;
        if ($groupId <= 0) {
            $this->io->error('Missing or invalid Zotero group id in extension configuration. Aborting command.');
            $this->logger->error('Missing or invalid Zotero group id in extension configuration. Command aborted.');
            throw new \RuntimeException('Missing or invalid Zotero group id.');
        }
        $this->zoteroGroupId = $groupId;

        // Optional/soft-required configuration with defaults
        $this->elasticLocaleIndexName = trim((string)($this->extConf['elasticLocaleIndexName'] ?? ''));
        if ($this->elasticLocaleIndexName === '') {
            $this->elasticLocaleIndexName = 'zoterolocales';
            $this->io->note('elasticLocaleIndexName not set. Falling back to "zoterolocales".');
            $this->logger->notice('elasticLocaleIndexName not set. Falling back to "zoterolocales".');
        }

        $elasticBulkSize = (int)($this->extConf['elasticBulkSize'] ?? 0);
        if ($elasticBulkSize <= 0) {
            $elasticBulkSize = 20;
            $this->io->note('elasticBulkSize not set or invalid. Falling back to 20.');
            $this->logger->notice('elasticBulkSize not set or invalid. Falling back to 20.');
        }
        $this->elasticBulkSize = $elasticBulkSize;

        // zoteroBulkSize (int 1..100 due to API limit; default 50)
        $zoteroBulkSize = (int)($this->extConf['zoteroBulkSize'] ?? 0);
        if ($zoteroBulkSize < 1 || $zoteroBulkSize > 100) {
            $this->io->note('zoteroBulkSize not set or out of range. Falling back to 50.');
            $this->logger->notice('zoteroBulkSize not set or out of range. Falling back to 50.');
            $zoteroBulkSize = 50;
        }
        $this->bulkSize = $zoteroBulkSize;

        // zoteroStyle
        $this->zoteroStyle = trim((string)($this->extConf['zoteroStyle'] ?? ''));
        if ($this->zoteroStyle === '') {
            $this->zoteroStyle = 'technische-universitat-dresden-historische-musikwissenschaft-note';
            $this->io->note('zoteroStyle not set. Falling back to "technische-universitat-dresden-historische-musikwissenschaft-note".');
            $this->logger->notice('zoteroStyle not set. Falling back to "technische-universitat-dresden-historische-musikwissenschaft-note".');
        }

        // zoteroLinkwrap (0/1 -> bool, default true if not set)
        $this->zoteroLinkwrap = ((int)($this->extConf['zoteroLinkwrap'] ?? 1)) === 1;

        // zoteroCollectionId (comma-separated strings)
        $collectionIdRaw = (string)($this->extConf['zoteroCollectionId'] ?? '');
        $this->zoteroCollectionIds = array_values(
            array_filter(
                array_map('trim', explode(',', $collectionIdRaw)),
                static fn(string $v) => $v !== ''
            )
        );

        // collectionToItemTypeMap (JSON -> array)
        $mapRaw = (string)($this->extConf['collectionToItemTypeMap'] ?? '');
        $map = json_decode($mapRaw, true);
        if (!is_array($map)) {
            $this->io->note('collectionToItemTypeMap is not a valid JSON object. Falling back to empty map.');
            $this->logger->notice('collectionToItemTypeMap is not a valid JSON object. Falling back to empty map.');
            $map = [];
        }
        $this->collectionToItemTypeMap = $map;

        // Create Elasticsearch client after successful validation
        $this->client = $this->getElasticClient();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

        // Use validated/parsed IDs from configuration
        Collection::wrap($this->zoteroCollectionIds)
            ->each(function ($collectionId) { $this->recordSubcollectionRecursiveley($collectionId); });

        if ($this->collectionIds->count() == 0) {
            // No configured IDs -> null means top-level items of the whole library
            $this->collectionIds = Collection::wrap([null]);
        }
    }

    private function getSubcollections(string $collectionId): void
    {
        $apiCounter = self::API_TRIALS;
        $rateLimitAttempts = 0;

        while (true) {
            try {
                // Use factory instead of direct instantiation for testability
                $client = $this->getZoteroApi();
                $response = $client
                    ->group($this->zoteroGroupId)
                    ->collections($collectionId)
                    ->collections()
                    ->send();

                // Respect Backoff/Retry-After header even on success
                $this->applyZoteroBackoffHeaders($response, 'getSubcollections');

                Collection::wrap($response->getBody())
                    ->recursive()
                    ->pluck('key')
                    ->each(function ($collectionId) { $this->recordSubcollectionRecursiveley($collectionId); });
                return; // Exit the loop on success

            } catch (\Throwable $e) {
                // Handle rate limit (429 or Backoff/Retry-After) without consuming API_TRIALS
                if ($this->handleRateLimitOnException($e, 'getSubcollections', $rateLimitAttempts)) {
                    continue;
                }

                $this->logger->warning('Error fetching subcollections: {message}', ['message' => $e->getMessage()]);
                $this->io->newline(1);
                $this->io->caution($e->getMessage());
                $this->io->newline(1);

                $code = method_exists($e, 'getCode') ? (int)$e->getCode() : 0;

                if ($code === 500) {
                    if ($apiCounter == 0) {
                        $this->io->note('Failed to fetch subcollections after ' . self::API_TRIALS . ' trials.');
                        $this->logger->error('Failed to fetch subcollections after {trials} attempts.', ['trials' => self::API_TRIALS]);
                        throw new \Exception('Error fetching subcollections: ' . $e->getMessage());
                    } else {
                        $this->logger->notice('Trying again after HTTP 500. {count} attempts left.', ['count' => --$apiCounter]);
                        $this->sleep(1);
                        continue;
                    }
                }

                // Other errors: abort
                $this->io->note('Aborting subcollection retrieval: ' . $e->getMessage());
                throw new \Exception('Error fetching subcollections: ' . $e->getMessage());
            }
        }
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
        $tempIndexAlias = $this->elasticIndexName.'_temp';
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
                            'index' => $this->elasticIndexName.'_*',
                            'alias' => $this->elasticIndexName.'-index',
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

        $this->collectionIds
            ->each(function($collectionId) {
                $this->io->section('Retrieving items from collection ' . $collectionId);

                // Use factory instead of direct instantiation for consistency and testability
                $client = $this->getZoteroApi();
                $client->group($this->zoteroGroupId);
                if ($collectionId != null) {
                    $client->collections($collectionId);
                }
                $response = $client
                    ->items()
                    ->top()
                    ->limit(1)
                    ->send();

                // Respect Backoff/Retry-After header even on success
                $this->applyZoteroBackoffHeaders($response, 'fullSync: preflight items count');

                if ($this->input->getOption('total')) {
                    $total = (int) $this->input->getOption('total');
                } else {
                    $total = (int) $response->getHeaders()['Total-Results'][0];
                }

                $cursor = 0;
                $rateLimitAttempts = 0;
                $this->io->progressStart($total);
                while ($cursor < $total) {
                    $apiCounter = self::API_TRIALS;
                    try {
                        $this->sync($cursor, 0, $collectionId);
                        $remainingItems = $total - $cursor;
                        $advanceBy = min($remainingItems, $this->bulkSize);
                        $this->io->progressAdvance($advanceBy);
                        $cursor += $this->bulkSize;
                    } catch (\Throwable $e) {
                        // Pass through rate limit violation unchanged,
                        // so that the test message is not overwritten.
                        if ($e->getMessage() === 'Rate limit retry budget exceeded.') {
                            throw $e;
                        }
                        // Handle 429 / Backoff
                        if ($this->handleRateLimitOnException($e, 'fullSync', $rateLimitAttempts)) {
                            // do not advance cursor, just retry
                            continue;
                        }

                        $this->io->newline(1);
                        $this->io->caution($e->getMessage());
                        $this->io->newline(1);

                        // Check if it's a 500 error from Zotero API
                        $isServerError500 = (method_exists($e, 'getCode') && (int)$e->getCode() == 500);

                        if ($isServerError500 && $apiCounter <= 1) {
                            $this->io->note('Giving up after ' . self::API_TRIALS . ' trials in function: fullSync.');
                            $this->logger->error('Bibliography sync unsuccessful. Zotero API sent {trials} error {error}.', ['trials' => self::API_TRIALS, 'error' => $e->getCode()]);
                            throw new \Exception('Bibliography sync unsuccessful.');
                        } else if ($isServerError500) {
                            $this->io->note('Trying fullSync again. ' . --$apiCounter . ' trials left.');
                            $this->sleep(1);
                        } else {
                            // all other errors
                            $this->io->note('Bibliography sync unsuccessful in fullSync: ' . $e->getMessage());
                            $this->logger->error('Error during fullSync: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                            throw new \Exception('Bibliography sync unsuccessful.');
                        }
                    }
                }
                $this->io->progressFinish();
            });

        // swap alias for index from zotero_temp to zotero and remove old indexes (keep the last one)
        $this->swapIndexAliases($tempIndexAlias);
        // delete old indexes
        $this->deleteOldIndexes();
    }

    protected function versionedSync(int $version, string $collectionId): void
    {
        if ($collectionId) {
            $this->io->section('Retrieving items from collection ' . $collectionId);
        }

        $apiCounter = self::API_TRIALS;
        $rateLimitAttempts = 0;

        while (true) {
            try {
                $this->sync(0, $version, $collectionId);
                $this->io->text('done');
                return;
            } catch (\Throwable $e) {
                // Pass through rate limit violation unchanged,
                // so that the test message is not overwritten.
                if ($e->getMessage() === 'Rate limit retry budget exceeded.') {
                    throw $e;
                }
                // Handle 429 / Backoff
                if ($this->handleRateLimitOnException($e, 'versionedSync', $rateLimitAttempts)) {
                    continue;
                }

                $this->io->newline(1);
                $this->io->caution($e->getMessage());
                $this->io->newline(1);

                $code = method_exists($e, 'getCode') ? (int)$e->getCode() : 0;
                if ($code === 500) {
                    if ($apiCounter == 0) {
                        $this->io->note('Giving up after ' . self::API_TRIALS . ' trials.');
                        $this->logger->warning('Bibliography sync unsuccessful. Zotero API sent {trials} 500 errors.', ['trials' => self::API_TRIALS]);
                        throw new \Exception('Bibliography sync unsuccessful.');
                    } else {
                        $this->io->note('Trying again. ' . --$apiCounter . ' trials left.');
                        $this->sleep(1);
                        continue;
                    }
                }

                // Other errors: abort
                $this->io->note('Aborting versioned sync: ' . $e->getMessage());
                throw new \Exception('Bibliography sync unsuccessful.');
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
            'index' => $this->elasticIndexName,
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
                $this->io->note('No Index with name: ' . $this->elasticIndexName . ' found. Return 0 as Version, create new index in next steps...');
                return 0;
            } else {
                $this->io->error("Exception: " . $e->getMessage());
                throw new \Exception('Bibliography sync unsuccessful.');
            }
        }
    }

    protected function fetchBibliography(int $cursor, int $version, ?string $collectionId): void
    {
        // Use factory for testability
        $client = $this->getZoteroApi();
        $client->group($this->zoteroGroupId);
        if ($collectionId != null) {
            $client->collections($collectionId);
        }
        $response = $client
            ->items()
            ->top()
            ->start($cursor)
            ->limit($this->bulkSize)
            ->setSince($version)
            ->send();

        // Respect Backoff/Retry-After header even on success
        $this->applyZoteroBackoffHeaders($response, 'fetchBibliography');

        $this->bibliographyItems = Collection::wrap($response->getBody())
            ->pluck('data');
    }

    protected function fetchCitations(int $cursor, int $version): void
    {
        $this->locales->each(function($locale) use($cursor, $version) { $this->fetchCitationLocale($locale, $cursor, $version); });
    }

    protected function fetchCitationLocale(string $locale, int $cursor, int $version): void
    {
        // Use factory instead of "new"
        $client = $this->getZoteroApi();
        $response = $client
            ->group($this->zoteroGroupId)
            ->items()
            ->top()
            ->start($cursor)
            ->limit($this->bulkSize)
            ->setSince($version)
            ->setInclude('citation')
            ->setStyle($this->zoteroStyle)
            ->setLinkwrap($this->zoteroLinkwrap)
            ->setLocale($locale)
            ->send();

        // Respect Backoff/Retry-After header even on success
        $this->applyZoteroBackoffHeaders($response, 'fetchCitationLocale');

        $this->localizedCitations = new Collection([ $locale =>
                Collection::wrap($response->getBody())
                    ->keyBy('key')
            ]);
    }

    protected function fetchTeiData(int $cursor, int $version): void
    {
        // Use factory for testability
        $client = $this->getZoteroApi();
        $response = $client
            ->group($this->zoteroGroupId)
            ->items()
            ->top()
            ->start($cursor)
            ->limit($this->bulkSize)
            ->setSince($version)
            ->setInclude('tei')
            ->send();

        // Respect Backoff/Retry-After header even on success
        $this->applyZoteroBackoffHeaders($response, 'fetchTeiData');

        $this->teiDataSets = Collection::wrap($response->getBody())
            ->keyBy('key');
    }

    protected function buildDataSets(): void
    {
        $bibEntryProcessor = new BibEntryProcessor($this->logger);

        $this->dataSets = $this->bibliographyItems
            ->map(function($bibliographyItem) use ($bibEntryProcessor) {
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

        $params = ['body' => []];
        $bulkCount = 0;

        foreach ($this->dataSets as $document) {
            $params['body'][] = [
                'index' => [
                    '_index' => $this->indexName,
                    '_id' => $document['key']
                ]
            ];

            $encoded = json_encode($document);
            if ($encoded === false || $encoded === '[]' || $encoded === '{}') {
                $this->logger->warning('Document cannot be encoded correctly', [
                    'document' => $document,
                    '_id' => $document['key'],
                    'json_error' => json_last_error_msg()
                ]);
                // Skip invalid documents
                continue;
            }

            $params['body'][] = $encoded;

            // Send when bulk size is reached
            if (!(++$bulkCount % $this->elasticBulkSize)) {
                try {
                    $this->client->bulk($params);
                } catch (\Exception $e) {
                    $this->logger->error('Elasticsearch error during bulk operation: ' . $e->getMessage(), [
                        'params' => $params,
                        'trace' => $e->getTraceAsString()
                    ]);
                    $this->io->note('Error during Elasticsearch bulk operation: ' . $e->getMessage());
                    throw new \Exception('Bibliography sync unsuccessful because of error during Elasticsearch bulk operation.');
                }

                $params = ['body' => []];
            }
        }

        // Send remaining documents, but only if the body is not empty
        if (!empty($params['body'])) {
            try {
                $this->client->bulk($params);
            } catch (\Exception $e) {
                $this->logger->error('Elasticsearch error at the end of bulk processing: ' . $e->getMessage(), [
                    'params' => $params,
                    'trace' => $e->getTraceAsString()
                ]);
                $this->io->note('Error at the end of Elasticsearch bulk processing: ' . $e->getMessage());
                throw new \Exception('Bibliography sync unsuccessful because of error at the end of Elasticsearch bulk processing.');
            }
        }
    }

    protected function swapIndexAliases(string $tempIndexAlias): void
    {
        // get index with alias = zotero
        try {
            $aliasesRequest = $this->client->indices()->getAlias(['name' => $this->elasticIndexName]);
            $aliasesArray = $aliasesRequest->asArray();

            foreach ($aliasesArray as $index => $aliasArray) {
                $this->io->note('Remove alias "' .$this->elasticIndexName. '" from index '. $index . ' and add it to ' . $this->indexName );
                // get index name with alias 'zotero'
                if (array_key_exists($this->elasticIndexName, $aliasArray['aliases'])) {
                    //swap alias from old to new index
                    $aliasParams = [
                        'body' => [
                            'actions' => [
                                [
                                    'remove' => [
                                        'index' => $index,
                                        'alias' => $this->elasticIndexName,
                                    ],
                                ],
                                [
                                    'add' => [
                                        'index' => $this->indexName,
                                        'alias' => $this->elasticIndexName,
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
                $this->io->note("Alias: " . $this->elasticIndexName . " does not exist. Move alias to ".$this->indexName);
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
                                    'alias' => $this->elasticIndexName,
                                ],
                            ]
                        ]
                    ]
                ];
                $this->client->indices()->updateAliases($aliasParams);

            } else {
                $this->io->error("Exception: " . $e->getMessage());
                $this->logger->error('Bibliography sync unsuccessful. Error getting alias: ' . $this->elasticIndexName);
                throw new \Exception('Bibliography sync unsuccessful.', 0, $e);
            }
        }
    }

    protected function deleteOldIndexes(): void
    {
        try {
            $aliasesRequest = $this->client->indices()->getAlias(['name' => $this->elasticIndexName.'-index']);
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
                $this->io->note("Nothing to remove, there are no indexes with alias " . $this->elasticIndexName.'-index');
            } else {
                $this->io->error("Exception: " . $e->getMessage());
                $this->logger->error('Bibliography sync unsuccessful. Error getting alias: ' . $this->elasticIndexName.'-index');
                throw new \Exception('Bibliography sync unsuccessful.', 0, $e);
            }
        }
    }

    // ---- Rate limit helpers ----

    /**
     * Apply Backoff / Retry-After headers on successful responses.
     */
    private function applyZoteroBackoffHeaders($response, string $context): void
    {
        try {
            $headers = $response->getHeaders();
        } catch (\Throwable $e) {
            return;
        }

        $seconds = $this->getBackoffSecondsFromHeaders($headers);
        if ($seconds > 0) {
            $seconds = min(self::RATE_LIMIT_MAX_SECONDS, $seconds);
            $this->io->note("Zotero requested backoff for {$seconds} seconds ({$context}).");
            $this->logger->notice('Zotero requested backoff for {seconds} seconds ({context}).', ['seconds' => $seconds, 'context' => $context]);
            $this->sleep($seconds);
        }
    }

    /**
     * Handle 429 or Backoff/Retry-After on exception. Returns true if it slept and caller should retry.
     * Throws if retry budget exceeded.
     */
    private function handleRateLimitOnException(\Throwable $e, string $context, int &$attempt): bool
    {
        // Extract status code and optional PSR-7 response (as provided by some HTTP clients)
        $code = method_exists($e, 'getCode') ? (int)$e->getCode() : 0;
        $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;

        // Try to read headers from the response for backoff hints
        $headers = [];
        if ($response && method_exists($response, 'getHeaders')) {
            try {
                $headers = $response->getHeaders();
            } catch (\Throwable $t) {
                $headers = [];
            }
        }

        $hasBackoffHeader = $this->getBackoffSecondsFromHeaders($headers) > 0;
        $is429 = ($code === 429);

        // Not a rate limit scenario -> let caller handle the exception
        if (!$is429 && !$hasBackoffHeader) {
            return false;
        }

        // Count this retry attempt first and enforce the retry budget
        $attempt++;
        if ($attempt >= self::RATE_LIMIT_MAX_RETRIES) {
            $this->io->error('Rate limit retry budget exceeded. Aborting.');
            $this->logger->error('Rate limit retry budget exceeded in {context}.', ['context' => $context]);
            throw new \Exception('Rate limit retry budget exceeded.');
        }

        // Determine sleep duration:
        // - Prefer Backoff/Retry-After headers if present
        // - Otherwise use exponential backoff, capped and with a sensible lower bound
        $seconds = $this->getBackoffSecondsFromHeaders($headers);
        if ($seconds <= 0) {
            $exp = max(0, $attempt - 1);
            $seconds = (int) min(
                self::RATE_LIMIT_MAX_SECONDS,
                max(self::RATE_LIMIT_DEFAULT_SECONDS, (int) pow(2, $exp) * self::RATE_LIMIT_DEFAULT_SECONDS)
            );
        } else {
            $seconds = min(self::RATE_LIMIT_MAX_SECONDS, $seconds);
        }

        $this->io->note("Rate limited (HTTP {$code}). Waiting {$seconds} seconds before retry ({$context}, attempt {$attempt}/" . self::RATE_LIMIT_MAX_RETRIES . ").");
        $this->logger->notice(
            'Rate limited (code {code}). Waiting {seconds}s before retry ({context}, attempt {attempt}/{max}).',
            [
                'code'    => $code,
                'seconds' => $seconds,
                'context' => $context,
                'attempt' => $attempt,
                'max'     => self::RATE_LIMIT_MAX_RETRIES,
            ]
        );

        // Sleep (can be overridden in tests via setSleeper)
        $this->sleep($seconds);

        return true;
    }



    private function getBackoffSecondsFromHeaders(array $headers): int
    {
        // Common header names: 'Backoff', 'Retry-After'
        if (isset($headers['Backoff'][0])) {
            $sec = (int)$headers['Backoff'][0];
            if ($sec > 0) {
                return $sec;
            }
        }
        if (isset($headers['Retry-After'][0])) {
            $retryAfter = $headers['Retry-After'][0];
            $sec = $this->parseRetryAfterSeconds($retryAfter);
            if ($sec > 0) {
                return $sec;
            }
        }
        return 0;
    }

    private function parseRetryAfterSeconds(string $value): int
    {
        // Retry-After can be seconds or HTTP-date
        if (is_numeric($value)) {
            return max(0, (int)$value);
        }
        $ts = strtotime($value);
        if ($ts !== false) {
            $delta = $ts - time();
            return $delta > 0 ? $delta : 0;
        }
        return 0;
    }

/*    protected function commitLocales(): void
    {
        $localeIndex = $this->elasticLocaleIndexName;
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
            $this->params['body'][] = json_encode($locale);
        }
        $this->client->bulk($params);

        $this->io->text('done');
    }*/
}
