#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fail if src/ references a first-party or known third-party package that is
 * not listed in composer.json "require" (require-dev-only or undeclared).
 *
 * This catches the class of bug where production installs (composer install
 * --no-dev) autoload src/ but cannot resolve a use statement.
 */

$root = dirname(__DIR__);
$composerPath = $root . '/composer.json';
$composer = json_decode((string) file_get_contents($composerPath), true, JSON_THROW_ON_ERROR);
/** @var array<string, mixed> $composer */
$require = array_keys($composer['require'] ?? []);
$requireDev = array_keys($composer['require-dev'] ?? []);
$requireSet = array_flip($require);
$requireDevSet = array_flip($requireDev);

/**
 * Map a use-statement FQCN to a composer package name, or null if not part of
 * the audited public surface (e.g. same-package symbols).
 */
function fqcnToComposerPackage(string $fqcn): ?string
{
    if (str_starts_with($fqcn, 'Waaseyaa\\Bimaaji\\')) {
        return null;
    }

    if (str_starts_with($fqcn, 'Waaseyaa\\')) {
        $rest = substr($fqcn, strlen('Waaseyaa\\'));
        $top = strstr($rest, '\\', true) ?: $rest;
        $kebab = strtolower((string) preg_replace('/([a-z\d])([A-Z])/', '$1-$2', $top));

        return 'waaseyaa/' . $kebab;
    }

    if (str_starts_with($fqcn, 'Symfony\\Component\\')) {
        $rest = substr($fqcn, strlen('Symfony\\Component\\'));
        $top = strstr($rest, '\\', true) ?: $rest;
        $kebab = strtolower((string) preg_replace('/([a-z\d])([A-Z])/', '$1-$2', $top));

        return 'symfony/' . $kebab;
    }

    if (str_starts_with($fqcn, 'PhpParser\\')) {
        return 'nikic/php-parser';
    }

    return null;
}

/**
 * @return list<string>
 */
function extractUseFqdnsFromLine(string $line): array
{
    if (preg_match('/^\s*use\s+(function|const)\s+/i', $line) === 1) {
        return [];
    }

    if (preg_match('/^\s*use\s+(.+);/', $line, $m) !== 1) {
        return [];
    }

    $clause = $m[1];
    $out = [];
    foreach (preg_split('/\s*,\s*/', $clause) ?: [] as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        $part = (string) preg_replace('/\s+as\s+\w+\s*$/i', '', $part);
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $out[] = $part;
    }

    return $out;
}

$srcDir = $root . '/src';
if (!is_dir($srcDir)) {
    fwrite(STDERR, "src/ directory not found.\n");
    exit(1);
}

$packagesNeeded = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
);

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $content = (string) file_get_contents($file->getPathname());
    foreach (explode("\n", $content) as $line) {
        foreach (extractUseFqdnsFromLine($line) as $fqcn) {
            $pkg = fqcnToComposerPackage($fqcn);
            if ($pkg === null) {
                // Same-package symbols (Waaseyaa\Bimaaji\...) or unsupported vendor — decide below.
                if (str_starts_with($fqcn, 'Waaseyaa\\Bimaaji\\')) {
                    continue;
                }

                fwrite(STDERR, "Public-surface dependency error: unmapped import in src/: {$fqcn}\n");
                fwrite(STDERR, "Extend scripts/check-public-surface-deps.php fqcnToComposerPackage() mapping.\n");
                exit(1);
            }
            $packagesNeeded[$pkg] = true;
        }
    }
}

foreach (array_keys($packagesNeeded) as $pkg) {
    if (isset($requireSet[$pkg])) {
        continue;
    }

    if (isset($requireDevSet[$pkg])) {
        fwrite(STDERR, "Public-surface dependency error: {$pkg} is imported from src/ but is only in require-dev.\n");
        fwrite(STDERR, "Move it to require (same constraint style as other waaseyaa/* entries).\n");
        exit(1);
    }

    fwrite(STDERR, "Public-surface dependency error: {$pkg} is imported from src/ but is not declared in composer.json require.\n");
    exit(1);
}

$count = count($packagesNeeded);
echo "check-public-surface-deps: OK ({$count} required packages referenced from src/)\n";
