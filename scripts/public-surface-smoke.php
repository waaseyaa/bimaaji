#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Minimal runtime smoke under composer install --no-dev.
 *
 * Exercises ApplicationGraphGenerator with a synthetic provider — no kernel,
 * no database, no row data — and asserts the top-level graph shape contracts.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;

$provider = new class () implements GraphSectionProviderInterface {
    public function getKey(): string
    {
        return 'entities';
    }

    public function provide(): GraphSection
    {
        return new GraphSection('entities', '1.0', [
            'demo' => [
                'label' => 'Demo',
                'class' => 'Waaseyaa\\Bimaaji\\Tests\\Fixtures\\DemoEntity',
                'keys' => ['id' => 'id'],
                'fields' => [],
            ],
        ]);
    }
};

$graph = (new ApplicationGraphGenerator([$provider]))->generate();
$payload = $graph->toArray();

if (($payload['version'] ?? null) !== '1.0') {
    fwrite(STDERR, "public-surface-smoke: expected graph version 1.0\n");
    exit(1);
}

if (!isset($payload['sections']) || !is_array($payload['sections'])) {
    fwrite(STDERR, "public-surface-smoke: missing sections map\n");
    exit(1);
}

if (!isset($payload['sections']['entities'])) {
    fwrite(STDERR, "public-surface-smoke: missing entities section\n");
    exit(1);
}

echo "public-surface-smoke: OK\n";
