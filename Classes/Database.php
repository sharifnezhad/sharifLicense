<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles database operations and AES-256-CBC encryption for the Sharif License plugin.
 */
class Database
{

    /** @var string Option key for the AES encryption key */
    private const ENCRYPTION_KEY_OPTION = 'sharif_license_encryption_key';

    /** @var string Option key for the API secret key */
    private const SECRET_KEY_OPTION = 'sharif_license_secret_key';

    /** @var string Base table name for licenses (without prefix) */
    private const LICENSES_TABLE = 'licenses';

    /** @var string Base table name for license IPs (without prefix) */
    private const LICENSE_IPS_TABLE = 'license_ips';

    /**
     * Create the wp_licenses and wp_license_ips tables and generate keys on plugin activation.
     *
     * @return void
     */
    public static function createTable(): void
    {
        global $wpdb;

        $licensesTable = $wpdb->prefix . self::LICENSES_TABLE;
        $licenseIpsTable = $wpdb->prefix . self::LICENSE_IPS_TABLE;
        $charsetCollate = $wpdb->get_charset_collate();

        // domain_hash stores a SHA-256 of the plaintext domain for fast unique lookup
        $sqlLicenses = "CREATE TABLE {$licensesTable} (
            id INT NOT NULL AUTO_INCREMENT,
            name TEXT NOT NULL,
            license_key TEXT NOT NULL,
            domain TEXT NOT NULL,
            domain_hash VARCHAR(64) NOT NULL,
            expired_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY domain_hash (domain_hash)
        ) {$charsetCollate};";

        $sqlLicenseIps = "CREATE TABLE {$licenseIpsTable} (
            id INT NOT NULL AUTO_INCREMENT,
            license_id INT NOT NULL,
            ip TEXT NOT NULL,
            PRIMARY KEY (id),
            KEY license_id (license_id)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sqlLicenses);
        dbDelta($sqlLicenseIps);

        self::generateKeysIfNotExist();
        self::registerRewriteRule();
        flush_rewrite_rules();
    }

    /**
     * Flush rewrite rules on plugin deactivation.
     *
     * @return void
     */
    public static function flushRewriteRules(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Generate encryption and secret keys if they don't already exist.
     *
     * @return void
     */
    private static function generateKeysIfNotExist(): void
    {
        if (!get_option(self::ENCRYPTION_KEY_OPTION)) {
            update_option(self::ENCRYPTION_KEY_OPTION, bin2hex(random_bytes(16)));
        }
        if (!get_option(self::SECRET_KEY_OPTION)) {
            update_option(self::SECRET_KEY_OPTION, bin2hex(random_bytes(20)));
        }
    }

    /**
     * Register the /api/validate rewrite rule.
     *
     * @return void
     */
    public static function registerRewriteRule(): void
    {
        add_rewrite_rule('^api/validate/?$', 'index.php?sharif_license_validate=1', 'top');
    }

    /**
     * Retrieve the AES encryption key from wp_options.
     *
     * @return string
     */
    public static function getEncryptionKey(): string
    {
        return (string)get_option(self::ENCRYPTION_KEY_OPTION, '');
    }

    /**
     * Retrieve the API secret key from wp_options.
     *
     * @return string
     */
    public static function getSecretKey(): string
    {
        return (string)get_option(self::SECRET_KEY_OPTION, '');
    }

    /**
     * Encrypt a plaintext value with AES-256-CBC using a deterministic IV.
     *
     * The IV is derived from the encryption key so the same plaintext always
     * produces the same ciphertext, enabling direct database WHERE lookups.
     *
     * @param string $plaintext Value to encrypt.
     * @param string $encryptionKey 32-byte AES key.
     *
     * @return string Base64-encoded ciphertext.
     */
    public static function encrypt(string $plaintext, string $encryptionKey): string
    {
        $deterministicIV = substr(hash('sha256', $encryptionKey), 0, 16);
        return base64_encode(openssl_encrypt($plaintext, 'AES-256-CBC', $encryptionKey, 0, $deterministicIV));
    }

    /**
     * Decrypt a base64-encoded AES-256-CBC ciphertext.
     *
     * @param string $encryptedValue Base64-encoded ciphertext.
     * @param string $encryptionKey 32-byte AES key.
     *
     * @return string Decrypted plaintext, or empty string on failure.
     */
    public static function decrypt(string $encryptedValue, string $encryptionKey): string
    {
        $deterministicIV = substr(hash('sha256', $encryptionKey), 0, 16);
        $decryptedValue = openssl_decrypt(base64_decode($encryptedValue), 'AES-256-CBC', $encryptionKey, 0, $deterministicIV);
        return $decryptedValue !== false ? $decryptedValue : '';
    }

    /**
     * Validate a domain name format.
     *
     * Allows standard hostnames like example.com or sub.example.com.
     *
     * @param string $domain Domain to validate.
     *
     * @return bool True if valid, false otherwise.
     */
    public static function isValidDomain(string $domain): bool
    {
        return (bool)preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domain);
    }

    /**
     * Validate an IPv4 or IPv6 address.
     *
     * @param string $ipAddress IP address to validate.
     *
     * @return bool True if valid, false otherwise.
     */
    public static function isValidIp(string $ipAddress): bool
    {
        return filter_var($ipAddress, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate a license key: only printable ASCII, no spaces, no Persian characters.
     *
     * @param string $licenseKey License key to validate.
     *
     * @return bool True if the key contains only allowed characters.
     */
    public static function isValidLicenseKey(string $licenseKey): bool
    {
        return (bool)preg_match('/^[\x21-\x7E]+$/', $licenseKey);
    }

    /**
     * Find the license ID that already owns a given IP address.
     *
     * IPs are encrypted deterministically, so the same plaintext always maps
     * to the same ciphertext and can be matched directly in the database.
     *
     * @param string $ipAddress Plaintext IP to look up.
     * @param int|null $excludeLicenseId License ID to skip (used during edit).
     *
     * @return int|null The owning license ID, or null if the IP is free.
     */
    public static function findLicenseIdByIp(string $ipAddress, ?int $excludeLicenseId = null): ?int
    {
        global $wpdb;

        $encryptionKey = self::getEncryptionKey();
        $encryptedIp = self::encrypt(trim($ipAddress), $encryptionKey);
        $licenseIpsTable = $wpdb->prefix . self::LICENSE_IPS_TABLE;

        if ($excludeLicenseId) {
            $ownerId = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT license_id FROM {$licenseIpsTable} WHERE ip = %s AND license_id != %d LIMIT 1",
                    $encryptedIp,
                    $excludeLicenseId
                )
            );
        } else {
            $ownerId = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT license_id FROM {$licenseIpsTable} WHERE ip = %s LIMIT 1",
                    $encryptedIp
                )
            );
        }

        return $ownerId ? (int)$ownerId : null;
    }

    /**
     * Check whether a domain is already registered to another license.
     *
     * Uses domain_hash for fast lookup without decrypting all records.
     *
     * @param string $domain Plaintext domain to check.
     * @param int|null $excludeLicenseId License ID to skip (used during edit to allow same domain).
     *
     * @return bool True if the domain is already taken.
     */
    public static function isDomainTaken(string $domain, ?int $excludeLicenseId = null): bool
    {
        global $wpdb;

        $licensesTable = $wpdb->prefix . self::LICENSES_TABLE;
        $domainHash = hash('sha256', strtolower(trim($domain)));

        if ($excludeLicenseId) {
            $existingId = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$licensesTable} WHERE domain_hash = %s AND id != %d LIMIT 1",
                    $domainHash,
                    $excludeLicenseId
                )
            );
        } else {
            $existingId = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$licensesTable} WHERE domain_hash = %s LIMIT 1",
                    $domainHash
                )
            );
        }

        return !empty($existingId);
    }

    /**
     * Insert a new license along with its IP addresses.
     *
     * @param string $name Plaintext license name/label.
     * @param string $licenseKey Plaintext license key.
     * @param string $domain Plaintext domain.
     * @param string[] $ipAddresses Array of plaintext IP addresses.
     * @param string $expiredDate Expiry date (Y-m-d).
     *
     * @return int|false Inserted license ID or false on failure.
     */
    public static function insertLicense(string $name, string $licenseKey, string $domain, array $ipAddresses, string $expiredDate)
    {
        global $wpdb;

        $encryptionKey = self::getEncryptionKey();
        $licensesTable = $wpdb->prefix . self::LICENSES_TABLE;
        $normalizedDomain = strtolower(trim($domain));

        $result = $wpdb->insert(
            $licensesTable,
            [
                'name' => $name,
                'license_key' => self::encrypt($licenseKey, $encryptionKey),
                'domain' => self::encrypt($normalizedDomain, $encryptionKey),
                'domain_hash' => hash('sha256', $normalizedDomain),
                'expired_date' => $expiredDate,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$result) {
            return false;
        }

        $licenseId = $wpdb->insert_id;
        self::replaceIpsForLicense($licenseId, $ipAddresses, $encryptionKey);

        return $licenseId;
    }

    /**
     * Update an existing license and replace all its IP addresses.
     *
     * @param int $licenseId The license record ID.
     * @param string $name New plaintext license name/label.
     * @param string $licenseKey New plaintext license key.
     * @param string $domain New plaintext domain.
     * @param string[] $ipAddresses New array of plaintext IP addresses.
     * @param string $expiredDate New expiry date (Y-m-d).
     *
     * @return bool True on success, false on failure.
     */
    public static function updateLicense(int $licenseId, string $name, string $licenseKey, string $domain, array $ipAddresses, string $expiredDate): bool
    {
        global $wpdb;

        $encryptionKey = self::getEncryptionKey();
        $licensesTable = $wpdb->prefix . self::LICENSES_TABLE;
        $normalizedDomain = strtolower(trim($domain));

        $result = $wpdb->update(
            $licensesTable,
            [
                'name' => $name,
                'license_key' => self::encrypt($licenseKey, $encryptionKey),
                'domain' => self::encrypt($normalizedDomain, $encryptionKey),
                'domain_hash' => hash('sha256', $normalizedDomain),
                'expired_date' => $expiredDate,
            ],
            ['id' => $licenseId],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            return false;
        }

        self::replaceIpsForLicense($licenseId, $ipAddresses, $encryptionKey);

        return true;
    }

    /**
     * Delete all IPs for a license and insert the new set.
     *
     * @param int $licenseId License record ID.
     * @param string[] $ipAddresses Array of plaintext IPs to store.
     * @param string $encryptionKey AES encryption key.
     *
     * @return void
     */
    private static function replaceIpsForLicense(int $licenseId, array $ipAddresses, string $encryptionKey): void
    {
        global $wpdb;

        $licenseIpsTable = $wpdb->prefix . self::LICENSE_IPS_TABLE;

        $wpdb->delete($licenseIpsTable, ['license_id' => $licenseId], ['%d']);

        foreach ($ipAddresses as $ipAddress) {
            $trimmedIp = trim($ipAddress);
            if (empty($trimmedIp)) {
                continue;
            }
            $wpdb->insert(
                $licenseIpsTable,
                [
                    'license_id' => $licenseId,
                    'ip' => self::encrypt($trimmedIp, $encryptionKey),
                ],
                ['%d', '%s']
            );
        }
    }

    /**
     * Find a license record by license key, including its list of allowed IPs.
     *
     * Returns the raw (encrypted) domain/ip fields — decryption happens in callers
     * that need display. The ip_list property contains decrypted IPs for comparison.
     *
     * @param string $licenseKey Plaintext license key to search for.
     *
     * @return object|null License row with 'ip_list' array, or null if not found.
     */
    public static function findByLicenseKey(string $licenseKey): ?object
    {
        global $wpdb;

        $encryptionKey = self::getEncryptionKey();
        $encryptedLicenseKey = self::encrypt($licenseKey, $encryptionKey);
        $licensesTable = $wpdb->prefix . self::LICENSES_TABLE;

        $licenseRecord = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$licensesTable} WHERE license_key = %s LIMIT 1",
                $encryptedLicenseKey
            )
        );

        if (!$licenseRecord) {
            return null;
        }

        $licenseRecord->ip_list = self::getDecryptedIpsByLicenseId((int)$licenseRecord->id, $encryptionKey);

        return $licenseRecord;
    }

    /**
     * Get a single license by ID with all fields decrypted, for the edit form.
     *
     * @param int $licenseId The license primary key.
     *
     * @return object|null License object with decrypted fields and ip_list, or null.
     */
    public static function getLicenseById(int $licenseId): ?object
    {
        global $wpdb;

        $encryptionKey = self::getEncryptionKey();
        $licensesTable = $wpdb->prefix . self::LICENSES_TABLE;

        $licenseRecord = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$licensesTable} WHERE id = %d LIMIT 1", $licenseId)
        );

        if (!$licenseRecord) {
            return null;
        }

        $licenseRecord->license_key = self::decrypt($licenseRecord->license_key, $encryptionKey);
        $licenseRecord->domain = self::decrypt($licenseRecord->domain, $encryptionKey);
        $licenseRecord->ip_list = self::getDecryptedIpsByLicenseId((int)$licenseRecord->id, $encryptionKey);

        return $licenseRecord;
    }

    /**
     * Retrieve all licenses with decrypted fields and their IP lists.
     *
     * @return object[] Array of license objects.
     */
    public static function getAllLicenses(): array
    {
        global $wpdb;

        $licensesTable = $wpdb->prefix . self::LICENSES_TABLE;
        $encryptionKey = self::getEncryptionKey();
        $rows = $wpdb->get_results("SELECT * FROM {$licensesTable} ORDER BY created_at DESC");

        if (!$rows) {
            return [];
        }

        foreach ($rows as $row) {
            $row->license_key = self::decrypt($row->license_key, $encryptionKey);
            $row->domain = self::decrypt($row->domain, $encryptionKey);
            $row->ip_list = self::getDecryptedIpsByLicenseId((int)$row->id, $encryptionKey);
        }

        return $rows;
    }

    /**
     * Retrieve and decrypt all IP addresses belonging to a license.
     *
     * @param int $licenseId The license record ID.
     * @param string $encryptionKey AES encryption key.
     *
     * @return string[] Array of decrypted IP address strings.
     */
    private static function getDecryptedIpsByLicenseId(int $licenseId, string $encryptionKey): array
    {
        global $wpdb;

        $licenseIpsTable = $wpdb->prefix . self::LICENSE_IPS_TABLE;
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT ip FROM {$licenseIpsTable} WHERE license_id = %d", $licenseId)
        );

        if (!$rows) {
            return [];
        }

        $decryptedIps = [];
        foreach ($rows as $row) {
            $decryptedIps[] = self::decrypt($row->ip, $encryptionKey);
        }
        return $decryptedIps;
    }

    /**
     * Delete a license and all its associated IP records.
     *
     * @param int $licenseId The license record ID.
     *
     * @return bool True on success, false on failure.
     */
    public static function deleteLicense(int $licenseId): bool
    {
        global $wpdb;

        $licensesTable = $wpdb->prefix . self::LICENSES_TABLE;
        $licenseIpsTable = $wpdb->prefix . self::LICENSE_IPS_TABLE;

        $wpdb->delete($licenseIpsTable, ['license_id' => $licenseId], ['%d']);
        $result = $wpdb->delete($licensesTable, ['id' => $licenseId], ['%d']);

        return $result !== false;
    }
}
