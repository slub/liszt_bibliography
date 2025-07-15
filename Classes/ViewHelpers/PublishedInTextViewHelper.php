<?php

namespace Slub\LisztBibliography\ViewHelpers;

use Slub\LisztBibliography\Services\TextCompositionService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;


/**
 * ViewHelper to render dt Element with comma separated fields and optional prefix for Detail View
 */
class PublishedInTextViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    private TextCompositionService $textCompositionService;

    public function __construct(TextCompositionService $textCompositionService)
    {
        $this->textCompositionService = $textCompositionService;
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('values', 'array', 'searchResult item array', true);
    }

    public function render(): string
    {

        $editedByLabel = LocalizationUtility::translate(
            'edited_by_label',
            'liszt_bibliography'
        ) ?? 'herausgegeben von';

        $volumeLabel = LocalizationUtility::translate(
            'volume_label',
            'liszt_bibliography'
        ) ?? 'Bd.';

        $issueLabel = LocalizationUtility::translate(
            'issue_label',
            'liszt_bibliography'
        ) ?? 'H.';

        $values = $this->arguments['values'];
        $prefixes = ['editorNames' => $editedByLabel.' ', 'volume' => $volumeLabel.' ', 'issue' => $issueLabel.' '];
        $suffixes = [];
        $noCommaRules = [['place','date']];

        $composeValues = match ($values['itemType']) {
            'book' => ['series' => $values['series'] ?? '','seriesNumber' => $values['seriesNumber'] ?? ''],
            'bookSection' => [
                'bookTitle' => $values['bookTitle'] ?? '',
                'volume' => $values['volume'] ?? '',
                'editorNames' => $this->getEditorNames($values['creators'] ?? []),
                'place' => $values['place'] ?? '',
                'date' => $values['date'] ?? '',
                'pages' => $values['pages'] ?? ''
            ],
            'journalArticle' => [
                'publicationTitle' => $values['publicationTitle'] ?? '',
                'volume' => $values['volume'] ?? '',
                'editorNames' => $this->getEditorNames($values['creators'] ?? []),
                'date' => $values['date'] ?? '',
                'pages' => $values['pages'] ?? ''
            ],
            'encyclopediaArticle' => [
                'encyclopediaTitle' => $values['encyclopediaTitle'] ?? '',
                'volume' => $values['volume'] ?? '',
                'editorNames' => $this->getEditorNames($values['creators'] ?? []),
                'place' => $values['place'] ?? '',
                'date' => $values['date'] ?? '',
                'pages' => $values['pages'] ?? ''
            ],
            'printedMusic' => $this->getPrintedMusicValues($values),
            default => [],
        };

        $formattedValues = $this->textCompositionService->formatCommaSeparatedValues($composeValues, $prefixes, $suffixes, $noCommaRules);

        if (empty($formattedValues)) {
            return '';
        }

        return $formattedValues;
    }

    /**
     * Extracts editor names from creators array
     *
     * @param array $creators Array of creator objects
     * @return string Comma-separated list of editor names
     */
    private function getEditorNames(array $creators): string
    {
        $editorNames = [];

        foreach ($creators as $creator) {
            if (($creator['creatorType'] ?? '') === 'editor') {
                $firstName = trim($creator['firstName'] ?? '');
                $lastName = trim($creator['lastName'] ?? '');

                // Combine first and last name
                $fullName = trim($firstName . ' ' . $lastName);

                if (!empty($fullName)) {
                    $editorNames[] = $fullName;
                }
            }
        }

        return implode(', ', $editorNames);
    }



    /**
     * Get values for printedMusic based on originalItemType
     *
     * @param array $values The complete values array
     * @return array Formatted values for printedMusic
     */
    private function getPrintedMusicValues(array $values): array
    {
        if (($values['originalItemType'] ?? '') === 'journalArticle') {
            return [
                'publicationTitle' => $values['publicationTitle'] ?? '',
                'volume' => $values['volume'] ?? '',
                'issue' => $values['issue'] ?? '',
                'date' => $values['date'] ?? '',
                'pages' => $values['pages'] ?? ''
            ];
        }

        return [
            'series' => $values['series'] ?? '',
            'seriesNumber' => $values['seriesNumber'] ?? '',
            'volume' => $values['volume'] ?? '',
        ];
    }

}
