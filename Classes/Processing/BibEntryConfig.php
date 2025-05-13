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
    const AUTHOR = [
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
    ];

    const EDITOR = [
        'compound' => [
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
            'separator' => ', '
        ]
    ];

    const TRANSLATOR = [
        'compound' => [
            'fields' => [
                [
                    'field' => 'firstName',
                    'conditionField' => 'creatorType',
                    'conditionValue' => 'translator',
                    'conditionRelation' => 'eq'
                ],
                [
                    'field' => 'lastName',
                    'conditionField' => 'creatorType',
                    'conditionValue' => 'translator',
                    'conditionRelation' => 'eq'
                ],
                [
                    'field' => 'name',
                    'conditionField' => 'creatorType',
                    'conditionValue' => 'translator',
                    'conditionRelation' => 'eq'
                ]
            ],
            'field' => 'creators',
            'separator' => ', '
        ]
    ];

    const TITLE = [ 'field' => 'title' ];

    const PUBLICATION_TITLE = [
        'field' => 'publicationTitle',
        'conditionField' => 'publicationTitle',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];

    const BOOK_TITLE = [
        'field' => 'bookTitle',
        'conditionField' => 'bookTitle',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];

    const UNIVERSITY = [
        'field' => 'university',
        'conditionField' => 'university',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];

    const VOLUME = [
        'field' => 'volume',
        'conditionField' => 'volume',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];

    const ISSUE = [
        'field' => 'issue',
        'conditionField' => 'issue',
        'conditionValue' => '',
        'conditionRelation' => 'neq'
    ];

    const PLACE = [
        'field' => 'place',
        'conditionField' => 'place',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];

    const DATE = [
        'field' => 'date',
        'conditionField' => 'date',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];

    const PAGES = [
        'field' => 'pages',
        'conditionField' => 'pages',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];

    const NUMBER_OF_VOLUMES = [
        'field' => 'numberOfVolumes',
        'conditionField' => 'numberOfVolumes',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];

    const SERIES = [
        'field' => 'series',
        'conditionField' => 'series',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];

    const SERIESNUMBER = [
        'field' => 'seriesNumber',
        'conditionField' => 'seriesNumber',
        'conditionValue' => '',
        'conditionRelation' => 'neq',
    ];

    const ENCYCLOPEDIATITLE = [
        'field' => 'encyclopediaTitle',
        'conditionField' => 'encyclopediaTitle',
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


    public static function getAuthorHeader(): array
    {
        return [ self::AUTHOR ];
    }

    public static function getEditorHeader(): array
    {
        return [ self::postfix(self::EDITOR, ' (Hg.)') ];
    }

    public static function getBody(): array
    {
        return [ self::TITLE ];
    }

    public static function getArticleFooter(): array
    {
        return [
            self::circumfix(self::PUBLICATION_TITLE, 'in: ', ' '),
            self::postfix(self::VOLUME, ' '),
            self::circumfix(self::DATE, '(', '), '),
            self::circumfix(self::ISSUE, 'Nr. ', ', '),
            self::PAGES
        ];
    }

    public static function getBookSectionFooter(): array
    {
        return [
            self::circumfix(self::BOOK_TITLE, 'in: ', ', '),
            self::circumfix(self::EDITOR, 'hg. von ', ', '),
            self::circumfix(self::TRANSLATOR, 'übers. von ', ', '),
            self::postfix(self::NUMBER_OF_VOLUMES, 'Bde., '),
            self::circumfix(self::VOLUME, 'Bd. ', ', '),
            self::postfix(self::PLACE, ' '),
            self::postfix(self::DATE, ', '),
            self::PAGES
        ];
    }

    public static function getBookFooter(): array
    {
        return [
            self::circumfix(self::EDITOR, 'hg. von ', ', '),
            self::circumfix(self::TRANSLATOR, 'übers. von ', ', '),
            self::postfix(self::NUMBER_OF_VOLUMES, 'Bde., '),
            self::circumfix(self::VOLUME, 'Bd. ', ', '),
            self::postfix(self::PLACE, ' '),
            self::DATE
        ];
    }

    public static function getPrintedMusicFooter(): array
    {
        return [
            self::circumfix(self::SERIES, 'in: ', ', '),
            self::postfix(self::SERIESNUMBER, ', '),
            self::prefix(self::VOLUME, 'Bd. '),
        ];
    }

    public static function getThesisFooter(): array
    {
        return [
            self::postfix(self::UNIVERSITY, ' '),
            self::DATE
        ];
    }

    public static function getEncyclopediaArticleFooter(): array
    {
        return [
            self::circumfix(self::ENCYCLOPEDIATITLE, 'in: ', ', '),
            self::circumfix(self::VOLUME, 'Bd. ', ', '),
            self::circumfix(self::EDITOR, 'hg. von ', ', '),
            self::postfix(self::PLACE, ' '),
            self::postfix(self::DATE, ', '),
            self::PAGES
        ];
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
