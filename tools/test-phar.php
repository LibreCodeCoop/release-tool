<?php

declare(strict_types=1);

$phar = dirname(__DIR__) . '/build/release-tool.phar';
if (!is_file($phar)) { fwrite(STDERR, "PHAR not found: {$phar}\n"); exit(2); }
$commands = [['--version'], ['list', '--raw'], ['help']];
foreach ($commands as $arguments) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar) . ' ' . implode(' ', array_map('escapeshellarg', $arguments));
    passthru($command, $exitCode);
    if ($exitCode !== 0) { fwrite(STDERR, sprintf("PHAR smoke test failed: %s\n", implode(' ', $arguments))); exit($exitCode); }
}
exit(0);
