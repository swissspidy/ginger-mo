<?php
/**
 * Base Ginger_MO_Translation_File class.
 *
 * @package Ginger_MO
 */

/**
 * Class Ginger_MO_Translation_File.
 */
class Ginger_MO_Translation_File {
	/**
	 * List of headers.
	 *
	 * @var array<string, string>
	 */
	protected $headers = array();

	/**
	 * Whether file has been parsed.
	 *
	 * @var bool
	 */
	protected $parsed = false;

	/**
	 * Error information.
	 *
	 * @var bool|string
	 */
	protected $error = false;

	/**
	 * File name.
	 *
	 * @var string
	 */
	protected $file = '';

	/**
	 * Translation entries.
	 *
	 * @var array<string, string>
	 */
	protected $entries = array();

	/**
	 * Plural forms function.
	 *
	 * @var callable|null Plural forms.
	 */
	protected $plural_forms = null;

	/**
	 * Constructor.
	 *
	 * @param string         $file File to load.
	 * @param 'read'|'write' $context Context.
	 */
	protected function __construct( $file, $context = 'read' ) {
		$this->file = $file;

		if ( 'write' === $context ) {
			if ( file_exists( $file ) ) {
				$this->error = is_writable( $file ) ? false : 'File is not writable';
			} elseif ( ! is_writable( dirname( $file ) ) ) {
				$this->error = 'Directory not writable';
			}
		} elseif ( ! is_readable( $file ) ) {
			$this->error = 'File not readable';
		}
	}

	/**
	 * Creates a new Ginger_MO_Translation_File instance for a given file.
	 *
	 * @param string         $file File name.
	 * @param 'read'|'write' $context Context.
	 * @param string         $filetype File type.
	 * @return false|Ginger_MO_Translation_File
	 */
	public static function create( $file, $context = 'read', $filetype = null ) {
		if ( ! $filetype ) {
			$filetype = substr( $file, strrpos( $file, '.' ) + 1 );
		}

		switch ( $filetype ) {
			case 'mo':
				$moe = new Ginger_MO_Translation_File_MO( $file, $context );
				break;
			case 'php':
				$moe = new Ginger_MO_Translation_File_PHP( $file, $context );
				break;
			case 'json':
				$moe = new Ginger_MO_Translation_File_JSON( $file, $context );
				break;
			default:
				$moe = false;
		}

		return $moe;
	}

	/**
	 * Returns all headers.
	 *
	 * @return array<string, string> Headers.
	 */
	public function headers() {
		if ( ! $this->parsed ) {
			$this->parse_file();
		}
		return $this->headers;
	}

	/**
	 * Returns all entries.
	 *
	 * @return array<string, string> Entries.
	 * @phstan-return array<string, non-empty-array<string>> Entries.
	 */
	public function entries() {
		if ( ! $this->parsed ) {
			$this->parse_file();
		}

		return $this->entries;
	}

	/**
	 * Returns the current error information.
	 *
	 * @return bool|string Error
	 */
	public function error() {
		return $this->error;
	}

	/**
	 * Returns the file name.
	 *
	 * @return string File name.
	 */
	public function get_file() {
		return $this->file;
	}

	/**
	 * Translates a given string.
	 *
	 * @param string $text String to translate.
	 * @return false|string Translation(s) on success, false otherwise.
	 */
	public function translate( $text ) {
		if ( ! $this->parsed ) {
			$this->parse_file();
		}

		return isset( $this->entries[ $text ] ) ? $this->entries[ $text ] : false;
	}

	/**
	 * Returns the plural form for a count.
	 *
	 * @param int $number Count.
	 * @return int Plural form.
	 */
	public function get_plural_form( $number ) {
		if ( ! $this->parsed ) {
			$this->parse_file();
		}

		// In case a plural form is specified as a header, but no function included, build one.
		if ( ! $this->plural_forms && isset( $this->headers['plural-forms'] ) ) {
			$this->plural_forms = $this->make_plural_form_function( $this->headers['plural-forms'] );
		}

		if ( is_callable( $this->plural_forms ) ) {
			/**
			 * Plural form.
			 *
			 * @phpstan-var int $result Plural form.
			 */
			$result = call_user_func( $this->plural_forms, $number );
			return $result;
		}

		// Default plural form matches English, only "One" is considered singular.
		return ( 1 === $number ? 0 : 1 );
	}

	/**
	 * Exports translations to file.
	 *
	 * @param Ginger_MO_Translation_File $destination Destination file.
	 * @return bool True on success, false otherwise.
	 */
	public function export( Ginger_MO_Translation_File $destination ) {
		if ( $destination->error() ) {
			return false;
		}

		if ( ! $this->parsed ) {
			$this->parse_file();
		}

		$destination->create_file( $this->headers, $this->entries );
		$this->error = $destination->error();

		return false === $this->error;
	}

	/**
	 * Makes a function, which will return the right translation index, according to the
	 * plural forms header
	 *
	 * @param string $expression Plural form expression.
	 * @return callable(int $num): int Plural forms function.
	 */
	public function make_plural_form_function( $expression ) {
		try {
			$handler = new Plural_Forms( rtrim( $expression, ';' ) );
			return array( $handler, 'get' );
		} catch ( Exception $e ) {
			// Fall back to default plural-form function.
			return $this->make_plural_form_function( 'n != 1' );
		}
	}

	/**
	 * Parses the file.
	 *
	 * @return void
	 */
	protected function parse_file() {} // TODO: Move to interface or make abstract.

	/**
	 * Writes translations to file.
	 *
	 * @param array<string, string> $headers Headers.
	 * @param mixed                 $entries Entries.
	 * @return bool True on success, false otherwise.
	 */
	protected function create_file( $headers, $entries ) {
		// TODO: Move to interface or make abstract.
		$this->error = 'Format not supported.';
		return false;
	}
}
