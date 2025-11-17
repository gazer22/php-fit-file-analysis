# Checkpoint and Restart Functionality

## Overview

The phpFITFileAnalysis class now supports checkpointing and restarting when processing FIT files in buffered database mode (`buffer_input_to_db = true`). This allows the processing to be interrupted and resumed later without losing progress, which is especially useful for large files or when processing might be interrupted by server timeouts, memory limits, or other issues.

## Key Features

- **Automatic Checkpointing**: Save processing state periodically based on configurable batch sizes
- **Seamless Resume**: Automatically detect and resume from checkpoints when processing is restarted
- **Database Persistence**: Checkpoint data is stored in a database table for reliability
- **File Position Tracking**: Tracks exact file pointer position for accurate resumption
- **State Preservation**: Saves all critical processing state including definition messages, tables created, and developer field descriptions

## Configuration Options

### New Options

1. **`mega_batch_size`** (integer, optional)
   - Number of records to process before saving a checkpoint
   - Default: `null` (checkpointing disabled)
   - Recommended: 50,000 - 200,000 depending on file size and available memory
   - Must be used with `buffer_input_to_db = true`

2. **`resume_from_checkpoint`** (boolean, optional)
   - Whether to automatically resume from a checkpoint if one exists
   - Default: `false`
   - Set to `true` to enable automatic resume

### Existing Required Options (for buffered mode)

```php
$options = array(
    'buffer_input_to_db' => true,
    'file_id'            => 12345,  // Unique file identifier
    'database'           => array(
        'table_name'       => 'fit_data',
        'data_source_name' => 'mysql:host=localhost;dbname=mydb',
        'username'         => 'user',
        'password'         => 'password',
    ),
    
    // New checkpoint options
    'mega_batch_size'        => 100000,  // Save checkpoint every 100k records
    'resume_from_checkpoint' => true,     // Auto-resume if checkpoint exists
);
```

## Usage Examples

### Example 1: Basic Checkpoint-Enabled Processing

```php
<?php
require_once 'src/phpFITFileAnalysis.php';

use gazer22\phpFITFileAnalysis;

// Configure with checkpointing
$options = array(
    'buffer_input_to_db' => true,
    'file_id'            => 101,
    'database'           => array(
        'table_name'       => 'activity_data',
        'data_source_name' => 'mysql:host=localhost;dbname=fitness',
        'username'         => 'fit_user',
        'password'         => 'secure_pass',
    ),
    'mega_batch_size'        => 50000,  // Checkpoint every 50k records
    'resume_from_checkpoint' => true,   // Auto-resume enabled
);

try {
    $pFFA = new phpFITFileAnalysis('path/to/large-file.fit', $options);
    
    // Processing will automatically:
    // 1. Check for existing checkpoint
    // 2. Resume from checkpoint if found
    // 3. Save new checkpoints every 50k records
    // 4. Clean up checkpoint on successful completion
    
    echo "Processing completed successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Progress has been saved - restart with same options to resume\n";
}
```

### Example 2: Manual Checkpoint Management

```php
<?php
// First run - process starts and may be interrupted
$options = array(
    'buffer_input_to_db'     => true,
    'file_id'                => 202,
    'mega_batch_size'        => 100000,
    'resume_from_checkpoint' => false,  // Don't auto-resume
    'database'               => array(
        'table_name'       => 'cycling_data',
        'data_source_name' => 'mysql:host=localhost;dbname=fitness',
        'username'         => 'user',
        'password'         => 'pass',
    ),
);

try {
    $pFFA = new phpFITFileAnalysis('long-ride.fit', $options);
} catch (Exception $e) {
    echo "Processing interrupted at: " . $e->getMessage() . "\n";
}

// Later - check if checkpoint exists and resume
$file_id = 202;
$pFFA_temp = new phpFITFileAnalysis(null, null);  // Empty instance for checking
if ($pFFA_temp->checkpointExists($file_id)) {
    echo "Checkpoint found for file $file_id\n";
    
    // Resume processing
    $options['resume_from_checkpoint'] = true;
    $pFFA = new phpFITFileAnalysis('long-ride.fit', $options);
    echo "Processing resumed and completed\n";
}
```

### Example 3: Different Checkpoint Sizes for Different File Types

```php
<?php
function processFile($file_path, $file_id, $file_size_mb) {
    // Adjust checkpoint frequency based on file size
    if ($file_size_mb < 10) {
        $mega_batch_size = null;  // No checkpointing for small files
    } elseif ($file_size_mb < 50) {
        $mega_batch_size = 50000;  // Medium files
    } else {
        $mega_batch_size = 100000;  // Large files - less frequent checkpoints
    }
    
    $options = array(
        'buffer_input_to_db'     => true,
        'file_id'                => $file_id,
        'mega_batch_size'        => $mega_batch_size,
        'resume_from_checkpoint' => true,
        'database'               => array(
            'table_name'       => 'fit_data',
            'data_source_name' => 'mysql:host=localhost;dbname=mydb',
            'username'         => 'user',
            'password'         => 'pass',
        ),
    );
    
    $pFFA = new phpFITFileAnalysis($file_path, $options);
    return $pFFA;
}
```

## How It Works

### Checkpoint Storage

Checkpoints are stored in a database table named `{table_name}_checkpoint` with the following structure:

- `file_num`: Unique file identifier (primary key)
- `file_path`: Path to the FIT file
- `file_pointer`: Current position in the file (bytes)
- `record_count`: Number of records processed
- `last_checkpoint_record`: Record number at last checkpoint
- `defn_mesgs`: Serialized definition messages
- `dev_field_descriptions`: Developer field descriptions
- `tables_created`: Information about database tables created
- `file_header`: FIT file header information
- `processing_start_time`: When processing started
- `checkpoint_time`: When checkpoint was saved
- `total_records_processed`: Total records processed in session

### Transactional Safety

Each checkpoint is saved using database transactions to prevent data corruption:

```
BEGIN TRANSACTION
  → Insert mega batch data (e.g., 100,000 records)
  → Save checkpoint (file position, record count, state)
COMMIT
```

**Why this matters:**
- If the server crashes mid-batch, the entire batch is rolled back
- On resume, you never get duplicate records
- Both data AND checkpoint are saved atomically (all-or-nothing)

**Requirements:**
- **MySQL/MariaDB:** Checkpoint table must use **InnoDB engine** (enforced automatically)
- **PostgreSQL/SQLite:** Transactions supported by default
- **MyISAM warning:** If detected, a warning is logged (no transaction support)

### Processing Flow

1. **Initial Start**:
   - File opens normally
   - Reads header
   - Processes records
   - Saves checkpoint every `mega_batch_size` records

2. **Resume from Checkpoint**:
   - Checkpoint detected for `file_id`
   - State restored (file_pointer, definitions, tables)
   - File reopened at saved position
   - Processing continues from last checkpoint

3. **Completion**:
   - All records processed
   - Final data flushed to database
   - Checkpoint automatically deleted

## Best Practices

### Choosing mega_batch_size

- **Small files (<10 MB)**: `null` - no checkpointing needed
- **Medium files (10-50 MB)**: `25000-50000` records
- **Large files (50-200 MB)**: `50000-100000` records
- **Very large files (>200 MB)**: `100000-200000` records

Consider:
- Larger batch sizes = fewer checkpoints = better performance
- Smaller batch sizes = more frequent saves = less work lost on failure
- Balance against your `buffer_size` (default 5000)

### Memory Considerations

```php
// For memory-constrained environments
$options = array(
    'buffer_input_to_db' => true,
    'buffer_size'        => 3000,   // Smaller buffer for DB writes
    'mega_batch_size'    => 30000,  // Checkpoint more frequently
    // ... other options
);
```

### Error Handling

```php
try {
    $pFFA = new phpFITFileAnalysis($file_path, $options);
    echo "Success! Processed file.\n";
} catch (Exception $e) {
    error_log("FIT file processing error: " . $e->getMessage());
    
    // Checkpoint is preserved - can resume later
    // Log the file_id for retry
    error_log("File ID {$options['file_id']} can be resumed");
}
```

### Manual Checkpoint Cleanup

```php
// If you need to manually clean up old checkpoints
$pFFA = new phpFITFileAnalysis(null, null);  // Empty instance

// Delete specific checkpoint
$pFFA->deleteCheckpoint($file_id);

// Or check first
if ($pFFA->checkpointExists($file_id)) {
    echo "Checkpoint exists for file $file_id\n";
    $pFFA->deleteCheckpoint($file_id);
}
```

## Important Notes

1. **File ID Must Be Unique**: The `file_id` option must be unique for each file to prevent checkpoint conflicts

2. **Same Options for Resume**: When resuming, use the same database configuration and `file_id` as the original processing

3. **Buffer vs Mega Batch**:
   - `buffer_size`: How many records to accumulate before writing to DB (default 5000)
   - `mega_batch_size`: How many records to process before saving checkpoint
   - Typically: `mega_batch_size` >> `buffer_size`

4. **Only in Buffered Mode**: Checkpointing only works when `buffer_input_to_db = true`

5. **Database Connection**: Ensure database connection is reliable; checkpoint saves require successful DB writes

6. **File Accessibility**: The FIT file must remain accessible at the same path for resumption

## Troubleshooting

### Duplicate Records After Resume

**Symptoms:** After resuming from checkpoint, some records appear twice in the database.

**Cause:** Database doesn't support transactions (e.g., MySQL with MyISAM tables).

**Solution:**
```sql
-- Check your checkpoint table engine
SELECT ENGINE FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'fit_data_checkpoint';

-- If not InnoDB, convert it (will be recreated as InnoDB automatically next run)
ALTER TABLE fit_data_checkpoint ENGINE=InnoDB;

-- Also ensure your data tables use InnoDB
ALTER TABLE record ENGINE=InnoDB;
ALTER TABLE session ENGINE=InnoDB;
```

The class automatically creates the checkpoint table with InnoDB. If you see the warning:
```
WARNING: Checkpoint table uses MyISAM engine. InnoDB recommended for transactional safety.
```
Follow the steps above to convert to InnoDB.

### Checkpoint Not Resuming

- Verify `resume_from_checkpoint = true` in options
- Check that `file_id` matches the original processing
- Ensure database connection is working
- Check logs for checkpoint load messages

### Performance Issues

- Increase `mega_batch_size` to reduce checkpoint frequency
- Adjust `buffer_size` for optimal DB write batching
- Consider server resources (memory, DB connection pool)

### Orphaned Checkpoints

```php
// Clean up old checkpoints manually if needed
$stmt = $pdo->query("SELECT file_num, checkpoint_time FROM fit_data_checkpoint");
while ($row = $stmt->fetch()) {
    $hours_old = (time() - $row['checkpoint_time']) / 3600;
    if ($hours_old > 24) {  // Older than 24 hours
        echo "Cleaning old checkpoint: {$row['file_num']}\n";
        // Delete manually or via deleteCheckpoint()
    }
}
```

## API Reference

### New Public Methods

#### `checkpointExists($file_num)`
Check if a checkpoint exists for a file.
- **Parameters**: `$file_num` (int) - File identifier
- **Returns**: `bool` - True if checkpoint exists

#### `deleteCheckpoint($file_num = null)`
Delete a checkpoint for a file.
- **Parameters**: `$file_num` (int, optional) - File identifier (defaults to current file)
- **Returns**: `bool` - True if successful

### Updated Methods

#### `__construct($file_path, $options, ...)`
Now supports checkpoint resume:
- Checks for existing checkpoint if enabled
- Restores state and resumes from checkpoint
- Falls back to normal processing if no checkpoint

#### `export_state()`
Enhanced to include checkpoint-related state:
- `mega_batch_size`
- `checkpoint_table`
- `current_file_path`
- `last_checkpoint_record`
- `processing_start_time`
- `total_records_processed`
- `file_pointer`
- `defn_mesgs`
- `dev_field_descriptions`

#### `from_state($state, $logger)`
Enhanced to restore checkpoint-related state from exported data.

## Version History

- **v1.0** (Current): Initial checkpoint/restart implementation
  - Automatic checkpointing at configurable intervals
  - Database-backed checkpoint storage
  - Seamless resume capability
  - State export/import enhancements
