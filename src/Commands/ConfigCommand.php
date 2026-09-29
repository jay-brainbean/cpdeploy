<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * `cpdeploy config <site> show | get <key.path> | set <key.path> <value> | edit`
 * (§10.2). Every change is validated (§8.3) before it is written.
 */
#[AsCommand(name: 'config', description: 'Show or change a site\'s settings (site.yml)')]
final class ConfigCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::REQUIRED, 'Site name')
            ->addArgument('action', InputArgument::OPTIONAL, 'show, get, set or edit', 'show')
            ->addArgument('key', InputArgument::OPTIONAL, 'Setting, e.g. php.version')
            ->addArgument('value', InputArgument::OPTIONAL, 'New value (set)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $registry = $this->services->sites();
        $action = (string) $input->getArgument('action');
        $key = $input->getArgument('key');

        switch ($action) {
            case 'show':
                $output->writeln(SiteRegistry::dump($registry->load($site)), OutputInterface::OUTPUT_RAW);

                return 0;

            case 'get':
                $path = $this->key($key, $site);
                $config = $registry->load($site);
                $missing = new \stdClass();
                $value = $config->get($path, $missing);
                if ($value === $missing) {
                    throw new CpdeployException(ErrorCode::USAGE, "There is no setting {$path}", "See them all with: cpdeploy config {$site} show");
                }
                $output->writeln(is_array($value) || is_bool($value) || $value === null ? rtrim(Yaml::dump($value, 4, 2)) : (string) $value, OutputInterface::OUTPUT_RAW);

                return 0;

            case 'set':
                $path = $this->key($key, $site);
                $raw = $input->getArgument('value');
                if (!is_string($raw)) {
                    throw new CpdeployException(ErrorCode::USAGE, 'Which value? Pass it after the setting', "Example: cpdeploy config {$site} set releases.keep 8");
                }
                $lock = $this->lock($site);
                try {
                    $config = $registry->load($site);
                    $updated = self::apply($config, $path, $raw);
                    $registry->save($updated);
                } finally {
                    $lock->release();
                }
                $output->writeln(sprintf('<fg=green>%s</> %s saved', $this->services->theme()->symbol('ok'), $path));

                return 0;

            case 'edit':
                return $this->edit($site, $input, $output);

            default:
                throw new CpdeployException(ErrorCode::USAGE, "Unknown action: {$action}", "Use: cpdeploy config {$site} show | get <key> | set <key> <value> | edit");
        }
    }

    /**
     * Parses $raw as a YAML value, keeping text where the current value is text
     * (so `set php.version 8.3` stays "8.3"). A new domain.docroot resets
     * converted_at, so the new path is converted at the next go-live (DOC-01 g).
     */
    public static function apply(SiteConfig $config, string $path, string $raw): SiteConfig
    {
        if ($path === 'name' || $path === 'schema') {
            throw new CpdeployException(ErrorCode::USAGE, "{$path} can't be changed", 'Create a new site instead.');
        }
        $missing = new \stdClass();
        $current = $config->get($path, $missing);
        if ($current === $missing) {
            throw new CpdeployException(ErrorCode::USAGE, "There is no setting {$path}", "See them all with: cpdeploy config {$config->name()} show");
        }
        try {
            $value = Yaml::parse($raw);
        } catch (ParseException) {
            $value = $raw;
        }
        if (is_string($current) && is_scalar($value) && !is_bool($value)) {
            $value = (string) $raw;
        }
        $updated = $config->with($path, $value);
        if ($path === 'domain.docroot' && $value !== $current) {
            $updated = $updated->with('domain.converted_at', null)->with('domain.backup', null);
        }

        return $updated;
    }

    private function edit(string $site, InputInterface $input, OutputInterface $output): int
    {
        if (!$this->services->isInteractive($input)) {
            throw new CpdeployException(ErrorCode::USAGE, 'config edit needs a terminal', "Use: cpdeploy config {$site} set <key> <value>");
        }
        $registry = $this->services->sites();
        $paths = $this->services->paths();
        $fs = $this->services->fs();
        $asker = $this->services->asker($input);
        $file = $paths->siteConfig($site);
        $lock = $this->lock($site);
        $tmp = $fs->tempFile('site-yml', SiteRegistry::dump($registry->load($site)));
        try {
            while (true) {
                $this->services->shell()->run([...$this->editor(), $tmp], (new RunOptions(timeout: null))->tty());
                $raw = (string) file_get_contents($tmp);
                try {
                    $config = $registry->parse($raw, $file);
                    if ($config->name() !== $site) {
                        throw new CpdeployException(ErrorCode::CONFIG_INVALID, "name can't change (it must stay {$site})", '');
                    }
                    $registry->save($config);
                    $output->writeln(sprintf('<fg=green>%s</> site.yml saved', $this->services->theme()->symbol('ok')));

                    return 0;
                } catch (CpdeployException $e) {
                    $output->writeln(sprintf('<fg=red>%s</> %s', $this->services->theme()->symbol('fail'), $e->getMessage()));
                    if ($asker->select('The file has problems', ['edit' => 'Edit again', 'discard' => 'Discard my changes'], 'edit') === 'discard') {
                        $output->writeln('Nothing was changed.');

                        return 0;
                    }
                }
            }
        } finally {
            @unlink($tmp);
            $lock->release();
        }
    }

    /**
     * ui.editor → $VISUAL → $EDITOR → nano → vi.
     *
     * @return list<string>
     */
    private function editor(): array
    {
        $env = $this->services->environment();
        foreach ([$this->services->config()->editor(), $env->get('VISUAL'), $env->get('EDITOR')] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return array_values(array_filter(preg_split('/\s+/', trim($candidate)) ?: [], static fn (string $p): bool => $p !== ''));
            }
        }

        return [$this->services->shell()->which('nano') !== null ? 'nano' : 'vi'];
    }

    private function key(mixed $key, string $site): string
    {
        if (!is_string($key) || preg_match('/^[a-z_]+(\.[a-z_0-9]+)*$/', $key) !== 1) {
            throw new CpdeployException(ErrorCode::USAGE, 'Which setting? Pass its path, e.g. php.version', "See them all with: cpdeploy config {$site} show");
        }

        return $key;
    }

    private function lock(string $site): Lock
    {
        $this->services->sites()->load($site);

        return Lock::site($this->services->paths()->siteLock($site), $site, 'config', $this->services->system()->userName(), Version::get(), $this->services->clock());
    }
}
