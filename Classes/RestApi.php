<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the POST /api/validate endpoint for WHMCS license verification.
 */
class RestApi {

    public function __construct() {
        add_action( 'init', [ Database::class, 'registerRewriteRule' ] );
        add_filter( 'query_vars', [ $this, 'registerQueryVar' ] );
        add_action( 'template_redirect', [ $this, 'handleValidateRequest' ] );
    }

    /**
     * Register the custom query variable used by the /api/validate rewrite rule.
     *
     * @param string[] $queryVars Existing registered query variables.
     *
     * @return string[] Modified list with the plugin's variable appended.
     */
    public function registerQueryVar( array $queryVars ): array {
        $queryVars[] = 'sharif_license_validate';
        return $queryVars;
    }

    /**
     * Handle POST /api/validate requests.
     *
     * Checks the X-Secret-Key header, then validates the license key, domain,
     * IP address (against the allowed IP list), and expiry date.
     *
     * @return void
     */
    public function handleValidateRequest(): void {
        if ( ! get_query_var( 'sharif_license_validate' ) ) {
            return;
        }

        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            $this->sendJsonResponse( false, sharif_lang( 'invalid_request_method' ), 405 );
            return;
        }

        $providedSecretKey = $_SERVER['HTTP_X_SECRET_KEY'] ?? '';
        $storedSecretKey   = Database::getSecretKey();

        if ( empty( $providedSecretKey ) || ! hash_equals( $storedSecretKey, $providedSecretKey ) ) {
            $this->sendJsonResponse( false, sharif_lang( 'unauthorized' ), 401 );
            return;
        }

        $requestBody = json_decode( file_get_contents( 'php://input' ), true );

        $licenseKey     = sanitize_text_field( $requestBody['license_key'] ?? '' );
        $requestDomain  = sanitize_text_field( $requestBody['domain'] ?? '' );
        $requestIp      = sanitize_text_field( $requestBody['ip'] ?? '' );

        if ( empty( $licenseKey ) || empty( $requestDomain ) || empty( $requestIp ) ) {
            $this->sendJsonResponse( false, sharif_lang( 'missing_params' ), 400 );
            return;
        }

        $licenseRecord = Database::findByLicenseKey( $licenseKey );

        if ( ! $licenseRecord ) {
            $this->sendJsonResponse( false, sharif_lang( 'license_invalid' ) );
            return;
        }

        $encryptionKey   = Database::getEncryptionKey();
        $encryptedDomain = Database::encrypt( strtolower( trim( $requestDomain ) ), $encryptionKey );

        if ( $licenseRecord->domain !== $encryptedDomain ) {
            $this->sendJsonResponse( false, sharif_lang( 'license_invalid' ) );
            return;
        }

        // Check whether the incoming IP is in the allowed IP list for this license
        $allowedIpAddresses = $licenseRecord->ip_list;
        if ( ! in_array( trim( $requestIp ), $allowedIpAddresses, true ) ) {
            $this->sendJsonResponse( false, sharif_lang( 'license_invalid' ) );
            return;
        }

        if ( strtotime( $licenseRecord->expired_date ) < time() ) {
            $this->sendJsonResponse( false, sharif_lang( 'license_expired' ) );
            return;
        }

        $this->sendJsonResponse( true, '' );
    }

    /**
     * Output a JSON response and terminate execution.
     *
     * @param bool   $isValid    Whether the license is valid.
     * @param string $message    Human-readable message.
     * @param int    $httpStatus HTTP status code.
     *
     * @return void
     */
    private function sendJsonResponse( bool $isValid, string $message, int $httpStatus = 200 ): void {
        http_response_code( $httpStatus );
        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode( [ 'valid' => $isValid, 'message' => $message ] );
        exit;
    }
}
