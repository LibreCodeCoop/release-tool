<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;

final class ReleaseFileUpdater
{
    public function update(string $path, string $content, string $version): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'xml' => $this->updateXmlVersion($path, $content, $version),
            'json' => $this->updateJsonVersion($path, $content, $version),
            default => throw new DomainException(sprintf(
                'Unsupported configured release-version file: %s',
                $path,
            )),
        };
    }

    private function updateXmlVersion(string $path, string $content, string $version): string
    {
        $count = preg_match_all('/<version>[^<]*<\/version>/', $content);
        if ($count !== 1) {
            throw new DomainException(sprintf(
                'Expected exactly one <version> element in %s, found %d.',
                $path,
                $count === false ? 0 : $count,
            ));
        }

        return (string) preg_replace(
            '/<version>[^<]*<\/version>/',
            '<version>' . $version . '</version>',
            $content,
            1,
        );
    }

    private function updateJsonVersion(string $path, string $content, string $version): string
    {
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_key_exists('version', $data) || !is_string($data['version'])) {
            throw new DomainException(sprintf('JSON release file has no top-level string version: %s', $path));
        }

        $data['version'] = $version;
        if (
            isset($data['packages'])
            && is_array($data['packages'])
            && isset($data['packages'][''])
            && is_array($data['packages'][''])
            && array_key_exists('version', $data['packages'][''])
        ) {
            if (!is_string($data['packages']['']['version'])) {
                throw new DomainException(sprintf('Root package version is not a string in %s.', $path));
            }
            $data['packages']['']['version'] = $version;
        }

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
        $indent = $this->detectJsonIndent($content);
        if ($indent === null) {
            return json_encode($data, $flags) . (str_ends_with($content, "\n") ? "\n" : "");
        }

        $encoded = json_encode($data, $flags | JSON_PRETTY_PRINT);
        if ($indent !== '    ') {
            $encoded = preg_replace_callback(
                '/^( +)/m',
                static function (array $match) use ($indent): string {
                    $levels = intdiv(strlen($match[1]), 4);

                    return str_repeat($indent, $levels);
                },
                $encoded,
            ) ?? $encoded;
        }

        return $encoded . (str_ends_with($content, "\n") ? "\n" : "");
    }

    private function detectJsonIndent(string $content): ?string
    {
        if (preg_match('/\n([ \t]+)"[^"]+"\s*:/', $content, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
