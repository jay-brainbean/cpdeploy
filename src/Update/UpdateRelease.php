<?php

declare(strict_types=1);

namespace Cpdeploy\Update;

/**
 * One GitHub release of cpdeploy, as self-update needs it.
 */
final class UpdateRelease
{
    public function __construct(
        public readonly string $tag,
        public readonly string $version,
        public readonly bool $prerelease,
        public readonly string $notes,
        /** API URLs of the assets (work for private repos with the token). */
        public readonly ?string $pharUrl,
        public readonly ?string $shaUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $row a GitHub release object
     */
    public static function fromApi(array $row): ?self
    {
        $tag = $row['tag_name'] ?? null;
        if (!is_string($tag) || $tag === '') {
            return null;
        }
        $urls = [];
        foreach (is_array($row['assets'] ?? null) ? $row['assets'] : [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && is_string($asset['url'] ?? null)) {
                $urls[$asset['name']] = $asset['url'];
            }
        }

        return new self(
            $tag,
            ltrim($tag, 'v'),
            ($row['prerelease'] ?? false) === true,
            is_string($row['body'] ?? null) ? $row['body'] : '',
            $urls[SelfUpdate::PHAR] ?? null,
            $urls[SelfUpdate::SHA] ?? null,
        );
    }
}
