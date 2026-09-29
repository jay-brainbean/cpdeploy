<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Cpdeploy\Config\SiteConfig;

/**
 * Outcome of `add --from`: the site was created, or the deploy key has to be
 * added by hand first (exit 2; the same command continues afterwards).
 */
final class AddFromFileResult
{
    /**
     * @param list<string> $instructions
     */
    private function __construct(
        public readonly ?SiteConfig $config,
        public readonly ?WizardState $state,
        public readonly bool $deployNow,
        public readonly array $instructions,
    ) {
    }

    public static function created(SiteConfig $config, WizardState $state, bool $deployNow): self
    {
        return new self($config, $state, $deployNow, []);
    }

    /**
     * @param list<string> $instructions
     */
    public static function keyNeeded(array $instructions): self
    {
        return new self(null, null, false, $instructions);
    }

    public function isCreated(): bool
    {
        return $this->config !== null;
    }
}
