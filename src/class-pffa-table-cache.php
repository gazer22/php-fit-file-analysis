<?php
/**
 * PFFA Table Cache Class
 *
 * This file contains the implementation of the PFFA_Table_Cache class,
 * which provides functionality to lazily load and cache data messages
 * from a single database table.
 *
 * @package gazer22
 */

// phpcs:disable WordPress

/**
 * Class PFFA_Table_Cache
 *
 * This class provides functionality to lazily load and cache data messages
 * from a single database table.
 */
class PFFA_Table_Cache implements \ArrayAccess, \Iterator {
	// \IteratorAggregate
	/**
	 * The PDO database connection instance.
	 *
	 * @var \PDO
	 */
	private $db;

	/**
	 * The key used for identifying the cache.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * The name of the database table to cache.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * A list of all of the columns in the table.
	 *
	 * @var array
	 */
	private $columns = array();

	/**
	 * The cache for storing fetched data.
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Logger instance for logging messages.
	 *
	 * @var \Psr\Log\LoggerInterface
	 */
	private $logger;

	/**
	 * Indicates whether the table has a 'timestamp' field.
	 *
	 * @var bool
	 */
	private $use_timestamp_as_key = false;

	/**
	 * The current position for the iterator.
	 *
	 * @var int
	 */
	private $position = 0;

	/**
	 * Constructor for the PFFA_Table_Cache class.
	 *
	 * @param PDO                      $db         The PDO database connection instance.
	 * @param string                   $key        The mesg_name for the related table.
	 * @param string                   $table_name The name of the table to cache.
	 * @param \Psr\Log\LoggerInterface $logger The logger instance for logging messages.
	 *
	 * @throws \RuntimeException If the table cannot be accessed.
	 */
	public function __construct( $db, $key, $table_name, $logger ) {
		$this->db         = $db;
		$this->key        = $key;
		$this->table_name = $table_name;
		$this->logger     = $logger;

		// Check if the table has a 'timestamp' field.
		try {
			$stmt = $this->db->prepare( "DESCRIBE {$this->table_name}" );
			$stmt->execute();
			$this->columns = array_values(
				array_filter(
					$stmt->fetchAll( \PDO::FETCH_COLUMN ),
					static function ( $column ) {
						return 'id' !== $column;
					}
				)
			);

			$this->use_timestamp_as_key = 'record' === $key && in_array( 'timestamp', $this->columns, true );
		} catch ( \PDOException $e ) {
			throw new \RuntimeException( "Failed to access table {$this->table_name} for {$key}: " . $e->getMessage() );
		}
	}

	/**
	 * Implement __toArray() magic method.
	 * This will pull all data from the database and return it as an array.
	 */
	public function __toArray(): array {
		$results = array();

		foreach ( $this->columns as $column ) {
			$results[$column] = $this->offsetGet( $column );
		}

		return $results;
	}

	/**
	 * Implement __debugInfo() magic method.
	 * This will return the current state of the object for debugging purposes.
	 */
	public function __debugInfo(): array {
		return $this->columns;
	}

	/**
	 * Return $this->columns as array_keys().
	 */
	public function get_keys(): array {
		return ( $this->columns );
	}

	/**
	 * Retrieve an item from the cache or database.
	 *
	 * @param mixed $field The offset to retrieve.
	 * @return mixed The cached or fetched data.
	 */
	public function offsetGet( mixed $field ): mixed {
		if ( ! isset( $this->cache[ $field ] ) ) {
			try {
				if ( $this->use_timestamp_as_key ) {
					$stmt = $this->db->prepare( "SELECT `timestamp`, `{$field}` FROM {$this->table_name} ORDER BY `timestamp`" );
					$stmt->execute();
					$this->cache[ $field ] = $stmt->fetchAll( \PDO::FETCH_KEY_PAIR );
				} else {
					$stmt = $this->db->prepare( "SELECT `{$field}` FROM {$this->table_name}" );
					$stmt->execute();
					$result                = $stmt->fetchAll( \PDO::FETCH_COLUMN );
					$this->cache[ $field ] = ( count( $result ) === 1 ) ? $result[0] : $result;
				}
			} catch ( \PDOException $e ) {
				$this->logger->error( "Error fetching data for field {$field} in table {$this->table_name}: " . $e->getMessage() );
				throw new \RuntimeException( "Failed to fetch data for field {$field}." );
			}
		}
		return $this->cache[ $field ];
	}

	/**
	 * Set a value in the cache.
	 *
	 * @param mixed $field The field to set.
	 * @param mixed $value The value to set.
	 */
	public function offsetSet( $field, $value ): void {
		new \LogicException( 'Direct setting of cache values is not allowed.' );
	}

	/**
	 * Check if a field exists in the cache.
	 *
	 * @param mixed $field The field to check.
	 * @return bool True if the field exists, false otherwise.
	 */
	public function offsetExists( $field ): bool {
		return isset( $this->cache[ $field ] );
	}

	/**
	 * Unset a value in the cache.
	 *
	 * @param mixed $field The field to unset.
	 * @return void
	 */
	public function offsetUnset( $field ): void {
		unset( $this->cache[ $field ] );
	}

	/**
	 * Rewind the iterator to the first element.
	 *
	 * @return void
	 */
	public function rewind(): void {
		$this->position = 0;
	}

	/**
	 * Return the current element.
	 *
	 * @return mixed The current element.
	 */
	public function current(): mixed {
		$key = $this->columns[$this->position];
		return $this->offsetGet( $key );
	}

	/**
	 * Return the current key.
	 *
	 * @return mixed The current key.
	 */
	public function key(): mixed {
		return $this->columns[ $this->position ];
	}

	/**
	 * Move to the next element.
	 */
	public function next(): void {
		++$this->position;
	}

	/**
	 * Check if the current position is valid.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public function valid(): bool {
		return $this->position < count( $this->columns );
	}

	/**
	 * Get an iterator for the columns array.
	 *
	 * This allows the object to be used in functions like array_keys().
	 *
	 * @return \ArrayIterator An iterator for the columns array.
	 */
	public function getIterator(): \ArrayIterator {
		return new \ArrayIterator( $this->columns );
	}
}

// phpcs:enable WordPress
