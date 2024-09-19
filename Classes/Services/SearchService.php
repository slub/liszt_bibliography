<?php

namespace Slub\LisztBibliography\Services;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Collection;
use Slub\LisztCommon\Common\ElasticClientBuilder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SearchService implements SearchServiceInterface
{
    private string $indexName = '';
    private array $params = [];
    private string $searchTerm = '';
    private string $uid = '';
    private int $from = 0;
    private int $size = 0;
    protected array $extConf;
    private ?Client $client = null;

    public function init(): bool
    {
        $this->client = ElasticClientBuilder::create()->
            autoconfig()->
            build();
        $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('liszt_bibliography');
        $this->indexName = $extConf['elasticIndexName'];

        return true;
    }

    public function reset(): SearchServiceInterface
    {
        return $this->search('')->
            get('')->
            from(0)->
            size(0);
    }

    public function search(string $searchTerm): SearchServiceInterface
    {
        $this->searchTerm = $searchTerm;

        return $this;
    }

    public function get(string $id): SearchServiceInterface
    {
        $this->uid = $id;

        return $this;
    }

    public function from(int $from): SearchServiceInterface
    {
        $this->from = $from;

        return $this;
    }

    public function limit(int $size): SearchServiceInterface
    {
        $this->size = $size;

        return $this;
    }

    public function send(): Collection
    {
        $this->createParams();

        return $this;

    }

    private function createParams(): void
    {
        $this->params = [];

        $this->params['index'] = $this->indexName;
        if ($this->uid > 0) {
            if ($this->searchTerm == '') {
                $this->params['id'] = $this->uid;
            } else {
                throw new \Exception('Search term and uid were set simultaneously. Aborting.');
            }
        } else {
            if ($this->searchTerm == '') {
                $this->params['body'] = [
                    'query' => [
                        'bool' => [
                            'must' => [
                                'match_all' => new \stdClass()
                            ]
                        ]
                    ]
                ];
            } else {
                $this->params['body'] = [
                    'query' => [
                        'must' => [
                            'query_string' => [
                                'query' => $this->searchTerm
                            ]
                        ]
                    ]
                ];
            }
        }

        if ($this->from > 0) {
            $this->params['body']['size'] = $this->size;
        }
        if ($this->size > 0) {
            $this->params['body']['from'] = $this->from;
        }
    }

    public function count(): int
    {
        $this->createParams();

        return $this->client->count();
    }
}
