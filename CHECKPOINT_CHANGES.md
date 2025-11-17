# Checkpoint and Restart Feature - Summary

## What Was Added

This refactoring adds checkpoint and restart capability to the `phpFITFileAnalysis` class, allowing processing to be interrupted and resumed without losing progress. This is particularly useful for:

- Large FIT files that take significant time to process
- Environments with limited execution time (e.g., PHP max_execution_time)
- Situations where processing might be interrupted (server restarts, memory limits)
- Long-running batch processes that need fault tolerance

## Changes Made

### 1. New Class Properties

Added several properties to track processing state:

- `$mega_batch_size` - Number of records between checkpoints
- `$current_file_path` - Path to file being processed
- `$checkpoint_table` - Database table name for checkpoints
- `$last_checkpoint_record` - Last record where checkpoint was saved
- `$processing_start_time` - When processing started
- `$total_records_processed` - Total records processed

### 2. New Methods

#### Checkpoint Management
- `createCheckpointTable()` - Creates checkpoint storage table
- `saveCheckpoint($record_count)` - Saves current processing state
- `loadCheckpoint($file_num)` - Loads saved checkpoint
- `restoreFromCheckpoint($checkpoint)` - Restores state from checkpoint
- `deleteCheckpoint($file_num)` - Removes checkpoint after completion
- `checkpointExists($file_num)` - Checks if checkpoint exists

### 3. Modified Methods

#### `__construct()`
- Detects existing checkpoints
- Auto-resumes from checkpoint when enabled
- Cleans up checkpoints after successful completion

#### `readDataRecords()`
- Accepts starting record number for resume
- Saves checkpoints at mega_batch_size intervals
- Seeks to file position when resuming

#### `export_state()` and `from_state()`
- Enhanced to include checkpoint-related state
- Enables complete state persistence/restoration

## Configuration

### New Options

```php
$options = array(
    // Existing required for buffered mode
    'buffer_input_to_db' => true,
    'file_id'            => 12345,
    'database'           => array(
        'table_name'       => 'fit_data',
        'data_source_name' => 'mysql:host=localhost;dbname=mydb',
        'username'         => 'user',
        'password'         => 'pass',
    ),
    
    // New checkpoint options
    'mega_batch_size'        => 100000,  // Optional: records per checkpoint
    'resume_from_checkpoint' => true,    // Optional: auto-resume enabled
);
```

## Usage

### Basic Usage with Checkpoints

```php
try {
    $pFFA = new phpFITFileAnalysis('large-file.fit', $options);
    // Processing will auto-checkpoint and auto-resume
} catch (Exception $e) {
    // Checkpoint saved - can resume by running again
}
```

### Manual Checkpoint Management

```php
// Check for checkpoint
if ($pFFA->checkpointExists($file_id)) {
    echo "Checkpoint found - will resume\n";
}

// Clean up old checkpoint
$pFFA->deleteCheckpoint($file_id);
```

## Technical Details

### Checkpoint Storage

Checkpoints are stored in a database table `{table_name}_checkpoint` containing:

- File identification (file_num, file_path)
- Processing position (file_pointer, record_count)
- State data (defn_mesgs, tables_created, file_header)
- Timing information (processing_start_time, checkpoint_time)

### How It Works

1. **During Processing**: Every `mega_batch_size` records, the current state is saved to the checkpoint table
2. **On Resume**: If a checkpoint exists and `resume_from_checkpoint` is true, processing starts from the saved file position
3. **On Completion**: The checkpoint is automatically deleted

### Performance Considerations

- **Checkpoint Frequency**: Balance between fault tolerance (smaller batch) and performance (larger batch)
- **Recommended**: 50,000 - 200,000 records depending on file size
- **Overhead**: Minimal - checkpoint save is a single database write

## Files Modified

- `src/phpFITFileAnalysis.php` - Core class with checkpoint functionality

## Files Added

- `CHECKPOINT_USAGE.md` - Comprehensive usage documentation
- `demo/checkpoint-example.php` - Working example script
- `CHECKPOINT_CHANGES.md` - This summary document

## Backward Compatibility

✅ **Fully backward compatible**

- Checkpointing is opt-in via `mega_batch_size` option
- Without this option, behavior is identical to previous version
- Existing code continues to work without modification
- Only applies to buffered database mode (`buffer_input_to_db = true`)

## Testing Recommendations

1. Test with small files without checkpointing
2. Test with large files with checkpointing enabled
3. Test resume by interrupting processing mid-way
4. Test with different `mega_batch_size` values
5. Test checkpoint cleanup after successful completion

## Future Enhancements (Not Implemented)

Potential future improvements:

- Support for multiple checkpoint retention
- Checkpoint expiration/cleanup policies
- Progress reporting based on checkpoints
- Checkpoint compression for large state data
- Alternative checkpoint storage backends (file-based, Redis, etc.)

## Questions or Issues?

See `CHECKPOINT_USAGE.md` for detailed documentation including:
- Complete API reference
- Usage examples
- Best practices
- Troubleshooting guide

---

**Version**: 1.0  
**Date**: November 2025  
**Author**: Refactored to support checkpoint/restart capability
