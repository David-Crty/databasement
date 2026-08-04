<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a user-supplied service endpoint (S3, STS) before it is handed to an
 * SDK that will fetch it server-side.
 *
 * Private and loopback addresses stay allowed on purpose: pointing a volume at
 * an on-premise MinIO is a first-class use case here. What is refused is the
 * link-local range, which serves no storage endpoint and is how cloud instance
 * metadata (and its IAM credentials) is reached.
 *
 * This narrows the reachable surface, it does not eliminate SSRF: DNS may still
 * change between validation and use. Egress restrictions remain the real control.
 */
readonly class SafeEndpointUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            $fail(__('The :attribute must be a valid URL including a scheme and host.'));

            return;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            $fail(__('The :attribute must use the http or https scheme.'));

            return;
        }

        foreach ($this->addressesFor($parts['host']) as $address) {
            if ($this->isLinkLocal($address)) {
                $fail(__('The :attribute must not resolve to a link-local or instance metadata address.'));

                return;
            }
        }
    }

    /**
     * Resolution is best-effort: a host this server cannot resolve is not
     * rejected, since the backup worker may resolve it differently.
     *
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];

        // gethostbynamel() is IPv4-only, so an AAAA-only host would otherwise
        // resolve to nothing and skip the check entirely.
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    private function isLinkLocal(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        // Unwrap ::ffff:a.b.c.d so a mapped address cannot skip the IPv4 check.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\x00", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        if (strlen($packed) === 4) {
            return str_starts_with($packed, "\xa9\xfe"); // 169.254.0.0/16
        }

        // fe80::/10, plus the IPv6 instance metadata address used by EC2.
        return (ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0x80)
            || $packed === inet_pton('fd00:ec2::254');
    }
}
