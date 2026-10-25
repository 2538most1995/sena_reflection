<?php

namespace App\Services;

class AcademicTermService
{
    public function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if (preg_match('#^([123])/(25\d{2})$#', $value, $match)) {
            return $match[1].'/'.$match[2];
        }
        if (preg_match('#^(\d{2})/([123])$#', $value, $match)) {
            return $match[2].'/25'.$match[1];
        }

        return null;
    }

    /** @param array<string, mixed> $filters */
    public function fromFilters(array $filters): ?string
    {
        return $this->normalize($filters['term'] ?? null)
            ?? $this->normalize(($filters['semester'] ?? '').'/'.($filters['year'] ?? ''));
    }

    /** @return array{semester: string, year: string} */
    public function defaults(?string $term = null): array
    {
        $term = $this->normalize($term);
        if ($term !== null) {
            [$semester, $year] = explode('/', $term);

            return compact('semester', 'year');
        }
        $month = (int) now()->format('n');

        return [
            'semester' => $month >= 5 && $month <= 10 ? '1' : '2',
            'year' => (string) ((int) now()->format('Y') + 543 - ($month < 5 ? 1 : 0)),
        ];
    }
}
