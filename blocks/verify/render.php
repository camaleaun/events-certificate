<?php
/**
 * Server render of the "Check a certificate" block (includes/verify-page.php).
 *
 * @package EventsCertificate
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo \EventsCertificate\VerifyPage\form( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in VerifyPage\form().
