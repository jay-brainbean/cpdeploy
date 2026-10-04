<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

/**
 * The wizard's side effects (WIZ-01, WIZ-04), so a cancel or a failed Create
 * can undo them.
 */
final class WizardTransaction
{
    /** The deploy key was created by this wizard (not reused). */
    public bool $keyCreated = false;
    /** GitHub id of the key this wizard registered. */
    public ?int $keyId = null;
    /** tmp/cpd-wizard-… */
    public ?string $tmpDir = null;

    // Create (WIZ-04)
    public ?string $siteDir = null;
    /** The site's own folder, ~/<site_dir> (LAY-04). */
    public ?string $siteFilesDir = null;
    public ?string $database = null;
    public ?string $user = null;

    public function hasKey(): bool
    {
        return $this->keyCreated || $this->keyId !== null;
    }
}
