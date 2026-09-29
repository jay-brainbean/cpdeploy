<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Closure;
use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Support\Environment;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;

/**
 * Opens text in the user's editor (ENV-07): a private temp copy (600) in tmp/,
 * then ui.editor → $VISUAL → $EDITOR → nano → vi. Invalid results offer
 * *Edit again* / *Discard*. The temp file is deleted in every case.
 */
final class Editor
{
    public function __construct(
        private readonly Shell $shell,
        private readonly Fs $fs,
        private readonly GlobalConfig $config,
        private readonly Environment $environment,
    ) {
    }

    /**
     * The edited text, or null when the user discarded it or changed nothing.
     *
     * @param Closure(string): ?string $validate an error message, or null when valid
     */
    public function edit(string $text, string $purpose, Closure $validate, Asker $asker, Reporter $reporter): ?string
    {
        $tmp = $this->fs->tempFile($purpose, $text);
        try {
            while (true) {
                $this->shell->run([...$this->command(), $tmp], (new RunOptions(timeout: null))->tty());
                $edited = (string) @file_get_contents($tmp);
                if ($edited === $text) {
                    return null;
                }
                $error = $validate($edited);
                if ($error === null) {
                    return $edited;
                }
                $reporter->warn($error);
                if ($asker->select('The file has problems', ['edit' => 'Edit again', 'discard' => 'Discard my changes'], 'edit') === 'discard') {
                    return null;
                }
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * ui.editor → $VISUAL → $EDITOR → nano → vi.
     *
     * @return list<string>
     */
    public function command(): array
    {
        foreach ([$this->config->editor(), $this->environment->get('VISUAL'), $this->environment->get('EDITOR')] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return array_values(array_filter(preg_split('/\s+/', trim($candidate)) ?: [], static fn (string $p): bool => $p !== ''));
            }
        }

        return [$this->shell->which('nano') !== null ? 'nano' : 'vi'];
    }
}
