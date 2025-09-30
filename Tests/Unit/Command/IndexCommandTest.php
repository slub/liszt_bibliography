<?php

declare(strict_types=1);

namespace Slub\LisztBibliography\Tests\Unit\Command;

use Exception;
use GuzzleHttp\Psr7\Response;
use Hedii\ZoteroApi\ZoteroApi;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Slub\LisztBibliography\Command\IndexCommand;
use Slub\LisztCommon\Common\Collection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

// Proxy to make protected methods testable and bypass ES client builder
final class IndexCommandTestProxy extends IndexCommand
{
    private ?ZoteroApi $zoteroApi = null;

    public function callInitialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
    }

    public function callGetVersion(): int
    {
        return parent::getVersion();
    }

    public function callFullSync(): void
    {
        parent::fullSync();
    }

    public function setCollectionIds(Collection $collectionIds): void
    {
        $this->collectionIds = $collectionIds;
    }

    // Allow tests to inject a ZoteroApi mock
    public function setZoteroApi(ZoteroApi $api): void
    {
        $this->zoteroApi = $api;
    }

    // Always return injected mock if available
    protected function getZoteroApi(): ZoteroApi
    {
        if ($this->zoteroApi instanceof ZoteroApi) {
            return $this->zoteroApi;
        }
        return parent::getZoteroApi();
    }

    // IMPORTANT: Bypass builder in test
    protected function getElasticClient()
    {
        // If already injected in test, use this one
        if ($this->client) {
            return $this->client;
        }

        // Fallback dummy used by initialize()
        return new class {
            public function indices()
            {
                return new class {
                    public function create(array $params): void {}
                    public function updateAliases(array $params): void {}
                    public function getAlias(array $params)
                    {
                        // Return a structure with asArray() as expected by the code
                        return new class {
                            public function asArray(): array
                            {
                                return [];
                            }
                        };
                    }
                    public function delete(array $params): void {}
                };
            }
            public function search(array $params = [])
            {
                // Default: no existing version
                return ['aggregations' => ['max_version' => ['value' => null]]];
            }
            public function bulk(array $params = [])
            {
                return null;
            }
        };
    }
}

final class IndexCommandTest extends UnitTestCase
{
    private IndexCommandTestProxy $subject;

    /** @var SiteFinder&MockObject */
    private $siteFinderMock;

    /** @var LoggerInterface&MockObject */
    private $loggerMock;

    /** @var InputInterface&MockObject */
    private $inputMock;

    /** @var OutputInterface&MockObject */
    private $outputMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteFinderMock = $this->createMock(SiteFinder::class);
        $this->siteFinderMock->method('getAllSites')->willReturn([]);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->inputMock = $this->createMock(InputInterface::class);
        $this->outputMock = $this->createMock(OutputInterface::class);

        // Minimal extension configuration for liszt_bibliography
        $extConf = $this->createMock(ExtensionConfiguration::class);
        $extConf->method('get')->willReturnMap([
            ['liszt_bibliography', '', [
                'elasticIndexName' => 'zotero',
                'zoteroApiKey' => 'test-api-key',
                'zoteroGroupId' => 123,
                'zoteroBulkSize' => 5,
            ]],
        ]);
        GeneralUtility::addInstance(ExtensionConfiguration::class, $extConf);

        // Instantiate proxy command (uses dummy ES client)
        $this->subject = new IndexCommandTestProxy($this->siteFinderMock, $this->loggerMock);
        $this->subject->setSleeper(static function (int $seconds): void {});
    }

    protected function tearDown(): void
    {
        if (method_exists(GeneralUtility::class, 'purgeInstances')) {
            GeneralUtility::purgeInstances();
        }
        parent::tearDown();
    }

    public function testGetVersionReturnsZeroWhenAllOptionIsSet(): void
    {
        $this->inputMock->method('getOption')->willReturnMap([
            ['all', true],
            ['total', null],
        ]);
        $this->inputMock->method('getArgument')->willReturnMap([
            ['version', null],
        ]);

        $this->subject->callInitialize($this->inputMock, $this->outputMock);
        $version = $this->subject->callGetVersion();
        self::assertSame(0, $version);
    }

    public function testGetVersionReturnsZeroWhenElasticsearchIndexIsMissing(): void
    {
        $this->inputMock->method('getOption')->willReturnMap([
            ['all', false],
            ['total', null],
        ]);
        $this->inputMock->method('getArgument')->willReturnMap([
            ['version', null],
        ]);

        $this->subject->callInitialize($this->inputMock, $this->outputMock);

        // Client dummy whose search() throws 404
        $client404 = new class {
            public function search(array $params = [])
            {
                throw new Exception('index missing', 404);
            }
        };
        $this->subject->setElasticClient($client404);

        $version = $this->subject->callGetVersion();
        self::assertSame(0, $version);
    }

    public function testFullSyncAbortsAfterRateLimitBudgetExceededOn429(): void
    {
        $this->inputMock->method('getOption')->willReturnMap([
            ['all', false],
            ['total', 1], // force fullSync and single item
        ]);
        $this->inputMock->method('getArgument')->willReturnMap([
            ['version', null],
        ]);

        $this->subject->callInitialize($this->inputMock, $this->outputMock);

        // ES client dummy for index creation/alias updates and safe alias cleanup
        $clientForFullSync = new class {
            public function indices()
            {
                return new class {
                    public function create(array $params): void {}
                    public function updateAliases(array $params): void {}
                    public function getAlias(array $params)
                    {
                        // Provide expected structure to avoid accidental failures beyond rate limit path
                        return new class {
                            public function asArray(): array
                            {
                                return [];
                            }
                        };
                    }
                    public function delete(array $params): void {}
                };
            }
            public function bulk(array $params = [])
            {
                return null;
            }
        };
        $this->subject->setElasticClient($clientForFullSync);

        // ZoteroApi mock
        $preflightResponse = new Response(200, ['Total-Results' => ['1']], '[]');

        $rateLimitResponse = new Response(429, ['Retry-After' => ['1']]);
        $rateLimitException = new class($rateLimitResponse) extends Exception {
            private Response $resp;
            public function __construct(Response $resp) { parent::__construct('Too Many Requests', 429); $this->resp = $resp; }
            public function getResponse(): Response { return $this->resp; }
        };

        /** @var ZoteroApi&MockObject $zoteroMock */
        $zoteroMock = $this->createMock(ZoteroApi::class);
        $zoteroMock->method('group')->willReturn($zoteroMock);
        $zoteroMock->method('collections')->willReturn($zoteroMock);
        $zoteroMock->method('items')->willReturn($zoteroMock);
        $zoteroMock->method('top')->willReturn($zoteroMock);
        $zoteroMock->method('start')->willReturn($zoteroMock);
        $zoteroMock->method('limit')->willReturn($zoteroMock);
        $zoteroMock->method('setSince')->willReturn($zoteroMock);
        $zoteroMock->method('setInclude')->willReturn($zoteroMock);
        $zoteroMock->method('setStyle')->willReturn($zoteroMock);
        $zoteroMock->method('setLinkwrap')->willReturn($zoteroMock);
        $zoteroMock->method('setLocale')->willReturn($zoteroMock);

        // Stable simulation: first call returns preflight, all subsequent calls throw 429
        $callCount = 0;
        $zoteroMock->method('send')->willReturnCallback(function () use (&$callCount, $preflightResponse, $rateLimitException) {
            if ($callCount === 0) {
                $callCount++;
                return $preflightResponse;
            }
            $callCount++;
            throw $rateLimitException;
        });

        // Inject ZoteroApi mock directly into the proxy
        $this->subject->setZoteroApi($zoteroMock);

        // fullSync expects collectionIds; set minimal [null]
        $this->subject->setCollectionIds(new Collection([null]));

        // Expect a clear log entry about rate limit budget exceed
        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Rate limit retry budget exceeded'),
                $this->anything()
            );

        $this->expectException(Exception::class);
        $this->subject->callFullSync();
    }
}
