<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Changelog;

use DomainException;
use LibreCode\ReleaseTool\Domain\Release\ReleaseActivity;
use LibreCode\ReleaseTool\Domain\Release\ReleaseItem;
use LibreCode\ReleaseTool\Domain\Version\Version;

final class ChangelogPolicy
{
    private const array CATEGORY_ORDER = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'];

    public function prepare(
        Version $version,
        ReleaseActivity $activity,
        string $pathTemplate,
        string $currentContent,
        ?string $date = null,
    ): ChangelogResult {
        $versionString = (string) $version;
        if (preg_match('/^## (?:\\[)?' . preg_quote($versionString, '/') . '(?:\\])?(?:\\s|$)/m', $currentContent) === 1) {
            throw new DomainException(sprintf('Changelog already contains release %s.', $versionString));
        }

        $target = str_replace('{major}', (string) $version->major, $pathTemplate);
        $entries = $this->categorize($activity);
        $section = $this->renderSection($versionString, $entries, $date ?? gmdate('Y-m-d'));

        $trimmed = trim($currentContent);
        if ($trimmed === '') {
            $content = "# Changelog\n\nAll notable changes to this release line are documented here.\n\n" . $section . "\n";
        } else {
            $headerEnd = $this->headerInsertionOffset($currentContent);
            $content = substr($currentContent, 0, $headerEnd)
                . (str_ends_with(substr($currentContent, 0, $headerEnd), "\n\n") ? '' : "\n\n")
                . $section
                . "\n"
                . ltrim(substr($currentContent, $headerEnd), "\n");
        }

        return new ChangelogResult($target, $section, $content);
    }

    /**
     * @return array<string, list<string>>
     */
    private function categorize(ReleaseActivity $activity): array
    {
        $categories = array_fill_keys(self::CATEGORY_ORDER, []);
        $dependencySeen = false;
        $translationSeen = false;

        $items = $activity->items;
        usort($items, static function (ReleaseItem $a, ReleaseItem $b): int {
            if ($a->pullRequestNumber !== null && $b->pullRequestNumber !== null) {
                return $a->pullRequestNumber <=> $b->pullRequestNumber;
            }

            return $a->title <=> $b->title;
        });

        foreach ($items as $item) {
            if ($item->isDependency()) {
                if (!$dependencySeen) {
                    $categories['Changed'][] = '- Dependency updates.';
                    $dependencySeen = true;
                }

                continue;
            }

            if ($item->isTranslation()) {
                if (!$translationSeen) {
                    $categories['Changed'][] = '- Translation updates.';
                    $translationSeen = true;
                }

                continue;
            }

            $title = $this->normalizeTitle($item->title);
            if ($title === '') {
                continue;
            }

            $category = $item->publicSecurityEntry
                ? 'Security'
                : match ($item->conventionalType) {
                    'feat' => 'Added',
                    'fix' => 'Fixed',
                    default => 'Changed',
                };

            $reference = $item->pullRequestNumber !== null ? sprintf(' (#%d)', $item->pullRequestNumber) : '';
            $categories[$category][] = sprintf('- %s%s', $title, $reference);
        }

        return array_filter($categories, static fn (array $values): bool => $values !== []);
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/^\[stable\d+\]\s*/i', '', $title) ?? $title;
        $title = preg_replace('/^backport:\s*/i', '', $title) ?? $title;
        $title = preg_replace('/^(?:feat|fix|perf|refactor|docs|build|ci|chore|test)(?:\([^)]*\))?!?:\s*/i', '', $title) ?? $title;

        return trim($title);
    }

    /**
     * @param array<string, list<string>> $entries
     */
    private function renderSection(string $version, array $entries, string $date): string
    {
        $lines = [sprintf('## %s - %s', $version, $date), ''];

        foreach (self::CATEGORY_ORDER as $category) {
            if (!isset($entries[$category])) {
                continue;
            }
            $lines[] = '### ' . $category;
            $lines[] = '';
            foreach ($entries[$category] as $entry) {
                $lines[] = $entry;
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    private function headerInsertionOffset(string $content): int
    {
        if (preg_match('/^## (?:\\[)?\\d+\\.\\d+\\.\\d+/m', $content, $match, PREG_OFFSET_CAPTURE) === 1) {
            return $match[0][1];
        }

        return strlen($content);
    }
}
