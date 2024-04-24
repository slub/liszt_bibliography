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
        $this->setDescription('Create elasticsearch index from zotero bibliography');
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
        // get bulk size and total size
        $this->bulkSize = (int) $this->extConf['zoteroBulkSize'];

        $this->io->section('Fetching Bibliography Data');
        $this->fetchBibliography();
        $this->io->section('Fetching Localized Citations');
        $this->fetchCitations();
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
            map(function($bibliographyItem) { 
                $this->io->progressAdvance();
                return self::buildDataSet($bibliographyItem, $this->localizedCitations, $this->teiDataSets);
            });
        $this->io->progressFinish();
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

    protected function fetchBibliography(): void
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
        while ($cursor < $this->total) {
            $this->io->progressAdvance($this->bulkSize);
            $response = $client->
                group($this->extConf['zoteroGroupId'])->
                items()->
                top()->
                start($cursor)->
                limit($this->bulkSize)->
                send();
            $collection = new Collection($response->getBody());
            $this->bibliographyItems = $this->bibliographyItems->
                concat($collection->pluck('data'));
            $cursor += $this->bulkSize;
        }
        $this->io->progressFinish();
    }

    protected function fetchCitations(): void
    {
        $this->localizedCitations = new Collection();
        $this->locales->each(function($locale) { $this->fetchCitationLocale($locale); });
    }

    protected function fetchCitationLocale(string $locale): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $style = $this->extConf['zoteroStyle'];
        $response = $client->
            group($this->extConf['zoteroGroupId'])->
            items()->
            top()->
            limit(1)->
            setInclude('citation')->
            setStyle($style)->
            setLinkwrap()->
            setLocale($locale)->
            send();

        // fetch bibliography items bulkwise
        $this->io->text($locale);
        $this->io->progressStart($this->total);
        $result = Collection::wrap($response->getBody())->keyBy('key');

        $cursor = $this->bulkSize;
        while ($cursor < $this->total) {
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
                $cursor += $this->bulkSize;
            } catch (\Exception $e) {
                $this->io->newline(2);
                $this->io->caution($e->getMessage());
                $this->io->note('Stay calm. This is normal for Zotero\'s API. I\'m trying it again.');
            }
            $this->io->progressAdvance($this->bulkSize);
        }

        $this->localizedCitations = $this->localizedCitations->merge(
            new Collection([ $locale => $result ])
        );
        $this->io->progressFinish();
    }

    protected function fetchTeiData(): void
    {
        $client = new ZoteroApi($this->extConf['zoteroApiKey']);
        $response = $client->
            group($this->extConf['zoteroGroupId'])->
            items()->
            top()->
            limit(1)->
            setInclude('tei')->
            send();

        // fetch bibliography items bulkwise
        $this->io->progressStart($this->total);
        $collection = new Collection($response->getBody());
        $this->teiDataSets = $collection->keyBy('key');

        $cursor = $this->bulkSize;
        while ($cursor < $this->total) {
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
                $cursor += $this->bulkSize;
            } catch (\Exception $e) {
                $this->io->newline(2);
                $this->io->caution($e->getMessage());
                $this->io->note('Stay calm. This is normal for Zotero\'s API. I\'m trying it again.');
            }
            $this->io->progressAdvance($this->bulkSize);
        }
        $this->io->progressFinish();
    }

    protected function commitBibliography(): void
    {
        $index = $this->extConf['elasticIndexName'];
        $this->io->text('Committing the ' . $index . ' index');

        $this->io->progressStart(count($this->bibliographyItems));
        if ($this->client->indices()->exists(['index' => $index])) {
            $this->client->indices()->delete(['index' => $index]);
            $this->client->indices()->create(['index' => $index]);
        }

        $params = [ 'body' => [] ];
        $bulkCount = 0;
        foreach ($this->dataSets as $document) {
            $this->io->progressAdvance();
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
        $this->io->progressFinish();
        $this->client->bulk($params);

        $this->io->text('done');
    }

}
