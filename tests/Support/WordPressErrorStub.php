<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test-only stand-in for the exact foreign WordPress/Core class contract.
class WP_Error {
	public function __construct( private readonly string $code = '', private readonly string $message = '', private readonly mixed $data = '' ) {}
	public function get_error_code(): string|int {
		return $this->code;
	}
	public function get_error_message(): string {
		return $this->message;
	}
	public function get_error_data(): mixed {
		return $this->data;
	}
}
