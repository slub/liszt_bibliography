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

class BibEntryProcessor
{

    public static function process(
        array $bibliographyItem,
        Collection $localizedCitations,
        Collection $teiDataSets
    ): array
    {
        // check for required Fields
        self::validateFields($bibliographyItem);

        $key = $bibliographyItem['key'];
        $bibliographyItem['localizedCitations'] = [];
        foreach ($localizedCitations as $locale => $localizedCitation) {
            $bibliographyItem['localizedCitations'][$locale] = $localizedCitation->get($key)['citation'];
        }
        $bibliographyItem['tei'] = $teiDataSets->get($key);
        $bibliographyItem['tx_lisztcommon_header'] = self::buildListingField($bibliographyItem, BibEntryConfig::getAuthorHeader());
        if ($bibliographyItem['tx_lisztcommon_header'] == '') {
            $bibliographyItem['tx_lisztcommon_header'] = self::buildListingField($bibliographyItem, BibEntryConfig::getEditorHeader());
        }
        $bibliographyItem['tx_lisztcommon_body'] = self::buildListingField($bibliographyItem, BibEntryConfig::getBody());
        switch($bibliographyItem['itemType']) {
            case 'book':
                $bibliographyItem['tx_lisztcommon_footer'] = self::buildListingField($bibliographyItem, BibEntryConfig::getBookFooter());
                break;
            case 'bookSection':
                $bibliographyItem['tx_lisztcommon_footer'] = self::buildListingField($bibliographyItem, BibEntryConfig::getBookSectionFooter());
                break;
            case 'journalArticle':
                $bibliographyItem['tx_lisztcommon_footer'] = self::buildListingField($bibliographyItem, BibEntryConfig::getArticleFooter());
                break;
            case 'thesis':
                $bibliographyItem['tx_lisztcommon_footer'] = self::buildListingField($bibliographyItem, BibEntryConfig::getThesisFooter());
                break;
        }

        $bibliographyItem['tx_lisztcommon_searchable'] = self::buildListingField($bibliographyItem, BibEntryConfig::SEARCHABLE_FIELDS);
        $bibliographyItem['tx_lisztcommon_boosted'] = self::buildListingField($bibliographyItem, BibEntryConfig::BOOSTED_FIELDS);
        return $bibliographyItem;
    }

    public static function buildListingField(
        array $bibliographyItem,
        array $fieldConfig
    ): Stringable
    {
        return Collection::wrap($fieldConfig)->
            map( function($field) use ($bibliographyItem) { return self::buildListingEntry($field, $bibliographyItem); })->
            join('')->
            trim();
    }

    private static function buildListingEntry(array $field, array $bibliographyItem): ?Stringable
    {
        // return empty string if field does not exist
        if (
            isset($field['field']) &&
            !isset($bibliographyItem[$field['field']]) ||
            isset($field['compound']['field']) &&
            !isset($bibliographyItem[$field['compound']['field']])
        ) {
            return null;
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
                    return self::processCompound($field['compound'], $bibliographyCell);
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

    private static function processCompound(array $field, array $bibliographyCell): ?Stringable
    {
        $compoundString = Collection::wrap($field['fields'])->
            // get selected strings
            map( function ($field) use ($bibliographyCell) { return self::buildListingEntry($field, $bibliographyCell); })->
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

    private static function validateFields(array $bibliographyItem): void
    {
        $fieldValidations = BibEntryConfig::getRequiredFields();
        $warnings = []; // Array to store warnings

        foreach ($fieldValidations as $field => $constraints) {
            if (!isset($bibliographyItem[$field])) {
                $warnings[] = "The required field '{$field}' is missing in the bibliography item.";
                continue;
            }

            $value = $bibliographyItem[$field];
            foreach ($constraints as $constraint => $constraintValue) {
                switch ($constraint) {
                    case 'type':
                        if ($constraintValue === 'string' && !is_string($value)) {
                            $warnings[] = "The field '{$field}' should be a string.";
                        } elseif ($constraintValue === 'int' && !is_int($value)) {
                            $warnings[] = "The field '{$field}' should be an integer.";
                        } elseif ($constraintValue === 'array' && !is_array($value)) {
                            $warnings[] = "The field '{$field}' should be an array.";
                        } elseif (strpos($constraintValue, 'date:') === 0) {
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
                }
            }
        }

        // Output all collected warnings, if any
        if (!empty($warnings)) {
            echo 'Warning for bibliography item: '. $bibliographyItem['key'] . "\n";
            foreach ($warnings as $warning) {
                echo $warning . "\n";
            }
        }
    }
}
