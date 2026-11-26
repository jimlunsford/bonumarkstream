<?php

/**
 * Shared outbound destination guard.
 *
 * Every server-side fetch of an untrusted URL (link previews, remote media
 * imports, and ActivityPub federation) accepts only public unicast
 * destinations. The decision uses the explicit CIDR denylists below. It does
 * not depend on PHP filter flags, whose coverage differs between PHP versions.
 *
 * IPv6 forms that carry an IPv4 destination (IPv4-mapped ::ffff:0:0/96, NAT64
 * 64:ff9b::/96, and 6to4 2002::/16) are unwrapped and the IPv4 destination is
 * checked instead.
 */

/**
 * Non-public IPv4 ranges.
 *
 * @return list<array{0:string,1:string}> CIDR and label pairs.
 */
function bms_network_ipv4_denylist(): array
{
    return [
        ['0.0.0.0/8', 'this network'],
        ['10.0.0.0/8', 'private use'],
        ['100.64.0.0/10', 'shared address space'],
        ['127.0.0.0/8', 'loopback'],
        ['169.254.0.0/16', 'link-local'],
        ['172.16.0.0/12', 'private use'],
        ['192.0.0.0/24', 'IETF protocol assignments'],
        ['192.0.2.0/24', 'documentation'],
        ['192.88.99.0/24', 'deprecated 6to4 relay anycast'],
        ['192.168.0.0/16', 'private use'],
        ['198.18.0.0/15', 'benchmarking'],
        ['198.51.100.0/24', 'documentation'],
        ['203.0.113.0/24', 'documentation'],
        ['224.0.0.0/4', 'multicast'],
        ['240.0.0.0/4', 'reserved'],
    ];
}

/**
 * Non-public IPv6 ranges, checked after IPv4-carrying forms are unwrapped.
 *
 * Specific ranges come first so the reported reason is precise. The final
 * three entries cover all space outside global unicast 2000::/3.
 *
 * @return list<array{0:string,1:string}> CIDR and label pairs.
 */
function bms_network_ipv6_denylist(): array
{
    return [
        ['::/128', 'unspecified'],
        ['::1/128', 'loopback'],
        ['::ffff:0:0:0/96', 'IPv4-translated'],
        ['::/96', 'deprecated IPv4-compatible'],
        ['64:ff9b:1::/48', 'local-use IPv4/IPv6 translation'],
        ['100::/64', 'discard-only'],
        ['2001::/23', 'IETF protocol assignments'],
        ['2001:db8::/32', 'documentation'],
        ['3fff::/20', 'documentation'],
        ['5f00::/16', 'segment routing'],
        ['fc00::/7', 'unique local'],
        ['fe80::/10', 'link-local'],
        ['fec0::/10', 'deprecated site-local'],
        ['ff00::/8', 'multicast'],
        ['::/3', 'reserved'],
        ['4000::/2', 'reserved'],
        ['8000::/1', 'reserved'],
    ];
}

/**
 * IPv6 prefixes that carry an IPv4 destination, with the byte offset of the
 * embedded IPv4 address.
 *
 * @return list<array{0:string,1:string,2:int}> CIDR, label, and offset.
 */
function bms_network_ipv4_carrying_prefixes(): array
{
    return [
        ['::ffff:0:0/96', 'IPv4-mapped', 12],
        ['64:ff9b::/96', 'NAT64', 12],
        ['2002::/16', '6to4', 2],
    ];
}

/** True when a packed address belongs to a CIDR range of the same family. */
function bms_network_cidr_contains(string $packedAddress, string $cidr): bool
{
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) {
        return false;
    }
    $packedNetwork = @inet_pton($parts[0]);
    $bits = (int)$parts[1];
    if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedAddress) || $bits < 0 || $bits > strlen($packedAddress) * 8) {
        return false;
    }

    $fullBytes = intdiv($bits, 8);
    if ($fullBytes > 0 && substr($packedAddress, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
        return false;
    }
    $remainingBits = $bits % 8;
    if ($remainingBits === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($packedAddress[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask);
}

/** Return the denylist label for a packed IPv4 address, or '' when public. */
function bms_network_ipv4_block_reason(string $packed): string
{
    foreach (bms_network_ipv4_denylist() as [$cidr, $label]) {
        if (bms_network_cidr_contains($packed, $cidr)) {
            return $label;
        }
    }
    return '';
}

/**
 * Explain why an IP address is not an acceptable outbound destination.
 *
 * Returns '' for a public unicast address. Anything that is not a literal
 * IPv4 or IPv6 address is refused.
 */
function bms_network_ip_block_reason(string $ip): string
{
    $ip = trim($ip, "[] \t\n\r\0\x0B");
    $packed = $ip !== '' ? @inet_pton($ip) : false;
    if (!is_string($packed)) {
        return 'not an IP address';
    }

    if (strlen($packed) === 4) {
        return bms_network_ipv4_block_reason($packed);
    }
    if (strlen($packed) !== 16) {
        return 'not an IP address';
    }

    foreach (bms_network_ipv4_carrying_prefixes() as [$cidr, $label, $offset]) {
        if (bms_network_cidr_contains($packed, $cidr)) {
            $reason = bms_network_ipv4_block_reason(substr($packed, $offset, 4));
            return $reason === '' ? '' : $reason . ' (' . $label . ')';
        }
    }

    foreach (bms_network_ipv6_denylist() as [$cidr, $label]) {
        if (bms_network_cidr_contains($packed, $cidr)) {
            return $label;
        }
    }
    return '';
}

/** True only for a literal public unicast IPv4 or IPv6 address. */
function bms_network_ip_is_public(string $ip): bool
{
    return bms_network_ip_block_reason($ip) === '';
}

/** True for host names that always name the local machine or network. */
function bms_network_host_is_local_name(string $host): bool
{
    $host = strtolower(rtrim(trim($host, " \t\n\r\0\x0B[]"), '.'));
    if ($host === '' || $host === 'localhost') {
        return true;
    }
    foreach (['.localhost', '.local', '.internal', '.home.arpa'] as $suffix) {
        if (str_ends_with($host, $suffix)) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve a host to its addresses and return them only when every address is
 * public. Returns [] for local names, unresolvable hosts, or any non-public
 * address. The caller pins the connection to the returned addresses.
 *
 * @return list<string>
 */
function bms_network_public_host_ips(string $host): array
{
    $host = strtolower(rtrim(trim($host, " \t\n\r\0\x0B[]"), '.'));
    if (bms_network_host_is_local_name($host)) {
        return [];
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ips[] = $host;
    } else {
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    foreach (['ip', 'ipv6'] as $key) {
                        if (!empty($record[$key])) {
                            $ips[] = (string)$record[$key];
                        }
                    }
                }
            }
        }
        if (!$ips && function_exists('gethostbynamel')) {
            $aRecords = @gethostbynamel($host);
            if (is_array($aRecords)) {
                $ips = array_merge($ips, $aRecords);
            }
        }
    }

    $public = [];
    foreach (array_unique($ips) as $ip) {
        if (!bms_network_ip_is_public((string)$ip)) {
            return [];
        }
        $public[] = (string)$ip;
    }
    return array_values(array_unique($public));
}
