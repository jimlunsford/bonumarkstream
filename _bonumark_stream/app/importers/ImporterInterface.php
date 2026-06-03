<?php

interface BMS_ImporterInterface
{
    public function label(): string;

    /** @param array<string,mixed> $file */
    public function canImport(array $file): bool;

    /** @param array<string,mixed> $file */
    public function importPreview(array $file): BMS_ImportResult;
}
