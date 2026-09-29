<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

/**
 * One screen of the add-site wizard (§9.3). run() returns what happens next.
 */
interface WizardStep
{
    public const NEXT = 'next';
    public const BACK = 'back';
    public const CANCEL = 'cancel';
    /** Review: create the site (and deploy, when CREATE_DEPLOY). */
    public const CREATE = 'create';
    public const CREATE_DEPLOY = 'create_deploy';
    /** Review → *Edit a step…*: "goto:<n>". */
    public const GOTO = 'goto:';

    public function run(WizardRun $w): string;
}
