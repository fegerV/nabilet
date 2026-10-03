<?php

declare(strict_types=1);

/**
 * NABILET Core — phantom class references.
 *
 * `tools/verify-models-schema.php` compares model FIELDS against the schema. It
 * cannot see a second, quieter failure: a relation or a value object naming a class
 * that does not exist.
 *
 * Eloquent resolves `$this->belongsTo(Hall::class)` lazily, at the moment the
 * relation is used — not when the model is loaded. So a model can pass every
 * static check, boot fine, serve every endpoint that does not touch that relation,
 * and then die with `Class "…" not found` the first time someone eager-loads it.
 * That is exactly how `HallSchema` (table `hall_schemas`, which never existed) and
 * `Venue::hallSchemas`/`Venue::translations` survived review.
 *
 * WHAT IT CHECKS
 *   For every `Foo::class` in `app/`, `routes/`, `database/`, resolve `Foo` the way
 *   PHP would — a fully-qualified name stands alone, a qualified name resolves its
 *   first segment against the imports, an unqualified name is an import if one
 *   exists and otherwise the file's own namespace — and report what resolves to
 *   nothing.
 *
 * WHY IT TOKENIZES
 *   A regex scan reads comments and string literals as code. The first version of
 *   this file did exactly that and reported four fatal-looking findings inside
 *   `// $this->app->bind(AuthRepositoryInterface::class, …)` — lines that are
 *   commented out, name classes that were never written, and cannot fail. A
 *   verifier that cries wolf on its own comments is worse than no verifier, so the
 *   scan runs over `token_get_all()` output, where comments and strings are single
 *   tokens that the walker skips.
 *
 * WHAT IT DELIBERATELY DOES NOT CHECK
 *   - Dynamic strings (`app($name)`, `new $class`) — unknowable statically.
 *   - Whether the named class is the RIGHT one. A relation can point at a real but
 *     wrong model — `Nabilet\Modules\Venues\Halls\Models\Hall` and
 *     `Nabilet\Modules\Venues\Models\Hall` both exist. That needs the schema and is
 *     `verify-models-schema.php`'s job.
 *   - Method existence on those classes.
 *
 * Usage:  php tools/verify-phantom-classes.php
 * Exit:   0 clean, 1 findings.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$scanDirs = [$root . '/app', $root . '/routes', $root . '/database'];

$files = [];
foreach ($scanDirs as $dir) {
    if (! is_dir($dir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

/**
 * Index every class this codebase declares, by short name.
 *
 * Used only to answer "did you mean…?" — a phantom `User` is nearly always the
 * real `User` under a namespace the file forgot to import, and naming it turns a
 * five-minute hunt into a one-line fix.
 *
 * @var array<string, list<string>> $declaredByShortName
 */
$declaredByShortName = [];

foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        continue;
    }

    $namespace = namespace_of($source);
    foreach (declared_types($source) as $shortName) {
        $fqcn = $namespace === '' ? $shortName : $namespace . '\\' . $shortName;
        $declaredByShortName[$shortName][] = $fqcn;
    }
}

/** @var list<string> $findings */
$findings = [];

foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        continue;
    }

    $namespace = namespace_of($source);
    if ($namespace === '') {
        continue;
    }

    $imports = imports_of($source);

    foreach (referenced_classes($source) as [$reference, $line]) {
        $resolved = resolve($reference, $imports, $namespace);

        if (class_exists($resolved) || interface_exists($resolved) || enum_exists($resolved) || trait_exists($resolved)) {
            continue;
        }

        $findings[] = [
            'where' => str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ':' . $line,
            'reference' => $reference,
            'resolved' => $resolved,
            'suggestions' => $declaredByShortName[short_name_of($resolved)] ?? [],
        ];
    }
}

echo "\nNABILET Core — phantom class references\n";
echo str_repeat('─', 74) . "\n\n";
echo '  PHP files scanned: ' . count($files) . "\n\n";

if ($findings === []) {
    echo "  Clean: every `Foo::class` resolves to a class that exists.\n\n";
    echo str_repeat('─', 74) . "\n";
    exit(0);
}

echo '  Unresolvable references (' . count($findings) . "):\n\n";
foreach ($findings as $finding) {
    printf("    %s  %s::class\n", $finding['where'], $finding['reference']);
    printf("        resolves to: %s — no such class\n", $finding['resolved']);

    if ($finding['suggestions'] !== []) {
        printf("        did you mean: %s\n", implode('  |  ', $finding['suggestions']));
    }

    echo "\n";
}

echo str_repeat('─', 74) . "\n";
echo "  FAIL — these become `Class not found` at runtime, usually inside a\n";
echo "         relation that is only touched on one rarely-used endpoint.\n\n";

exit(1);

/**
 * The file's namespace, read from tokens.
 */
function namespace_of(string $source): string
{
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_NAMESPACE) {
            continue;
        }

        $name = '';
        for ($j = $i + 1; $j < $count; $j++) {
            $next = $tokens[$j];

            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($next === ';' || $next === '{') {
                break;
            }
            if (is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $name .= $next[1];
                continue;
            }

            break;
        }

        return ltrim($name, '\\');
    }

    return '';
}

/**
 * Short names of every class, interface, enum and trait the file declares.
 *
 * @return list<string>
 */
function declared_types(string $source): array
{
    $tokens = token_get_all($source);
    $found = [];

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];
        if (! is_array($token) || ! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }

        for ($j = $i + 1; $j < $n; $j++) {
            $next = $tokens[$j];
            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            // `::class` is T_CLASS too, and anonymous classes have no name.
            if (is_array($next) && $next[0] === T_STRING) {
                $found[] = $next[1];
            }
            break;
        }
    }

    return $found;
}

/**
 * Namespace-level imports, alias => FQCN.
 *
 * Only depth-0 `use` statements count: a `use` inside a class body imports a
 * trait, and one inside a closure is `use ($var)`. Both would otherwise poison
 * the alias table.
 *
 * @return array<string, string>
 */
function imports_of(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $imports = [];
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token === '{') {
            $depth++;
            continue;
        }
        if ($token === '}') {
            $depth--;
            continue;
        }
        if ($depth !== 0 || ! is_array($token) || $token[0] !== T_USE) {
            continue;
        }

        // Collect the statement as raw text and parse it afterwards. Walking the
        // token stream directly loses `use Foo\Bar as Baz;` — the alias arrives
        // *after* the name it renames, so the name registers under its own tail.
        $buffer = '';
        for ($j = $i + 1; $j < $count; $j++) {
            $next = $tokens[$j];

            if ($next === ';') {
                break;
            }
            if (is_array($next) && in_array($next[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($next) && $next[0] === T_WHITESPACE) {
                $buffer .= ' ';
                continue;
            }

            $buffer .= is_array($next) ? $next[1] : $next;
        }

        foreach (parse_use_statement($buffer) as $alias => $fqcn) {
            $imports[$alias] = $fqcn;
        }
    }

    return $imports;
}

/**
 * `Foo\Bar`, `Foo\Bar as Baz`, `Foo\{Bar, Baz as Qux}` → alias => FQCN.
 *
 * @return array<string, string>
 */
function parse_use_statement(string $statement): array
{
    $statement = trim(preg_replace('/\s+/', ' ', $statement) ?? '');
    if ($statement === '') {
        return [];
    }

    $prefix = '';
    $body = $statement;

    if (str_contains($statement, '{')) {
        $prefix = rtrim(trim(substr($statement, 0, strpos($statement, '{'))), '\\');
        $body = trim(substr($statement, strpos($statement, '{') + 1), " \t\n\r\0\x0B}");
    }

    $imports = [];
    foreach (explode(',', $body) as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }

        $alias = '';
        if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $item, $match)) {
            $item = trim($match[1]);
            $alias = $match[2];
        }

        $fqcn = ltrim($prefix === '' ? $item : $prefix . '\\' . $item, '\\');
        if ($fqcn === '') {
            continue;
        }

        $imports[$alias !== '' ? $alias : short_name_of($fqcn)] = $fqcn;
    }

    return $imports;
}

/**
 * Every `Foo::class` reference in the file, with its line number.
 *
 * @return list<array{0: string, 1: int}>
 */
function referenced_classes(string $source): array
{
    $tokens = token_get_all($source);
    $found = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token)) {
            continue;
        }

        $isName = in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
        if (! $isName) {
            continue;
        }

        // Skip property/method/constant fetches: `$obj->Foo` and `Foo::BAR` are
        // not class references unless the very next token is `class`.
        if ($i > 0 && is_array($tokens[$i - 1]) && in_array($tokens[$i - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }

        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }

        if ($j >= $count || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_DOUBLE_COLON) {
            continue;
        }

        $k = $j + 1;
        while ($k < $count && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $k++;
        }

        if ($k >= $count || ! is_array($tokens[$k]) || $tokens[$k][0] !== T_CLASS) {
            continue;
        }

        $found[] = [$token[1], $token[2]];
    }

    return $found;
}

/**
 * Resolve a reference the way PHP does.
 *
 * @param array<string, string> $imports
 */
function resolve(string $reference, array $imports, string $namespace): string
{
    if (str_starts_with($reference, '\\')) {
        return ltrim($reference, '\\');
    }

    $firstSegment = str_contains($reference, '\\')
        ? substr($reference, 0, strpos($reference, '\\'))
        : $reference;

    if (isset($imports[$firstSegment])) {
        $tail = str_contains($reference, '\\')
            ? substr($reference, strpos($reference, '\\'))
            : '';

        return $imports[$firstSegment] . $tail;
    }

    return $namespace . '\\' . $reference;
}

function short_name_of(string $fqcn): string
{
    $position = strrpos($fqcn, '\\');

    return $position === false ? $fqcn : substr($fqcn, $position + 1);
}
