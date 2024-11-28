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
            self::postfix(self::PUBLICATION_TITLE, ' '),
            self::postfix(self::VOLUME, ' '),
            self::circumfix(self::DATE, '(', '), '),
            self::circumfix(self::ISSUE, 'Nr. ', ', '),
            self::PAGES
        ];
    }

    public static function getBookSectionFooter(): array
    {
        return [
            self::circumfix(self::BOOK_TITLE, 'In ', ', '),
            self::postfix(self::VOLUME, ', '),
            self::circumfix(self::EDITOR, 'hg. von ', ', '),
            self::circumfix(self::TRANSLATOR, 'übers. von ', ', '),
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
            self::postfix(self::PLACE, ' '),
            self::DATE
        ];
    }

    public static function getThesisFooter(): array
    {
        return [
            self::postfix(self::UNIVERSITY, ' '),
            self::DATE
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
}
