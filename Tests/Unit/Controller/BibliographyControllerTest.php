<?php

namespace Slub\LisztBibliography\Tests\Unit\Controller;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Slub\LisztBibliography\Controller\BibliographyController;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\StreamFactory;
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

        $methodsToMock = ['indexAction'];
        $responseFactory = GeneralUtility::makeInstance(ResponseFactory::class);
        $streamFactory = GeneralUtility::makeInstance(StreamFactory::class);
        $this->subject = $this->getAccessibleMock(BibliographyController::class, $methodsToMock, [$responseFactory, $streamFactory]);
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function indexActionReturnsHtmlResponse(): void
    {
        $result = $this->subject->indexAction();

        self::assertInstanceOf(ResponseInterface::class, $result);
    }

}
