<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

/*ExtensionUtility::registerPlugin(
    'liszt_bibliography',
    'BibliographyListing',
    'Liszt Bibliography Listing'
);*/

/*ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'LLL:EXT:liszt_bibliography/Resources/Private/Language/locallang.xlf:listing_title',
        'lisztbibliography_listing',
        'content-text'
    ]
);*/

/*$GLOBALS['TCA']['tt_content']['types']['lisztbibliography_listing'] = [
    'showitem' => '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
           --palette--;;general,
           header; Title,
           bodytext;LLL:EXT:core/Resources/Private/Language/Form/locallang_ttc.xlf:bodytext_formlabel,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
           --palette--;;hidden,
           --palette--;;acces,
        ',
    'columnsOverrides' => [
        'bodytext' => [
            'config' => [
                'enableRichtext' => true,
                'richtextConfiguration' => 'default'
            ]
        ]
    ]
];*/

