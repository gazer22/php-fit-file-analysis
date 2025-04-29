<?php

// phpcs:disable WordPress

// TODO: Modify so that it has a list of tables (e.g., tables_created from phpFITFileAnalysis Class)
// so that it can go to the correct table based on the key.

/**
 * Class PFFA_Data_Mesgs
 *
 * This class provides functionality to lazily load and cache data messages
 * from a database. It implements ArrayAccess for array-like access and
 * Iterator for iteration over cached data.
 */
class PFFA_Data_Mesgs implements ArrayAccess, Iterator {
	private $db;
	private $cache    = array();
	private $keys     = array();
	private $position = 0;

	/**
	 * Constructor for the PFFA_Data_Mesgs class.
	 *
	 * @param PDO $db The PDO database connection instance.
	 */
	public function __construct( PDO $db ) {
		$this->db = $db;
	}

	/**
	 * Retrieve an item from the cache or database.
	 *
	 * @param mixed $key The key to retrieve.
	 * @return mixed The cached or fetched data.
	 */
	public function offsetGet( $key ): mixed {
		if ( ! isset( $this->cache[ $key ] ) ) {
			$stmt = $this->db->prepare( 'SELECT * FROM messages WHERE msg_id = :msg_id' );
			$stmt->execute( array( 'msg_id' => $key ) );
			$this->cache[ $key ] = $stmt->fetchAll( PDO::FETCH_ASSOC );
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
		$this->cache[ $key ] = $value;
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
	 * Rewind the Iterator to the first element.
	 *
	 * @return void
	 */
	public function rewind(): void {
		$this->keys     = array_keys( $this->cache );
		$this->position = 0;
	}

	/**
	 * Return the current element in the cache.
	 *
	 * @return mixed The current element.
	 */
	public function current(): mixed {
		return $this->cache[ $this->keys[ $this->position ] ];
	}

	/**
	 * Return the key of the current element.
	 *
	 * @return mixed The key of the current element.
	 */
	public function key(): mixed {
		return $this->keys[ $this->position ];
	}

	/**
	 * Move forward to the next element in the cache.
	 *
	 * @return void
	 */
	public function next(): void {
		++$this->position;
	}

	/**
	 * Check if the current position is valid.
	 *
	 * @return bool True if the current position is valid, false otherwise.
	 */
	public function valid(): bool {
		return isset( $this->keys[ $this->position ] );
	}
}

// phpcs:enable WordPress



// Usage example
// $pdo  = new PDO( 'mysql:host=localhost;dbname=testdb', 'username', 'password' );
// $data = new LazyDataLoader( $pdo );

// Access data lazily
// print_r( $data['msg1']['field1'] ); // Fetch only when needed
