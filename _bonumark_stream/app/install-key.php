<?php

/**
 * First-run setup key.
 *
 * Until installation finishes, the installer accepts no settings, opens no
 * database connection, and makes no network request for a visitor who has
 * not entered the setup key. The key is written to a file in the private
 * _bonumark_stream folder, so reading it takes the same file access (FTP or
 * a hosting file manager) that uploaded the package. No shell is needed.
 *
 * The key is never shown in a page. Installation deletes the key file.
 */

function bms_install_key_path(): string
{
    return bms_root_path('setup-key.php');
}

function bms_install_key_attempts_path(): string
{
    return bms_root_path('tmp/setup-key-attempts.json');
}

/**
 * Wrong keys are limited per network: an IPv4 address, or the /64 network of
 * an IPv6 address. A network that enters five wrong keys within 15 minutes
 * must wait until the oldest of them is 15 minutes old.
 *
 * @return array{window_seconds:int,free_failures:int,max_clients:int}
 */
function bms_install_key_policy(): array
{
    return ['window_seconds' => 900, 'free_failures' => 5, 'max_clients' => 1000];
}

/** Letters and digits that cannot be misread: no 0, O, 1, I, or L. */
function bms_install_key_alphabet(): string
{
    return 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
}

/** A new key: six groups of four characters, about 118 bits. */
function bms_install_key_generate(): string
{
    $alphabet = bms_install_key_alphabet();
    $last = strlen($alphabet) - 1;
    $groups = [];
    for ($group = 0; $group < 6; $group++) {
        $characters = '';
        for ($index = 0; $index < 4; $index++) {
            $characters .= $alphabet[random_int(0, $last)];
        }
        $groups[] = $characters;
    }
    return implode('-', $groups);
}

/** Uppercase with spaces and dashes removed, so a pasted key compares exactly. */
function bms_install_key_normalize(string $key): string
{
    return strtoupper(preg_replace('/[\s-]+/', '', $key) ?? '');
}

function bms_install_key_fingerprint(string $key): string
{
    return hash('sha256', 'bonumark-setup-key|' . bms_install_key_normalize($key));
}

/**
 * The key file is a PHP file that ends the request with a 404, so even a
 * server that is missing the private-folder deny rules does not show it.
 */
function bms_install_key_file_contents(string $key): string
{
    return "<?php\n"
        . "/*\n"
        . " * Bonumark Stream setup key\n"
        . " *\n"
        . " * Setup key: " . $key . "\n"
        . " *\n"
        . " * The installer asks for this key before it accepts any settings.\n"
        . " * Copy the key above into the installer page in your browser.\n"
        . " *\n"
        . " * Bonumark Stream deletes this file when installation finishes.\n"
        . " * To get a new key, delete this file and reload the installer.\n"
        . " */\n"
        . "http_response_code(404);\n"
        . "exit;\n";
}

/** The current key, normalized, or null when there is no readable key file. */
function bms_install_key_read(): ?string
{
    $path = bms_install_key_path();
    clearstatcache(true, $path);
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $contents = @file_get_contents($path, false, null, 0, 8192);
    if (!is_string($contents) || preg_match('/^ \* Setup key: ([A-Za-z0-9-]{1,64})\s*$/m', $contents, $match) !== 1) {
        return null;
    }
    $key = bms_install_key_normalize((string)$match[1]);
    $alphabet = preg_quote(bms_install_key_alphabet(), '/');
    return strlen($key) === 24 && preg_match('/^[' . $alphabet . ']+$/', $key) === 1 ? $key : null;
}

/**
 * Return the current key, writing a new key file when none can be read.
 * Returns null when the private folder cannot hold the key file.
 */
function bms_install_key_ensure(): ?string
{
    $existing = bms_install_key_read();
    if ($existing !== null) {
        return $existing;
    }
    $path = bms_install_key_path();
    $contents = bms_install_key_file_contents(bms_install_key_generate());
    // Write the whole file first, then move it into place, so a reader never
    // sees a partly written key.
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($temporary);
        return null;
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        return null;
    }
    return bms_install_key_read();
}

/**
 * Check a submitted key and record the outcome for the client's network.
 *
 * A network past the wrong-key limit is refused before the key is compared.
 * Attempts are stored under an HMAC of the network keyed by the current
 * setup key, so the file holds no addresses, and a new key starts the limit
 * over. When attempts cannot be recorded, nothing is compared.
 *
 * @return 'accepted'|'rejected'|'limited'|'unavailable'
 */
function bms_install_key_check(string $submitted, string $key, string $clientAddress, ?int $now = null): string
{
    $policy = bms_install_key_policy();
    $now = $now ?? time();
    $path = bms_install_key_attempts_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        return 'unavailable';
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return 'unavailable';
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            return 'unavailable';
        }
        rewind($handle);
        $raw = stream_get_contents($handle);
        $stored = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        $stored = is_array($stored) ? $stored : [];
        $cutoff = $now - (int)$policy['window_seconds'];
        $clients = [];
        foreach ($stored as $client => $failures) {
            if (!is_string($client) || preg_match('/^[a-f0-9]{64}$/', $client) !== 1 || !is_array($failures)) {
                continue;
            }
            $recent = array_values(array_filter(
                array_map('intval', $failures),
                static fn(int $failedAt): bool => $failedAt > $cutoff
            ));
            if ($recent !== []) {
                $clients[$client] = $recent;
            }
        }

        $write = static function (array $clients) use ($handle, $policy): bool {
            // Keep the store bounded during distributed attempts.
            if (count($clients) > (int)$policy['max_clients']) {
                uasort($clients, static fn(array $left, array $right): int => max($right) <=> max($left));
                $clients = array_slice($clients, 0, (int)$policy['max_clients'], true);
            }
            $encoded = json_encode($clients, JSON_UNESCAPED_SLASHES);
            return is_string($encoded) && ftruncate($handle, 0) && rewind($handle)
                && fwrite($handle, $encoded) === strlen($encoded) && fflush($handle);
        };

        $client = hash_hmac('sha256', bms_client_network_key($clientAddress), 'bonumark-setup-key|' . $key);
        $failures = $clients[$client] ?? [];
        if (count($failures) >= (int)$policy['free_failures']) {
            return 'limited';
        }
        // Count the attempt as a failure before comparing. If it cannot be
        // stored, the key is not compared at all.
        $failures[] = $now;
        $clients[$client] = $failures;
        if (!$write($clients)) {
            return 'unavailable';
        }
        if (!hash_equals($key, bms_install_key_normalize($submitted))) {
            return 'rejected';
        }
        unset($clients[$client]);
        $write($clients);
        return 'accepted';
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Remove the key once installation has finished. installed.lock already
 * refuses the installer; a key file that cannot be deleted is overwritten
 * with an unrelated key so the old one is useless.
 */
function bms_install_key_retire(): void
{
    $path = bms_install_key_path();
    clearstatcache(true, $path);
    if (is_file($path) && !@unlink($path)) {
        @file_put_contents($path, bms_install_key_file_contents(bms_install_key_generate()));
        error_log('Bonumark Stream could not delete _bonumark_stream/setup-key.php after installation. Delete the file manually.');
    }
    @unlink(bms_install_key_attempts_path());
}
