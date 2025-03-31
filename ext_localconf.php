<?php

declare(strict_types=1);

use Slub\LisztBibliography\Controller\BibliographyController;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;

defined('TYPO3') or die();

// Logfile for IndexCommand
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Slub']['LisztBibliography']['Command']['IndexCommand']['writerConfiguration'] = [
    LogLevel::INFO => [
        FileWriter::class => [
            'logFile' => Environment::getVarPath() . '/log/liszt_bibliography_commands.log',
        ]
    ]
];

/*ExtensionUtility::configurePlugin(
    'LisztBibliography',
    'BibliographyListing',
    [ BibliographyController::class => 'index' ],
    [ BibliographyController::class => 'index' ]
);*/

/*ExtensionManagementUtility::addPageTSConfig(
    '<INCLUDE_TYPOSCRIPT: source="FILE:EXT:liszt_bibliography/Configuration/TsConfig/Page/Mod/Wizards/Listing.tsconfig">'
);*/
