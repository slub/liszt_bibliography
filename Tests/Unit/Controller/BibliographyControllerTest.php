<?php

namespace Slub\LisztBibliography\Tests\Unit\Controller;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Slub\LisztBibliography\Controller\BibliographyController;
use Slub\LisztBibliography\Services\ElasticSearchService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * @covers Slub\LisztBibliography\Controller\BibliographyController
 */
final class BibliographyControllerTest extends UnitTestCase
{
    private ?BibliographyController $subject = null;

    protected function setUp(): void
    {
        parent::setUp();

        $methodsToMock = ['searchAction'];
        $elasticSearchService = GeneralUtility::makeInstance(ElasticSearchService::class);
        $this->subject = $this->getAccessibleMock(BibliographyController::class, $methodsToMock, [$elasticSearchService]);
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function searchActionReturnsHtmlResponse(): void
    {
        $result = $this->subject->searchAction();

        self::assertInstanceOf(ResponseInterface::class, $result);
    }

}
