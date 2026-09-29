<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Log;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Ui\Asker;
use Cpdeploy\Ui\Reporter;

/**
 * One rollback run (§11.8): the site, the target and the release it replaces,
 * the target's PHP (RB-03), how the domain's PHP changes (GL-03), and where to
 * report. Built by Rollback::prepare(); used by the `rollback` command and by a
 * deploy whose health check failed (HC-03).
 */
final class RollbackJob
{
    /** The domain's MultiPHP version before the rollback, e.g. ea-php83. */
    public ?string $domainPhpTag = null;
    /** GL-03 input: none | upgrade | downgrade | family. */
    public string $phpChange = PhpService::CHANGE_NONE;
    public ?Domain $domain = null;

    /** @var list<string> RB-04 warnings, shown before confirming */
    public array $risks = [];

    /** @var list<string> */
    public array $notes = [];

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(
        public SiteConfig $site,
        public readonly Release $target,
        public readonly ?Release $live,
        public readonly PhpInstall $php,
        public readonly Reporter $reporter,
        public readonly Asker $asker,
        public readonly ?Log $log,
        /** Run the health check (§11.6) after the switch. */
        public readonly bool $healthCheck,
        /** RB-06 step 7: on a failed health check, offer to switch back (the command, interactive). */
        public readonly bool $offerSwitchBack,
        public readonly float $artisanTimeout,
    ) {
    }

    public function name(): string
    {
        return $this->site->name();
    }

    public function warn(string $text): void
    {
        $this->warnings[] = $text;
        $this->reporter->warn($text);
        $this->log?->write('WARNING: ' . $text);
    }

    /**
     * RB-04: shown and logged before confirming; they don't make the result a warning.
     */
    public function showRisks(): void
    {
        foreach ($this->risks as $risk) {
            $this->reporter->warn($risk);
            $this->log?->write('RISK: ' . $risk);
        }
    }

    public function note(string $text): void
    {
        $this->notes[] = $text;
        $this->log?->write('NOTE: ' . $text);
    }

    /**
     * Options for an artisan command in $dir, output streamed to the reporter.
     */
    public function options(string $dir, string $label): RunOptions
    {
        return (new RunOptions(cwd: $dir, timeout: $this->artisanTimeout, label: $label))->reporting(
            fn (string $line) => $this->reporter->line($line),
            fn () => $this->reporter->tick(),
        );
    }
}
