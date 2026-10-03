<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Closure;
use Cpdeploy\Services;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Ui\Asker;
use Cpdeploy\Ui\ErrorView;
use Cpdeploy\Ui\Format;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Ui\Theme;
use DateTimeImmutable;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What every menu screen needs (§9): the services, how to ask (Asker) and
 * report (Reporter), where to write, and the shared screen conventions —
 * title lines (UIG-04), errors in the §13 format, and "back to the menu after
 * an action" (UIG-05). Menus hold no business logic (§6.2); they call the same
 * service methods as the commands (ARC-03).
 */
final class MenuContext
{
    public const BACK = '__back';

    public function __construct(
        public readonly Services $services,
        public readonly Asker $asker,
        public readonly OutputInterface $output,
        public readonly Reporter $reporter,
        public readonly Theme $theme,
    ) {
    }

    /**
     * UIG-04: "cpdeploy · shop · Deploy".
     */
    public function title(string ...$parts): void
    {
        $this->output->writeln('');
        $this->output->writeln('<options=bold>' . ErrorView::escape(implode(' · ', ['cpdeploy', ...$parts])) . '</>');
    }

    public function line(string $text = ''): void
    {
        $this->output->writeln($text === '' ? '' : ' ' . ErrorView::escape($this->services->masker()->mask($text)));
    }

    public function ok(string $text): void
    {
        $this->output->writeln(sprintf(' <fg=green>%s</> %s', $this->theme->symbol('ok'), ErrorView::escape($this->services->masker()->mask($text))));
    }

    public function warn(string $text): void
    {
        $this->output->writeln(sprintf(' <fg=yellow>%s</> %s', $this->theme->symbol('warn'), ErrorView::escape($this->services->masker()->mask($text))));
    }

    public function error(CpdeployException $e): void
    {
        $this->output->writeln(ErrorView::lines($e, $this->services->masker(), $this->theme));
    }

    /**
     * UIG-05: "Press Enter to continue" after output the user needs to read.
     */
    public function pause(): void
    {
        $this->asker->pause();
    }

    /**
     * Runs one menu action. A handled error is shown (then a pause) and the menu
     * continues; Cancel only says so. Returns whether the action finished.
     *
     * @param Closure(): void $action
     */
    public function attempt(Closure $action): bool
    {
        try {
            $action();

            return true;
        } catch (CpdeployException $e) {
            if ($e->errorCode === ErrorCode::CANCELLED) {
                $this->line('Cancelled.');

                return false;
            }
            $this->error($e);
            $this->pause();

            return false;
        } finally {
            // A Ctrl+C belongs to the action it cancelled, not to the next one.
            $this->services->signals()->reset();
        }
    }

    /**
     * A `select` whose last options are ← Back (UI-02).
     *
     * @param array<int|string, string> $options
     */
    public function choose(string $label, array $options, int|string|null $default = null, bool $back = true): int|string
    {
        if ($back) {
            $options[self::BACK] = $this->theme->symbol('back') . ' Back';
        }

        return $this->asker->select($label, $options, $default ?? array_key_first($options));
    }

    /**
     * UI-01: `select` for up to 8 entries, `search` beyond.
     *
     * @param array<string, string> $options
     */
    public function pick(string $label, array $options): string
    {
        if (count($options) <= 8) {
            return (string) $this->choose($label, $options);
        }

        return (string) $this->asker->search($label, static function (string $query) use ($options): array {
            $query = strtolower(trim($query));
            $matches = $query === '' ? $options : array_filter($options, static fn (string $label, string $value): bool => str_contains(strtolower($value . ' ' . $label), $query), ARRAY_FILTER_USE_BOTH);

            return $matches + [self::BACK => '← Back'];
        });
    }

    /**
     * "2h ago" for an ISO time, or "—".
     */
    public function when(mixed $iso): string
    {
        if (!is_string($iso) || $iso === '') {
            return '—';
        }
        try {
            return Format::relative(new DateTimeImmutable($iso), $this->services->clock()->now());
        } catch (\Exception) {
            return $iso;
        }
    }
}
