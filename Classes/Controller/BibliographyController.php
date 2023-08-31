<?php

declare(strict_types=1);

/*
 * This file is part of the Liszt Catalog Raisonne project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 */

namespace Slub\LisztBibliography\Controller;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Slub\LisztCommon\Controller\ClientEnabledController;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class BibliographyController extends ClientEnabledController
{
    /** id of list target **/
    const MAIN_TARGET = 'bib-list';
    const SIDE_TARGET = 'bib-list-side';
    const SIZE = 20;

    /** @var jsCall */
    protected string $jsCall;

    /** @var div */
    protected string $div;

    protected string $bibIndex;
    protected string $localeIndex;

    public function __construct(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory)
    {
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
    }

    public function initializeAction(): void
    {
        $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('liszt_bibliography');
        $this->bibIndex = $extConf['elasticIndexName'];
        $this->localeIndex = $extConf['elasticLocaleIndexName'];
    }

    public function indexAction(): ResponseInterface
    {
        $this->createJsCall();
        $this->wrapTargetDiv();
        $contentStream = $this->
            streamFactory->
            createStream(
                $this->div . 
                $this->jsCall
            );

        return $this->
            responseFactory->
            createResponse()->
            withBody($contentStream);
    }

    private function wrapTargetDiv(): void
    {
        $sideCol = '<div id="' .
            self::SIDE_TARGET .
            '" class="col-md-4 col-xl-3 order-md-2"><ul class="list-group"></ul></div>';
        $mainCol =  '<div id="' .
            self::MAIN_TARGET .
            '" class="col-md order-md-1"></div>';
        $this->div = '<div class="container"><div class="row">' .
            $sideCol . $mainCol . '</div>';
    }

    private function createJsCall(): void
    {
        $this->jsCall = 
            '<script>;document.addEventListener("DOMContentLoaded", _ => new BibliographyController({' .
                'target:"' . self::MAIN_TARGET . '",' .
                'sideTarget:"' . self::SIDE_TARGET . '",' .
                'size:' . self::SIZE . ',' .
                'bibIndex:"' . $this->bibIndex . '",' .
                'localeIndex:"' . $this->localeIndex . '"' .
                '}));</script>';
    }
}
