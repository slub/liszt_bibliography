<?php

declare(strict_types=1);

use Slub\LisztBibliography\Controller\BibliographyController;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

ExtensionUtility::configurePlugin(
    'LisztBibliography',
    'BibliographyListing',
    [ BibliographyController::class => 'index' ],
    [ BibliographyController::class => 'index' ]
);

