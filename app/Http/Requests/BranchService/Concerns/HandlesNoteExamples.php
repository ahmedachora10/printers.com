<?php

namespace App\Http\Requests\BranchService\Concerns;

/**
 * Shared handling of a branch service's ready-made detail phrases, used by both
 * the store and update requests so the two can never drift. Blank rows the UI
 * leaves behind are dropped before validation, and the list is reindexed so it
 * stores as a JSON array rather than an object with gaps.
 */
trait HandlesNoteExamples
{
    protected function normalizeNoteExamples(): void
    {
        $examples = $this->input('note_examples');

        if (! is_array($examples)) {
            return;
        }

        $this->merge([
            'note_examples' => collect($examples)
                ->filter(fn ($example) => is_string($example))
                ->map(fn (string $example) => trim($example))
                ->filter(fn (string $example) => $example !== '')
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function noteExampleRules(): array
    {
        return [
            'note_examples' => ['nullable', 'array', 'max:10'],
            'note_examples.*' => ['string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    protected function noteExampleMessages(): array
    {
        return [
            'note_examples.max' => 'الحد الأقصى 10 أمثلة للخدمة الواحدة.',
            'note_examples.*.max' => 'المثال يجب ألا يتجاوز 120 حرفاً.',
        ];
    }
}
