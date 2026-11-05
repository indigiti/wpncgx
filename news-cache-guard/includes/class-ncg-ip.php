<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

final class IP {
    public static function client_ip(): string {
        $remote = self::valid_ip((string)($_SERVER['REMOTE_ADDR'] ?? '')) ?: '0.0.0.0';
        if ((int)Settings::get('trust_cloudflare', 1) === 1 && self::in_any_cidr($remote, Settings::lines('trusted_proxy_cidrs'))) {
            $cf = self::valid_ip((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if ($cf !== null) return $cf;
        }
        return $remote;
    }

    public static function bucket(string $ip): string {
        $prefix = strpos($ip, ':') !== false ? (int)Settings::get('ipv6_prefix', 64) : (int)Settings::get('ipv4_prefix', 32);
        $network = self::network($ip, $prefix);
        return $network !== null ? $network . '/' . $prefix : $ip;
    }

    public static function valid_ip(string $ip): ?string {
        $ip = trim($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public static function in_any_cidr(string $ip, array $cidrs): bool {
        foreach ($cidrs as $cidr) {
            if (self::in_cidr($ip, $cidr)) return true;
        }
        return false;
    }

    public static function in_cidr(string $ip, string $cidr): bool {
        $cidr = trim($cidr);
        if ($cidr === '') return false;
        if (strpos($cidr, '/') === false) {
            $candidate = @inet_pton($cidr);
            $actual = @inet_pton($ip);
            return $candidate !== false && $actual !== false && hash_equals($candidate, $actual);
        }
        [$net, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($prefix === null || !is_numeric($prefix)) return false;
        $prefix = (int)$prefix;
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
        $bits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $bits) return false;
        $full = intdiv($prefix, 8);
        $rem = $prefix % 8;
        if ($full > 0 && substr($ipBin, 0, $full) !== substr($netBin, 0, $full)) return false;
        if ($rem === 0) return true;
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return ((ord($ipBin[$full]) & $mask) === (ord($netBin[$full]) & $mask));
    }

    public static function network(string $ip, int $prefix): ?string {
        $bin = @inet_pton($ip);
        if ($bin === false) return null;
        $bits = strlen($bin) * 8;
        if ($prefix < 0 || $prefix > $bits) return null;
        $bytes = array_values(unpack('C*', $bin));
        $full = intdiv($prefix, 8);
        $rem = $prefix % 8;
        for ($i = 0; $i < count($bytes); $i++) {
            if ($i < $full) continue;
            if ($i === $full && $rem > 0) {
                $bytes[$i] &= ((0xFF << (8 - $rem)) & 0xFF);
            } else {
                $bytes[$i] = 0;
            }
        }
        $packed = '';
        foreach ($bytes as $b) $packed .= chr($b);
        $out = @inet_ntop($packed);
        return $out === false ? null : $out;
    }
}
