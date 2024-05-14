<?php


namespace Slub\LisztBibliography\Services;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Http\Promise\Promise;
use Illuminate\Support\Collection;
use Slub\LisztCommon\Common\ElasticClientBuilder;
use Slub\LisztBibliography\Interfaces\ElasticSearchServiceInterface;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;



class ElasticSearchService implements ElasticSearchServiceInterface
{

    protected ?Client $client = null;
    protected string $bibIndex;
    protected string $localeIndex;

    // Todo: Enable Elasticsearch Security: built-in security features are not enabled.
    public function init(): bool
    {
        $this->client = ElasticClientBuilder::getClient();
        $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('liszt_bibliography');
        $this->bibIndex = $extConf['elasticIndexName'];
        $this->localeIndex = $extConf['elasticLocaleIndexName'];
        return true;
    }

    public function getElasticInfo(): array
    {
        $this->init();
        return ($this->client->info()->asArray());
    }

    public function search(): Collection
    {
        $this->init();
        $params = [
            'index' => $this->bibIndex,
            'body' => [
                'query' => [
                    'match_all' => new \stdClass()
                ],
            'size' => 10,
             '_source' => ['itemType', 'title', 'creators', 'pages','date','language', 'localizedCitations'],
                'aggs' => [
                    'itemType' => [
                        'terms' => [
                            'field' => 'itemType.keyword',
                        ]
                    ],
                    'place' => [
                        'terms' => [
                            'field' => 'place.keyword',
                        ]
                    ]
                ]
            ]
        ];
        // ToDo: handle exceptions!
        $response = $this->client->search($params);
        return new Collection($response->asArray());
    }


}
