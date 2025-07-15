<?php

namespace Slub\LisztBibliography\ViewHelpers;

use Slub\LisztBibliography\Services\TextCompositionService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper to render dt Element with comma separated fields and optional prefix for Detail View
 */
class PublishedTextViewHelper extends AbstractViewHelper
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
        $values = $this->arguments['values'];
        $prefixes = [];
        $suffixes = [];
        $noCommaRules = [['place','date']];

        $composeValues = match ($values['itemType']) {
            'thesis' => ['university' => $values['university'] ?? '','place' => $values['place'] ?? '', 'date' =>$values['date'] ?? ''],
            default => ['place' => $values['place'] ?? '', 'date' => $values['date'] ?? ''],
        };

        $formattedValues = $this->textCompositionService->formatCommaSeparatedValues($composeValues, $prefixes, $suffixes, $noCommaRules);

        // Return empty string if no formatted values
        if (empty($formattedValues)) {
            return '';
        }


        return $formattedValues;
    }
}
