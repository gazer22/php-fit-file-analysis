<?php
error_reporting( E_ALL );
if ( ! class_exists( 'gazer22\phpFITFileAnalysis' ) ) {
	require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require __DIR__ . '/../vendor/autoload.php';

// JKK. composer require --dev phpunit/phpunit
// JKK. phpunit tests
// JKK. phpunit tests/pFFA-Basic-Test.php

// TODO: may need to update the test classes to extend PHPUnit\Framework\TestCase instead of PHPUnit_Framework_TestCase.

class pFFA_Basic_Test extends \PHPUnit\Framework\TestCase {

	private $base_dir;
	private $demo_files  = array();
	private $valid_files = array( 'mountain-biking.fit', 'power-analysis.fit', 'road-cycling.fit', 'swim.fit' );

	public function setUp(): void {
		$this->base_dir = __DIR__ . '/../demo/fit_files/';
	}

	public function testDemoFilesExist() {
		$this->demo_files = array_values( array_diff( scandir( $this->base_dir ), array( '..', '.' ) ) );
		sort( $this->demo_files );
		sort( $this->valid_files );
		$this->assertEquals( $this->valid_files, $this->demo_files );
		var_dump( $this->demo_files );
	}

	/**
	 * @expectedException Exception
	 */
	public function testEmptyFilepath() {
		$pFFA = new gazer22\phpFITFileAnalysis( '' );
	}

	/**
	 * @expectedException Exception
	 */
	public function testFileDoesntExist() {
		$pFFA = new gazer22\phpFITFileAnalysis( 'file_doesnt_exist.fit' );
	}

	/**
	 * @expectedException Exception
	 */
	public function testInvalidFitFile() {
		$file_path = $this->base_dir . '../composer.json';
		$pFFA      = new gazer22\phpFITFileAnalysis( $file_path );
	}


	public function testDemoFileBasics() {
		foreach ( $this->demo_files as $filename ) {

				$options = array(
					'buffer_input_to_db' => true,
					'database'           => array(
						'table_name'       => 'a_test_demo_file_' . $filename,
						'data_source_name' => 'mysql:host=localhost;dbname=' . $_ENV['DB_NAME'],
						'username'         => $_ENV['DB_USER'],
						'password'         => $_ENV['DB_PASSWORD'],
					),
				);

				$pFFA = new gazer22\phpFITFileAnalysis( $this->base_dir . $filename, $options );

				$this->assertGreaterThan( 0, $pFFA->data_mesgs['activity']['timestamp'], 'No Activity timestamp!' );

				if ( isset( $pFFA->data_mesgs['record'] ) ) {
					$this->assertGreaterThan( 0, count( $pFFA->data_mesgs['record']['timestamp'] ), 'No Record timestamps!' );

					// Check if distance from record messages is +/- 2% of distance from session message
					if ( is_array( $pFFA->data_mesgs['record']['distance'] ) ) {
						$distance_difference = abs( end( $pFFA->data_mesgs['record']['distance'] ) - $pFFA->data_mesgs['session']['total_distance'] / 1000 );
						$this->assertLessThan( 0.02 * end( $pFFA->data_mesgs['record']['distance'] ), $distance_difference, 'Session distance should be similar to last Record distance' );
					}

					// Look for big jumps in latitude and longitude
					if ( isset( $pFFA->data_mesgs['record']['position_lat'] ) && is_array( $pFFA->data_mesgs['record']['position_lat'] ) ) {
						foreach ( $pFFA->data_mesgs['record']['position_lat'] as $key => $value ) {
							if ( isset( $pFFA->data_mesgs['record']['position_lat'][ $key - 1 ] ) ) {
								if ( abs( $pFFA->data_mesgs['record']['position_lat'][ $key - 1 ] - $pFFA->data_mesgs['record']['position_lat'][ $key ] ) > 1 ) {
									$this->assertTrue( false, 'Too big a jump in latitude' );
								}
							}
						}
					}
					if ( isset( $pFFA->data_mesgs['record']['position_long'] ) && is_array( $pFFA->data_mesgs['record']['position_long'] ) ) {
						foreach ( $pFFA->data_mesgs['record']['position_long'] as $key => $value ) {
							if ( isset( $pFFA->data_mesgs['record']['position_long'][ $key - 1 ] ) ) {
								if ( abs( $pFFA->data_mesgs['record']['position_long'][ $key - 1 ] - $pFFA->data_mesgs['record']['position_long'][ $key ] ) > 1 ) {
									$this->assertTrue( false, 'Too big a jump in longitude' );
								}
							}
						}
					}
				}
		}
	}
}
