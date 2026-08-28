<?php
/**
 * Static internationalization guardrails for production PHP and JavaScript.
 *
 * Run with: php tests/i18n-audit.php
 */

declare(strict_types=1);

$plugin_path = dirname( __DIR__ ) . '/indexlane-redirect-internal-link-auditor.php';
$script_path = dirname( __DIR__ ) . '/assets/admin.js';
$domain      = 'indexlane-redirect-internal-link-auditor';
$source      = file_get_contents( $plugin_path );
$script      = file_get_contents( $script_path );

if ( false === $source || false === $script ) {
	fwrite( STDERR, "Could not read the production plugin files.\n" );
	exit( 1 );
}

if ( ! preg_match( '/^[ \t]*\*[ \t]*Text Domain:[ \t]*' . preg_quote( $domain, '/' ) . '[ \t]*$/m', $source ) ) {
	fwrite( STDERR, "The plugin header must declare the exact WordPress.org text domain.\n" );
	exit( 1 );
}

$functions = array(
	'__'           => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'_e'           => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'_x'           => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 2 ),
	'_ex'          => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 2 ),
	'_n'           => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 3 ),
	'_nx'          => array( 'literal_arguments' => array( 0, 1, 3 ), 'domain_argument' => 4 ),
	'_n_noop'      => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 2 ),
	'_nx_noop'     => array( 'literal_arguments' => array( 0, 1, 2 ), 'domain_argument' => 3 ),
	'esc_html__'   => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'esc_html_e'   => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'esc_html_x'   => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 2 ),
	'esc_attr__'   => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'esc_attr_e'   => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'esc_attr_x'   => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 2 ),
);

/**
 * Remove whitespace and comments from one parsed call argument.
 *
 * @param array<int,mixed> $argument Argument tokens.
 * @return array<int,mixed>
 */
function indexlane_i18n_significant_tokens( array $argument ): array {
	return array_values(
		array_filter(
			$argument,
			static function ( $token ): bool {
				return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);
}

/**
 * Return a literal token value, or null when an expression was used.
 *
 * @param array<int,mixed> $argument Argument tokens.
 */
function indexlane_i18n_literal_value( array $argument ): ?string {
	$tokens = indexlane_i18n_significant_tokens( $argument );
	if ( 1 !== count( $tokens ) || ! is_array( $tokens[0] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[0][0] ) {
		return null;
	}

	$literal = $tokens[0][1];
	return substr( $literal, 1, -1 );
}

$tokens                  = token_get_all( $source );
$errors                  = array();
$translation_call_count  = 0;
$last_translator_comment = null;
$last_comment_end_line   = 0;

for ( $index = 0, $token_count = count( $tokens ); $index < $token_count; $index++ ) {
	$token = $tokens[ $index ];
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		if ( false !== stripos( $token[1], 'translators:' ) ) {
			$last_translator_comment = $token[1];
			$last_comment_end_line   = $token[2] + substr_count( $token[1], "\n" );
		}
		continue;
	}

	if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ strtolower( $token[1] ) ] ) ) {
		continue;
	}

	$function_name = strtolower( $token[1] );
	$open_index    = $index + 1;
	while ( $open_index < $token_count && is_array( $tokens[ $open_index ] ) && T_WHITESPACE === $tokens[ $open_index ][0] ) {
		$open_index++;
	}
	if ( $open_index >= $token_count || '(' !== $tokens[ $open_index ] ) {
		continue;
	}

	$arguments = array( array() );
	$depth     = 1;
	for ( $cursor = $open_index + 1; $cursor < $token_count; $cursor++ ) {
		$current = $tokens[ $cursor ];
		if ( is_string( $current ) ) {
			if ( in_array( $current, array( '(', '[', '{' ), true ) ) {
				$depth++;
			} elseif ( in_array( $current, array( ')', ']', '}' ), true ) ) {
				$depth--;
				if ( 0 === $depth ) {
					break;
				}
			} elseif ( ',' === $current && 1 === $depth ) {
				$arguments[] = array();
				continue;
			}
		}
		$arguments[ count( $arguments ) - 1 ][] = $current;
	}

	$translation_call_count++;
	$requirements = $functions[ $function_name ];
	foreach ( $requirements['literal_arguments'] as $argument_index ) {
		if ( ! isset( $arguments[ $argument_index ] ) || null === indexlane_i18n_literal_value( $arguments[ $argument_index ] ) ) {
			$errors[] = sprintf( '%s() line %d must use a literal string for argument %d.', $function_name, $token[2], $argument_index + 1 );
		}
	}

	$domain_index = $requirements['domain_argument'];
	$call_domain  = isset( $arguments[ $domain_index ] ) ? indexlane_i18n_literal_value( $arguments[ $domain_index ] ) : null;
	if ( $domain !== $call_domain ) {
		$errors[] = sprintf( '%s() line %d must use the literal text domain %s.', $function_name, $token[2], $domain );
	}

	$primary_literal = isset( $arguments[0] ) ? indexlane_i18n_literal_value( $arguments[0] ) : null;
	if ( null !== $primary_literal && preg_match( '/(?<!%)%(?:[0-9]+\$)?[-+0-9.]*[bcdeEfFgGosuxX]/', $primary_literal ) ) {
		if ( null === $last_translator_comment || $token[2] - $last_comment_end_line > 2 ) {
			$errors[] = sprintf( '%s() line %d has placeholders without an adjacent translators comment.', $function_name, $token[2] );
		}
	}

	$index = $cursor;
}

if ( $translation_call_count < 1 ) {
	$errors[] = 'No production translation calls were found.';
}

if ( preg_match( '/(?:textContent|innerHTML)\s*=\s*[\'\"]/', $script ) || preg_match( '/\bconfirm\s*\(\s*[\'\"]/', $script ) ) {
	$errors[] = 'The browser controller contains a directly rendered literal instead of a PHP-localized string.';
}

if ( false === strpos( $source, 'placeholder="<?php esc_attr_e(' ) ) {
	$errors[] = 'The visible old-domain placeholder must be translated and attribute-escaped.';
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, sprintf( "Internationalization source audit passed (%d translation calls).\n", $translation_call_count ) );
