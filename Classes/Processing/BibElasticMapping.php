<?php
declare(strict_types=1);

namespace Slub\LisztBibliography\Processing;

// the Extension ICU analyzer must be installed in Elasticsearch
// field "fulltext" with ICU analyzer for improved text search
// Supports transliteration (e.g., здравствуйте -> zdravstvujte)
// and normalization (e.g., straße -> strasse, Kornél -> Kornel)

class BibElasticMapping
{
    public static function getMappingParams(string $index): array
    {
        return [
            'index' => $index,
            'body' => [
                'settings' => [
                    'analysis' => [
                        'analyzer' => [
                            'icu_fulltext_analyzer' => [
                                'type' => 'custom',
                                'tokenizer' => 'icu_tokenizer',
                                'filter' => [
                                    'icu_folding',
                                    'icu_normalizer',
                                    'icu_transform',
                                    'lowercase',
                                    'stop'
                                ]
                            ],
                            'icu_search_analyzer' => [
                                'type' => 'custom',
                                'tokenizer' => 'icu_tokenizer',
                                'filter' => [
                                    'icu_folding',
                                    'icu_normalizer',
                                    'icu_transform',
                                    'lowercase'
                                ]
                            ]
                        ],
                        'filter' => [
                            'icu_transform' => [
                                'type' => 'icu_transform',
                                'id' => 'Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC'
                            ]
                        ]
                    ]
                ],
                'mappings' => [
                    'dynamic' => false,
                    'properties' => [
                        'key' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256] ] ],
                        'itemType' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'version' => [ 'type' => 'long' ],
                        'title' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'shortTitle' => [ 'type' => 'text'],
                        'university' => [ 'type' => 'text'],
                        'bookTitle' => [ 'type' => 'text'],
                        'series' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'seriesNumber' => [ 'type' => 'text'],
                        'pages' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'volume' => [ 'type' => 'text'],
                        'publicationTitle' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'language' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'place' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'date' => [ 'type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword', 'ignore_above' => 256 ] ] ],
                        'dateAdded' => [ 'type' => 'date' ],
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
                                'name' => [
                                    'type' => 'text',
                                    'fields' => [
                                        'keyword' => [
                                            'type' => 'keyword', 'ignore_above' => 256
                                        ]
                                    ],
                                ],
                                BibEntryProcessor::FULLNAME_KEY => ['type' => 'text', 'fields' => [ 'keyword' => [ 'type' => 'keyword'] ] ],
                            ]
                        ],
                        'fulltext' => [
                            'type' => 'text',
                            'analyzer' => 'icu_fulltext_analyzer',
                            'search_analyzer' => 'icu_search_analyzer',
                            'fields' => [
                                'raw' => [
                                    'type' => 'text',
                                    'analyzer' => 'standard'
                                ]
                            ]
                        ],
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
                        BibEntryProcessor::YEAR_FIELD => [ 'type' => 'short' ],
                        BibEntryProcessor::ORIGINAL_ITEM_TYPE => [ 'type' => 'text'],
                    ]
                ]
            ]
        ];
    }
}
