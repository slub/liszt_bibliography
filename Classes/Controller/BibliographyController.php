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
use Slub\LisztCommon\Controller\ClientEnabledController;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use Slub\LisztBibliography\Interfaces\ElasticSearchServiceInterface;


final class BibliographyController extends ClientEnabledController
{

    // set resultLimit as intern variable from $this->settings['resultLimit'];
    protected int $resultLimit;

    // Dependency Injection of Repository
    // https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/DependencyInjection/Index.html#Dependency-Injection

    public function __construct(private readonly ElasticSearchServiceInterface $elasticSearchService)
    {
        $this->resultLimit = $this->settings['resultLimit'] ?? 25;

    }



    public function listAction(): ResponseInterface
    {
        $this->view->assign('bibliographyList', $this->elasticSearchService->search());
        return $this->htmlResponse();
    }





}
