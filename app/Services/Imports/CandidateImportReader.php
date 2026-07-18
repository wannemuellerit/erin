<?php

namespace App\Services\Imports;

use DateInterval;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class CandidateImportReader
{
    /**
     * @return array{headers: list<string>, rows: list<array<string, string|null>>}
     */
    public function preview(string $path, string $filename, int $limit = 5): array
    {
        $headers = [];
        $rows = [];

        foreach ($this->rows($path, $filename) as $index => $row) {
            if ($index === 0) {
                $headers = $this->headers($row);

                continue;
            }

            $rows[] = $this->combine($headers, $row);
            if (count($rows) >= $limit) {
                break;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, string|null>  $mapping
     * @return Generator<int, array<string, string|null>>
     */
    public function mappedRows(
        string $path,
        string $filename,
        array $mapping,
    ): Generator {
        $headers = [];

        foreach ($this->rows($path, $filename) as $index => $row) {
            if ($index === 0) {
                $headers = $this->headers($row);

                continue;
            }

            $source = $this->combine($headers, $row);
            $mapped = [];
            foreach ($mapping as $target => $header) {
                $mapped[$target] = $header === null || $header === ''
                    ? null
                    : ($source[$header] ?? null);
            }

            if (collect($mapped)->filter(fn (?string $value): bool => filled($value))->isEmpty()) {
                continue;
            }

            yield $index + 1 => $mapped;
        }
    }

    /**
     * @return Generator<int, list<string|null>>
     */
    private function rows(string $path, string $filename): Generator
    {
        $reader = $this->readerFor($path, $filename);
        $reader->open($path);
        $index = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $index => array_values(array_map(
                        fn (mixed $value): ?string => $this->stringValue($value),
                        $row->toArray(),
                    ));
                    $index++;
                }

                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<string|null>  $row
     * @return list<string>
     */
    private function headers(array $row): array
    {
        $headers = [];
        foreach ($row as $index => $value) {
            $base = trim((string) $value);
            $header = $base !== '' ? $base : 'Spalte '.($index + 1);
            $candidate = $header;
            $suffix = 2;

            while (in_array($candidate, $headers, true)) {
                $candidate = "{$header} ({$suffix})";
                $suffix++;
            }

            $headers[] = $candidate;
        }

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string|null>  $row
     * @return array<string, string|null>
     */
    private function combine(array $headers, array $row): array
    {
        $result = [];
        foreach ($headers as $index => $header) {
            $result[$header] = $row[$index] ?? null;
        }

        return $result;
    }

    private function readerFor(string $path, string $filename): CsvReader|XlsxReader
    {
        return match (Str::lower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'csv' => new CsvReader(new CsvOptions(FIELD_DELIMITER: $this->csvDelimiter($path))),
            'xlsx' => new XlsxReader,
            default => throw new InvalidArgumentException('Nur CSV- und XLSX-Dateien werden unterstützt.'),
        };
    }

    private function csvDelimiter(string $path): string
    {
        $stream = fopen($path, 'r');
        if (! is_resource($stream)) {
            return ',';
        }

        $line = (string) fgets($stream);
        fclose($stream);
        $delimiters = [',', ';', "\t"];
        usort($delimiters, fn (string $left, string $right): int => substr_count($line, $right)
            <=> substr_count($line, $left));

        return $delimiters[0];
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value instanceof DateInterval) {
            return $value->format('%r%a');
        }

        if (is_array($value) && collect($value)->every(
            fn (mixed $part): bool => $part instanceof TextRun,
        )) {
            return trim(collect($value)->map(
                fn (TextRun $part): string => $part->text,
            )->implode(''));
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }
}
