<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

/**
 * What *Remove site* did, and what is left for the user to do by hand.
 */
final class RemoveResult
{
    /**
     * @param list<string> $notes
     * @param list<string> $manual
     */
    public function __construct(
        public readonly array $notes,
        public readonly array $manual,
        /** removed/<site>-<ts> holding shared/ and site.yml, or null when deleted. */
        public readonly ?string $keptAt,
    ) {
    }
}
