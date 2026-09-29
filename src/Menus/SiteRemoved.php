<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use RuntimeException;

/**
 * Leaves Manage site after *Remove site*: there is nothing left to manage.
 */
final class SiteRemoved extends RuntimeException
{
}
