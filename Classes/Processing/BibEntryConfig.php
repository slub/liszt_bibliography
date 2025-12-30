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

namespace Slub\LisztBibliography\Processing;

class BibEntryConfig
{
    const REQUIRED_FIELDS = [
        'key' => [
            'type' => 'string',
            'not_empty' => true,
            'min_length' => 3,
            'critical' => true, // skip this doc from indexing
        ],
        'title' => [
            'not_empty' => true,
            'critical' => true,
        ],
        'itemType' => [
            'type' => 'string',
            'not_empty' => true,
            'critical' => true,
            'allowedValues' => ['book', 'bookSection', 'journalArticle', 'thesis', 'webpage', 'encyclopediaArticle', 'attachment']
        ],
        'creators' => [
            'type' => 'array',
            'min_array_length' => 1,
        ],
        'date' => [
            'type' => 'string',
            'not_empty' => true,
            'min_length' => 4,
            'contains_year' => true, // new constrain type für special zotero date field (string with (multiple) 4-digit numbers)
        ],

    ];
/*    const AUTHOR = [
        'compound' => [
            'fields' => [
                [
                    'field' => 'firstName',
                    'conditionField' => 'creatorType',
                    'conditionValue' => 'author',
                    'conditionRelation' => 'eq'
                ],
                [
                    'field' => 'lastName',
                    'conditionField' => 'creatorType',
                    'conditionValue' => 'author',
                    'conditionRelation' => 'eq'
                ],
                [
                    'field' => 'name',
                    'conditionField' => 'creatorType',
                    'conditionValue' => 'author',
                    'conditionRelation' => 'eq'
                ]
            ],
            'field' => 'creators',
            'separator' => ', '
        ]
    ];*/



    const TITLE = [ 'field' => 'title' ];


    const DATE = [
        'field' => 'date',
        'conditionField' => 'date',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];




    // Alternatively, it would be possible to solve this by 'copy_to' the existing fields into the 'fulltext' field
    const SEARCHABLE_FIELDS = [
        [
            'compound' => [
                'fields' => [
                    [
                        'field' => 'firstName',
                        'conditionField' => 'creatorType',
                        'conditionValue' => 'author',
                        'conditionRelation' => 'eq'
                    ],
                    [
                        'field' => 'lastName',
                        'conditionField' => 'creatorType',
                        'conditionValue' => 'author',
                        'conditionRelation' => 'eq'
                    ],
                    [
                        'field' => 'name',
                        'conditionField' => 'creatorType',
                        'conditionValue' => 'author',
                        'conditionRelation' => 'eq'
                    ]
                ],
                'field' => 'creators',
                'separator' => ' ',
                'postfix' => ' '
            ]
        ],
        [
            'field' => 'title',
            'postfix' => ' '
        ],
        [
            'field' => 'university',
            'postfix' => ' '
        ],
        [
            'field' => 'bookTitle',
            'postfix' => ' '
        ],
        [
            'field' => 'series',
            'postfix' => ' '
        ],
        [
            'field' => 'publicationTitle',
            'postfix' => ' '
        ],
        [
            'field' => 'place',
            'postfix' => ' '
        ],
        [
            'field' => 'date',
            'postfix' => ' '
        ],
        [
            'field' => 'key',
            'postfix' => ' '
        ]
    ];

    const BOOSTED_FIELDS = [
        [
            'compound' => [
                'fields' => [
                    [
                        'field' => 'lastName',
                        'conditionField' => 'creatorType',
                        'conditionValue' => 'author',
                        'conditionRelation' => 'eq'
                    ]
                ],
                'field' => 'creators',
                'separator' => ' ',
                'reverseFirst' => true,
                'postfix' => ' '
            ]
        ],
        [ 'field' => 'title' ],
        [ 'field' => 'date' ]
    ];

    const AUTHORS_FIELD = [
        [
            'compoundArray' => [
                'fields' => [
                    [
                        'field' => 'firstName',
                        'conditionField' => 'creatorType',
                        'conditionValue' => 'author',
                        'conditionRelation' => 'eq'
                    ],
                    [
                        'field' => 'lastName',
                        'conditionField' => 'creatorType',
                        'conditionValue' => 'author',
                        'conditionRelation' => 'eq'
                    ],
                    [
                        'field' => 'name',
                        'conditionField' => 'creatorType',
                        'conditionValue' => 'author',
                        'conditionRelation' => 'eq'
                    ]
                ],
                'field' => 'creators',
                'separator' => ', ',
                'reverseFirst' => true,

            ]
        ]
    ];

    const EDITORS_FIELD = [
        [
            'compoundArray' => [
                'fields' => [
                    [
                        'field' => 'firstName',
                        'conditionField' => 'creatorType',
                        'conditionValue' => 'editor',
                        'conditionRelation' => 'eq'
                    ],
                    [
                        'field' => 'lastName',
                        'conditionField' => 'creatorType',
                        'conditionValue' => 'editor',
                        'conditionRelation' => 'eq'
                    ],
                    [
                        'field' => 'name',
                        'conditionField' => 'creatorType',
                        'conditionValue' => 'editor',
                        'conditionRelation' => 'eq'
                    ]
                ],
                'field' => 'creators',
                'separator' => ', ',
                'reverseFirst' => true,
            ]
        ]
    ];


    public static function getRequiredFields(): array
    {
        return self::REQUIRED_FIELDS;
    }



    private static function prefix(array $field, string $prefix): array
    {
        $field['prefix'] = $prefix;
        return $field;
    }

    private static function postfix(array $field, string $postfix): array
    {
        $field['postfix'] = $postfix;
        return $field;
    }

    private static function circumfix(array $field, string $prefix, string $postfix): array
    {
        return self::prefix(
            self::postfix($field, $postfix),
            $prefix);
    }

    private static function surround(array $field): array
    {
        return self::circumfix($field, '(', ')');
    }

    private static function space(array $field): array
    {
        $field['postfix'] = ' ';
        return $field;
    }

    private static function surroundSpace(array $field): array
    {
        return self::space(self::surround($field));
    }

    private static function comma(array $field): array
    {
        $field['postfix'] = ', ';
        return $field;
    }

    private static function surroundComma(array $field): array
    {
        return self::comma(self::surround($field));
    }
}
