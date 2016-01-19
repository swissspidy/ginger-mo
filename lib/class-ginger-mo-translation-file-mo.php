<?php

class Ginger_MO_Translation_File_MO extends Ginger_MO_Translation_File {
	// used for unpack(), little endian = V, big endian = N
	protected $uint32 = false;
	protected $use_mb_substr = false;
	protected $file_contents = null;

	const MAGIC_MARKER = 0x950412de;

	protected function __construct( $file, $context ) {
		parent::__construct( $file, $context );
		$this->use_mb_substr = function_exists('mb_substr') && ( (ini_get( 'mbstring.func_overload' ) & 2) != 0 );
	}

	protected function detect_endian_and_validate_file() {
		$header = $this->read( 0, 4 );

		$big = unpack( 'N', $header );
		$big = reset( $big );
		$little = unpack( 'V', $header );
		$little = reset( $little );

		if ( $big === self::MAGIC_MARKER ) {
			return 'N';
		} elseif ( $little === self::MAGIC_MARKER ) {
			return 'V';
		} else {
			$this->error = "Magic Marker doesn't exist";
			return false;
		}
	}

	protected function read( $from, $bytes ) {
		if ( $this->use_mb_substr ) {
			return mb_substr( $this->file_contents, $from, $bytes, '8bit' );
		} else {
			return substr( $this->file_contents, $from, $bytes );
		}
	}

	protected function parse_file() {
		$this->parsed = true;

		$this->file_contents = file_get_contents( $this->file );

		$this->uint32 = $this->detect_endian_and_validate_file( $this->read( 0, 4 ) );
		if ( ! $this->uint32 ) {
			return false;
		}

		$offsets = $this->read( 4, 24 );
		if ( ! $offsets ) {
			return false;
		}

		$offsets = unpack( "{$this->uint32}rev/{$this->uint32}total/{$this->uint32}originals_addr/{$this->uint32}translations_addr/{$this->uint32}hash_length/{$this->uint32}hash_addr", $offsets );

		$offsets['originals_length'] = $offsets['translations_addr'] - $offsets['originals_addr'];
		$offsets['translations_length'] = $offsets['hash_addr'] - $offsets['translations_addr'];

		// Load the Originals
		$original_data = str_split( $this->read( $offsets['originals_addr'], $offsets['originals_length'] ), 8 );
		$translations_data = str_split( $this->read( $offsets['translations_addr'], $offsets['translations_length'] ), 8 );

		foreach ( array_keys( $original_data ) as $i ) {
			$o = unpack( "{$this->uint32}length/{$this->uint32}pos", $original_data[ $i ] );
			$t = unpack( "{$this->uint32}length/{$this->uint32}pos", $translations_data[ $i ] );

			$original = $this->read( $o['pos'], $o['length'] );
			$translation = $this->read( $t['pos'], $t['length'] );
			$translation = rtrim( $translation, "\0" ); // GlotPress bug

			// Metadata about the MO file is stored in the first translation entry.
			if ( '' === $original ) {
				foreach ( explode( "\n", $translation ) as $meta_line ) {
					if ( ! $meta_line ) continue;
					list( $name, $value ) = array_map( 'trim', explode( ':', $meta_line, 2 ) );
					$this->headers[ strtolower( $name ) ] = $value;
				}
			} else {
				$this->entries[ $original ] = $translation;
			}
		}

		unset( $this->file_contents, $original_data, $translations_data );

		return true;
	}

	protected function create_file( $headers, $entries ) {
		// Prefix the headers as the first key.
		$headers_string = '';
		foreach ( $headers as $header => $value ) {
			$headers_string .= "{$header}: $value\n";
		}
		$entries = array_merge( array( '' => $headers_string ), $entries );
		$entry_count = count( $entries );

		if ( ! $this->uint32 ) {
			$this->uint32 = 'V';
		}

		$bytes_for_entries = $entry_count * 4 * 2; // Pair of 32bit ints per entry.
		$originals_addr = 28 /* header */;
		$translations_addr = $originals_addr + $bytes_for_entries;
		$hash_addr = $translations_addr + $bytes_for_entries;
		$entry_offsets = $hash_addr;

		$file_header = pack( $this->uint32 . '*', self::MAGIC_MARKER, 0 /* rev */, $entry_count, $originals_addr, $translations_addr, 0 /* hash_length */, $hash_addr );

		$o_entries = $t_entries = $o_addr = $t_addr = '';
		foreach ( $entries as $original => $translations ) {
			$o_addr .= pack( $this->uint32 . '*', strlen( $original ), $entry_offsets );
			$entry_offsets += strlen( $original ) + 1;
			$o_entries .= $original . pack('x');
		}

		foreach ( $entries as $original => $translations ) {
			$t_addr .= pack( $this->uint32 . '*', strlen( $translations ), $entry_offsets );
			$entry_offsets += strlen( $translations ) + 1;
			$t_entries .= $translations . pack('x');
		}

		return (bool) file_put_contents( $this->file, $file_header . $o_addr . $t_addr . $o_entries . $t_entries );
	}

}
