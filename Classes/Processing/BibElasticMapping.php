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
                        'itemType' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'version' => [ 'type' => 'long' ],
                        'title' => [ 'type' => 'text'],
                        'university' => [ 'type' => 'text'],
                        'bookTitle' => [ 'type' => 'text'],
                        'series' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'publicationTitle' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'language' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'place' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'date' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'archiveLocation' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'journalTitle'  => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
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
                                ],
                                'lastName' => [
                                    'type' => 'text',
                                    'fields' => [
                                        'keyword' => [
                                            'type' => 'keyword', 'ignore_above' => 256
                                        ]
                                    ],
                                ],
                            ]
                        ],
                        'fulltext' => [ 'type' => 'text' ],
                        BibEntryProcessor::HEADER_FIELD => [ 'type' => 'text' ],
                        BibEntryProcessor::BODY_FIELD => [ 'type' => 'text' ],
                        BibEntryProcessor::FOOTER_FIELD => [ 'type' => 'text' ],
                        BibEntryProcessor::SEARCHABLE_FIELD => ['type' => 'text', 'copy_to' => 'fulltext'],
                        BibEntryProcessor::BOOSTED_FIELD => ['type' => 'text'],
                        BibEntryProcessor::AUTHORS_FIELD => [
                            'type' => 'nested',
                            'properties' => [
                                BibEntryProcessor::FULLNAME_KEY => [
                                    'type' => 'text',
                                    'fields' => [
                                        'keyword' => [
                                            'type' => 'keyword', 'ignore_above' => 256
                                        ],
                                    ],
                                ],
                            ]
                        ],
                        BibEntryProcessor::EDITORS_FIELD => [
                            'type' => 'nested',
                            'properties' => [
                                BibEntryProcessor::FULLNAME_KEY => [
                                    'type' => 'text',
                                    'fields' => [
                                        'keyword' => [
                                            'type' => 'keyword', 'ignore_above' => 256
                                        ],
                                    ],
                                ],
                            ]
                        ],
                    ]
                ]
            ]
        ];
    }
}
