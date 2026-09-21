<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

final class ConventionalTitleParser
{
    public function parse(string $title): ConventionalTitle
    {
        $normalized = trim($title);
        do {
            $before = $normalized;
            $normalized = preg_replace('/^\[stable\d+\]\s*/i', '', $normalized) ?? $normalized;
            $normalized = preg_replace('/^backport(?:\([^)]*\))?:\s*/i', '', $normalized) ?? $normalized;
        } while ($normalized !== $before);

        if (preg_match(
            '/^(?<type>feat|fix|perf|refactor|docs|build|ci|chore|test)(?:\((?<scope>[^)]+)\))?(?<breaking>!)?:\s*(?<subject>.+)$/i',
            $normalized,
            $match,
        ) !== 1) {
            return new ConventionalTitle($normalized, null, null, $normalized, false);
        }

        return new ConventionalTitle(
            $normalized,
            strtolower($match['type']),
            $match['scope'] !== '' ? strtolower($match['scope']) : null,
            trim($match['subject']),
            $match['breaking'] === '!',
        );
    }
}
