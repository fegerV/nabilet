<?php

declare(strict_types=1);

namespace Nabilet\Core\Modules;

use Nabilet\Core\Errors\ValidationError;

/**
 * Parsed, validated representation of a module's `module.json` manifest.
 *
 * Every module — first-party (app/Modules/*) and third-party (plugins/*) — ships
 * a manifest. The manifest is the module's contract with the kernel: what it is,
 * what it needs, and what it is allowed to do.
 *
 * Example (ТЗ §5):
 *
 *     {
 *       "name": "telegram",
 *       "title": "Telegram",
 *       "version": "1.0.0",
 *       "enabled": true,
 *       "requires": ["notifications"],
 *       "permissions": ["telegram.manage"],
 *       "provides": ["order.paid", "ticket.issued"]
 *     }
 *
 * `requires` is not just documentation — ModuleManager resolves boot order from it
 * and refuses to boot a module whose dependencies are missing or disabled.
 */
final class ModuleManifest
{
    public const TYPE_CORE = 'core';
    public const TYPE_PLUGIN = 'plugin';

    /**
     * @param list<string> $requires
     * @param list<string> $permissions
     * @param list<string> $provides
     */
    private function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $version,
        public readonly bool $enabled,
        public readonly array $requires,
        public readonly array $permissions,
        public readonly array $provides,
        public readonly string $type,
        public readonly string $path,
        public readonly ?string $description = null,
        public readonly ?string $author = null,
        public readonly int $priority = 100,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationError
     */
    public static function fromArray(array $data, string $path, string $type = self::TYPE_CORE): self
    {
        $errors = [];

        $name = (string) ($data['name'] ?? '');
        if ($name === '') {
            $errors['name'][] = 'Module name is required.';
        } elseif (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $errors['name'][] = 'Module name must be lowercase snake_case, starting with a letter (got "' . $name . '").';
        }

        $version = (string) ($data['version'] ?? '');
        if ($version === '') {
            $errors['version'][] = 'Module version is required.';
        } elseif (! preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
            $errors['version'][] = 'Module version must look like "1.0" or "1.0.0" (got "' . $version . '").';
        }

        foreach (['requires', 'permissions', 'provides'] as $key) {
            if (isset($data[$key]) && ! is_array($data[$key])) {
                $errors[$key][] = sprintf('"%s" must be an array of strings.', $key);
            }
        }

        if ($errors !== []) {
            throw new ValidationError($errors, sprintf('Invalid module manifest at %s.', $path));
        }

        /** @var list<string> $requires */
        $requires = array_values(array_map('strval', $data['requires'] ?? []));

        if (in_array($name, $requires, true)) {
            throw new ValidationError(
                ['requires' => ['Module "' . $name . '" cannot require itself.']],
                sprintf('Invalid module manifest at %s.', $path)
            );
        }

        return new self(
            name: $name,
            title: (string) ($data['title'] ?? ucfirst($name)),
            version: $version,
            enabled: (bool) ($data['enabled'] ?? true),
            requires: $requires,
            permissions: array_values(array_map('strval', $data['permissions'] ?? [])),
            provides: array_values(array_map('strval', $data['provides'] ?? [])),
            type: $type,
            path: rtrim($path, '/\\'),
            description: isset($data['description']) ? (string) $data['description'] : null,
            author: isset($data['author']) ? (string) $data['author'] : null,
            priority: (int) ($data['priority'] ?? 100),
        );
    }

    public static function fromFile(string $file, string $type = self::TYPE_CORE): self
    {
        if (! is_file($file)) {
            throw new ValidationError(['file' => ['Manifest not found: ' . $file]], 'Module manifest is missing.');
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new ValidationError(['file' => ['Manifest is not readable: ' . $file]], 'Module manifest is unreadable.');
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ValidationError(
                ['file' => ['Invalid JSON: ' . $e->getMessage()]],
                'Module manifest is not valid JSON.'
            );
        }

        return self::fromArray($data, dirname($file), $type);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'version' => $this->version,
            'enabled' => $this->enabled,
            'requires' => $this->requires,
            'permissions' => $this->permissions,
            'provides' => $this->provides,
            'type' => $this->type,
            'description' => $this->description,
            'author' => $this->author,
            'priority' => $this->priority,
        ];
    }

    public function serviceProviderClass(): ?string
    {
        $candidates = [
            sprintf('Nabilet\\Modules\\%s\\%sServiceProvider', $this->studly(), $this->studly()),
            sprintf('Nabilet\\Plugins\\%s\\%sServiceProvider', $this->studly(), $this->studly()),
            sprintf('Nabilet\\Plugins\\%s\\ServiceProvider', $this->studly()),
        ];

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    public function studly(): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $this->name)));
    }
}
