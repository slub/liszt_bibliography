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

    const ORIGINAL_ITEM_TYPE = 'originalItemType'; // original itemType from Zotero for separate printedMusic in Template


    public function __construct(
        // Note the logLevel setting in the Extension Configuration
        private readonly LoggerInterface $logger
    )
    { }

    //Todo: maybe we find a better name for "process" ;-)
    public function process(
        array $bibliographyItem,
        array $collectionToItemTypeMap,
        Collection $localizedCitations,
        Collection $teiDataSets
    ): array
    {

        // validate fields and return 'skipped' if this doc should bei skipped
        if (!$this->validateFields($bibliographyItem)) {
            return ['key' => $bibliographyItem['key'], 'skipped' => true];
        }

        $key = $bibliographyItem['key'];
        $itemTypeResult = $this->calculateItemType($bibliographyItem, $collectionToItemTypeMap);
        $bibliographyItem['itemType'] = $itemTypeResult['itemType'];
        if ($itemTypeResult['originalItemType'] !== null) {
            $bibliographyItem[self::ORIGINAL_ITEM_TYPE] = $itemTypeResult['originalItemType'];
        }
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
            case 'printedMusic':
                // @Matthias: ToDo: please check the following 2 footers
                $bibliographyItem[self::FOOTER_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::getPrintedMusicFooter());
                break;
            case 'encyclopediaArticle':
                $bibliographyItem[self::FOOTER_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::getEncyclopediaArticleFooter());
                break;
        }

        $bibliographyItem[self::SEARCHABLE_FIELD] = $this->buildListingField($bibliographyItem, BibEntryConfig::SEARCHABLE_FIELDS);
        $bibliographyItem[self::BOOSTED_FIELD]    = $this->buildListingField($bibliographyItem, BibEntryConfig::BOOSTED_FIELDS);

        $bibliographyItem[self::AUTHORS_FIELD] = $this->buildNestedField($bibliographyItem, BibEntryConfig::AUTHORS_FIELD);
        $bibliographyItem[self::EDITORS_FIELD] = $this->buildNestedField($bibliographyItem, BibEntryConfig::EDITORS_FIELD);
        $bibliographyItem[self::YEAR_FIELD]    = $this->buildYearField($bibliographyItem, BibEntryConfig::DATE);

        return $bibliographyItem;
    }


    protected function calculateItemType(
        array $bibliographyItem,
        array $collectionToItemTypeMap
    ): array {
        $originalItemType = $bibliographyItem['itemType'];

        foreach ($bibliographyItem['collections'] as $itemCollection) {
            foreach ($collectionToItemTypeMap as $mapCollection => $itemType) {
                if ($itemCollection == $mapCollection) {
                    // Return both new itemType and original itemType
                    return [
                        'itemType' => $itemType,
                        'originalItemType' => $originalItemType
                    ];
                }
            }
        }

        // No mapping found, return only itemType
        return [
            'itemType' => $originalItemType,
            'originalItemType' => null
        ];
    }

    protected function buildListingField(
        array $bibliographyItem,
        array $fieldConfig
    ): Stringable {
        $collectedFields = Collection::wrap($fieldConfig)
            ->map(function($field) use ($bibliographyItem) {
                return $this->buildListingEntry($field, $bibliographyItem);
            })
            ->filter(); // filter out null values

        if (is_array($collectedFields->get(0))) {
            return $collectedFields->get(0);
        }

        $result = $collectedFields->join('')->trim();
        return $result->replaceMatches('/,\s*$/', '');  // remove trailing commas
    }


    /**
     * Extracts a year value from a date string
     * Assumes that validation has already been performed by validateFields()
     */
    protected function buildYearField(
        array $bibliographyItem,
        array $fieldConfig
    ): ?int {
        if (!isset($fieldConfig['field'])) {
            return null; // No logging needed - this is a configuration error
        }

        $dateField = $fieldConfig['field'];
        $dateString = $bibliographyItem[$dateField] ?? '';

        // Only process valid non-empty strings (validation already done)
        if (!is_string($dateString) || trim($dateString) === '') {
            return null;
        }

        // find all 4 digit matches and use the last one
        if (preg_match_all('/\b(\d{4})\b/', $dateString, $matches) && !empty($matches[1])) {
            $lastIndex = count($matches[1]) - 1;
            return (int)$matches[1][$lastIndex];
        }
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
                $this->logger->warning('buildListingEntry did not return an array for {field} in id {id}', [
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

        // return an collection when compoundArray option is set
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

        // return null if conditions are not met
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


    private function validateFields(array $bibliographyItem): bool
    {
        $fieldValidations = BibEntryConfig::getRequiredFields();
        $warnings = []; // array for warnings
        $criticalWarnings = []; // array for critical warnings to skip this doc
        foreach ($fieldValidations as $field => $constraints) {
            $isCritical = $constraints['critical'] ?? false;
            if (!isset($bibliographyItem[$field])) {
                $message = "The required field '{$field}' is missing in the bibliography entry.";
                if ($isCritical) {
                    $criticalWarnings[] = $message;
                } else {
                    $warnings[] = $message;
                }
                continue;
            }
            $value = $bibliographyItem[$field];
            foreach ($constraints as $constraint => $constraintValue) {
                if (in_array($constraint, ['critical'])) continue; // skip configuration fields
                $message = null;
                switch ($constraint) {
                    case 'type':
                        if ($constraintValue === 'string' && !is_string($value)) {
                            $warnings[] = "The field '{$field}' should be a string.";
                        } elseif ($constraintValue === 'int' && !is_int($value)) {
                            $warnings[] = "The field '{$field}' should be an integer.";
                        } elseif ($constraintValue === 'array' && !is_array($value)) {
                            $warnings[] = "The field '{$field}' should be an array.";
                        } elseif (str_starts_with($constraintValue, 'date:')) {
                            $format = substr($constraintValue, 5);
                            $d = \DateTime::createFromFormat($format, $value);
                            if (!$d || $d->format($format) !== $value) {
                                $warnings[] = "The field '{$field}' should be a date in the format '{$format}'.";
                            }
                        }
                        break;
                    case 'not_empty':
                        if ($constraintValue && empty($value)) {
                            $warnings[] = "The field '{$field}' must not be empty.";
                        }
                        break;
                    case 'min_length':
                        if (strlen($value) < $constraintValue) {
                            $warnings[] = "The field '{$field}' should be at least {$constraintValue} characters long.";
                        }
                        break;
                    case 'min_array_length':
                        if (is_array($value) && count($value) < $constraintValue) {
                            $warnings[] = "The array '{$field}' should have at least {$constraintValue} elements.";
                        }
                        break;
                    case 'contains_year':
                        if ($constraintValue && !preg_match('/\b(\d{4})\b/', $value)) {
                            $message = "The field '{$field}' should contain a valid 4-digit year.";
                        }
                        break;
                    case 'allowedValues':
                        if (!in_array($value, $constraintValue)) {
                            $allowedList = implode("', '", $constraintValue);
                            $message = "The value '{$value}' for field '{$field}' is not allowed. Allowed values are: '{$allowedList}'.";
                        }
                        break;
                }
                if ($message) {
                    if ($isCritical) {
                        $criticalWarnings[] = $message;
                    } else {
                        $warnings[] = $message;
                    }
                }
            }
        }
        // Log warnings
        $key = $bibliographyItem['key'] ?? 'Unknown entry';
        if (!empty($warnings)) {
            $this->logger->warning("Non-critical warnings for entry {$key}", $warnings);
        }
        if (!empty($criticalWarnings)) {
            $this->logger->error("Critical errors for entry {$key} - skipping", $criticalWarnings);
            return false; // skip record
        }
        return true; // process record
    }

}
