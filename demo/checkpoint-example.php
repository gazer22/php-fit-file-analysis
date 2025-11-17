<?php
/**
 * Example: Processing a large FIT file with checkpoint/restart capability
 *
 * This example demonstrates how to use the checkpoint feature to process
 * large FIT files that might be interrupted due to server timeouts,
 * memory limits, or other issues.
 */

require_once __DIR__ . '/src/phpFITFileAnalysis.php';

use gazer22\phpFITFileAnalysis;

// =============================================================================
// CONFIGURATION
// =============================================================================

$file_path = __DIR__ . '/demo/fit_files/josh-400k.fit';  // Path to your FIT file.
$file_id   = 12345;  // Unique identifier for this file.

// Database configuration.
$db_config = array(
	'table_name'       => 'fit_data_example',
	'data_source_name' => 'mysql:host=localhost;dbname=testdb',
	'username'         => 'root',
	'password'         => '',
);

// Processing options.
$options = array(
	'buffer_input_to_db'     => true,      // Enable buffered database mode
	'file_id'                => $file_id,  // Unique file ID
	'database'               => $db_config,

	// Checkpoint configuration.
	'mega_batch_size'        => 50000,     // Save checkpoint every 50k records
	'resume_from_checkpoint' => true,      // Auto-resume if checkpoint exists

	// Optional: adjust buffer size for DB writes.
	'buffer_size'            => 5000,      // Records to buffer before DB write
);

// =============================================================================
// PROCESSING
// =============================================================================

echo "=================================================\n";
echo "FIT File Processing with Checkpoint/Restart\n";
echo "=================================================\n\n";

echo "File: $file_path\n";
echo "File ID: $file_id\n";
echo 'Checkpoint interval: ' . number_format( $options['mega_batch_size'] ) . " records\n";
echo 'Buffer size: ' . number_format( $options['buffer_size'] ) . " records\n\n";

try {
	// Create instance - will auto-resume if checkpoint exists
	$start_time = microtime( true );

	echo "Starting processing...\n";
	$pFFA = new phpFITFileAnalysis( $file_path, $options );

	$elapsed = microtime( true ) - $start_time;

	echo "\n=================================================\n";
	echo "SUCCESS!\n";
	echo "=================================================\n";
	echo 'Processing completed in ' . round( $elapsed, 2 ) . " seconds\n";

	// Access the processed data
	$tables = $pFFA->getTableInfo();
	echo "\nTables created:\n";
	foreach ( $tables as $name => $info ) {
		echo "  - $name: {$info['location']}\n";
	}

	echo "\nCheckpoint automatically cleaned up.\n";

} catch ( Exception $e ) {
	echo "\n=================================================\n";
	echo "PROCESSING INTERRUPTED\n";
	echo "=================================================\n";
	echo 'Error: ' . $e->getMessage() . "\n";
	echo "\nA checkpoint has been saved.\n";
	echo "To resume processing, run this script again with the same file_id.\n";
	echo "\nThe processing will continue from where it left off.\n";
	exit( 1 );
}

// =============================================================================
// ADDITIONAL EXAMPLES
// =============================================================================

echo "\n\n=================================================\n";
echo "Additional Checkpoint Management Examples\n";
echo "=================================================\n\n";

// Example 1: Check if checkpoint exists
echo "Example 1: Checking for existing checkpoint\n";
echo "-------------------------------------------\n";

// Create a temporary instance just for checking
$temp_instance = new phpFITFileAnalysis( null, null );

if ( $temp_instance->checkpointExists( $file_id ) ) {
	echo "Checkpoint exists for file_id: $file_id\n";
} else {
	echo "No checkpoint found for file_id: $file_id\n";
}

echo "\n";

// Example 2: Manual checkpoint deletion (if needed)
echo "Example 2: Manual checkpoint cleanup\n";
echo "------------------------------------\n";

$cleanup_file_id = 99999;  // Example file ID to clean up

// First check if it exists
if ( $temp_instance->checkpointExists( $cleanup_file_id ) ) {
	echo "Found checkpoint for file_id: $cleanup_file_id\n";
	echo "Deleting checkpoint...\n";

	if ( $temp_instance->deleteCheckpoint( $cleanup_file_id ) ) {
		echo "Checkpoint deleted successfully.\n";
	} else {
		echo "Failed to delete checkpoint.\n";
	}
} else {
	echo "No checkpoint found for file_id: $cleanup_file_id (nothing to clean up)\n";
}

echo "\n";

// Example 3: Processing without checkpoints (for small files)
echo "Example 3: Processing without checkpoints\n";
echo "-----------------------------------------\n";

$small_file_options = array(
	'buffer_input_to_db' => true,
	'file_id'            => 54321,
	'database'           => $db_config,
	// No mega_batch_size = no checkpointing
	'buffer_size'        => 5000,
);

echo "For small files, you may not need checkpointing.\n";
echo "Simply omit the 'mega_batch_size' option.\n";
echo "Example configuration:\n";
print_r( $small_file_options );

echo "\n=================================================\n";
echo "Examples Complete\n";
echo "=================================================\n";
