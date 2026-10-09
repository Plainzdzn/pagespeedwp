<?php
/**
 * Ersetzen in Text, serialisierten Daten und JSON.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Ersetzt Upload-Pfade in einem Datenbankwert, je nach Art des Werts:
 *
 * - Serialisierte PHP-Daten: Die Zeichenketten werden im serialisierten Text selbst
 *   umgeschrieben und ihre Längenangaben neu berechnet. Es wird nichts deserialisiert,
 *   deshalb funktionieren auch Objekte unbekannter Klassen, und alles andere bleibt Byte für Byte gleich.
 *   Verschachtelt serialisierte Zeichenketten werden mit umgeschrieben.
 * - JSON (z. B. `_elementor_data`): dekodieren, rekursiv ersetzen, wieder kodieren wie Elementor
 *   (wp_json_encode). Ist der Wert nicht dekodierbar, wird der Rohtext ersetzt, inklusive `\/`.
 * - Sonst: Rohtext.
 *
 * Zeichenketten unter dem Schlüssel `custom_css` werden nie geändert (Briefing §4.2, nur melden).
 *
 * Kommt ohne WordPress aus (bis auf die JSON-Kodierung, siehe json_encode()) und ist dadurch testbar.
 */
final class Value_Replacer {

	/**
	 * Schlüssel, unter denen nie ersetzt wird.
	 */
	const PROTECTED_KEYS = array( 'custom_css' );

	/**
	 * URL-Erkennung.
	 *
	 * @var Url_Matcher
	 */
	private $matcher;

	/**
	 * Alter Pfad => neuer Pfad.
	 *
	 * @var array<string, string>
	 */
	private $map;

	/**
	 * Ersetzungen im letzten Aufruf.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Ersetzungen im letzten Aufruf je altem Pfad.
	 *
	 * @var array<string, int>
	 */
	private $by_path = array();

	/**
	 * Konstruktor.
	 *
	 * @param Url_Matcher           $matcher URL-Erkennung.
	 * @param array<string, string> $map     Alter Pfad => neuer Pfad.
	 */
	public function __construct( Url_Matcher $matcher, array $map ) {
		$this->matcher = $matcher;
		$this->map     = $map;
	}

	/**
	 * Ersetzungen im letzten Aufruf.
	 *
	 * @return int
	 */
	public function count() {
		return $this->count;
	}

	/**
	 * Ersetzungen im letzten Aufruf je altem Pfad.
	 *
	 * @return array<string, int>
	 */
	public function counts_by_path() {
		return $this->by_path;
	}

	/**
	 * Ersetzt in einem Wert, Art automatisch erkannt (serialisiert, sonst Text).
	 *
	 * @param string $value Wert aus der Datenbank.
	 * @return string
	 */
	public function replace( $value ) {
		$this->count   = 0;
		$this->by_path = array();

		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		return self::is_serialized( $value ) ? $this->replace_serialized_raw( $value ) : $this->replace_text( $value, null );
	}

	/**
	 * Ersetzt in einem JSON-Wert wie `_elementor_data`.
	 *
	 * @param string $json JSON-Text.
	 * @return string
	 */
	public function replace_json( $json ) {
		$this->count   = 0;
		$this->by_path = array();

		if ( ! is_string( $json ) || '' === $json ) {
			return $json;
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return $this->replace_text( $json, null );
		}

		$data = $this->replace_in_array( $data );

		if ( 0 === $this->count ) {
			return $json;
		}

		$encoded = self::json_encode( $data );

		return false === $encoded ? $json : $encoded;
	}

	/**
	 * Ersetzt rekursiv in dekodierten Daten.
	 *
	 * @param array $data Daten.
	 * @return array
	 */
	private function replace_in_array( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->replace_in_array( $value );
			} elseif ( is_string( $value ) ) {
				$data[ $key ] = $this->replace_text( $value, $key );
			}
		}

		return $data;
	}

	/**
	 * Ersetzt in einem Text, außer unter geschützten Schlüsseln.
	 *
	 * @param string          $text Text.
	 * @param string|int|null $key  Schlüssel, unter dem der Text steht.
	 * @return string
	 */
	private function replace_text( $text, $key ) {
		if ( null !== $key && in_array( strtolower( (string) $key ), self::PROTECTED_KEYS, true ) ) {
			return $text;
		}

		$count  = 0;
		$result = $this->matcher->replace( $text, $this->map, $count, $this->by_path );

		$this->count += $count;

		return $result;
	}

	/**
	 * Schreibt alle Zeichenketten in serialisierten Daten um und berechnet ihre Längen neu.
	 *
	 * @param string $serialized Serialisierte Daten.
	 * @return string Unverändert, wenn der Text nicht vollständig lesbar ist.
	 */
	private function replace_serialized_raw( $serialized ) {
		$offset = 0;
		$output = '';
		$before = $this->count;

		if ( ! $this->rewrite_value( $serialized, $offset, $output, null ) || strlen( $serialized ) !== $offset ) {
			$this->count   = $before;
			$this->by_path = array();
			return $serialized;
		}

		return $output;
	}

	/**
	 * Liest einen serialisierten Wert ab $offset und schreibt ihn (umgeschrieben) nach $output.
	 *
	 * @param string          $data   Serialisierter Text.
	 * @param int             $offset Leseposition, wird weitergesetzt.
	 * @param string          $output Ausgabe, wird ergänzt.
	 * @param string|int|null $key    Schlüssel des Werts im umgebenden Array oder Objekt.
	 * @return bool false bei ungültigem Format.
	 */
	private function rewrite_value( $data, &$offset, &$output, $key ) {
		$length = strlen( $data );
		if ( $offset >= $length ) {
			return false;
		}

		$type = $data[ $offset ];

		switch ( $type ) {
			case 'N':
				return self::copy_until( $data, $offset, $output, ';' );

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				return self::copy_until( $data, $offset, $output, ';' );

			case 's':
				if ( ! preg_match( '/\Gs:(\d+):"/', $data, $match, 0, $offset ) ) {
					return false;
				}
				$start = $offset + strlen( $match[0] );
				$bytes = (int) $match[1];
				if ( $start + $bytes + 2 > $length || '";' !== substr( $data, $start + $bytes, 2 ) ) {
					return false;
				}
				$string = substr( $data, $start, $bytes );
				$offset = $start + $bytes + 2;

				$string  = self::is_serialized( $string ) ? $this->rewrite_nested( $string ) : $this->replace_text( $string, $key );
				$output .= 's:' . strlen( $string ) . ':"' . $string . '";';
				return true;

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $data, $match, 0, $offset ) ) {
					return false;
				}
				$output .= $match[0];
				$offset += strlen( $match[0] );
				return $this->rewrite_members( $data, $offset, $output, (int) $match[1] );

			case 'O':
				if ( ! preg_match( '/\GO:\d+:"[^"]*":(\d+):\{/', $data, $match, 0, $offset ) ) {
					return false;
				}
				$output .= $match[0];
				$offset += strlen( $match[0] );
				return $this->rewrite_members( $data, $offset, $output, (int) $match[1] );

			case 'C':
				// Eigene Serialisierung einer Klasse: Inhalt ist undurchsichtig und bleibt unverändert.
				if ( ! preg_match( '/\GC:\d+:"[^"]*":(\d+):\{/', $data, $match, 0, $offset ) ) {
					return false;
				}
				$end = $offset + strlen( $match[0] ) + (int) $match[1];
				if ( $end >= $length || '}' !== $data[ $end ] ) {
					return false;
				}
				$output .= substr( $data, $offset, $end + 1 - $offset );
				$offset  = $end + 1;
				return true;

			case 'E':
				if ( ! preg_match( '/\GE:(\d+):"/', $data, $match, 0, $offset ) ) {
					return false;
				}
				$end = $offset + strlen( $match[0] ) + (int) $match[1];
				if ( $end + 2 > $length || '";' !== substr( $data, $end, 2 ) ) {
					return false;
				}
				$output .= substr( $data, $offset, $end + 2 - $offset );
				$offset  = $end + 2;
				return true;
		}

		return false;
	}

	/**
	 * Schreibt Schlüssel-Wert-Paare eines Arrays oder Objekts um, bis zur schließenden Klammer.
	 *
	 * @param string $data   Serialisierter Text.
	 * @param int    $offset Leseposition.
	 * @param string $output Ausgabe.
	 * @param int    $count  Anzahl der Paare.
	 * @return bool
	 */
	private function rewrite_members( $data, &$offset, &$output, $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			$key_start = $offset;
			$key_out   = '';
			if ( ! $this->rewrite_key( $data, $offset, $key_out ) ) {
				return false;
			}
			$output .= $key_out;
			$key     = self::key_value( substr( $data, $key_start, $offset - $key_start ) );

			if ( ! $this->rewrite_value( $data, $offset, $output, $key ) ) {
				return false;
			}
		}

		if ( ! isset( $data[ $offset ] ) || '}' !== $data[ $offset ] ) {
			return false;
		}

		$output .= '}';
		++$offset;

		return true;
	}

	/**
	 * Kopiert einen Schlüssel (i:… oder s:…) unverändert.
	 *
	 * @param string $data   Serialisierter Text.
	 * @param int    $offset Leseposition.
	 * @param string $output Ausgabe.
	 * @return bool
	 */
	private function rewrite_key( $data, &$offset, &$output ) {
		if ( preg_match( '/\Gi:-?\d+;/', $data, $match, 0, $offset ) ) {
			$output .= $match[0];
			$offset += strlen( $match[0] );
			return true;
		}

		if ( preg_match( '/\Gs:(\d+):"/', $data, $match, 0, $offset ) ) {
			$end = $offset + strlen( $match[0] ) + (int) $match[1];
			if ( '";' !== substr( $data, $end, 2 ) ) {
				return false;
			}
			$output .= substr( $data, $offset, $end + 2 - $offset );
			$offset  = $end + 2;
			return true;
		}

		return false;
	}

	/**
	 * Klartext eines serialisierten Schlüssels.
	 *
	 * @param string $token z. B. `s:10:"custom_css";` oder `i:3;`.
	 * @return string|int
	 */
	private static function key_value( $token ) {
		if ( preg_match( '/^i:(-?\d+);$/', $token, $match ) ) {
			return (int) $match[1];
		}

		$start = strpos( $token, '"' );

		// Bei Objekten tragen private und geschützte Eigenschaften ein Präfix mit Nullbytes.
		$name = substr( $token, $start + 1, -2 );
		$pos  = strrpos( $name, "\0" );

		return false === $pos ? $name : substr( $name, $pos + 1 );
	}

	/**
	 * Verschachtelt serialisierte Zeichenkette umschreiben.
	 *
	 * @param string $nested Serialisierte Daten in einer Zeichenkette.
	 * @return string
	 */
	private function rewrite_nested( $nested ) {
		$offset = 0;
		$output = '';
		$before = $this->count;

		if ( ! $this->rewrite_value( $nested, $offset, $output, null ) || strlen( $nested ) !== $offset ) {
			$this->count = $before;
			return $nested;
		}

		return $output;
	}

	/**
	 * Kopiert bis einschließlich eines Zeichens.
	 *
	 * @param string $data   Text.
	 * @param int    $offset Leseposition.
	 * @param string $output Ausgabe.
	 * @param string $char   Endzeichen.
	 * @return bool
	 */
	private static function copy_until( $data, &$offset, &$output, $char ) {
		$end = strpos( $data, $char, $offset );
		if ( false === $end ) {
			return false;
		}

		$output .= substr( $data, $offset, $end + 1 - $offset );
		$offset  = $end + 1;

		return true;
	}

	/**
	 * Ist der Text serialisiert? Gleiche Logik wie is_serialized() im Core, ohne WordPress.
	 *
	 * @param string $data Text.
	 * @return bool
	 */
	public static function is_serialized( $data ) {
		if ( ! is_string( $data ) ) {
			return false;
		}

		$data = trim( $data );

		if ( 'N;' === $data ) {
			return true;
		}

		if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
			return false;
		}

		$last = substr( $data, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}

		return (bool) preg_match( '/^(?:s:\d+:"|a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:"|[bid]:[0-9.E+-]+;)/s', $data );
	}

	/**
	 * JSON wie wp_json_encode() mit Standard-Optionen, auch ohne WordPress.
	 *
	 * @param mixed $data Daten.
	 * @return string|false
	 */
	private static function json_encode( $data ) {
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback nur ohne WordPress (Tests).
	}
}
