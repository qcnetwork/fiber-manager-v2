<?php

namespace App\Services\Transfer;

use DateTimeInterface;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Reads the ODC and ODP sheets of an .xlsx file into rows keyed by column name.
 */
class SheetReader
{
    /**
     * @return array{odc: list<array{label: string, data: array<string, mixed>}>, odp: list<array{label: string, data: array<string, mixed>}>}
     */
    public function read(string $path): array
    {
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true; // keeps row numbers in messages equal to Excel's

        $reader = new Reader($options);
        try {
            $reader->open($path);
        } catch (Throwable) {
            throw new ImportException('File Excel tidak bisa dibuka. Pastikan formatnya .xlsx.');
        }

        $rows = ['odc' => [], 'odp' => []];
        $found = false;

        foreach ($reader->getSheetIterator() as $sheet) {
            $kind = $this->sheetKind($sheet->getName());
            if (! $kind) {
                continue;
            }
            $found = true;

            $columns = null;
            $number = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $number++;
                $values = array_map(fn ($v) => $this->clean($v), $row->toArray());

                if ($columns === null) {
                    if (array_filter($values, fn ($v) => $v !== null) === []) {
                        continue; // blank lines above the header
                    }
                    $columns = array_map(fn ($h) => Columns::normalize((string) $h, $kind), $values);
                    if (! in_array('kode', $columns, true)) {
                        throw new ImportException('Sheet "'.$sheet->getName().'" tidak punya kolom "kode".');
                    }

                    continue;
                }

                $data = [];
                foreach ($columns as $i => $key) {
                    if ($key !== null) {
                        $data[$key] = $values[$i] ?? null;
                    }
                }

                if (array_filter($data, fn ($v) => $v !== null) === []) {
                    continue; // empty row
                }

                $rows[$kind][] = ['label' => strtoupper($kind).' baris '.$number, 'data' => $data];
            }
        }

        $reader->close();

        if (! $found) {
            throw new ImportException('Tidak ditemukan sheet bernama "ODC" atau "ODP". Gunakan template impor.');
        }

        return $rows;
    }

    private function sheetKind(string $name): ?string
    {
        $name = mb_strtolower(trim($name));

        return match (true) {
            str_starts_with($name, 'odc') => 'odc',
            str_starts_with($name, 'odp') => 'odp',
            default => null,
        };
    }

    private function clean(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
