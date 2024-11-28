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
                    return $compoundFields->reverse()->join(', ');;
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
