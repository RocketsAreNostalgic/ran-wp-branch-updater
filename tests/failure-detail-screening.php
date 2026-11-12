<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedFailureReason;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;
use RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure;

$assert = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'FAIL: ' . $message );
	}
};

foreach ( FailureReason::cases() as $vocabulary ) {
	$assert( 1 === preg_match( '/\A[a-z][a-z0-9_]{0,47}\z/D', $vocabulary->value ), 'reason id is well formed: ' . $vocabulary->value );
}
$assert( count( FailureReason::cases() ) === count( array_unique( array_map( static fn( FailureReason $vocabulary ): string => $vocabulary->value, FailureReason::cases() ) ) ), 'reason ids are unique' );

// Safe detail is kept verbatim.
foreach ( array( 'Requires at least', 'Requires PHP', 'Version', 'Plugin Name', 'incompatible_archive', 'plugin_update_fatal_error_rollback_successful', 'http_404', 'wordpress 6.4.1' ) as $safe ) {
	$assert( AdmittedFailureReason::screen_detail( $safe ) === $safe, 'safe detail is kept: ' . $safe );
}

// Unsafe detail is dropped whole, never redacted or partly kept.
$digest = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
$unsafe = array(
	'absolute path'              => '/var/www/html/wp-content/plugins/demo',
	'windows path'               => 'C:\\Users\\demo\\site',
	'windows path forward slash' => 'C:/Users/demo/site',
	'home path'                  => '~/sites/demo',
	'relative traversal'         => 'plugins..demo',
	'path inside text'           => 'copy failed for /srv/demo',
	'markup'                     => '<script>alert(1)</script>',
	'entity'                     => 'fish &amp; chips',
	'url'                        => 'https://example.test/archive.zip',
	'credentialed url'           => 'https://user:hunter2@example.test/x',
	'userinfo only'              => 'user@example',
	'github token'               => 'ghp_abcdefghijklmnopqrstuvwxyz0123456789',
	'bearer word'                => 'Bearer abcdef',
	'api key word'               => 'api_key abc',
	'secret word'                => 'client secret rejected',
	'password word'              => 'password rejected',
	'key word'                   => 'key rejected',
	'jwt'                        => 'eyJhbGciOiJIUzI1NiJ9',
	'aws key'                    => 'AKIAABCDEFGHIJKLMNOP',
	'sha1 hex'                   => $digest,
	'sha1 hex separated'         => implode( '-', str_split( $digest, 8 ) ),
	'sha1 hex spaced'            => implode( ' ', str_split( $digest, 4 ) ),
	'sha256 hex'                 => hash( 'sha256', 'x' ),
	'sha512 hex'                 => hash( 'sha512', 'x' ),
	'sha384 hex'                 => hash( 'sha384', 'x' ),
	'sha224 hex'                 => hash( 'sha224', 'x' ),
	'md5 hex'                    => hash( 'md5', 'x' ),
	'digest prefix'              => 'sha256 abc',
	'digest prefix dash'         => 'sha512-abc',
	'short git id'               => 'abc1234',
	'short github token'         => 'ghp_abc',
	'short slack token'          => 'xoxb-1234',
	'short gitlab token'         => 'glpat-abc123',
	'short secret key'           => 'sk-abc',
	'partial hash'               => substr( $digest, 0, 20 ),
	'base64 32 bytes'            => 'ypeBEsobvcr6wjGzmiPcTaeG7/gUfE5yuYB3ha/uSLs',
	'base64url 48 bytes'         => 'VKWbnyKwuAiA2EJ-VIt8I6vYc0huHwNdzpzWl-hRdQM8qojm1XvDXvrgta_TFF8x',
	'base64 64 bytes'            => 'H0D8ktokFpR1CXnubPWC8tXX0o4YM13gWrxU0FYOD1MChgxlK/CNVgJSql50IQVG82n7u86MEs/HlXsmUv6adQ==',
	'control character'          => "line\nbreak",
	'null byte'                  => "a\0b",
	'zero width space'           => "a\u{200B}b",
	'right to left override'     => "a\u{202E}b",
	'non ascii'                  => 'caf' . "\u{E9}",
	'invalid utf8'               => "a\xFF",
	'empty'                      => '',
	'oversized'                  => str_repeat( 'word ', 400 ),
);
foreach ( $unsafe as $label => $input ) {
	$assert( null === AdmittedFailureReason::screen_detail( $input ), 'unsafe detail is dropped: ' . $label );
}

// The complete input is screened before it is bounded: a secret that starts past the bound,
// or that straddles it, still drops the whole detail instead of leaving a safe-looking prefix.
$padding = str_repeat( 'a ', 30 );
$assert( null === AdmittedFailureReason::screen_detail( $padding . $digest ), 'a digest after the bound drops the detail' );
$assert( null === AdmittedFailureReason::screen_detail( substr( $padding, 0, 50 ) . $digest ), 'a digest straddling the bound drops the detail' );
$assert( null === AdmittedFailureReason::screen_detail( substr( $padding, 0, 52 ) . '/etc/passwd' ), 'a path after the bound drops the detail' );
$assert( null === AdmittedFailureReason::screen_detail( substr( $padding, 0, 50 ) . 'token=abc' ), 'a credential word after the bound drops the detail' );
$bounded = AdmittedFailureReason::screen_detail( str_repeat( 'word ', 20 ) );
$assert( null !== $bounded && strlen( $bounded ) <= AdmittedFailureReason::MAXIMUM_DETAIL_LENGTH, 'a safe long detail is bounded to 64 characters' );

// Documented trade-off: screening is by shape, so harmless text that looks like a credential is dropped too.
$assert( null === AdmittedFailureReason::screen_detail( 'tokenizer_failed' ), 'a tokenizer-style false positive is dropped (accepted over-blocking)' );
$assert( null === AdmittedFailureReason::screen_detail( 'session_expired' ), 'a session-style false positive is dropped (accepted over-blocking)' );
$assert( 'monkey' === AdmittedFailureReason::screen_detail( 'monkey' ), 'a word that only contains key survives' );

// Reason values are validated against the closed list.
$failure = new AdmittedBranchStageFailure( 'archive_integrity_failed', 'zip_invalid', 'Requires PHP' );
$assert( 'archive_integrity_failed' === $failure->outcome_code && 'archive_integrity_failed' === $failure->getMessage(), 'stage failure keeps its outcome and message' );
$assert( 'zip_invalid' === $failure->reason && 'Requires PHP' === $failure->detail, 'stage failure carries reason and detail' );
$assert( ( new ReflectionClass( $failure ) )->isSubclassOf( RuntimeException::class ), 'stage failure remains a RuntimeException' );
$plain = new AdmittedBranchStageFailure( 'provider_failed' );
$assert( null === $plain->reason && null === $plain->detail && null === $plain->as_reason(), 'stage failure reason is optional' );
foreach ( array( 'Zip_Invalid', 'unknown_reason', 'zip-invalid', '', str_repeat( 'a', 49 ), 'wp_error:copy_failed' ) as $bad_reason ) {
	try {
		new AdmittedBranchStageFailure( 'provider_failed', $bad_reason );
		$assert( false, 'an unknown reason must be rejected: ' . $bad_reason );
	} catch ( InvalidArgumentException $expected ) {
		$assert( 'The failure reason is not in the closed list.' === $expected->getMessage(), 'unknown reason is rejected with a fixed message' );
	}
}
$leaky = new AdmittedBranchStageFailure( 'provider_failed', 'provider_error', '/home/demo/.ssh/id_rsa' );
$assert( 'provider_error' === $leaky->reason && null === $leaky->detail, 'an unsafe detail is dropped while the reason survives' );
$assert( 'provider_error' === $leaky->as_reason()?->reason->value && null === $leaky->as_reason()->detail, 'the derived record carries no unsafe detail' );

// The record screens every detail, whichever way it is built.
$record = new AdmittedFailureReason( FailureReason::PROVIDER_ERROR, '/etc/passwd', 'Not Valid', true );
$assert( null === $record->detail && null === $record->primary_outcome && $record->cleanup_failed, 'the record screens detail and validates the primary outcome' );
$record = $record->with_cleanup( 'provider_failed' );
$assert( 'provider_failed' === $record->primary_outcome && $record->cleanup_failed, 'with_cleanup records the superseded outcome' );

// Chain lookup.
$inner = new ReasonedFailure( 'inner', 'zip_invalid' );
$outer = new RuntimeException( 'outer', 0, new RuntimeException( 'middle', 0, $inner ) );
$assert( ReasonedFailure::find( $outer ) === $inner, 'find returns the nearest reasoned failure in the chain' );
$assert( null === ReasonedFailure::find( new RuntimeException( 'plain' ) ), 'find returns null without a reasoned failure' );

// Call-site guard: every detail origin in src/ is a closed literal or an explicitly allow-listed expression.
// A new site that passes anything else (for example message text) fails here until it is reviewed.
$allowed_origins = array(
	'$header',
	'$changed',
	'$parent_failure',
	'$cause?->detail',
	'$result->get_detail()',
	'$this->detail',
	'$this->error_detail( $result )',
	'$failure instanceof \Error ? \'php_error\' : \'exception\'',
	'$failure instanceof ReasonedFailure ? \'archive_changed\' : \'input_invalid\'',
	'$failure->getCode() >= 100 && $failure->getCode() <= 599 ? \'http_\' . $failure->getCode() : null',
);
// Argument index of the detail for each constructor or factory.
$origin_calls = array(
	'new ReasonedFailure'                => 2,
	'new AdmittedBranchStageFailure'     => 2,
	'new AdmittedFailureReason'          => 1,
	'CorePackageExecutionResult::failed' => 1,
);
$split        = static function ( string $arguments ): array {
	$parts   = array();
	$depth   = 0;
	$quote   = '';
	$current = '';
	foreach ( str_split( $arguments ) as $character ) {
		if ( '' !== $quote ) {
			$quote = $character === $quote ? '' : $quote;
		} elseif ( "'" === $character || '"' === $character ) {
			$quote = $character;
		} elseif ( '(' === $character || '[' === $character ) {
			++$depth;
		} elseif ( ')' === $character || ']' === $character ) {
			--$depth;
		} elseif ( ',' === $character && 0 === $depth ) {
			$parts[] = trim( $current );
			$current = '';
			continue;
		}
		$current .= $character;
	}
	$parts[] = trim( $current );
	return $parts;
};
$checked      = 0;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Scan local source for detail call sites.
	$source = (string) file_get_contents( $file->getPathname() );
	foreach ( $origin_calls as $call => $index ) {
		$length = strlen( $source );
		preg_match_all( '/' . preg_quote( $call . '(', '/' ) . '/', $source, $matches, PREG_OFFSET_CAPTURE );
		$offset = 0;
		foreach ( $matches[0] as [$matched, $found] ) {
			if ( $found < $offset ) {
				continue;
			}
			$start  = $found + strlen( $matched );
			$depth  = 1;
			$quote  = '';
			$cursor = $start;
			while ( $depth > 0 && $cursor < $length ) {
				$character = $source[ $cursor ];
				if ( '' !== $quote ) {
					$quote = $character === $quote ? '' : $quote;
				} elseif ( "'" === $character || '"' === $character ) {
					$quote = $character;
				} elseif ( '(' === $character ) {
					++$depth;
				} elseif ( ')' === $character ) {
					--$depth;
				}
				++$cursor;
			}
			$offset    = $cursor;
			$arguments = $split( substr( $source, $start, $cursor - $start - 1 ) );
			$origin    = $arguments[ $index ] ?? null;
			if ( null === $origin || '' === $origin || 'null' === $origin ) {
				continue;
			}
			++$checked;
			if ( 1 === preg_match( '/\A\'([^\'\\\\]*)\'\z/', $origin, $literal ) ) {
				$assert( AdmittedFailureReason::screen_detail( $literal[1] ) === $literal[1], $file->getFilename() . ': literal detail survives the screen: ' . $literal[1] );
				continue;
			}
			$assert( in_array( $origin, $allowed_origins, true ), $file->getFilename() . ': detail origin is not allow-listed: ' . $origin );
			preg_match_all( '/\'([^\']*)\'/', $origin, $literals );
			foreach ( $literals[1] as $literal_text ) {
				$assert( 'http_' === $literal_text || AdmittedFailureReason::screen_detail( $literal_text ) === $literal_text, 'allow-listed literal survives the screen: ' . $literal_text );
			}
		}
	}
}
$assert( $checked >= 14, 'the call-site guard found the detail call sites (' . $checked . ')' );

// The documentation names every reason, and is linked from the README and agent guidance.
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local documentation to keep the vocabulary and its documentation in step.
$inventory = (string) file_get_contents( dirname( __DIR__ ) . '/docs/error-detail-inventory.md' );
foreach ( FailureReason::cases() as $vocabulary ) {
	$assert( str_contains( $inventory, '`' . $vocabulary->value . '`' ), 'the inventory documents reason ' . $vocabulary->value );
}
foreach ( array( 'README.md', 'AGENTS.md' ) as $document ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local documentation to keep the links in step.
	$assert( str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/' . $document ), 'docs/error-detail-inventory.md' ), $document . ' links the inventory' );
}

echo "PASS failure detail screening and reason vocabulary\n";
