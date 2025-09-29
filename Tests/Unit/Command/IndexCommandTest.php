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

// Proxy, um geschützte Methoden testbar zu machen und den ES-Client-Builder zu umgehen
final class IndexCommandTestProxy extends IndexCommand
{
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

    // WICHTIG: Builder im Test umgehen
    protected function getElasticClient()
    {
        // Wenn im Test bereits injiziert, diesen verwenden
        if ($this->client) {
            return $this->client;
        }

        // Fallback-Dummy, der von initialize() verwendet wird
        return new class {
            public function indices()
            {
                return new class {
                    public function create(array $params): void {}
                    public function updateAliases(array $params): void {}
                    public function getAlias(array $params)
                    {
                        // Liefere eine Struktur mit asArray(), wie sie vom Code erwartet wird
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
                // Standard: keine vorhandene Version
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

        // Minimale Extension-Konfiguration für liszt_bibliography
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

        // Proxy-Command instanziieren (verwendet den Dummy-ES-Client)
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

        // Client-Dummy, dessen search() 404 wirft
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
            ['total', 1],
        ]);
        $this->inputMock->method('getArgument')->willReturnMap([
            ['version', null],
        ]);

        $this->subject->callInitialize($this->inputMock, $this->outputMock);

        // ES-Client-Dummy für Index-Erstellung/Alias-Updates
        $clientForFullSync = new class {
            public function indices()
            {
                return new class {
                    public function create(array $params): void {}
                    public function updateAliases(array $params): void {}
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
        $zoteroMock->method('send')->will($this->onConsecutiveCalls(
            $preflightResponse,
            $this->throwException($rateLimitException),
            $this->throwException($rateLimitException),
            $this->throwException($rateLimitException),
            $this->throwException($rateLimitException),
            $this->throwException($rateLimitException)
        ));
        GeneralUtility::addInstance(ZoteroApi::class, $zoteroMock);

        // fullSync erwartet collectionIds; minimal [null] setzen
        $this->subject->setCollectionIds(new Collection([null]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Rate limit retry budget exceeded.');

        $this->subject->callFullSync();
    }
}
