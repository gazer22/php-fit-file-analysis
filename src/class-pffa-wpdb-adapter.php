<?php
// phpcs:ignoreFile
/**
 * Simplified PDO-compatible adapter around WordPress' wpdb object.
 *
 * @package php-fit-file-analysis
 */

// Provide lightweight fallbacks when WordPress helpers are unavailable.
if ( ! class_exists( 'PDOException' ) ) {
	class PDOException extends \Exception {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'esc_sql' ) ) {
	/**
	 * Minimal fallback for esc_sql() outside of WordPress.
	 *
	 * @param string $value Value to escape.
	 * @return string
	 */
	function esc_sql( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return addslashes( $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal fallback for wp_json_encode() outside of WordPress.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 */
	function wp_json_encode( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return json_encode( $value );
	}
}

// phpcs:disable WordPress

/**
 * Adapter that mimics the subset of the PDO API used by phpFITFileAnalysis while
 * delegating to a WordPress wpdb connection.
 */
class PFFA_WPDB_Adapter {
	/**
	 * Underlying WordPress database connection.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Whether a transaction has been started via this adapter.
	 *
	 * @var bool
	 */
	private $in_transaction = false;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database connection.
	 *
	 * @throws \InvalidArgumentException When the provided connection is invalid.
	 */
	public function __construct( $wpdb ) {
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			throw new \InvalidArgumentException( 'PFFA_WPDB_Adapter requires an object implementing the wpdb interface.' );
		}

		$this->wpdb = $wpdb;
	}

	/**
	 * Retrieve the underlying wpdb instance.
	 *
	 * @return \wpdb
	 */
	public function get_wpdb() {
		return $this->wpdb;
	}

	/**
	 * Prepare a SQL statement.
	 *
	 * @param string $sql SQL statement.
	 * @return PFFA_WPDB_Statement
	 */
	public function prepare( $sql ) {
		return new PFFA_WPDB_Statement( $this->wpdb, $sql, $this );
	}

	/**
	 * Execute a SQL query immediately.
	 *
	 * @param string $sql SQL statement.
	 * @return PFFA_WPDB_Statement
	 */
	public function query( $sql ) {
		$statement = new PFFA_WPDB_Statement( $this->wpdb, $sql, $this );
		$statement->execute();
		return $statement;
	}

	/**
	 * Execute a SQL statement that does not return a result set.
	 *
	 * @param string $sql SQL statement.
	 * @return int Number of affected rows.
	 *
	 * @throws \PDOException When the execution fails.
	 */
	public function exec( $sql ) {
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			throw $this->buildException( 'Database query failed: ' . ( $this->wpdb->last_error ?: 'unknown error' ) );
		}
		return (int) $result;
	}

	/**
	 * Begin a database transaction.
	 *
	 * @return bool True when a transaction is started, false when already in progress.
	 *
	 * @throws \PDOException When the transaction cannot be started.
	 */
	public function beginTransaction() {
		if ( $this->in_transaction ) {
			return false;
		}
		$result = $this->wpdb->query( 'START TRANSACTION' );
		if ( false === $result ) {
			throw $this->buildException( 'Failed to start transaction: ' . ( $this->wpdb->last_error ?: 'unknown error' ) );
		}
		$this->in_transaction = true;
		return true;
	}

	/**
	 * Commit the active transaction.
	 *
	 * @return bool True on success, false if no transaction was active.
	 *
	 * @throws \PDOException When the commit fails.
	 */
	public function commit() {
		if ( ! $this->in_transaction ) {
			return false;
		}
		$result = $this->wpdb->query( 'COMMIT' );
		if ( false === $result ) {
			throw $this->buildException( 'Failed to commit transaction: ' . ( $this->wpdb->last_error ?: 'unknown error' ) );
		}
		$this->in_transaction = false;
		return true;
	}

	/**
	 * Roll back the active transaction.
	 *
	 * @return bool True on success, false if no transaction was active.
	 *
	 * @throws \PDOException When the rollback fails.
	 */
	public function rollBack() {
		if ( ! $this->in_transaction ) {
			return false;
		}
		$result = $this->wpdb->query( 'ROLLBACK' );
		if ( false === $result ) {
			throw $this->buildException( 'Failed to roll back transaction: ' . ( $this->wpdb->last_error ?: 'unknown error' ) );
		}
		$this->in_transaction = false;
		return true;
	}

	/**
	 * Determine whether a transaction is currently active.
	 *
	 * @return bool
	 */
	public function inTransaction() {
		return $this->in_transaction;
	}

	/**
	 * Retrieve database attributes.
	 * Only supports the subset currently used by phpFITFileAnalysis.
	 *
	 * @param mixed $attribute Attribute identifier.
	 * @return mixed|null
	 */
	public function getAttribute( $attribute ) {
		if ( ( defined( '\\PDO::ATTR_DRIVER_NAME' ) && \PDO::ATTR_DRIVER_NAME === $attribute ) || 'driver_name' === $attribute ) {
			return 'mysql';
		}

		return null;
	}

	/**
	 * Set database attributes. No-op for the adapter, retained for API compatibility.
	 *
	 * @return bool Always true.
	 */
	public function setAttribute() {
		return true;
	}

	/**
	 * Quote a value for use in SQL statements.
	 *
	 * @param mixed $value Value to quote.
	 * @return string
	 */
	public function quote( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}

		if ( is_bool( $value ) ) {
			return $value ? "'1'" : "'0'";
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return "'" . (string) $value . "'";
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$value = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		}

		$string_value = (string) $value;
		if ( function_exists( 'esc_sql' ) ) {
			$escaped = esc_sql( $string_value );
		} else {
			$escaped = addslashes( $string_value );
		}

		return "'" . $escaped . "'";
	}

	/**
	 * Helper to build a PDOException or RuntimeException depending on availability.
	 *
	 * @param string $message Error message.
	 * @return \Exception
	 */
	public function buildException( $message ) {
		if ( class_exists( '\\PDOException' ) ) {
			return new \PDOException( $message );
		}

		return new \RuntimeException( $message );
	}
}

/**
 * Lightweight stand-in for PDOStatement backed by wpdb.
 */
class PFFA_WPDB_Statement {
	/**
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * @var string
	 */
	private $sql;

	/**
	 * @var PFFA_WPDB_Adapter
	 */
	private $adapter;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private $results = array();

	/**
	 * @var bool
	 */
	private $executed = false;

	/**
	 * @var int
	 */
	private $row_count = 0;

	/**
	 * @var int
	 */
	private $cursor = 0;

	/**
	 * The most recent SQL string executed by this statement.
	 *
	 * @var string|null
	 */
	public $queryString = null;

	/**
	 * Constructor.
	 *
	 * @param \wpdb              $wpdb    WordPress database connection.
	 * @param string              $sql     SQL string.
	 * @param PFFA_WPDB_Adapter   $adapter Parent adapter.
	 */
	public function __construct( $wpdb, $sql, $adapter ) {
		$this->wpdb    = $wpdb;
		$this->sql     = $sql;
		$this->adapter = $adapter;
	}

	/**
	 * Execute the statement.
	 *
	 * @param array $params Optional bound parameters.
	 * @return bool
	 *
	 * @throws \PDOException When the execution fails.
	 */
	public function execute( array $params = array() ) {
		$query = $this->buildQuery( $params );

		$this->queryString = $query;
		$command           = strtoupper( strtok( ltrim( $query ), " \t\n\r" ) );

		if ( in_array( $command, array( 'SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN' ), true ) ) {
			$results = $this->wpdb->get_results( $query, ARRAY_A );
			if ( null === $results ) {
				throw $this->adapter->buildException( 'Database query failed: ' . ( $this->wpdb->last_error ?: 'unknown error' ) );
			}
			$this->results   = $results;
			$this->row_count = count( $results );
		} else {
			$result = $this->wpdb->query( $query );
			if ( false === $result ) {
				throw $this->adapter->buildException( 'Database query failed: ' . ( $this->wpdb->last_error ?: 'unknown error' ) );
			}
			$this->row_count = (int) $this->wpdb->rows_affected;
			$this->results   = array();
		}

		$this->executed = true;
		$this->cursor   = 0;

		return true;
	}

	/**
	 * Fetch the next row.
	 *
	 * @param int $fetch_style PDO fetch style constant.
	 * @return array|false|mixed
	 */
	public function fetch( $fetch_style = null ) {
		if ( ! $this->executed ) {
			$this->execute();
		}

		if ( $this->cursor >= count( $this->results ) ) {
			return false;
		}

		$row = $this->results[ $this->cursor ];
		++$this->cursor;

		return $this->formatRow( $row, $fetch_style );
	}

	/**
	 * Fetch all rows.
	 *
	 * @param int $fetch_style PDO fetch style constant.
	 * @return array
	 */
	public function fetchAll( $fetch_style = null ) {
		if ( ! $this->executed ) {
			$this->execute();
		}

		switch ( $fetch_style ) {
			case defined( '\\PDO::FETCH_COLUMN' ) ? \PDO::FETCH_COLUMN : 7:
				return array_map( array( $this, 'extractFirstValue' ), $this->results );
			case defined( '\\PDO::FETCH_KEY_PAIR' ) ? \PDO::FETCH_KEY_PAIR : 12:
				return $this->toKeyValuePairs( $this->results );
			case defined( '\\PDO::FETCH_NUM' ) ? \PDO::FETCH_NUM : 3:
				return array_map( 'array_values', $this->results );
			default:
				return $this->results;
		}
	}

	/**
	 * Fetch a single column from the next row.
	 *
	 * @param int $column_number Column index.
	 * @return mixed|false
	 */
	public function fetchColumn( $column_number = 0 ) {
		$result = $this->fetch( defined( '\\PDO::FETCH_NUM' ) ? \PDO::FETCH_NUM : 3 );
		if ( false === $result ) {
			return false;
		}

		return isset( $result[ $column_number ] ) ? $result[ $column_number ] : false;
	}

	/**
	 * Close the cursor and free stored result data.
	 */
	public function closeCursor() {
		$this->results   = array();
		$this->executed  = false;
		$this->row_count = 0;
		$this->cursor    = 0;
	}

	/**
	 * Return the number of rows affected or retrieved.
	 *
	 * @return int
	 */
	public function rowCount() {
		return $this->row_count;
	}

	/**
	 * Internal helper to prepare the final SQL query.
	 *
	 * @param array $params Bound parameters.
	 * @return string
	 */
	private function buildQuery( array $params ) {
		$query          = $this->sql;
		$ordered_values = array();

		if ( preg_match_all( '/:(\w+)/', $query, $matches ) ) {
			foreach ( $matches[1] as $placeholder ) {
				$key = ':' . $placeholder;
				if ( array_key_exists( $key, $params ) ) {
					$value = $params[ $key ];
				} elseif ( array_key_exists( $placeholder, $params ) ) {
					$value = $params[ $placeholder ];
				} else {
					throw $this->adapter->buildException( 'Missing bound parameter :' . $placeholder );
				}

				if ( null === $value ) {
					$query = $this->replaceFirstOccurrence( $query, $key, 'NULL' );
					continue;
				}

				$format = $this->determineFormat( $value );
				$query  = $this->replaceFirstOccurrence( $query, $key, $format );
				$ordered_values[] = $this->normalizeValue( $value );
			}
		}

		if ( false !== strpos( $query, '?' ) ) {
			$positional = $this->extractPositionalParams( $params );
			foreach ( $positional as $value ) {
				$position = strpos( $query, '?' );
				if ( false === $position ) {
					throw $this->adapter->buildException( 'Too many bound parameters supplied.' );
				}

				if ( null === $value ) {
					$query = substr_replace( $query, 'NULL', $position, 1 );
					continue;
				}

				$format = $this->determineFormat( $value );
				$query  = substr_replace( $query, $format, $position, 1 );
				$ordered_values[] = $this->normalizeValue( $value );
			}

			if ( false !== strpos( $query, '?' ) ) {
				throw $this->adapter->buildException( 'Not enough bound parameters supplied.' );
			}
		}

		if ( empty( $ordered_values ) ) {
			return $query;
		}

		$prepared = $this->wpdb->prepare( $query, $ordered_values );
		if ( false === $prepared ) {
			throw $this->adapter->buildException( 'Failed to prepare database query.' );
		}

		return $prepared;
	}

	/**
	 * Determine the wpdb format string for a value.
	 *
	 * @param mixed $value Value to analyse.
	 * @return string
	 */
	private function determineFormat( $value ) {
		if ( is_int( $value ) ) {
			return '%d';
		}
		if ( is_bool( $value ) ) {
			return '%d';
		}
		if ( is_float( $value ) ) {
			return '%f';
		}

		return '%s';
	}

	/**
	 * Normalise a value for binding.
	 *
	 * @param mixed $value Value to normalise.
	 * @return mixed
	 */
	private function normalizeValue( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		}

		return $value;
	}

	/**
	 * Replace the first occurrence of a substring.
	 *
	 * @param string $subject  Subject string.
	 * @param string $search   Search string.
	 * @param string $replace  Replacement.
	 * @return string
	 */
	private function replaceFirstOccurrence( $subject, $search, $replace ) {
		$position = strpos( $subject, $search );
		if ( false === $position ) {
			return $subject;
		}

		return substr_replace( $subject, $replace, $position, strlen( $search ) );
	}

	/**
	 * Extract positional parameters (numeric keys) from the provided array.
	 *
	 * @param array $params Parameters.
	 * @return array
	 */
	private function extractPositionalParams( array $params ) {
		$positional = array();

		foreach ( $params as $key => $value ) {
			if ( is_int( $key ) || ( is_string( $key ) && ctype_digit( $key ) ) ) {
				$positional[] = $value;
			}
		}

		if ( empty( $positional ) ) {
			$positional = array_values( $params );
		}

		return $positional;
	}

	/**
	 * Convert a row to the requested fetch style output.
	 *
	 * @param array $row        Row data.
	 * @param int   $fetch_style Requested fetch style.
	 * @return mixed
	 */
	private function formatRow( array $row, $fetch_style ) {
		if ( null === $fetch_style ) {
			$fetch_style = defined( '\\PDO::FETCH_ASSOC' ) ? \PDO::FETCH_ASSOC : 2;
		}

		switch ( $fetch_style ) {
			case defined( '\\PDO::FETCH_NUM' ) ? \PDO::FETCH_NUM : 3:
				return array_values( $row );
			case defined( '\\PDO::FETCH_COLUMN' ) ? \PDO::FETCH_COLUMN : 7:
				return $this->extractFirstValue( $row );
			default:
				return $row;
		}
	}

	/**
	 * Extract the first column value from a row.
	 *
	 * @param array $row Row data.
	 * @return mixed
	 */
	private function extractFirstValue( array $row ) {
		return count( $row ) > 0 ? reset( $row ) : null;
	}

	/**
	 * Convert result rows into key/value pairs.
	 *
	 * @param array $rows Result rows.
	 * @return array
	 */
	private function toKeyValuePairs( array $rows ) {
		$pairs = array();
		foreach ( $rows as $row ) {
			$values = array_values( $row );
			if ( count( $values ) < 2 ) {
				continue;
			}
			$pairs[ $values[0] ] = $values[1];
		}

		return $pairs;
	}
}

// phpcs:enable WordPress
