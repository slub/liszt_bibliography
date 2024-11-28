<?php
declare(strict_types=1);

namespace Slub\LisztBibliography\Processing;

// create a field "fulltext" and copy content of "tx_lisztcommon_searchable" to fulltext

class BibElasticMapping
{
    public static function getMappingParams(string $index): array
    {
        return [
            'index' => $index,
            'body' => [
                'mappings' => [
                    'dynamic' => false,
                    'properties' => [
                        'version' => [ 'type' => 'long' ],
                        'title' => [ 'type' => 'text'],
                        'university' => [ 'type' => 'text'],
                        'bookTitle' => [ 'type' => 'text'],
                        'series' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'publicationTitle' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'place' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'date' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'archiveLocation' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'itemType' => [ 'type' => 'keyword'],
                        'journalTitle'  => [ 'type' => 'keyword'],
                        'creators' => [
                            'type' => 'nested',
                            'properties' => [
                                'creatorType' => [
                                    'type' => 'keyword'
                                ],
                                'firstName' => [
                                    'type' => 'text',
                                    'fields' => [
                                        'keyword' => [
                                            'type' => 'keyword', 'ignore_above' => 256
                                        ],
                                    ],
                                    'copy_to' => 'creators.fullName'
                                ],
                                'lastName' => [
                                    'type' => 'text',
                                    'fields' => [
                                        'keyword' => [
                                            'type' => 'keyword', 'ignore_above' => 256
                                        ]
                                    ],
                                    'copy_to' => 'creators.fullName'
                                ],
                                'fullName' => ['type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword'] ] ],
                            ]
                        ],
                        'fulltext' => [ 'type' => 'text' ],
                        'tx_lisztcommon_header' => [ 'type' => 'text' ],
                        'tx_lisztcommon_body' => [ 'type' => 'text' ],
                        'tx_lisztcommon_footer' => [ 'type' => 'text' ],
                        'tx_lisztcommon_searchable' => ['type' => 'text', 'copy_to' => 'fulltext'],
                        'tx_lisztcommon_boosted' => ['type' => 'text'],
                    ]
                ]
            ]
        ];
    }
}
