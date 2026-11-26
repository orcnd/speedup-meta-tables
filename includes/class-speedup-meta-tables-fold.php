<?php
/**
 * Case and accent folding that matches a MySQL collation.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces every character by one representative of its collation weight, so a byte search equals the collation's LIKE.
 */
class SpeedUp_Meta_Tables_Fold {

	/**
	 * Character maps per collation.
	 *
	 * @var array
	 */
	private static $maps = array();

	/**
	 * The map of one collation, from the option cache or measured with WEIGHT_STRING.
	 *
	 * @param mysqli $m  Connection.
	 * @param string $cc Collation.
	 * @return array
	 */
	public static function map( $m, string $cc ): array {
		if ( isset( self::$maps[ $cc ] ) ) {
			return self::$maps[ $cc ];
		}
		$name = 'speedup_meta_tables_fold_' . preg_replace( '/\W/', '', $cc );
		$o    = get_option( $name );
		if ( is_array( $o ) && $o ) {
			self::$maps[ $cc ] = $o;
			return $o;
		}
		$by = array();
		$o  = array();
		for ( $i = 0x21; $i < 0x250; $i++ ) {
			if ( $i > 0x7e && $i < 0xa1 ) {
				continue;
			}
			$ch = html_entity_decode( '&#' . $i . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$r  = $m->query( "SELECT WEIGHT_STRING(_utf8mb4'" . $m->real_escape_string( $ch ) . "' COLLATE " . preg_replace( '/\W/', '', $cc ) . ')' );
			if ( ! $r ) {
				return array();
			}
			$w        = bin2hex( (string) $r->fetch_row()[0] );
			$by[ $w ] = $by[ $w ] ?? strtolower( $ch );
			$o[ $ch ] = $by[ $w ];
		}
		$o[' ']            = ' ';
		self::$maps[ $cc ] = $o;
		update_option( $name, $o, false );
		return $o;
	}

	/**
	 * Folds a string.
	 *
	 * @param mysqli $m  Connection.
	 * @param string $cc Collation.
	 * @param string $s  Text.
	 * @return string
	 */
	public static function fold( $m, string $cc, string $s ): string {
		$s = str_replace( "\n", "\x01", $s );
		if ( ! preg_match( '/[^\x20-\x7e]/', $s ) ) {
			return strtolower( $s );
		}
		$map = self::map( $m, $cc );
		$o   = '';
		foreach ( mb_str_split( $s ) as $c ) {
			$o .= $map[ $c ] ?? mb_strtolower( $c );
		}
		return $o;
	}
}
