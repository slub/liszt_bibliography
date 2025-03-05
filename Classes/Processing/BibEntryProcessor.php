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

use Illuminate\Support\Stringable;
use Illuminate\Support\Str;
use Slub\LisztCommon\Common\Collection;
use Slub\LisztCommon\Processing\IndexProcessor;
use Psr\Log\LoggerInterface;

class BibEntryProcessor extends IndexProcessor
{
    const AUTHORS_FIELD = 'tx_lisztbibliography_authors';
    const EDITORS_FIELD = 'tx_lisztbibliography_editors';

    const YEAR_FIELD = 'tx_lisztbibliography_year';
    const FULLNAME_KEY = 'fullName';

    public function __construct(
        private readonly LoggerInterface $logger
    )
    { }

    //Todo: maybe we find a better name for "process" ;-)
    public function process(
        array $bibliographyItem,
        Collection $localizedCitations,
        Collection $teiDataSets
    ): array
    {
        $key = $bibliographyItem['key'];
        $bibliographyItem['localizedCitations'] = [];
        foreach ($localizedCitations as $locale => $localizedCitation) {
            $bibliographyItem['localizedCitations'][$locale] = $localizedCitation->get($key)['citation'];
        }
        $bibliographyItem['tei'] = $teiDataSets->get($key);
        $bibliographyItem[self::HEADER_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::getAuthorHeader());
        if ($bibliographyItem[self::HEADER_FIELD] == '') {
            $bibliographyItem[self::HEADER_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::getEditorHeader());
        }
        $bibliographyItem[self::BODY_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::getBody());

        switch ($bibliographyItem[self::TYPE_FIELD]) {
            case 'book':
                $bibliographyItem[self::FOOTER_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::getBookFooter());
                break;
            case 'bookSection':
                $bibliographyItem[self::FOOTER_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::getBookSectionFooter());
                break;
            case 'journalArticle':
                $bibliographyItem[self::FOOTER_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::getArticleFooter());
                break;
            case 'thesis':
                $bibliographyItem[self::FOOTER_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::getThesisFooter());
                break;
        }

        $bibliographyItem[self::SEARCHABLE_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::SEARCHABLE_FIELDS);
        $bibliographyItem[self::BOOSTED_FIELD]    = $this->buildListingField($bibliographyItem, BibEntryConfig::BOOSTED_FIELDS);

        $bibliographyItem[self::AUTHORS_FIELD] = $this->buildNestedField($bibliographyItem, BibEntryConfig::AUTHORS_FIELD);
        $bibliographyItem[self::EDITORS_FIELD] = $this->buildNestedField($bibliographyItem, BibEntryConfig::EDITORS_FIELD);
        $bibliographyItem[self::YEAR_FIELD]    = $this->buildYearField($bibliographyItem, BibEntryConfig::DATE);

        return $bibliographyItem;
    }

    protected function buildListingField(
        array $bibliographyItem,
        array $fieldConfig
    ): Stringable {
        $collectedFields = Collection::wrap($fieldConfig)
            ->map(function($field) use ($bibliographyItem) {
                return $this->buildListingEntry($field, $bibliographyItem);
            });
        if (is_array($collectedFields->get(0))) {
            return $collectedFields->get(0);
        }
        return $collectedFields->
            join('')->
            trim();
    }

    protected function buildYearField(
        array $bibliographyItem,
        array $fieldConfig
    ): ?int {
        if (!isset($fieldConfig['field'])) {
            $this->logger->info('no YEAR field in fieldConfig');
            return null;
        }
        $dateField = $fieldConfig['field'];
        $dateString = $bibliographyItem[$dateField] ?? '';
        if (!is_string($dateString) || trim($dateString) === '') {
            $this->logger->info('YEAR field is empty in {field} for id {id}', [
                'field' => $dateField,
                'value' => $dateString,
                'id' => $bibliographyItem['key'] ?? ''
            ]);
            return null;
        }
        if (preg_match('/\b(\d{4})\b/', $dateString, $matches)) {
            return (int)$matches[1];
        }
        $this->logger->info('not 4-digit YEAR field found {dateString} in id {id}', [
            'dateString' => $dateString,
            'id' => $bibliographyItem['key'] ?? ''
        ]);
        return null;
    }

/*
 * function for nested fields with an array of object,
 * maybe ist much easier with an own BibEntryConfig
*/
    protected function buildNestedField(
        array $bibliographyItem,
        array $fieldConfig
    ): array
    {
        return Collection::wrap($fieldConfig)->
            map(function ($field) use ($bibliographyItem) {
            $entry = $this->buildListingEntry($field, $bibliographyItem);

            // buildListingEntry can return null if no field creators exist
            if ($entry === null) {
                // ignore or return empty array ?
                return []; //
            }

            if (!is_array($entry)) {
                $this->logger->info('buildListingEntry did not return an array for {field} in id {id}', [
                    'field' => $field['field'],
                    'id' => $bibliographyItem['key'] ?? ''
                ]);
                throw new \UnexpectedValueException(
                    'Expected array from buildListingEntry, but got: ' . gettype($entry)
                );
            }
            return $entry;
            })->
            flatMap(function (array $item): Collection {
                return Collection::wrap($item)->map( function ($i) {
                    return [self::FULLNAME_KEY => $i];
                    });
            })->toArray();

        /*
        returns:
        (
            [0] => Array
            ([fullName] => Illuminate\Support\Stringable Object ([value:protected] => Michael Saffle))

            [1] => Array
            ([fullName] => Illuminate\Support\Stringable Object([value:protected] => Michael Saffle)
        )
        */
    }

    protected function buildListingEntry(array $field, array $bibliographyItem): Stringable|array|null
    {
        // return empty string if field does not exist
        if (
            isset($field['field']) &&
            !isset($bibliographyItem[$field['field']]) ||
            isset($field['compound']['field']) &&
            !isset($bibliographyItem[$field['compound']['field']]) ||
            isset($field['compoundArray']['field']) &&
            !isset($bibliographyItem[$field['compoundArray']['field']])
        ) {
            return null;
        }

        // return an array when compoundArray option is set
        if (isset($field['compoundArray'])) {
            // build compound fields
            return Collection::wrap($bibliographyItem[$field['compoundArray']['field']])->
                // get selected strings
                map(function ($bibliographyCell) use ($field) {
                    return $this->processCompound($field['compoundArray'], $bibliographyCell);
                })->
                // filter out non fitting fields
                filter()->
                toArray();
        }

        // return empty string if conditions are not met
        if (
            isset($field['conditionField']) &&
            isset($field['conditionValue']) &&
            isset($field['conditionRelation'])
        ){
            // return empty if equality condition is not met
            if (
                $field['conditionRelation'] == 'eq' &&
                $bibliographyItem[$field['conditionField']] != $field['conditionValue']
            ) {
                return null;
            }
            // return empty if inequality condition is not met
            if (
                $field['conditionRelation'] == 'neq' &&
                $bibliographyItem[$field['conditionField']] == $field['conditionValue']
            ) {
                return null;
            }
        }

        if (isset($field['compound'])) {
            // build compound fields
            $compoundString = Collection::wrap($bibliographyItem[$field['compound']['field']])->
                // get selected strings
                map(function ($bibliographyCell) use ($field) {
                    return $this->processCompound($field['compound'], $bibliographyCell);
                })->
                // filter out non fitting fields
                filter()->
                // join fields
                when( isset($field['compound']['separator']), function($compoundFields) use ($field) {
                        return $compoundFields->join($field['compound']['separator']);
                    }, function($compoundFields) {
                        return $compoundFields->join('');
                });
            $bodyString = Str::of($compoundString);
        } else {
            $bodyString = Str::of($bibliographyItem[$field['field']]);
        }

        if ($bodyString->isEmpty()) {
            return null;
        }

        // prefix handling
        $fieldString = Str::of('');
        if (isset($field['prefix'])) {
            $fieldString = $fieldString->append($field['prefix']);
        }
        if (isset($field['compound']['prefix'])) {
            $fieldString = $fieldString->append($field['compound']['prefix']);
        }

        // body handling
        $fieldString = $fieldString->append($bodyString);

        // postfix handling
        if (isset($field['postfix'])) {
            $fieldString = $fieldString->append($field['postfix']);
        }
        if (isset($field['compound']['postfix'])) {
            $fieldString = $fieldString->append($field['compound']['postfix']);
        }

        return $fieldString;
    }

    protected function processCompound(array $field, array $bibliographyCell): ?Stringable
    {
        $compoundString = Collection::wrap($field['fields'])->
            // get selected strings
            map( function ($field) use ($bibliographyCell) { return $this->buildListingEntry($field, $bibliographyCell); })->
            // filter out empty fields
            filter()->
            // conditionally reverse and join fields
            when(
                isset($field['reverseFirst']) &&
                $field['reverseFirst'] == true,
                function($compoundFields) {
                    return $compoundFields->reverse()->join(', ');
                },
                function($compoundFields) {
                    return $compoundFields->join(' ');
                }
            );

        if ($compoundString->isEmpty()) {
            return null;
        }
        return $compoundString;
    }
}
