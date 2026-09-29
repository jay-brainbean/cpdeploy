<?php

declare(strict_types=1);

namespace Cpdeploy\GitHub;

use RuntimeException;

/**
 * GitHub answered 422 to adding a deploy key: the key is already used somewhere.
 * Internal: DeployKeyService handles it by generating a new key (GIT-17).
 */
final class KeyInUseException extends RuntimeException
{
}
