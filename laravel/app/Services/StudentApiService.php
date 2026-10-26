<?php

namespace App\Services;

use App\Models\StudentApiConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class StudentApiService
{
    public const SDL_URL = 'https://krumost.com/SDL_school/api/v1/integrations/student-data/students';

    private ?array $resolvedConfiguration = null;

    public function __construct(private readonly AcademicTermService $terms, private readonly EducationLevelService $levels) {}

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        if ($this->resolvedConfiguration !== null) {
            return $this->resolvedConfiguration;
        }
        $configuration = config('student_api');
        if (Schema::hasTable('student_api_connections')) {
            $connection = StudentApiConnection::query()->find(1);
            if ($connection !== null) {
                $configuration['driver'] = 'sdl';
                $configuration['url'] = self::SDL_URL;
                try {
                    $configuration['token'] = Crypt::decryptString((string) $connection->token);
                } catch (\Throwable) {
                    $configuration['token'] = '';
                }
            }
        }

        return $configuration;
    }

    /** @return array{driver: string, url: string, token_configured: bool, latest_term: ?string, source_label: string} */
    public function status(): array
    {
        $configuration = $this->configuration();

        return [
            'driver' => $this->isSdl() ? 'sdl' : 'legacy',
            'url' => $this->publicUrl((string) $configuration['url']),
            'token_configured' => $this->isSdl() && trim((string) $configuration['token']) !== '',
            'latest_term' => Schema::hasTable('student_api_connections') ? StudentApiConnection::query()->whereKey(1)->value('latest_term') : null,
            'source_label' => $this->isSdl() ? 'SDL School' : 'SENA Care เดิม',
        ];
    }

    /** @return array{latest_term: ?string, student_count: int} */
    public function probe(?string $token = null): array
    {
        $configuration = $this->configuration();
        $token = trim($token ?? ($this->isSdl() ? (string) $configuration['token'] : ''));
        if ($token === '') {
            throw new RuntimeException('กรุณาระบุ API token ของ SDL School');
        }
        $probe = new self($this->terms, $this->levels);
        $probe->resolvedConfiguration = ['driver' => 'sdl', 'url' => self::SDL_URL, 'token' => $token, 'key' => '', 'fallback_urls' => []];
        [$rows, $meta] = $probe->requestPages(self::SDL_URL, $token, [], false);
        $probe->teachers(self::SDL_URL, $token, true, true);

        return ['latest_term' => $probe->latestTerm($meta, $rows), 'student_count' => (int) ($meta['pagination']['total'] ?? $rows->count())];
    }

    public function saveToken(?string $token): array
    {
        $configuration = $this->configuration();
        $token = trim($token ?: ($this->isSdl() ? (string) $configuration['token'] : ''));
        $result = $this->probe($token);
        StudentApiConnection::updateOrCreate(['id' => 1], ['token' => Crypt::encryptString($token), 'latest_term' => $result['latest_term'], 'verified_at' => now()]);
        $this->resolvedConfiguration = null;

        return $result;
    }

    private function publicUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->configuration(), JSON_THROW_ON_ERROR));
    }

    public function isSdl(): bool
    {
        $configuration = $this->configuration();

        return $configuration['driver'] === 'sdl'
            || ($configuration['driver'] === 'auto' && str_contains((string) $configuration['url'], '/integrations/student-data'));
    }

    /** @return Collection<int, array{subject: string, subject_code: string, level: string, source: string}> */
    public function subjects(string $term, bool $refresh = false): Collection
    {
        if (! $this->isSdl() || ! preg_match('#^[12]/25\d{2}$#', $term)) {
            throw new RuntimeException('SDL subject API requires a supported academic term');
        }
        $configuration = $this->configuration();
        $credential = trim((string) $configuration['token']);
        if ($credential === '') {
            throw new RuntimeException('SDL subject API is not configured');
        }
        $cacheKey = 'student-source:subjects:v1:'.hash('sha256', $this->fingerprint().'|'.$term);
        if (! $refresh && is_array($cached = Cache::get($cacheKey))) {
            return collect($cached);
        }

        // The catalogue returns the complete term in one response, not pages.
        $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(12)
            ->retry(2, 250, fn ($exception) => $exception instanceof ConnectionException, throw: false)
            ->withToken($credential)->withHeader('X-Student-Data-Token', $credential)
            ->get(preg_replace('#/students$#', '/subjects', self::SDL_URL), ['term' => $term]);
        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload)
            || (array_key_exists('success', $payload) && ! $payload['success'])
            || ! is_array($payload['data'] ?? null) || ! array_is_list($payload['data'])
            || ($payload['meta']['term'] ?? null) !== $term) {
            throw new RuntimeException('SDL subject API returned an invalid response');
        }

        $subjects = collect($payload['data'])->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row): ?array {
                $code = is_string($row['code'] ?? null) ? trim($row['code']) : '';
                $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
                if ($code === '' || $name === '' || mb_strlen($code) > 50 || mb_strlen($name) > 150) {
                    return null;
                }

                return ['subject' => $name, 'subject_code' => $code, 'level' => '', 'source' => 'sdl'];
            })->filter()->unique(fn (array $row): string => mb_strtolower($row['subject_code']))
            ->sortBy('subject_code')->values();
        if ($payload['data'] !== [] && $subjects->isEmpty()) {
            throw new RuntimeException('SDL subject API does not contain usable subjects');
        }
        Cache::put($cacheKey, $subjects->all(), 300);

        return $subjects;
    }

    /** @param array<string, mixed> $filters @return array{Collection, array<string, mixed>} */
    public function fetch(array $filters, bool $allPages = true, bool $refresh = false): array
    {
        $term = $this->terms->fromFilters($filters);
        $cacheKey = 'student-source:v1:'.hash('sha256', json_encode([$this->fingerprint(), $filters, $allPages], JSON_THROW_ON_ERROR));
        if (! $refresh && is_array($cached = Cache::get($cacheKey))) {
            return [collect($cached['items']), $cached['meta']];
        }
        $sdl = $this->isSdl();
        $configuration = $this->configuration();
        $credential = trim((string) $configuration[$sdl ? 'token' : 'key']);
        $urls = $sdl ? [$configuration['url']] : [$configuration['url'], ...$configuration['fallback_urls']];
        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === [] || $credential === '') {
            throw new RuntimeException('Student API is not configured');
        }
        $lastException = null;
        foreach ($urls as $url) {
            try {
                [$rows, $meta] = $this->requestPages((string) $url, $credential, $filters, $allPages || ($sdl && ($filters['view'] ?? '') === 'groups'));
                $rows = $rows->map(fn (array $row): array => $sdl ? $this->sdlRow($row) : $row);
                $sourceTerm = $this->latestTerm($meta, $rows);
                $selectedTerm = $term ?? $sourceTerm;
                if ($selectedTerm !== null) {
                    $rows = $rows->filter(function (array $row) use ($selectedTerm, $sourceTerm): bool {
                        $rowTerm = $this->terms->normalize($row['current_term'] ?? $row['academic_term'] ?? null);

                        return $rowTerm !== null ? $rowTerm === $selectedTerm : $sourceTerm === $selectedTerm;
                    });
                }
                if ($sdl) {
                    $teachers = $this->teachers((string) $url, $credential, $refresh);
                    $rows = $rows->map(fn (array $row): array => [...$row, 'teachers' => $teachers->filter(fn (array $teacher): bool => count(array_intersect($teacher['assigned_groups'], [$row['group_code'], $row['class_name']])) > 0)->map(fn (array $teacher): array => ['username' => $teacher['username'], 'name' => $teacher['name']])->values()->all()]);
                    if (($filters['view'] ?? '') === 'groups') {
                        $rows = $rows->groupBy(fn (array $row): string => implode('|', [$row['group_code'], $row['class_name'], $row['level_code']]))
                            ->map(fn (Collection $members): array => [...$members->first(), 'student_id' => '', 'full_name' => '', 'student_count' => $members->count()]);
                    }
                }
                $meta = [...$meta, 'latest_term' => $sourceTerm, 'selected_term' => $selectedTerm, 'driver' => $sdl ? 'sdl' : 'legacy'];
                $rows = $rows->values();
                Cache::put($cacheKey, ['items' => $rows->all(), 'meta' => $meta], 300);

                return [$rows, $meta];
            } catch (\Throwable $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? new RuntimeException('Student API unavailable');
    }

    /** @param array<string, mixed> $filters @return array{Collection, array<string, mixed>} */
    private function requestPages(string $url, string $credential, array $filters, bool $allPages): array
    {
        $sdl = $this->isSdl();
        $params = $filters;
        unset($params['year'], $params['semester'], $params['term'], $params['refresh']);
        if ($sdl) {
            $params = ['per_page' => 100, 'sort' => 'code'];
            foreach (['search' => $filters['q'] ?? $filters['search'] ?? null, 'level' => ($filters['level'] ?? 'all') !== 'all' ? ($filters['level'] ?? null) : null, 'group' => $filters['class_group'] ?? $filters['group'] ?? null, 'term' => $this->terms->fromFilters($filters)] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $params[$key] = $value;
                }
            }
        } elseif (($term = $this->terms->fromFilters($filters)) !== null) {
            $params['term'] = $term;
        }
        $rows = collect();
        $page = $allPages ? 1 : max(1, (int) ($filters['page'] ?? 1));
        $lastPage = $page;
        $meta = [];
        $pagesFetched = 0;
        do {
            $request = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(12)
                ->retry(2, 250, fn ($exception) => $exception instanceof ConnectionException);
            $request = $sdl ? $request->withToken($credential)->withHeader('X-Student-Data-Token', $credential) : $request->withHeader('X-API-Key', $credential);
            $response = $request->get($url, [...$params, 'page' => $page, ...($sdl ? [] : ['api_key' => $credential])]);
            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload) || (array_key_exists('success', $payload) && ! $payload['success'])) {
                throw new RuntimeException('Student API returned an invalid response');
            }
            $pageRows = $this->extractRows($payload);
            $pageMeta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $dataMeta = is_array($payload['data'] ?? null) && ! array_is_list($payload['data']) ? $payload['data'] : [];
            $pagination = is_array($pageMeta['pagination'] ?? null) ? $pageMeta['pagination'] : (is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [...$dataMeta, ...$pageMeta]);
            if ($pagesFetched === 0) {
                $meta = $pageMeta;
                if ($allPages) {
                    $total = (int) ($pagination['total'] ?? $payload['total'] ?? 0);
                    $perPage = max(1, (int) ($pagination['per_page'] ?? $pagination['limit'] ?? $params['per_page'] ?? $params['limit'] ?? 1000));
                    $lastPage = max(1, (int) ($pagination['last_page'] ?? $pagination['total_pages'] ?? $pagination['pages'] ?? ceil($total / $perPage)));
                    if ($lastPage > 100) {
                        throw new RuntimeException('Student API exceeded supported page count');
                    }
                }
            }
            if ($allPages && $pageRows->isEmpty() && $lastPage > 1) {
                throw new RuntimeException('Student API returned an incomplete page');
            }
            $rows = $rows->concat($pageRows);
            $pagesFetched++;
            $page++;
        } while ($page <= $lastPage);

        return [$rows->values(), [...$meta, 'pages_fetched' => $pagesFetched]];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function teachers(string $studentsUrl, string $credential, bool $refresh, bool $required = false): Collection
    {
        $url = preg_replace('#/students/?$#', '/teachers', $studentsUrl);
        $cacheKey = 'student-source:teachers:'.$this->fingerprint();
        if (! $refresh && is_array($cached = Cache::get($cacheKey))) {
            return collect($cached);
        }
        try {
            [$rows] = $this->requestPages((string) $url, $credential, [], true);
            $rows = $rows->map(fn (array $row): array => [
                'username' => (string) ($row['username'] ?? ''),
                'name' => (string) ($row['name'] ?? $row['display_name'] ?? ''),
                'assigned_groups' => array_values(array_filter(is_array($row['assigned_groups'] ?? null) ? $row['assigned_groups'] : [], 'is_string')),
            ])->values();
            Cache::put($cacheKey, $rows->all(), 300);

            return $rows;
        } catch (\Throwable $exception) {
            if ($required) {
                throw $exception;
            }

            return collect();
        }
    }

    private function extractRows(array $payload): Collection
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        foreach ([$payload['data'] ?? null, $payload['items'] ?? null, $payload['students'] ?? null, $payload['result'] ?? null, $data['items'] ?? null, $data['students'] ?? null] as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return collect($candidate)->filter(fn ($row) => is_array($row))->values();
            }
        }

        throw new RuntimeException('Student API response does not contain a list');
    }

    /** @return array<string, mixed> */
    private function sdlRow(array $row): array
    {
        $name = is_array($row['name'] ?? null) ? $row['name'] : [];
        $group = is_array($row['group'] ?? null) ? $row['group'] : [];
        $level = is_array($row['level'] ?? null) ? $row['level'] : [];

        return [
            'student_id' => (string) ($row['code'] ?? ''),
            'full_name' => (string) ($name['full_name'] ?? trim(implode(' ', array_filter([$name['prefix'] ?? '', $name['first_name'] ?? '', $name['last_name'] ?? ''])))),
            'group_code' => (string) ($group['code'] ?? ''),
            'class_name' => (string) ($group['name'] ?? ''),
            ...($this->levels->fields($level['id'] ?? $level['label'] ?? null) ?? []),
            'current_term' => $this->terms->normalize($row['current_term'] ?? null),
            '_source_driver' => 'sdl',
        ];
    }

    private function latestTerm(array $meta, Collection $rows): ?string
    {
        $terms = collect([$meta['current_term'] ?? null, $meta['latest_term'] ?? null])
            ->concat(collect($meta['filter_options']['terms'] ?? [])->map(fn ($value) => is_array($value) ? ($value['value'] ?? null) : $value))
            ->concat($rows->map(fn (array $row) => $row['current_term'] ?? $row['academic_term'] ?? null))
            ->map(fn ($value) => $this->terms->normalize($value))->filter()->unique()
            ->sortByDesc(fn (string $term): string => substr($term, 2).$term[0]);

        return $terms->first();
    }
}
