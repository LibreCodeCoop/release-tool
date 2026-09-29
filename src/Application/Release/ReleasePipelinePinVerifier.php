<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;

final readonly class ReleasePipelinePinVerifier
{
    public function __construct(private GitRepository $git)
    {
    }

    /**
     * @param list<string> $paths
     * @param list<string> $actionPrefixes
     * @return array<string, array{candidate:list<string>,reference:list<string>}>
     */
    public function mismatches(
        string $candidateRef,
        string $referenceRef,
        array $paths,
        array $actionPrefixes,
    ): array {
        $candidateSha = $this->git->resolve($candidateRef);
        $referenceSha = $this->git->resolve($referenceRef);
        $mismatches = [];

        foreach ($paths as $path) {
            $candidate = $this->pins($this->git->readFile($candidateSha, $path), $actionPrefixes);
            $reference = $this->pins($this->git->readFile($referenceSha, $path), $actionPrefixes);
            if ($candidate !== $reference) {
                $mismatches[$path] = [
                    'candidate' => $candidate,
                    'reference' => $reference,
                ];
            }
        }

        return $mismatches;
    }

    /**
     * @param list<string> $actionPrefixes
     * @return list<string>
     */
    private function pins(string $content, array $actionPrefixes): array
    {
        preg_match_all(
            '/\buses:\s*([A-Za-z0-9_.\/-]+)@([0-9a-f]{40})\b/',
            $content,
            $matches,
            PREG_SET_ORDER,
        );

        $pins = [];
        foreach ($matches as $match) {
            $action = $match[1];
            foreach ($actionPrefixes as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    $pins[] = $action . '@' . $match[2];
                    break;
                }
            }
        }

        $pins = array_values(array_unique($pins));
        sort($pins);
        return $pins;
    }
}
