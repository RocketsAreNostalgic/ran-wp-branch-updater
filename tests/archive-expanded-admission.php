<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use ReflectionMethod;
use RuntimeException;
use ZipArchive;

require dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * @param resource $stream
 * @param int<1,max> $length
 */
function fread( $stream, int $length ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Delegate the real native stream read and observe only its returned byte count.
	$bytes = \fread( $stream, $length );
	if ( is_string( $bytes ) ) {
		$GLOBALS['ran_wp_branch_updater_bytes_read'] = ran_wp_branch_updater_stream_bytes() + strlen( $bytes );
	}
	return $bytes;
}

/** @phpstan-impure */
function ran_wp_branch_updater_stream_bytes(): int {
	$read = $GLOBALS['ran_wp_branch_updater_bytes_read'] ?? 0;
	if ( ! is_int( $read ) ) {
		throw new RuntimeException( 'Stream telemetry must remain an integer.' );
	}
	return $read;
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Standalone assertion diagnostics identify the synthetic fixture, never rendered HTML.
		throw new RuntimeException( $message );
	}
};
$root   = __DIR__ . '/build/expanded-admission-' . bin2hex( random_bytes( 4 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only this synthetic proof's private directory.
$assert( mkdir( $root, 0700, true ), 'Fixture directory must be created.' );
$archive_path = $root . '/valid.zip';
$zip          = new ZipArchive();
$assert( true === $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Fixture ZIP must open.' );
$header = "<?php\n/*\nPlugin Name: Demo\nVersion: 1.2.3\n*/\n";
$zip->addFromString( 'repo/demo.php', $header );
$zip->addFromString( 'repo/a.txt', str_repeat( 'a', 700000 ) );
$zip->setCompressionName( 'repo/a.txt', ZipArchive::CM_STORE );
$zip->addFromString( 'repo/b.txt', str_repeat( 'b', 700000 ) );
$zip->setCompressionName( 'repo/b.txt', ZipArchive::CM_STORE );
$zip->close();
$deployment = new BranchDeploymentDeclaration( 'expanded-admission', 'plugin', 'demo', 'synthetic/demo', 'fixture', 'main', null, 'install' );
/** @return array{code:int,bytes:int,facts:array{expanded:int,expected_version:string}|null} */
$inspect = static function ( string $archive_path, int $limit ) use ( $deployment ): array {
	$GLOBALS['ran_wp_branch_updater_bytes_read'] = 0;
	$code                                        = 0;
	$facts                                       = null;
	try {
		$facts = ( new ArchiveValidator() )->validate( $archive_path, $deployment, null, 10485760, $limit, '7.0' );
	} catch ( RuntimeException $error ) {
		$code = $error->getCode();
	}
	return array(
		'code'  => $code,
		'bytes' => ran_wp_branch_updater_stream_bytes(),
		'facts' => $facts,
	);
};
$total   = strlen( $header ) + 1400000;
$exact   = $inspect( $archive_path, $total );
$assert(
	0 === $exact['code'] && $total === $exact['bytes'] && array(
		'expanded'         => $total,
		'expected_version' => '1.2.3',
	) === $exact['facts'],
	'Exact aggregate boundary must preserve valid archive facts and all streamed bytes.'
);
$over = $inspect( $archive_path, 1048576 );
$assert( ArchiveValidator::CODE_EXPANDED_TOO_LARGE === $over['code'] && strlen( $header ) + 700000 === $over['bytes'], 'Cumulative rejection must avoid reading the already-over-limit entry.' );
$one_over = $inspect( $archive_path, $total - 1 );
$assert( ArchiveValidator::CODE_EXPANDED_TOO_LARGE === $one_over['code'] && $over['bytes'] === $one_over['bytes'], 'One byte over the aggregate cap must refuse the same final entry.' );
$first_over = $inspect( $archive_path, strlen( $header ) - 1 );
$assert( ArchiveValidator::CODE_EXPANDED_TOO_LARGE === $first_over['code'] && 0 === $first_over['bytes'], 'An over-limit first entry must be refused before any stream read.' );

// Modify the final classic ZIP member's matching local/central fields, retaining a readable ZIP directory.
foreach ( array(
	'crc'        => array( 16, 14, 1 ),
	'size'       => array( 24, 22, -1 ),
	'compressed' => array( 20, 18, -1 ),
) as $field => [$central_offset, $local_offset, $delta] ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Patch only synthetic ZIP bytes in this isolated fixture directory.
	$bytes   = (string) file_get_contents( $archive_path );
	$central = strrpos( $bytes, "PK\x01\x02" );
	if ( false === $central ) {
		throw new RuntimeException( 'Missing fixture central entry.' );
	}
	$local = unpack( 'Voffset', substr( $bytes, $central + 42, 4 ) );
	$value = unpack( 'Vvalue', substr( $bytes, $central + $central_offset, 4 ) );
	if ( ! is_array( $local ) || ! is_int( $local['offset'] ) || ! is_array( $value ) || ! is_int( $value['value'] ) ) {
		throw new RuntimeException( 'Invalid synthetic ZIP patch offset.' );
	}
	$bytes        = substr_replace( $bytes, pack( 'V', $value['value'] + $delta ), $central + $central_offset, 4 );
	$bytes        = substr_replace( $bytes, pack( 'V', $value['value'] + $delta ), $local['offset'] + $local_offset, 4 );
	$corrupt_path = $root . '/' . $field . '.zip';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only this proof's synthetic corrupt ZIP.
	file_put_contents( $corrupt_path, $bytes );
	$corrupt = $inspect( $corrupt_path, $total );
	$assert( ArchiveValidator::CODE_ARCHIVE_INTEGRITY === $corrupt['code'], 'Admitted corrupt entry must retain actual-length/CRC refusal: ' . $field );
	$oversized = $inspect( $corrupt_path, 1048576 );
	$assert( ArchiveValidator::CODE_EXPANDED_TOO_LARGE === $oversized['code'] && $over['bytes'] === $oversized['bytes'], 'Declared-size refusal must precede corruption inspection of an unadmitted entry: ' . $field );
}

$add = new ReflectionMethod( ArchiveValidator::class, 'add_expanded_bytes' );
$assert( PHP_INT_MAX === $add->invoke( new ArchiveValidator(), PHP_INT_MAX - 1, 1, PHP_INT_MAX ), 'Maximum integer exact sum must remain admissible.' );
foreach ( array( array( PHP_INT_MAX, 1, PHP_INT_MAX ), array( 0, PHP_INT_MAX, PHP_INT_MAX - 1 ) ) as $arguments ) {
	try {
		$add->invoke( new ArchiveValidator(), ...$arguments );
		$assert( false, 'Overflowing aggregate must refuse before integer addition.' );
	} catch ( RuntimeException $error ) {
		$assert( ArchiveValidator::CODE_EXPANDED_TOO_LARGE === $error->getCode(), 'Overflow boundary must retain expanded-size error.' );
	}
}
// Entry claiming size=0 while carrying compressed bytes must stop immediately instead of inflating to the archive ceiling.
$zero_claimed_path = $root . '/zero-claimed.zip';
$zero_zip          = new ZipArchive();
$assert( true === $zero_zip->open( $zero_claimed_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Zero-claimed fixture ZIP must open.' );
$zero_zip->addFromString( 'repo/demo.php', $header );
$zero_zip->addFromString( 'repo/boom.txt', str_repeat( 'z', 200000 ) );
$zero_zip->setCompressionName( 'repo/boom.txt', ZipArchive::CM_STORE );
$zero_zip->close();
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Patch synthetic ZIP central and local size to 0.
$zero_bytes   = (string) file_get_contents( $zero_claimed_path );
$zero_central = strrpos( $zero_bytes, "PK\x01\x02" );
if ( false === $zero_central ) {
	throw new RuntimeException( 'Missing central entry for zero-claimed fixture.' );
}
$zero_local = unpack( 'Voffset', substr( $zero_bytes, $zero_central + 42, 4 ) );
if ( ! is_array( $zero_local ) || ! is_int( $zero_local['offset'] ) ) {
	throw new RuntimeException( 'Invalid local offset for zero-claimed fixture.' );
}
$zero_bytes = substr_replace( $zero_bytes, pack( 'V', 0 ), $zero_central + 24, 4 );
$zero_bytes = substr_replace( $zero_bytes, pack( 'V', 0 ), $zero_local['offset'] + 22, 4 );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write synthetic zero-claimed ZIP fixture.
file_put_contents( $zero_claimed_path, $zero_bytes );
$zero_result = $inspect( $zero_claimed_path, 209715200 );
$assert(
	ArchiveValidator::CODE_ARCHIVE_INTEGRITY === $zero_result['code'] && $zero_result['bytes'] <= strlen( $header ) + 1,
	'Entry claiming size=0 must abort immediately after bounding per-entry stream reads.'
);

// High compression-ratio archive above the minimum decompressed floor must be rejected before streaming.
$ratio_bomb_path = $root . '/ratio-bomb.zip';
$ratio_zip       = new ZipArchive();
$assert( true === $ratio_zip->open( $ratio_bomb_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Ratio-bomb fixture ZIP must open.' );
$ratio_zip->addFromString( 'repo/demo.php', $header );
$ratio_zip->addFromString( 'repo/dense.txt', str_repeat( 'A', 262144 ) );
$ratio_zip->close();
$ratio_result = $inspect( $ratio_bomb_path, 209715200 );
$assert(
	ArchiveValidator::CODE_EXPANDED_TOO_LARGE === $ratio_result['code'] && strlen( $header ) === $ratio_result['bytes'],
	'High compression-ratio entry above the floor must fail before streaming its payload.'
);

// Small legitimate highly compressible asset below the decompressed floor must remain admissible.
$small_asset_path = $root . '/small-asset.zip';
$small_zip        = new ZipArchive();
$assert( true === $small_zip->open( $small_asset_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Small-asset fixture ZIP must open.' );
$small_zip->addFromString( 'repo/demo.php', $header );
$small_zip->addFromString( 'repo/icon.svg', '<svg>' . str_repeat( ' ', 32768 ) . '</svg>' );
$small_zip->close();
$small_result = $inspect( $small_asset_path, 209715200 );
$assert( 0 === $small_result['code'], 'Small compressible asset below the ratio floor must remain admissible.' );

// Directory entry carrying non-zero payload bytes must be rejected.
$dir_payload_path = $root . '/dir-payload.zip';
$dir_zip          = new ZipArchive();
$assert( true === $dir_zip->open( $dir_payload_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Directory-payload fixture ZIP must open.' );
$dir_zip->addFromString( 'repo/demo.php', $header );
$dir_zip->addFromString( 'repo/sub/', 'unexpected-directory-payload' );
$dir_zip->setExternalAttributesName( 'repo/sub/', ZipArchive::OPSYS_UNIX, 0040755 << 16 );
$dir_zip->close();
$dir_result = $inspect( $dir_payload_path, 209715200 );
$assert( ArchiveValidator::CODE_ARCHIVE_INTEGRITY === $dir_result['code'], 'Directory entry carrying payload bytes must fail integrity validation.' );

foreach ( array( 'valid', 'crc', 'size', 'compressed', 'zero-claimed', 'ratio-bomb', 'small-asset', 'dir-payload' ) as $case ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only this proof's known synthetic ZIP paths.
	unlink( $root . '/' . $case . '.zip' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this proof's now-empty synthetic directory.
rmdir( $root );
echo "PASS expanded admission: 700045 entry-stream bytes read, final 700000-byte entry skipped; exact/over boundaries, corruption precedence and integer overflow\n";
