<?php
/**
 * This file contains the PFFA_Data_Mesgs class, which provides functionality
 * to lazily load and cache data messages from a database.
 *
 * @package phpFITFileAnalysis
 */

// phpcs:disable WordPress

// TODO: Modify so that it has a list of tables (e.g., tables_created from phpFITFileAnalysis Class)
// so that it can go to the correct table based on the key.

/**
 * Class PFFA_Data_Mesgs
 *
 * This class provides functionality to lazily load and cache data messages
 * from a database.
 */
class PFFA_Data_Mesgs implements \ArrayAccess, \Iterator {
	/**
	 * The PDO database connection instance.
	 *
	 * @var \PDO
	 */
	private $db;

	/**
	 * Cache for storing data messages.
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * List of tables used for determining the correct table based on the key.
	 *
	 * @var array
	 */
	private $tables;

	/**
	 * Logger instance for logging messages.
	 *
	 * @var \Psr\Log\LoggerInterface
	 */
	private $logger;

	/**
	 * The current position for the iterator.
	 *
	 * @var int
	 */
	private $position = 0;

	/**
	 * Constructor for the PFFA_Data_Mesgs class.
	 *
	 * @param PDO                      $db     The PDO database connection instance.
	 * @param array                    $tables The list of tables used for determining the correct table based on the key.
	 * @param \Psr\Log\LoggerInterface $logger The logger instance for logging messages.
	 */
	public function __construct( $db, $tables, $logger ) {
		if ( ! $db instanceof \PDO ) {
			throw new \InvalidArgumentException( 'Invalid database connection provided.' );
		}
		$this->db     = $db;
		$this->tables = $tables;
		$this->logger = $logger;
	}

	/**
	 * Set the tables property.
	 *
	 * @param array $tables The list of tables to set.
	 */
	public function setTables( array $tables ) {
		$this->tables = $tables;
	}


	/**
	 * Retrieve an item from the cache or database.
	 *
	 * @param mixed $key The key to retrieve.
	 * @return mixed The cached or fetched data.
	 */
	public function offsetGet( mixed $key ): mixed {
		if ( ! isset( $this->tables[ $key ] ) ) {
			throw new \OutOfBoundsException( "Key {$key} does not exist in tables." );
		}

		if ( ! isset( $this->cache[ $key ] ) ) {
			try {
				$this->cache[ $key ] = new PFFA_Table_Cache( $this->db, $key, $this->tables[ $key ]['location'], $this->logger );
			} catch ( \Exception $e ) {
				$this->logger->error( "Error fetching data for key {$key}: " . $e->getMessage() );
				throw new \RuntimeException( "Failed to fetch data for key {$key}." );
			}
		}
		return $this->cache[ $key ];
	}

	/**
	 * Set a value in the cache.
	 *
	 * @param mixed $key   The key to set.
	 * @param mixed $value The value to set.
	 */
	public function offsetSet( $key, $value ): void {
		new \LogicException( 'Direct setting of cache values is not allowed.' );
	}

	/**
	 * Check if a key exists in the cache.
	 *
	 * @param mixed $key The key to check.
	 * @return bool True if the key exists, false otherwise.
	 */
	public function offsetExists( $key ): bool {
		return isset( $this->cache[ $key ] );
	}

	/**
	 * Unset a value in the cache.
	 *
	 * @param mixed $key The key to unset.
	 * @return void
	 */
	public function offsetUnset( $key ): void {
		unset( $this->cache[ $key ] );
	}

	/**
	 * Implement __toArray() magic method.
	 * This will pull all data from the database and return it as an array.
	 */
	public function __toArray(): array {
		$results = array();
		$tables  = array_keys( $this->tables );

		foreach ( $tables as $table ) {
			$results[$table] = $this->offsetGet( $table );
		}

		return $results;
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
		$key = array_keys( $this->tables )[ $this->position ];
		return $this->offsetGet( $key );
	}

	/**
	 * Return the current key.
	 *
	 * @return mixed The current key.
	 */
	public function key(): mixed {
		return array_keys( $this->tables )[ $this->position ];
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
		return $this->position < count( $this->tables );
	}
}

// phpcs:enable WordPress
