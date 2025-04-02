<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;

defined('TYPO3') or die();

$extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('liszt_bibliography');

$logLevel = match((int)$extConf['logLevel']) {
    0 => LogLevel::INFO,
    1 => LogLevel::NOTICE,
    2 => LogLevel::WARNING,
    default => LogLevel::ERROR
};

// Logfile for IndexCommand
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Slub']['LisztBibliography']['Command']['IndexCommand']['writerConfiguration'] = [
    $logLevel => [
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
