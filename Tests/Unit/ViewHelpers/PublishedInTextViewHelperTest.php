<?php

namespace Slub\LisztBibliography\Tests\Unit\ViewHelpers;

use Slub\LisztBibliography\Services\TextCompositionService;
use Slub\LisztBibliography\ViewHelpers\PublishedInTextViewHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * @covers Slub\LisztBibliography\Services\PublishedInTextViewHelper
 */
final class PublishedInTextViewHelperTest extends UnitTestCase
{
    const EX_ENTRY = [
        "itemType"=> "bookSection",
        "title"=> "Ferdinand Hiller and Franz Liszt: A Friendship Built at the Keyboard, The Sundered and Never Healed",
        "creators"=>
            [
                [
                    "creatorType"=> "author",
                    "firstName"=> "Ralph P.",
                    "lastName"=> "Locke"
                ],
                [
                    "creatorType"=> "author",
                    "firstName"=> "Jürgen",
                    "lastName"=> "Thym"
                ]
            ],
        "bookTitle"=> "Unity in Variety. Essays in Musicology for R. Larry Todd",
        "series"=> "",
        "seriesNumber"=> "",
        "volume"=> "",
        "numberOfVolumes"=> "",
        "edition"=> "",
        "place"=> "Wien",
        "publisher"=> "",
        "date"=> "2024",
        "pages"=> "S. 287-306",
        "language"=> "Englisch",
        "shortTitle"=> "Locke 2024"
    ];

    private ?PublishedInTextViewHelper $subject = null;

    protected function setUp(): void
    {
        parent::setUp();
        $textCompositionService = GeneralUtility::makeInstance(TextCompositionService::class);
        $this->subject = GeneralUtility::makeInstance(PublishedInTextViewHelper::class, $textCompositionService);
        $this->subject->setArguments(['values' => self::EX_ENTRY]);
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
/*
    public function bookSectionIsRenderedCorrectly(): void
    {
        $string = $this->subject->render();

        self::assertEquals('', $string);
    }
*/

}
