<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Masker;

/**
 * The §13 error block, for the command line and the menus:
 *   ✗ <message>
 *     Live site: <affected / not changed>
 *     Fix: <hint>
 *     Log: <path>
 */
final class ErrorView
{
    /**
     * Console-formatted lines (text escaped for Symfony's formatter).
     *
     * @return list<string>
     */
    public static function lines(CpdeployException $e, Masker $masker, Theme $theme): array
    {
        $lines = [sprintf('<fg=red>%s</> %s', $theme->symbol('fail'), self::escape($masker->mask($e->getMessage())))];
        $lines[] = '  Live site: ' . ($e->liveAffected ? '<fg=yellow>affected — see the message</>' : 'not changed');
        if ($e->hint !== '') {
            $lines[] = '  Fix: ' . self::escape($masker->mask($e->hint));
        }
        if ($e->logPath !== null) {
            $lines[] = '  Log: ' . $e->logPath;
        }

        return $lines;
    }

    public static function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }
}
