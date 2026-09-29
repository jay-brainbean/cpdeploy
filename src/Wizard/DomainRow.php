<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Cpdeploy\Cpanel\Domain;

/**
 * One row of wizard step 4: a domain, its folder, and whether it can be used.
 */
final class DomainRow
{
    public function __construct(
        public readonly Domain $domain,
        public readonly string $docroot,
        /** empty, N items, app found, symlink, used by site "x", unavailable: … */
        public readonly string $status,
        public readonly bool $available,
        /** The full DOC-01 message when unavailable. */
        public readonly ?string $problem = null,
        /** An existing Laravel app to offer importing. */
        public readonly ?string $app = null,
    ) {
    }
}
