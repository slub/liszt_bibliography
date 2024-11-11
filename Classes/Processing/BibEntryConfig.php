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
    const HEADER_FIELDS = [
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
                'separator' => ', ',
                'reverseFirst' => true
            ]
        ]
    ];
    const ALT_HEADER_FIELDS = [
        [
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
                'separator' => ', ',
                'reverseFirst' => true
            ]
        ]
    ];
    const BODY_FIELDS = [
        [
            'field' => 'title',
            'postfix' => ' '
        ],
        [
            'field' => 'shortTitle',
            'prefix' => '(',
            'postfix' => ')'
        ],
    ];
    const FOOTER_FIELDS = [
        [ 'compound' => [
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
            ],
            'field' => 'creators',
            'separator' => ', ',
            'postfix' => ': '
        ]],
        [
            'field' => 'publicationTitle',
            'conditionField' => 'publicationTitle',
            'conditionValue' => '',
            'conditionRelation' => 'neq',
            'postfix' => ', '
        ],
        [
            'field' => 'bookTitle',
            'conditionField' => 'bookTitle',
            'conditionValue' => '',
            'conditionRelation' => 'neq',
            'postfix' => ', '
        ],
        [
            'field' => 'university',
            'conditionField' => 'university',
            'conditionValue' => '',
            'conditionRelation' => 'neq',
            'postfix' => ' '
        ],
        [
            'field' => 'volume',
            'conditionField' => 'volume',
            'conditionValue' => '',
            'conditionRelation' => 'neq',
            'postfix' => ' '
        ],
        [
            'field' => 'issue',
            'conditionField' => 'issue',
            'conditionValue' => '',
            'conditionRelation' => 'neq',
            'prefix' => '(',
            'postfix' => ') '
        ],
        [
            'field' => 'place',
            'conditionField' => 'place',
            'conditionValue' => '',
            'conditionRelation' => 'neq',
            'postfix' => ' '
        ],
        [
            'field' => 'date',
            'conditionField' => 'date',
            'conditionValue' => '',
            'conditionRelation' => 'neq',
        ],
        [
            'field' => 'pages',
            'conditionField' => 'pages',
            'conditionValue' => '',
            'conditionRelation' => 'neq',
            'prefix' => ', '
        ]
    ];
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
