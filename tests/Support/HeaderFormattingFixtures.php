<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1;

/** Synthetic WordPress header formats shared by standalone and installed proofs. */
final class HeaderFormattingFixtures {
	/** @return array<string,string> */
	public static function variants( string $name ): array {
		$lines = array( $name . ': Demo', 'Version: 1.2.3', 'Requires at least: 6.5', 'Requires PHP: 8.2', 'Template: parent' );
		return array(
			'lf'               => "/*\n" . implode( "\n", $lines ) . "\n*/\n",
			'crlf'             => "/*\r\n" . implode( "\r\n", $lines ) . "\r\n*/\r\n",
			'cr'               => "/*\r" . implode( "\r", $lines ) . "\r*/\r",
			'closing-comments' => implode( "\n", array_map( static fn( string $line ): string => '/* ' . $line . ' */ ignored', $lines ) ),
			'closing-tags'     => implode( "\n", array_map( static fn( string $line ): string => $line . ' ?> ignored', $lines ) ),
			'opening-php'      => implode( "\n", array_map( static fn( string $line ): string => '<?php /* ' . $line . ' */', $lines ) ),
			'opening-php-cr'   => implode( "\r", array_map( static fn( string $line ): string => " \t<?php # " . $line . ' ?> ignored', $lines ) ),
		);
	}
}
