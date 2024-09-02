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

use Elasticsearch\Client;
use Hedii\ZoteroApi\ZoteroApi;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
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

    function __construct(SiteFinder $siteFinder)
    {
        parent::__construct();

        $this->locales = Collection::wrap($siteFinder->getAllSites())->
            map(function (Site $site): array { return $site->getLanguages(); })->
            flatten()->
            map(function (SiteLanguage $language): string { return $language->getHreflang(); });
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
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Set all if all data sets should be updated.'
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output) {
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
            $this->fullSync();
        } else {
            $this->io->text('Synchronizing all data from version ' . $version);
            $this->versionedSync($version);
        }

        return 0;
    }

    protected function fullSync(): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $response = $client->
            group($this->extConf['zoteroGroupId'])->
            items()->
            top()->
            limit(1)->
            send();
        $this->total = (int) $response->getHeaders()['Total-Results'][0];

        // fetch bibliography items bulkwise
        $this->io->progressStart($this->total);
        $collection = new Collection($response->getBody());
        $this->bibliographyItems = $collection->
            pluck('data');

        $cursor = $this->bulkSize;
        $index = $this->extConf['elasticIndexName'];
        if ( $this->client->indices()->exists(['index' => $index])) {
            $this->client->indices()->delete(['index' => $index]);
            $this->client->indices()->create(['index' => $index]);
        }
        while ($cursor < $this->total) {
            try {
                $this->sync($cursor, 0);
                $this->io->progressAdvance($this->bulkSize);
            } catch (\Exception $e) {
                $this->io->newline(2);
                $this->io->caution($e->getMessage());
                $this->io->note('Stay calm. This is normal for Zotero\'s API. I\'m trying it again.');
            }
            $cursor += $this->bulkSize;
        }
        $this->io->progressFinish();
    }

    protected function versionedSync(int $version): void
    {
        $this->sync(0, $version);
        $this->io->text('done');
    }

    protected function sync(int $version = 0, int $cursor = 0): void
    {
        $this->fetchBibliography(0, $version);
        $this->fetchCitations(0, $version);
        $this->fetchTeiData(0, $version);
        $this->buildDataSets();
        $this->commitBibliography();
    }

    protected function getVersion($input): int
    {
        // if -a is specified, perfom a full update
        if ($input->getOption('all')) {
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

        return (int) $this->client->search($params)['aggregations']['max_version']['value'];
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
