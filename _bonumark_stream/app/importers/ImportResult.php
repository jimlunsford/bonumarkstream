<?php

class BMS_ImportResult
{
    public string $importerName;
    /** @var list<BMS_ImportItem> */
    public array $items = [];
    /** @var list<string> */
    public array $warnings = [];
    /** @var list<string> */
    public array $errors = [];

    public function __construct(string $importerName)
    {
        $this->importerName = $importerName;
    }

    public function addItem(BMS_ImportItem $item): void
    {
        $this->items[] = $item;
    }

    public function addWarning(string $warning): void
    {
        $warning = trim($warning);
        if ($warning !== '') {
            $this->warnings[] = $warning;
        }
    }

    public function addError(string $error): void
    {
        $error = trim($error);
        if ($error !== '') {
            $this->errors[] = $error;
        }
    }

    public function hasItems(): bool
    {
        return count($this->items) > 0;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'importer' => $this->importerName,
            'items' => array_map(static fn(BMS_ImportItem $item): array => $item->toArray(), $this->items),
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}
