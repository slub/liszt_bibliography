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
        $key = $bibliographyItem['key'];
        $bibliographyItem['localizedCitations'] = [];
        foreach ($localizedCitations as $locale => $localizedCitation) {
            $bibliographyItem['localizedCitations'][$locale] = $localizedCitation->get($key)['citation'];
        }
        $bibliographyItem['tei'] = $teiDataSets->get($key);
        $bibliographyItem['tx_lisztcommon_header'] = self::buildListingField($bibliographyItem, BibEntryConfig::HEADER_FIELDS);
        $bibliographyItem['tx_lisztcommon_body'] = self::buildListingField($bibliographyItem, BibEntryConfig::BODY_FIELDS);
        $bibliographyItem['tx_lisztcommon_footer'] = self::buildListingField($bibliographyItem, BibEntryConfig::FOOTER_FIELDS);
        $bibliographyItem['tx_lisztcommon_searchable'] = Collection::wrap($bibliographyItem)->
            filter( function ($val, $key) { return $val && in_array($key, BibEntryConfig::SEARCHABLE_FIELDS); } )->
            join(' ')->
            trim();
        $bibliographyItem['tx_lisztcommon_boosted'] = Collection::wrap($bibliographyItem)->
            filter( function ($val, $key) { return $val && in_array($key, BibEntryConfig::BOOSTED_FIELDS); } )->
            join(' ')->
            trim();

        return $bibliographyItem;
    }

    public static function buildListingField(
        array $bibliographyItem,
        array $fieldConfig
    ): Stringable
    {
        return Collection::wrap($fieldConfig)->
            map( function($field) use ($bibliographyItem) { return self::buildListingEntry($field, $bibliographyItem); })->
            filter()->
            join(' ')->
            trim();
    }

    private static function buildListingEntry(array $field, array $bibliographyItem): Stringable
    {
        // return empty string if field does not exist
        if (
            isset($field['field']) &&
            !isset($bibliographyItem[$field['field']]) ||
            isset($field['compound']['field']) &&
            !isset($bibliographyItem[$field['compound']['field']])
        ) {
            return Str::of('');
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
                return Str::of('');
            }
            // return empty if inequality condition is not met
            if (
                $field['conditionRelation'] == 'neq' &&
                $bibliographyItem[$field['conditionField']] == $field['conditionValue']
            ) {
                return Str::of('');
            }
        }

        $fieldString = Str::of('');
        if (isset($field['prefix'])) {
            $fieldString = $fieldString->append($field['prefix']);
        }

        // build compound fields
        if (isset($field['compound'])) {
            $compoundString = Collection::wrap($bibliographyItem[$field['compound']['field']])->
                map(function ($bibliographyCell) use ($field) { return self::processCompound($field['compound'], $bibliographyCell); })->
                when( isset($field['separator']), function($compoundFields) {
                        return $compoundFields->join($field['separator']);
                    }, function($compoundFields) {
                        return $compoundFields->join('');
                });
            $fieldString = $fieldString->append($compoundString);
        } else {
            $fieldString = $fieldString->append($bibliographyItem[$field['field']]);
        }

        if (isset($field['postfix'])) {
            $fieldString = $fieldString->append($field['postfix']);
        }

        return $fieldString;
    }

    private static function processCompound(array $field, array $bibliographyCell): Stringable
    {
        return Collection::wrap($field['fields'])->
            map( function ($field) use ($bibliographyCell) { return self::buildListingEntry($field, $bibliographyCell); })->
            when(
                isset($field['reverseFirst']) &&
                $field['reverseFirst'] == true,
                function($compoundFields) {
                    return $compoundFields->reverse();
                }
            )->
            join(' ');
    }
}
