<?php

namespace Slub\LisztBibliography\Tests\Unit\Command;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slub\LisztBibliography\Command\IndexCommand;
use Slub\LisztCommon\Common\Collection;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * @covers Slub\LisztBibliography\Command\IndexCommand
 */
final class IndexCommandTest extends UnitTestCase
{
    private const LIVE_INDEX = 'zotero';

    private IndexCommand $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'collectionToItemTypeMap' => '{}',
            'elasticIndexName' => self::LIVE_INDEX,
            'elasticBulkSize' => '20',
        ]);
        GeneralUtility::addInstance(ExtensionConfiguration::class, $extensionConfiguration);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $this->subject = new IndexCommand($siteFinder, new NullLogger());
    }

    /**
     * @test
     */
    public function fullSyncWritesToTheNewTimestampedIndex(): void
    {
        self::assertSame($this->subject->indexName, $this->invoke('getTargetIndex', 0));
        self::assertStringStartsWith(self::LIVE_INDEX . '_', $this->subject->indexName);
    }

    /**
     * @test
     */
    public function incrementalSyncWritesToTheLiveIndex(): void
    {
        self::assertSame(self::LIVE_INDEX, $this->invoke('getTargetIndex', 80425));
    }

    /**
     * @test
     */
    public function bulkRequestIsSentToTheGivenTargetIndex(): void
    {
        $http = new class () implements ClientInterface {
            public array $requests = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = (string) $request->getBody();

                return new Response(
                    200,
                    ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'],
                    '{"errors":false,"items":[]}'
                );
            }
        };
        $client = ClientBuilder::create()->setHosts(['http://localhost:9200'])->setHttpClient($http)->build();

        $this->setProperty('client', $client);
        $this->setProperty('dataSets', new Collection([[ 'key' => 'K1', 'title' => 'a title' ]]));

        $this->invoke('commitBibliography', self::LIVE_INDEX);

        self::assertCount(1, $http->requests);
        $action = json_decode(strtok($http->requests[0], "\n"), true);
        self::assertSame(self::LIVE_INDEX, $action['index']['_index']);
        self::assertSame('K1', $action['index']['_id']);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($this->subject, $method))->invoke($this->subject, ...$arguments);
    }

    private function setProperty(string $name, mixed $value): void
    {
        (new \ReflectionProperty($this->subject, $name))->setValue($this->subject, $value);
    }
}
