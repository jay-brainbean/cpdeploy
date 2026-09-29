<?php

declare(strict_types=1);

namespace Cpdeploy\Cpanel;

/**
 * The account's domains and document roots (DomainInfo::domains_data format=hash).
 */
final class DomainService
{
    /** @var list<Domain>|null */
    private ?array $cache = null;

    public function __construct(private readonly Uapi $uapi)
    {
    }

    /**
     * @return list<Domain>
     */
    public function all(bool $refresh = false): array
    {
        if ($this->cache !== null && !$refresh) {
            return $this->cache;
        }
        $data = $this->uapi->call('DomainInfo', 'domains_data', ['format' => 'hash']);
        $data = is_array($data) ? $data : [];

        $domains = [];
        if (is_array($data['main_domain'] ?? null)) {
            $domains[] = self::toDomain($data['main_domain'], Domain::MAIN, null);
        }
        $fallbackIp = $domains[0]->ip ?? '';
        foreach (['addon_domains' => Domain::ADDON, 'sub_domains' => Domain::SUB, 'parked_domains' => Domain::PARKED] as $key => $type) {
            foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $row) {
                if (is_array($row)) {
                    $domains[] = self::toDomain($row, $type, $fallbackIp);
                } elseif (is_string($row) && $row !== '') {
                    // Parked domains may be listed as plain names.
                    $domains[] = new Domain($row, $type, $domains[0]->documentRoot ?? '', $fallbackIp);
                }
            }
        }

        return $this->cache = array_values(array_filter($domains, static fn (Domain $d): bool => $d->name !== ''));
    }

    public function find(string $name): ?Domain
    {
        $name = strtolower(rtrim($name, '.'));
        foreach ($this->all() as $domain) {
            if (strtolower($domain->name) === $name) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $row
     */
    private static function toDomain(array $row, string $type, ?string $fallbackIp): Domain
    {
        $str = static fn (string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';

        return new Domain(
            $str('domain'),
            $type,
            rtrim($str('documentroot'), '/'),
            $str('ip') !== '' ? $str('ip') : (string) $fallbackIp,
            $str('phpversion') !== '' ? $str('phpversion') : null,
            $str('servername') !== '' ? $str('servername') : null,
        );
    }
}
