<?php

namespace Slub\LisztBibliography\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper to build search result header string from creators array
 * Returns all authors (creatorType 'author') comma-separated
 * If no authors exist, returns all editors (creatorType 'editor') comma-separated with " (Hg.)" suffix
 */
class SearchResultItemHeaderViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('creators', 'array', 'Array of creator objects', true);
    }

    public function render(): string
    {
        $creators = $this->arguments['creators'];

        if (!is_array($creators) || empty($creators)) {
            return '';
        }

        $authors = $this->extractCreatorsByType($creators, 'author');
        if (!empty($authors)) {
            return implode(', ', $authors);
        }

        $editors = $this->extractCreatorsByType($creators, 'editor');
        if (!empty($editors)) {
            return implode(', ', $editors) . ' (Hg.)';
        }
        return '';
    }


    private function extractCreatorsByType(array $creators, string $creatorType): array
    {
        $extractedNames = [];

        foreach ($creators as $creator) {
            if (($creator['creatorType'] ?? '') === $creatorType) {
                $fullName = $this->buildFullName($creator);

                if (!empty($fullName)) {
                    $extractedNames[] = $fullName;
                }
            }
        }
        return $extractedNames;
    }


    private function buildFullName(array $creator): string
    {
        // Use 'name' field if available
        if (!empty($creator['name'])) {
            return trim($creator['name']);
        }

        // Build name from firstName and lastName
        $firstName = trim($creator['firstName'] ?? '');
        $lastName = trim($creator['lastName'] ?? '');
        $fullName = trim($firstName . ' ' . $lastName);

        return $fullName;
    }
}
