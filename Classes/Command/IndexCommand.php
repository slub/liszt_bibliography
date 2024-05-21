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
use Slub\LisztCommon\Common\ElasticClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;


/* ToDo:
- exception handling (for networking problems, api down etc.?) and check empty zoteroAPIKey, zoteroGroupId
- sfae the "Last-Modified-Version" Header from zotero and check if the data has changed since the last fetch
- potential infinite loops, exit strategy with loop count in try/catch
- fetch the api in bulks of 50 and save bulk wise in elasticsearch?
*/
class IndexCommand extends Command
{

    protected string $apiKey;
    protected Collection $bibliographyItems;
    protected Collection $teiDataSets;
    protected Collection $dataSets;
    protected Client $client;
    protected array $extConf;
    protected SymfonyStyle $io;
    protected int $bulkSize;
    protected int $total;
    protected Collection $locales;
    protected Collection $localizedCitations;
    protected InputInterface $input;

    function __construct(SiteFinder $siteFinder)
    {
        parent::__construct();

        $this->locales = Collection::wrap($siteFinder->getAllSites())->
        map(function (Site $site): array {
            return $site->getLanguages();
        })->
        flatten()->
        map(function (SiteLanguage $language): string {
            return $language->getHreflang();
        });
    }

    protected function getRequest(): ServerRequestInterface
    {
        // ToDo: $GLOBALS['TYPO3_REQUEST'] was deprecated in TYPO3 v9.2 and will be removed in a future version.
        return $GLOBALS['TYPO3_REQUEST'];
    }

    protected function configure(): void
    {
        $this->setDescription('Create elasticsearch index from zotero bibliography')
            ->addOption('fetch-citations', null, InputOption::VALUE_NONE, 'Run with fetch localized citations');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('liszt_bibliography');
        $this->client = ElasticClientBuilder::getClient();
        $this->apiKey = $this->extConf['zoteroApiKey'];
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getDescription());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // get bulk size and total size
        $this->bulkSize = (int)$this->extConf['zoteroBulkSize'];
        $this->input = $input;
        $this->io->section('Fetching Bibliography Data');
        $this->fetchBibliography();
        if ($input->getOption('fetch-citations')) {
            $this->io->section('Fetching Localized Citations');
            $this->fetchCitations();
        }
        $this->io->section('Fetching TEI Data');
        $this->fetchTeiData();
        $this->io->section('Building Datasets');
        $this->buildDataSets();
        $this->io->section('Committing Bibliography Data');
        $this->commitBibliography();
        return 0;
    }

    protected function buildDataSets(): void
    {
        $this->io->progressStart($this->total);
        $this->dataSets = $this->bibliographyItems->
        map(function ($bibliographyItem) {
            $this->io->progressAdvance();
            return self::buildDataSet($bibliographyItem, $this->input->getOption('fetch-citations') ? $this->localizedCitations : null, $this->teiDataSets);
        });
        $this->io->progressFinish();
    }

    protected static function buildDataSet(
        array       $bibliographyItem,
        ?Collection $localizedCitations,
        Collection  $teiDataSets
    )
    {
        $key = $bibliographyItem['key'];
        $bibliographyItem['localizedCitations'] = [];
        if ($localizedCitations) {
            foreach ($localizedCitations as $locale => $localizedCitation) {
                $bibliographyItem['localizedCitations'][$locale] = $localizedCitation->get($key)['citation'];
            }
        }
        $bibliographyItem['tei'] = $teiDataSets->get($key);
        return $bibliographyItem;
    }

    protected function fetchBibliography(): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $this->bibliographyItems = new Collection();
        $this->total = 1;
        $cursor = 0;
        // fetch bibliography items bulkwise
        while ($cursor < ($this->total + $this->bulkSize)) {
            $response = $client->
            group($this->extConf['zoteroGroupId'])->
            items()->
            top()->
            start($cursor)->
            limit($this->bulkSize)->
            send();
            $headers = $response->getHeaders();
            if (isset($headers['Total-Results'][0])) {
                $this->total = (int)$headers['Total-Results'][0];
            } else {
                $this->io->error('break fetchBibliography loop because of missing Total-Results, ' .$this->total);
                break; //  break the loop if there is no result header to prevent infinite loops
            }
            if ($cursor === 0) {
                $this->io->progressStart($this->total);
            }
            $collection = new Collection($response->getBody());
            $this->bibliographyItems = $this->bibliographyItems->
            concat($collection->pluck('data'));
            // adjust progress bar
            $remainingItems = $this->total - $cursor;
            $advanceBy = min($remainingItems, $this->bulkSize);
            $this->io->progressAdvance($advanceBy);
            $cursor += $this->bulkSize;
        }
        $this->io->progressFinish();
    }

    protected function fetchCitations(): void
    {
        $this->localizedCitations = new Collection();
        $this->locales->each(function ($locale) {
            $this->fetchCitationLocale($locale);
        });
    }

    protected function fetchCitationLocale(string $locale): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $style = $this->extConf['zoteroStyle'];
        $this->io->text($locale);
        $result = new Collection();
        $cursor = 0;
        // fetch bibliography items bulkwise
        while ($cursor < ($this->total + $this->bulkSize)) {
            if ($cursor === 0) {
                $this->io->progressStart($this->total);
            }
            try {
                $response = $client->
                group($this->extConf['zoteroGroupId'])->
                items()->
                top()->
                start($cursor)->
                limit($this->bulkSize)->
                setInclude('citation')->
                setStyle($style)->
                setLinkwrap()->
                setLocale($locale)->
                send();
                $result = $result->merge(Collection::wrap($response->getBody())->keyBy('key'));
                // adjust progress bar
                $remainingItems = $this->total - $cursor;
                $advanceBy = min($remainingItems, $this->bulkSize);
                $this->io->progressAdvance($advanceBy);
                $cursor += $this->bulkSize;
            } catch (\Exception $e) {
                $this->io->newline(2);
                $this->io->caution($e->getMessage());
                $this->io->note('Stay calm. This is normal for Zotero\'s API. I\'m trying it again. fetchCitationLocale');
            }
        }
        $this->localizedCitations = $this->localizedCitations->merge(
            new Collection([$locale => $result])
        );
        $this->io->progressFinish();
    }

    protected function fetchTeiData(): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $this->teiDataSets = new Collection();
        $cursor = 0;
        // fetch bibliography items bulkwise
        while ($cursor < ($this->total + $this->bulkSize)) {
            if ($cursor === 0) {
                $this->io->progressStart($this->total);
            }
            try {
                $response = $client->
                group($this->extConf['zoteroGroupId'])->
                items()->
                top()->
                start($cursor)->
                limit($this->bulkSize)->
                setInclude('tei')->
                send();
                $collection = new Collection($response->getBody());
                $this->teiDataSets = $this->teiDataSets->
                concat($collection->keyBy('key'));
                // adjust progress bar
                $remainingItems = $this->total - $cursor;
                $advanceBy = min($remainingItems, $this->bulkSize);
                $this->io->progressAdvance($advanceBy);
                $cursor += $this->bulkSize;
            } catch (\Exception $e) {
                $this->io->newline(2);
                $this->io->caution($e->getMessage());
                $this->io->note('Stay calm. This is normal for Zotero\'s API. I\'m trying it again. fetchTeiData');
            }
        }
        $this->io->progressFinish();
    }


    protected function commitBibliography(): void
    {
        $index = $this->extConf['elasticIndexName'];
        $this->io->text('Committing the ' . $index . ' index');

        $this->io->progressStart(count($this->bibliographyItems));

        // index params -> mapping fields for facetting in index
        // Todo: optimize with synthetic _source and copy fields?
        /*        $elasticIndexMappings = [
                    'index' => ['index' => $index],
                    'body' => [
                        'mappings' => [
                            'properties' => [
                                'itemType' => [
                                    'type' => 'keyword',
                                ]
                            ]
                        ]
                    ]
                ];*/

        /* For more recent versions of Elasticsearch (8.x),
       a call to $client->indices()->exists($indexParams) no longer returns a boolean,
       it instead returns an instance of Elastic\Elasticsearch\Response\Elasticsearch.
       This response is actually a 200 HTTP response if the index exists, or a HTTP 404 if it does not */
        try {
            if ($this->client->indices()->exists(['index' => $index])) {
                $this->client->indices()->delete(['index' => $index]);
                $this->client->indices()->create(['index' => $index]);
            }
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                echo 'code=' . $e->getCode();
                $this->io->note("Index: " . $index . " not exist. Try to create new index");
                $this->client->indices()->create(['index' => $index]);
            } else {
                $this->io->error("Exception: " . $e->getMessage());
                exit; // or die(); // eventually clean memory
            }
        }

        $params = ['body' => []];
        $bulkCount = 0;
        foreach ($this->dataSets as $document) {
            $this->io->progressAdvance();
            $params['body'][] = ['index' =>
                [
                    '_index' => $index,
                    '_id' => $document['key']
                ]
            ];
            $params['body'][] = json_encode($document);

            if (!(++$bulkCount % $this->extConf['elasticBulkSize'])) {
                $this->client->bulk($params);
                $params = ['body' => []];
            }
        }
        $this->io->progressFinish();
        //  $this->client->bulk($params);

        $this->io->text('done');
    }

}
