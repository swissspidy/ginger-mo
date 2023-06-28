<?php
/**
 * Class Ginger_MO_Translation_File_MO.
 *
 * @package Ginger_MO
 */

/**
 * Class Ginger_MO_Translation_File_MO.
 */
class Ginger_MO_Translation_File_MO extends Ginger_MO_Translation_File {
	/**
	 * Endian value.
	 *
	 * V for little endian, N for big endian, or false.
	 *
	 * Used for unpack().
	 *
	 * @var false|'V'|'N'
	 */
	protected $uint32 = false;

	/**
	 * The magic number of the GNU message catalog format.
	 *
	 * @var int
	 */
	const MAGIC_MARKER = 0x950412de;

	/**
	 * Constructor.
	 *
	 * @param string         $file File to load.
	 * @param 'read'|'write' $context Context.
	 */
	protected function __construct( $file, $context ) {
		parent::__construct( $file, $context );
	}

	/**
	 * Detects endian and validates file.
	 *
	 * @param string $header File contents.
	 * @return false|'V'|'N' V for little endian, N for big endian, or false on failure.
	 */
	protected function detect_endian_and_validate_file( $header ) {
		$big = unpack( 'N', $header );

		if ( ! $big ) {
			return false;
		}

		$big = reset( $big );

		if ( ! $big ) {
			return false;
		}

		$little = unpack( 'V', $header );

		if ( ! $little ) {
			return false;
		}

		$little = reset( $little );

		if ( ! $little ) {
			return false;
		}

		if ( self::MAGIC_MARKER === $big ) {
			return 'N';
		}

		if ( self::MAGIC_MARKER === $little ) {
			return 'V';
		}

		$this->error = "Magic Marker doesn't exist";
		return false;
	}

	/**
	 * Parses the file.
	 *
	 * @return bool True on success, false otherwise.
	 */
	protected function parse_file() {
		$this->parsed = true;

		$file_contents = file_get_contents( $this->file );

		if ( false === $file_contents ) {
			return false;
		}

		$file_length = strlen( $file_contents );

		if ( $file_length < 24 ) {
			$this->error = 'Invalid Data.';
			return false;
		}

		$this->uint32 = $this->detect_endian_and_validate_file( substr( $file_contents, 0, 4 ) );
		if ( ! $this->uint32 ) {
			return false;
		}

		$offsets = substr( $file_contents, 4, 24 );
		if ( ! $offsets ) {
			return false;
		}

		$offsets = unpack( "{$this->uint32}rev/{$this->uint32}total/{$this->uint32}originals_addr/{$this->uint32}translations_addr/{$this->uint32}hash_length/{$this->uint32}hash_addr", $offsets );

		if ( ! $offsets ) {
			return false;
		}

		$offsets['originals_length']    = $offsets['translations_addr'] - $offsets['originals_addr'];
		$offsets['translations_length'] = $offsets['hash_addr'] - $offsets['translations_addr'];

		if ( $offsets['rev'] > 0 ) {
			$this->error = 'Unsupported Revision.';
			return false;
		}

		if ( $offsets['translations_addr'] > $file_length || $offsets['originals_addr'] > $file_length ) {
			$this->error = 'Invalid Data.';
			return false;
		}

		// Load the Originals.
		$original_data     = str_split( substr( $file_contents, $offsets['originals_addr'], $offsets['originals_length'] ), 8 );
		$translations_data = str_split( substr( $file_contents, $offsets['translations_addr'], $offsets['translations_length'] ), 8 );

		foreach ( array_keys( $original_data ) as $i ) {
			$o = unpack( "{$this->uint32}length/{$this->uint32}pos", $original_data[ $i ] );
			$t = unpack( "{$this->uint32}length/{$this->uint32}pos", $translations_data[ $i ] );

			if ( ! $o || ! $t ) {
				continue;
			}

			$original    = substr( $file_contents, $o['pos'], $o['length'] );
			$translation = substr( $file_contents, $t['pos'], $t['length'] );
			// GlotPress bug.
			$translation = rtrim( $translation, "\0" );

			// Metadata about the MO file is stored in the first translation entry.
			if ( '' === $original ) {
				foreach ( explode( "\n", $translation ) as $meta_line ) {
					if ( ! $meta_line ) {
						continue;
					}

					list( $name, $value ) = array_map( 'trim', explode( ':', $meta_line, 2 ) );

					$this->headers[ strtolower( $name ) ] = $value;
				}
			} else {
				$this->entries[ (string) $original ] = $translation;
			}
		}

		return true;
	}

	/**
	 * Writes translations to file.
	 *
	 * @param array<string, string>   $headers Headers.
	 * @param array<string, string[]> $entries Entries.
	 * @return bool True on success, false otherwise.
	 */
	protected function create_file( $headers, $entries ) {
		// Prefix the headers as the first key.
		$headers_string = '';
		foreach ( $headers as $header => $value ) {
			$headers_string .= "{$header}: $value\n";
		}
		$entries     = array_merge( array( '' => $headers_string ), $entries );
		$entry_count = count( $entries );

		if ( ! $this->uint32 ) {
			$this->uint32 = 'V';
		}

		$bytes_for_entries = $entry_count * 4 * 2;
		// Pair of 32bit ints per entry.
		$originals_addr    = 28; /* header */
		$translations_addr = $originals_addr + $bytes_for_entries;
		$hash_addr         = $translations_addr + $bytes_for_entries;
		$entry_offsets     = $hash_addr;

		$file_header = pack( $this->uint32 . '*', self::MAGIC_MARKER, 0 /* rev */, $entry_count, $originals_addr, $translations_addr, 0 /* hash_length */, $hash_addr );

		if ( ! $file_header ) {
			$file_header = '';
		}

		$o_entries = '';
		$t_entries = '';
		$o_addr    = '';
		$t_addr    = '';

		foreach ( array_keys( $entries ) as $original ) {
			$o_addr        .= pack( $this->uint32 . '*', strlen( $original ), $entry_offsets );
			$entry_offsets += strlen( $original ) + 1;
			$o_entries     .= $original . pack( 'x' );
		}

		foreach ( $entries as $translations ) {
			$translation    = is_array( $translations ) ? implode( "\0", $translations ) : $translations;
			$t_addr        .= pack( $this->uint32 . '*', strlen( $translation ), $entry_offsets );
			$entry_offsets += strlen( $translation ) + 1;
			$t_entries     .= $translation . pack( 'x' );
		}

		return (bool) file_put_contents( $this->file, $file_header . $o_addr . $t_addr . $o_entries . $t_entries );
	}
}
