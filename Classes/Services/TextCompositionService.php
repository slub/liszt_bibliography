<?php

namespace Slub\LisztBibliography\Services;

/**
 * Service for composing text elements with specific formatting rules
 */
class TextCompositionService
{
    /**
     * Formats a keyed array of values into a comma-separated string with prefixes and suffixes
     * prefixes and suffixes are identified by their keys, no comma rules may be given
     *
     * @param array $values Array of values to format
     * @return string Formatted string with comma separation, prefix and suffix
     */
    public function formatCommaSeparatedValues(
        array $values,
        array $prefixes = [],
        array $suffixes = [],
        array $noCommaRules = []
    ): string
    {
        // non-empty values only
        $filtered = array_filter($values, fn($v) => $v !== null && $v !== '');
        if (empty($filtered)) {
            return '';
        }

        $result = [];
        $prevKey = null;

        $noCommaBetween = function ($k1, $k2) use ($noCommaRules) {
            foreach ($noCommaRules as $pair) {
                if ($pair === [$k1, $k2]) {
                    return true;
                }
            }
            return false;
        };

        foreach ($filtered as $key => $value) {
            $prefix = $prefixes[$key] ?? '';
            $suffix = $suffixes[$key] ?? '';

            // logic for comma separation
            if ($prevKey !== null && !$noCommaBetween($prevKey, $key)) {
                $result[] = ', ';
            } elseif ($prevKey !== null) {
                $result[] = ' ';
            }

            $result[] = $prefix . $value . $suffix;
            $prevKey = $key;
        }

        return implode('', $result);
    }
}
