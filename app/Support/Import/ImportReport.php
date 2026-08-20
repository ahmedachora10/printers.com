<?php

namespace App\Support\Import;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What one Excel import did, or would do. The same object serves the dry-run
 * preview and the real commit — the two run the identical code path, so the
 * preview can never promise something the commit then does differently.
 *
 * The report describes itself in Arabic (labels and reasons come from here,
 * not from the client) so a single import dialog can render any import screen
 * without knowing which sheet it is looking at.
 *
 * @implements Arrayable<string, mixed>
 */
class ImportReport implements Arrayable
{
    /** @var array<string, array{label: string, value: int, tone: string}> */
    private array $summary = [];

    /** @var array<int, array{row: int, label: string, action: string, reason: string|null}> */
    private array $rows = [];

    public function __construct(public readonly bool $dryRun = false) {}

    /**
     * Declare a counter up front so it shows as zero rather than vanishing —
     * "0 فئة جديدة" is information; a missing tile is a question.
     */
    public function declareCounter(string $key, string $label, string $tone = 'info'): self
    {
        $this->summary[$key] ??= ['label' => $label, 'value' => 0, 'tone' => $tone];

        return $this;
    }

    public function count(string $key): void
    {
        $this->summary[$key]['value']++;
    }

    /** A row that landed: `$action` is create|update|skip. */
    public function row(int $row, string $label, string $action, ?string $reason = null): void
    {
        $this->rows[] = ['row' => $row, 'label' => $label, 'action' => $action, 'reason' => $reason];
    }

    public function skip(int $row, string $label, string $reason): void
    {
        $this->row($row, $label, 'skip', $reason);
        $this->count('skipped');
    }

    /** @return array<int, array{row: int, label: string, action: string, reason: string|null}> */
    public function skippedRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $row) => $row['action'] === 'skip'));
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'totalRows' => count($this->rows),
            'summary' => collect($this->summary)
                ->map(fn (array $tile, string $key) => ['key' => $key, ...$tile])
                ->values()
                ->all(),
            // The preview table stays readable: the head of the sheet, plus
            // every skipped row wherever it sits — those are what the user
            // has to act on.
            'rows' => array_slice($this->rows, 0, 50),
            'skipped' => $this->skippedRows(),
        ];
    }
}
