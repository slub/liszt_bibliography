<?php

namespace Slub\LisztBibliography\Tests\Unit\Services;

use Slub\LisztBibliography\Services\TextCompositionService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * @covers Slub\LisztBibliography\Services\TextCompositionService
 */
final class TextCompositionServiceTest extends UnitTestCase
{
    const EX_VAL1 = 'a';
    const EX_VAL2 = 'b';
    const EX_KEY1 = 'x';
    const EX_KEY2 = 'y';
    const EX_RULE = [ self::EX_KEY1, self::EX_KEY2 ];
    const EX_PREFIX = 'pre';
    const EX_SUFFIX = 'post';
    const EX_PREFIX_ARRAY = [ self::EX_KEY1 => self::EX_PREFIX ];
    const EX_SUFFIX_ARRAY = [ self::EX_KEY1 => self::EX_SUFFIX ];

    private ?TextCompositionService $subject = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = GeneralUtility::makeInstance(TextCompositionService::class);
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function emptyValuesLeadToEmptyString(): void
    {
        $string = $this->subject->formatCommaSeparatedValues(['', null]);

        self::assertEquals('', $string);
    }

    /**
     * @test
     */
    public function twoValuesAreRenderedWithComma(): void
    {
        $string = $this->subject->formatCommaSeparatedValues([self::EX_KEY1 => self::EX_VAL1, self::EX_KEY2 =>  self::EX_VAL2]);
        $expected = self::EX_VAL1 . ', ' . self::EX_VAL2;

        self::assertEquals($expected, $string);
    }

    /**
     * @test
     */
    public function noCommaRulePreventsComma(): void
    {
        $string = $this->subject->formatCommaSeparatedValues([self::EX_KEY1 => self::EX_VAL1, self::EX_KEY2 => self::EX_VAL2], [], [], [self::EX_RULE]);
        $expected = self::EX_VAL1 . ' ' . self::EX_VAL2;

        self::assertEquals($expected, $string);
    }

    /**
     * @test
     */
    public function prefixIsProcessedCorrectly(): void
    {
        $string = $this->subject->formatCommaSeparatedValues([self::EX_KEY1 => self::EX_VAL1, self::EX_KEY2 => self::EX_VAL2], self::EX_PREFIX_ARRAY);
        $expected = self::EX_PREFIX . self::EX_VAL1 . ', ' . self::EX_VAL2;

        self::assertEquals($expected, $string);
    }

    /**
     * @test
     */
    public function suffixIsProcessedCorrectly(): void
    {
        $string = $this->subject->formatCommaSeparatedValues([self::EX_KEY1 => self::EX_VAL1, self::EX_KEY2 => self::EX_VAL2], [], self::EX_SUFFIX_ARRAY);
        $expected = self::EX_VAL1 . self::EX_SUFFIX . ', ' . self::EX_VAL2;

        self::assertEquals($expected, $string);
    }

}
