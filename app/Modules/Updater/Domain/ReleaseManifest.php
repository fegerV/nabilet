<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Domain;

/**
 * Подписанное описание релиза. Чистый домен, никаких Illuminate и I/O.
 *
 * Канонические байты для подписи собираются здесь и в сборщике
 * `scripts/build-hosting-zip.ps1` по одному и тому же правилу: строка
 * `NABILET-UPDATE-V1`, затем `key=value` в этой же последовательности, без
 * завершающего перевода строки, в UTF-8. Любая правка порядка ломает все старые
 * подписи — это сделано намеренно: формат меняется только вместе с префиксом.
 */
final class ReleaseManifest
{
    public const PREFIX = 'NABILET-UPDATE-V1';

    /** @param list<string> $extensions @param list<string> $fromVersions */
    public function __construct(
        public readonly string $version,
        public readonly string $releasedAt,
        public readonly string $channel,
        public readonly string $artifact,
        public readonly string $sha256,
        public readonly int $sizeBytes,
        public readonly string $phpMin,
        public readonly string $phpMax,
        public readonly array $extensions,
        public readonly array $fromVersions,
        public readonly int $migrations,
        public readonly string $signature,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $version = self::stringField($data, 'version');
        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new \InvalidArgumentException('Некорректная версия релиза.');
        }

        $sha = strtolower(self::stringField($data, 'sha256'));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new \InvalidArgumentException('Некорректный SHA-256 релиза.');
        }

        $artifact = self::stringField($data, 'artifact');
        if ($artifact !== basename($artifact) || preg_match('/^[A-Za-z0-9._-]+$/', $artifact) !== 1) {
            throw new \InvalidArgumentException('Некорректное имя архива релиза.');
        }

        $php = $data['php'] ?? null;
        if (! is_array($php)) {
            throw new \InvalidArgumentException('В манифесте нет требований к PHP.');
        }

        $extensions = self::stringList($php['extensions'] ?? null, 'extensions');
        sort($extensions, SORT_STRING);

        return new self(
            $version,
            self::stringField($data, 'released_at'),
            self::stringField($data, 'channel'),
            $artifact,
            $sha,
            self::intField($data, 'size_bytes'),
            self::stringField($php, 'min'),
            self::stringField($php, 'max_exclusive'),
            $extensions,
            self::stringList($data['from_versions'] ?? [], 'from_versions'),
            self::intField($data, 'migrations'),
            isset($data['signature']) && is_string($data['signature']) ? $data['signature'] : '',
        );
    }

    /** Канонические байты, которые подписывает сборщик и проверяет клиент. */
    public function canonical(): string
    {
        return implode("\n", [
            self::PREFIX,
            'schema=1',
            'version=' . $this->version,
            'released_at=' . $this->releasedAt,
            'channel=' . $this->channel,
            'artifact=' . $this->artifact,
            'sha256=' . $this->sha256,
            'size_bytes=' . $this->sizeBytes,
            'php_min=' . $this->phpMin,
            'php_max=' . $this->phpMax,
            'extensions=' . implode(',', $this->extensions),
            'from_versions=' . implode(',', $this->fromVersions),
            'migrations=' . $this->migrations,
        ]);
    }

    /**
     * Может ли этот релиз быть обновлением с $currentVersion.
     *
     * Пустой `from_versions` означает «любая более старая версия»: сборщик без
     * переменной окружения не знает историю, но даунгрейд всё равно запрещён.
     */
    public function appliesTo(string $currentVersion): bool
    {
        if (version_compare($this->version, $currentVersion, '<=')) {
            return false;
        }

        if ($this->fromVersions === []) {
            return true;
        }

        return in_array($currentVersion, $this->fromVersions, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'released_at' => $this->releasedAt,
            'channel' => $this->channel,
            'artifact' => $this->artifact,
            'sha256' => $this->sha256,
            'size_bytes' => $this->sizeBytes,
            'php' => [
                'min' => $this->phpMin,
                'max_exclusive' => $this->phpMax,
                'extensions' => $this->extensions,
            ],
            'from_versions' => $this->fromVersions,
            'migrations' => $this->migrations,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('В манифесте отсутствует поле %s.', $key));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $data */
    private static function intField(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException(sprintf('Поле %s манифеста должно быть числом.', $key));
        }

        return (int) $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new \InvalidArgumentException(sprintf('Поле %s манифеста должно быть списком.', $key));
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new \InvalidArgumentException(sprintf('Поле %s содержит пустую запись.', $key));
            }
            $out[] = trim($item);
        }

        return $out;
    }
}
