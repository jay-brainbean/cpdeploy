<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;

/**
 * A GitHub repository address (§9.3 step 1, GIT-01). Accepted forms:
 *   o/r · git@github.com:o/r(.git) · https://github.com/o/r(.git) · ssh://git@ssh.github.com:443/o/r(.git)
 */
final class RepoUrl
{
    public const TRANSPORT_SSH22 = 'ssh22';
    public const TRANSPORT_SSH443 = 'ssh443';

    private const OWNER = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})';
    private const NAME = '[A-Za-z0-9._-]{1,100}';

    public function __construct(
        public readonly string $owner,
        public readonly string $name,
    ) {
    }

    public static function parse(string $input): self
    {
        $value = trim($input);
        $patterns = [
            '#^(' . self::OWNER . ')/(' . self::NAME . ')$#',
            '#^git@github\.com:(' . self::OWNER . ')/(' . self::NAME . ')$#i',
            '#^https?://(?:www\.)?github\.com/(' . self::OWNER . ')/(' . self::NAME . ')/?$#i',
            '#^ssh://git@(?:ssh\.)?github\.com(?::(?:22|443))?/(' . self::OWNER . ')/(' . self::NAME . ')$#i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $m) === 1) {
                $name = (string) preg_replace('/\.git$/i', '', $m[2]);
                if ($name !== '' && $name !== '.' && $name !== '..') {
                    return new self($m[1], $name);
                }
            }
        }

        throw new CpdeployException(
            ErrorCode::USAGE,
            "That isn't a GitHub repository address: {$value}",
            'Examples: acme/shop or git@github.com:acme/shop.git',
        );
    }

    public function fullName(): string
    {
        return $this->owner . '/' . $this->name;
    }

    /**
     * GIT-01: the remote URL for a transport.
     */
    public function remote(string $transport): string
    {
        return $transport === self::TRANSPORT_SSH443
            ? 'ssh://git@ssh.github.com:443/' . $this->fullName() . '.git'
            : 'git@github.com:' . $this->fullName() . '.git';
    }

    public function newKeyUrl(): string
    {
        return 'https://github.com/' . $this->fullName() . '/settings/keys/new';
    }

    public function keysUrl(): string
    {
        return 'https://github.com/' . $this->fullName() . '/settings/keys';
    }
}
