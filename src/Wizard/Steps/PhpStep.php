<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard\Steps;

use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Project\ProjectInfo;
use Cpdeploy\Runtime\DomainPhp;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Wizard\SiteInspector;
use Cpdeploy\Wizard\WizardRun;
use Cpdeploy\Wizard\WizardStep;

/**
 * Step 6 (§9.3): the PHP version, with each installed version checked against
 * the project's platform requirements (PHP-05), and whether the domain's
 * MultiPHP version follows it at go-live (PHP-06).
 */
final class PhpStep implements WizardStep
{
    public function run(WizardRun $w): string
    {
        $state = $w->state;
        $ctx = $w->ctx;
        if ($state->files === null || $state->info === null) {
            return self::BACK;
        }
        $w->title(6, 'PHP version');
        $inspector = $w->services()->siteInspector();
        $requirement = self::requirement($state->info);
        if ($requirement !== null) {
            $ctx->line($requirement);
        }
        $domainPhp = $inspector->domainPhp($state->domain);
        if ($domainPhp !== null) {
            $ctx->line('Domain currently uses: ' . self::describeDomain($domainPhp));
        }
        $composer = $state->config($w->preset())->composerVersion();
        $options = $inspector->phpOptions($state->info, $state->files, $composer, $ctx->reporter);
        if ($options === []) {
            $ctx->warn('No PHP installation was found on this server.');

            return self::BACK;
        }

        $labels = [];
        $installs = [];
        $default = null;
        foreach ($options as [$install, $problems]) {
            $key = $install->tag();
            $installs[$key] = $install;
            $labels[$key] = sprintf('PHP %-6s (%s)  %s', $install->majorMinor(), $install->family, self::status($problems, $ctx->theme->symbol('ok'), $ctx->theme->symbol('fail'), $ctx->theme->symbol('warn')));
            if ($state->phpVersion !== null && $install->majorMinor() === $state->phpVersion && $install->family === $state->phpFamily) {
                $default = $key;
            }
        }
        $default ??= SiteInspector::defaultPhp($options, $domainPhp)?->tag();

        $compatible = array_filter($options, static fn (array $o): bool => $o[1] === []);
        if ($compatible === []) {
            $ctx->warn('None of the installed PHP versions meets the requirements:');
            foreach ($options as [$install, $problems]) {
                $ctx->line(sprintf('  PHP %s (%s): %s', $install->majorMinor(), $install->family, $problems === null ? "couldn't be checked" : implode('; ', $problems)));
            }
            $ctx->line('Enable extensions in cPanel → MultiPHP INI Editor / Select PHP Version, or ask your host.');
            $choice = $ctx->choose('What now?', ['continue' => 'Continue anyway (the build will fail until fixed)']);
            if ($choice === MenuContext::BACK) {
                return self::BACK;
            }
        }

        $choice = (string) $ctx->choose('Which PHP should the site use?', $labels, $default);
        if ($choice === MenuContext::BACK) {
            return self::BACK;
        }
        $install = $installs[$choice];
        $state->phpVersion = $install->majorMinor();
        $state->phpFamily = $install->family;

        if ($domainPhp !== null && $domainPhp->source === DomainPhp::SOURCE_SELECTOR) {
            // PHP-06: a domain on CloudLinux PHP Selector.
            $tag = PhpInstall::tagFor(PhpInstall::EA, $install->majorMinor());
            $alt = $w->services()->phpLocator()->find($install->majorMinor(), PhpInstall::ALT);
            $picks = ['a' => "Switch this domain to MultiPHP {$tag} (PHP Selector extensions will no longer apply)"];
            if ($alt !== null) {
                $picks['b'] = "Keep PHP Selector; I'll change it in cPanel → Select PHP Version";
            }
            $pick = (string) $ctx->asker->select('This domain uses CloudLinux PHP Selector', $picks, $install->family === PhpInstall::ALT && $alt !== null ? 'b' : 'a');
            if ($pick === 'b' && $alt !== null) {
                $state->phpFamily = PhpInstall::ALT;
            } elseif ($install->family === PhpInstall::ALT) {
                $ea = $w->services()->phpLocator()->find($install->majorMinor(), PhpInstall::EA);
                if ($ea !== null) {
                    $state->phpFamily = PhpInstall::EA;
                }
            }
            $state->syncMultiPhp = true;
        } else {
            $state->syncMultiPhp = $ctx->asker->confirm("Also set this as the domain's PHP version (MultiPHP) at go-live?", $state->syncMultiPhp);
        }

        return self::NEXT;
    }

    /**
     * "composer.lock needs PHP ^8.2 and: intl, gd, …" (null without Composer).
     */
    public static function requirement(ProjectInfo $info): ?string
    {
        if (!$info->hasComposer()) {
            return null;
        }
        $require = $info->composerRequire();
        $extensions = [];
        foreach (array_keys($require) as $name) {
            if (str_starts_with($name, 'ext-')) {
                $extensions[] = substr($name, 4);
            }
        }
        $source = $info->hasLock() ? 'composer.lock' : 'composer.json';
        $php = isset($require['php']) ? 'PHP ' . $require['php'] : 'any PHP';
        if ($extensions === []) {
            return "{$source} needs {$php}";
        }
        $shown = array_slice($extensions, 0, 6);

        return "{$source} needs {$php} and: " . implode(', ', $shown) . (count($extensions) > 6 ? ', …' : '');
    }

    /**
     * @param list<string>|null $problems
     */
    private static function status(?array $problems, string $ok, string $fail, string $warn): string
    {
        if ($problems === null) {
            return "{$warn} couldn't check";
        }
        if ($problems === []) {
            return "{$ok} compatible";
        }
        $first = $problems[0];
        if (preg_match('/^php \S+ \(requires (.+)\)$/', $first, $m) === 1) {
            return "{$fail} needs PHP {$m[1]}";
        }

        return "{$fail} " . (preg_match('/^ext-(\S+)/', $first, $m) === 1 ? 'missing extension: ' . $m[1] : $first) . (count($problems) > 1 ? ' (+' . (count($problems) - 1) . ')' : '');
    }

    private static function describeDomain(DomainPhp $php): string
    {
        return match ($php->source) {
            DomainPhp::SOURCE_SELECTOR => "PHP {$php->majorMinor} (CloudLinux PHP Selector)",
            DomainPhp::SOURCE_SYSTEM_DEFAULT => "PHP {$php->majorMinor} (the server default)",
            default => "PHP {$php->majorMinor} (MultiPHP)",
        };
    }
}
