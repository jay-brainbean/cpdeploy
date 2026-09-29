<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

/**
 * What the user needs to add a deploy key by hand (GIT-17, no token).
 */
final class ManualKeyInstructions
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $publicKey,
    ) {
    }

    /**
     * @return list<string>
     */
    public function lines(string $repo): array
    {
        return [
            "Add this deploy key to {$repo}:",
            '  ' . $this->url,
            '  Title:  ' . $this->title,
            '  Key:    ' . $this->publicKey,
            '  Leave "Allow write access" unchecked.',
        ];
    }
}
