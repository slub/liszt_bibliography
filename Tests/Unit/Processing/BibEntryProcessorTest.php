<?php

namespace Slub\LisztBibliography\Tests\Unit\Controller;

use Illuminate\Support\Str;
use Slub\LisztBibliography\Processing\BibEntryConfig;
use Slub\LisztBibliography\Processing\BibEntryProcessor;
use Slub\LisztCommon\Common\Collection;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * @covers Slub\LisztBibliography\Controller\BibliographyController
 */
final class BibEntryProcessorTest extends UnitTestCase
{
    private ?BibEntryProcessor $subject = null;
    private array $exampleBookArray = [];
    private array $exampleBookWithoutAuthorArray = [];
    private array $exampleBookWithAnonymousAuthorArray = [];
    private array $exampleBookSectionArray = [];
    private array $exampleArticleArray = [];

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

        $this->exampleBook =
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

        $this->exampleBookWithoutAuthor =
            <<<JSON
            {
                "key": "key",
                "itemType": "book",
                "title": "$this->title",
                "creators": [
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
                "volume": "$this->volume",
                "numberOfVolumes": "$this->numberOfVolumes",
                "place": "$this->place",
                "date": "$this->date"
            }
            JSON;

        $this->exampleBookWithAnonymousAuthor =
            <<<JSON
            {
                "key": "key",
                "itemType": "book",
                "title": "$this->title",
                "creators": [
                    {
                        "creatorType": "author",
                        "firstName": "",
                        "lastName": "$this->authorLastName"
                    }
                ],
                "place": "$this->place",
                "date": "$this->date"
            }
            JSON;

        $this->exampleArticle =
            <<<JSON
            {
                "key": "key",
                "itemType": "journalArticle",
                "title": "$this->title",
                "creators": [
                    {
                        "creatorType": "author",
                        "firstName": "$this->authorFirstName",
                        "lastName": "$this->authorLastName"
                    }
                ],
                "publicationTitle": "$this->bookTitle",
                "date": "$this->date",
                "pages": "$this->pages",
                "volume": "$this->volume",
                "issue": "$this->issue"
            }
            JSON;

        $this->exampleBookSection =
            <<<JSON
            {
                "key": "key",
                "itemType": "bookSection",
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
                "bookTitle": "$this->bookTitle",
                "volume": "$this->volume",
                "numberOfVolumes": "$this->numberOfVolumes",
                "place": "$this->place",
                "date": "$this->date",
                "pages": "$this->pages"
            }
            JSON;

        $this->subject = GeneralUtility::makeInstance(BibEntryProcessor::class);
        $this->exampleBookArray = json_decode($this->exampleBook, true);
        $this->exampleBookWithoutAuthorArray = json_decode($this->exampleBookWithoutAuthor, true);
        $this->exampleBookWithAnonymousAuthorArray = json_decode($this->exampleBookWithAnonymousAuthor, true);
        $this->exampleArticleArray = json_decode($this->exampleArticle, true);
        $this->exampleBookSectionArray = json_decode($this->exampleBookSection, true);
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function headerIsProcessedCorrectly(): void
    {
        $book = $this->subject->process($this->exampleBookArray, new Collection(), new Collection());
        $bookSection = $this->subject->process($this->exampleBookSectionArray, new Collection(), new Collection());
        $article = $this->subject->process($this->exampleArticleArray, new Collection(), new Collection());
        $bookWithoutAuthor = $this->subject->process($this->exampleBookWithoutAuthorArray, new Collection(), new Collection());
        $bookWithAnonymousAuthor = $this->subject->process($this->exampleBookWithAnonymousAuthorArray, new Collection(), new Collection());

        $expected = Str::of($this->authorFirstName . ' ' . $this->authorLastName);
        $expectedWithoutAuthor = Str::of($this->editorFirstName . ' ' . $this->editorLastName . ' (Hg.)');
        $expectedWithAnonymousAuthor = Str::of($this->authorLastName);

        self::assertEquals($book['tx_lisztcommon_header'], $expected);
        self::assertEquals($bookSection['tx_lisztcommon_header'], $expected);
        self::assertEquals($article['tx_lisztcommon_header'], $expected);
        self::assertEquals($bookWithoutAuthor['tx_lisztcommon_header'], $expectedWithoutAuthor);
        self::assertEquals($bookWithAnonymousAuthor['tx_lisztcommon_header'], $expectedWithAnonymousAuthor);
    }

    /**
     * @test
     */
    public function bodyIsProcessedCorrectly(): void
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
    public function bookFooterIsProcessedCorrectly(): void
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
    public function bookSectionFooterIsProcessedCorrectly(): void
    {
        $bookSection = $this->subject->process($this->exampleBookSectionArray, new Collection(), new Collection());
        $expected = Str::of(
            'in: ' . $this->bookTitle . ', ' .
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
    public function articleFooterIsProcessedCorrectly(): void
    {
        $article = $this->subject->process($this->exampleArticleArray, new Collection(), new Collection());
        $expected = Str::of(
            'in: ' . $this->bookTitle . ' ' .
            $this->volume .
            ' (' . $this->date . '), Nr. ' .
            $this->issue . ', ' .
            $this->pages
        );

        self::assertEquals($expected, $article['tx_lisztcommon_footer']);
    }


}
