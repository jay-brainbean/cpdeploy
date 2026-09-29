<?php

declare(strict_types=1);

namespace Cpdeploy\GitHub;

use Closure;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;

/**
 * Set / test / remove the optional GitHub token (§9.6). Used by both
 * `cpdeploy token …` and Settings (ARC-03).
 */
final class TokenService
{
    /** GH-03 guidance, shown before asking for a token. */
    public const GUIDANCE = [
        'Create a fine-grained token at github.com → Settings → Developer settings → Fine-grained tokens:',
        '  Repository access: only the repos you deploy',
        '  Permissions: Administration: Read and write (to add and remove deploy keys);',
        '               Metadata: Read is included automatically',
        '  Expiration: your choice; cpdeploy warns 14 days before it expires.',
    ];

    /**
     * @param Closure(string): GitHubApi $api builds the API client for a token
     */
    public function __construct(
        private readonly TokenStore $store,
        private readonly Closure $api,
    ) {
    }

    /**
     * Validates the token with GET /user, then saves it. An invalid token is not saved.
     */
    public function set(string $token): GitHubUser
    {
        $token = trim($token);
        if (!TokenStore::looksValid($token)) {
            throw new CpdeployException(ErrorCode::TOKEN_INVALID, "That doesn't look like a GitHub token", 'Paste the whole token (it starts with github_pat_ for a fine-grained token).');
        }
        $user = ($this->api)($token)->user();
        $this->store->set($token);

        return $user;
    }

    public function test(): GitHubUser
    {
        $token = $this->store->get();
        if ($token === null) {
            throw new CpdeployException(ErrorCode::TOKEN_INVALID, 'No GitHub token is set', 'Optional. Add one with: cpdeploy token set');
        }

        return ($this->api)($token)->user();
    }

    public function remove(): bool
    {
        return $this->store->remove();
    }

    public function has(): bool
    {
        return $this->store->has();
    }
}
