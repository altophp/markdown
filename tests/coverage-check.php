<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

/*
 * Fails the build when line coverage drops below a floor.
 *
 * Usage: php tests/coverage-check.php <clover.xml> <minimum-percent>
 */

const USAGE = "Usage: php tests/coverage-check.php <clover.xml> <minimum-percent>\n";

$arguments = $_SERVER['argv'] ?? null;
if (!\is_array($arguments) || 3 !== \count($arguments)) {
    fwrite(\STDERR, USAGE);
    exit(2);
}

$path = $arguments[1];
$threshold = $arguments[2];
if (!\is_string($path) || !is_numeric($threshold)) {
    fwrite(\STDERR, USAGE);
    exit(2);
}

$minimum = (float) $threshold;

if (!is_file($path)) {
    fwrite(\STDERR, \sprintf("Coverage report not found: %s\n", $path));
    exit(2);
}

$document = new DOMDocument();
if (!@$document->load($path)) {
    fwrite(\STDERR, \sprintf("Coverage report is not valid XML: %s\n", $path));
    exit(2);
}

$nodes = (new DOMXPath($document))->query('/coverage/project/metrics');
$metrics = false === $nodes ? null : $nodes->item(0);
if (!$metrics instanceof DOMElement) {
    fwrite(\STDERR, "Coverage report has no project metrics.\n");
    exit(2);
}

$statements = (int) $metrics->getAttribute('statements');
$covered = (int) $metrics->getAttribute('coveredstatements');
if (0 === $statements) {
    fwrite(\STDERR, "Coverage report contains no executable lines.\n");
    exit(2);
}

$percentage = $covered / $statements * 100;
printf("Line coverage: %.2f%% (%d/%d), required: %.2f%%\n", $percentage, $covered, $statements, $minimum);

if ($percentage + 1e-9 < $minimum) {
    fwrite(\STDERR, "Coverage threshold not met.\n");
    exit(1);
}
