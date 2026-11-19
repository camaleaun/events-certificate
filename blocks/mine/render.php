<?php
/**
 * Server render of the "Get my certificate" block (includes/mine.php).
 *
 * @package EventsCertificate
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo \EventsCertificate\Mine\form( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in Mine\form().
