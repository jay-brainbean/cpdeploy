<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard\Steps;

use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Menus\StepsMenu;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Wizard\WizardRun;
use Cpdeploy\Wizard\WizardStep;

/**
 * Step 9 (§9.3): the deploy steps editor of Manage site (§9.5.6) on the
 * wizard's answers. Changes are kept in the state until Create.
 */
final class DeployStepsStep implements WizardStep
{
    public function run(WizardRun $w): string
    {
        $state = $w->state;
        $preset = $w->preset();
        $done = (new StepsMenu($w->ctx))->edit(
            ['Add a site', 'Step 9/' . WizardRun::STEPS, 'Deploy steps'],
            static fn (): SiteConfig => $state->config($preset),
            static function (array $changes) use ($state, $preset): void {
                $before = $state->config($preset);
                $config = $before;
                foreach ($changes as $path => $value) {
                    $config = $config->with($path, $value);
                }
                // Only what this change breaks: the other answers are checked at Review.
                $errors = array_values(array_diff(SiteSchema::validate($config->toArray())['errors'], SiteSchema::validate($before->toArray())['errors']));
                if ($errors !== []) {
                    throw new CpdeployException(ErrorCode::CONFIG_INVALID, implode("\n", $errors), 'Nothing was changed.');
                }
                foreach ($changes as $path => $value) {
                    $state->overrides[$path] = $value;
                }
            },
            true,
        );

        return $done ? self::NEXT : self::BACK;
    }
}
