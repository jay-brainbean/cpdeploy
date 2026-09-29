<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Services;

/**
 * What the wizard's steps share: the menu context, the answers and the side
 * effects so far, and the screen conventions of §9.3.
 */
final class WizardRun
{
    public const STEPS = 10;
    public const BACK_INPUT = '<';

    /** True when the user is moving forward (a skipped step then goes on, else back). */
    public bool $forward = true;

    /** The legacy-import question (§10.6) is asked once. */
    public bool $legacyAsked = false;

    public function __construct(
        public readonly MenuContext $ctx,
        public readonly WizardState $state,
        public readonly WizardTransaction $tx,
    ) {
    }

    public function services(): Services
    {
        return $this->ctx->services;
    }

    /**
     * "Add a site · Step 4/10 · Domain".
     */
    public function title(int $step, string $name): void
    {
        $this->ctx->title('Add a site', "Step {$step}/" . self::STEPS, $name);
    }

    /**
     * A text prompt that accepts "<" as "go back one step" (UI-03): null means Back.
     *
     * @param (\Closure(string): ?string)|null $validate
     */
    public function text(string $label, string $default = '', string $placeholder = '', ?\Closure $validate = null): ?string
    {
        $answer = $this->ctx->asker->text(
            $label,
            $default,
            $placeholder,
            true,
            static fn (string $v): ?string => trim($v) === self::BACK_INPUT || $validate === null ? null : $validate(trim($v)),
            'Type < to go back',
        );
        $answer = trim($answer);

        return $answer === self::BACK_INPUT ? null : $answer;
    }

    /**
     * What a step that doesn't apply returns: on in the direction of travel.
     */
    public function skip(): string
    {
        return $this->forward ? WizardStep::NEXT : WizardStep::BACK;
    }

    /**
     * "~/shop.example.com" for display.
     */
    public function tilde(string $path): string
    {
        $home = $this->services()->paths()->home();

        return str_starts_with($path, $home . '/') ? '~' . substr($path, strlen($home)) : $path;
    }

    /**
     * An absolute path from "~/…" or "/…" input.
     */
    public function expand(string $path): string
    {
        $path = trim($path);
        if ($path === '~' || str_starts_with($path, '~/')) {
            $path = $this->services()->paths()->home() . substr($path, 1);
        }

        return rtrim($path, '/');
    }

    /**
     * The preset for the chosen type (§8.4).
     *
     * @return array<string, mixed>
     */
    public function preset(): array
    {
        return $this->services()->presets()->for($this->state->type ?? 'laravel');
    }
}
