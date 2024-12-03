<?php

namespace Slub\LisztBibliography\Tests\Unit\Controller;

use Elastic\Elasticsearch\ClientInterface;
use Elastic\Elasticsearch\Endpoints\Indices;
use GuzzleHttp\Psr7\Response;
use Hedii\ZoteroApi\ZoteroApi;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Slub\LisztBibliography\Command\IndexCommand;
use Slub\LisztBibliography\Exception\TooManyRequestsException;
use Slub\LisztBibliography\Processing\BibEntryConfig;
use Slub\LisztBibliography\Processing\BibEntryProcessor;
use Slub\LisztBibliography\Tests\Fixtures\ClientMock;
use Slub\LisztCommon\Common\Collection;
use Slub\LisztCommon\Common\ElasticClientBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * @covers Slub\LisztBibliography\Controller\BibliographyController
 */
final class IndexCommandTest extends UnitTestCase
{
    private ?IndexCommand $subject = null;
    private string $exampleEntry = '';

    private string $authorFirstName = 'ex_author_first';
    private string $authorLastName = 'ex_author_last';
    private string $editorFirstName = 'ex_editor_first';
    private string $editorLastName = 'ex_editor_last';
    private string $translatorFirstName = 'ex_translator_first';
    private string $translatorLastName = 'ex_translator_last';
    private string $title = 'ex_title';
    private string $bookTitle = 'ex_book_title';
    private string $place = 'ex_place';
    private string $date = 'ex_date';
    private string $pages = 'ex_pages';
    private string $volume = 'ex_volume';
    private string $numberOfVolumes = 'ex_number_of_volumes';
    private string $issue = 'ex_issue';

    private string $exampleBook = '';
    private string $exampleBookWithoutAuthor = '';
    private string $exampleBookWithAnonymousAuthor = '';
    private string $exampleArticle = '';
    private string $exampleBookSection = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->exampleEntry =
            <<<JSON
            {
                "key": "key",
                "itemType": "book",
                "title": "$this->title",
                "creators": [
                    {
                        "creatorType": "author",
                        "firstName": "$this->authorFirstName",
                        "lastName": "$this->authorLastName"
                    },
                    {
                        "creatorType": "editor",
                        "firstName": "$this->editorFirstName",
                        "lastName": "$this->editorLastName"
                    },
                    {
                        "creatorType": "translator",
                        "firstName": "$this->translatorFirstName",
                        "lastName": "$this->translatorLastName"
                    }
                ],
                "place": "$this->place",
                "volume": "$this->volume",
                "numberOfVolumes": "$this->numberOfVolumes",
                "date": "$this->date"
            }
            JSON;

        $siteFinderMock = $this->createMock(SiteFinder::class);
        $loggerMock = $this->createMock(LoggerInterface::class);
        $this->subject = GeneralUtility::makeInstance(IndexCommand::class, $siteFinderMock, $loggerMock);
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function commandBreaksOn429(): void
    {
        $response = $this->createStub(Response::class);
        $client = $this->createStub(ZoteroApi::class);
        $response->method('getStatusCode')->
            willReturn(429);
        $client->method('send')->
            willReturn($response);
/*
        $response = $this->getAccessibleMock(Response::class, ['getStatusCode'], [], '', false);
        $response->method('getStatusCode')->
            willReturn(429);
        $selfReturningMethods = [ 'group', 'items', 'top', 'start', 'limit', 'setSince' ];
        $client = $this->getAccessibleMock(ZoteroApi::class,
            ['send', ...$selfReturningMethods ],
            [],
            '',
            false
        );
        $client->method('send')->
            willReturn($response);
        foreach ($selfReturningMethods as $method) {
            $client->
                method($method)->
                willReturn($client);
        }
*/

/*
        $indices = $this->createStub(Indices::class);
        $indices->method('exists')->
            willReturn(true);
*/
        $elasticClient = $this->createStub(ClientInterface::class);
        //$elasticClient->method('search')->willReturn(true);
/*
        $elasticClient->method('indices')->
            willReturn($indices);
        $elasticClient->method('exists')->
            willReturn(true);
*/
        $elasticClientBuilder = $this->createStub(ElasticClientBuilder::class);
        $elasticClientBuilder->method('getClient')->
            willReturn($elasticClient);
        GeneralUtility::addInstance(ElasticClientBuilder::class, $elasticClientBuilder);

        $this->expectException(TooManyRequestsException::class);
        GeneralUtility::addInstance(ZoteroApi::class, $client);

        $extConfMap = [
            [
                'liszt_bibliography',
                '',
                [
                    'zoteroApiKey' => 'abc',
                    'zoteroGroupId' => 'abc',
                    'zoteroBulkSize' => 10,
                    'elasticIndexName' => 'zotero'
                ]
            ],
            [
                'liszt_common',
                '',
                [
                    'elasticHostName' => 'https://elasticsearch',
                    'elasticCaFilePath' => '',
                    'elasticPwdFileName' => '',
                    'elasticCredentialsFilePath' => '',
                    'zoteroBulkSize' => 10
                ]
            ]
        ];

        $extConf = $this->createStub(ExtensionConfiguration::class);
        $extConf->method('get')->willReturnMap($extConfMap);
        GeneralUtility::addInstance(ExtensionConfiguration::class, $extConf);
        GeneralUtility::addInstance(ExtensionConfiguration::class, $extConf);

        $inputMock = $this->createStub(InputInterface::class);
        $outputMock = $this->createStub(OutputInterface::class);

        $this->subject->run($inputMock, $outputMock);

    }

    /**
     * @test
     */
    public function commandBreaksAfterThreeTimes500(): void
    {
        $book = $this->subject->process($this->exampleBookArray, new Collection(), new Collection());
        $bookSection = $this->subject->process($this->exampleBookSectionArray, new Collection(), new Collection());
        $article = $this->subject->process($this->exampleArticleArray, new Collection(), new Collection());

        self::assertEquals(Str::of($this->title), $book['tx_lisztcommon_body']);
        self::assertEquals(Str::of($this->title), $bookSection['tx_lisztcommon_body']);
        self::assertEquals(Str::of($this->title), $article['tx_lisztcommon_body']);
    }

    /**
     * @test
     */
    public function unsetGroupIdLeadsToException(): void
    {
        $book = $this->subject->process($this->exampleBookArray, new Collection(), new Collection());
        $expected = Str::of(
            'hg. von ' . $this->editorFirstName . ' ' . $this->editorLastName . ', ' .
            'übers. von ' . $this->translatorFirstName . ' ' . $this->translatorLastName . ', ' .
            $this->numberOfVolumes . 'Bde., ' .
            'Bd. ' . $this->volume . ', ' .
            $this->place . ' ' .
            $this->date
        );

        self::assertEquals($expected, $book['tx_lisztcommon_footer']);
    }

    /**
     * @test
     */
    public function unsetApiKeyLeadsToException(): void
    {
        $bookSection = $this->subject->process($this->exampleBookSectionArray, new Collection(), new Collection());
        $expected = Str::of(
            'In ' . $this->bookTitle . ', ' .
            'hg. von ' . $this->editorFirstName . ' ' . $this->editorLastName . ', ' .
            'übers. von ' . $this->translatorFirstName . ' ' . $this->translatorLastName . ', ' .
            $this->numberOfVolumes . 'Bde., ' .
            'Bd. ' . $this->volume . ', ' .
            $this->place . ' ' .
            $this->date . ', ' .
            $this->pages
        );

        self::assertEquals($expected, $bookSection['tx_lisztcommon_footer']);
    }

    /**
     * @test
     */
    public function exampleEntryIsIndexedCorrectly(): void
    {
        $article = $this->subject->process($this->exampleArticleArray, new Collection(), new Collection());
        $expected = Str::of(
            $this->bookTitle . ' ' .
            $this->volume .
            ' (' . $this->date . '), Nr. ' .
            $this->issue . ', ' .
            $this->pages
        );

        self::assertEquals($expected, $article['tx_lisztcommon_footer']);
    }


}

class SomeClass { public function doSomething($a) { return 'a';}}
