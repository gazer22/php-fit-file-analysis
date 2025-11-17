# Checkpoint Transaction Safety

## Overview

The checkpoint/resume feature uses database transactions to ensure **atomic operations** and prevent data corruption. This guarantees that you never get duplicate records when resuming from an interrupted process.

## How It Works

### The Problem

Without transactions, checkpoint saving is a two-step process:

```
1. Insert 100,000 records to database  ✓
2. Save checkpoint                     ✗ (server crashes)
```

**Result:** On resume, the class restarts from record 0, re-inserting those 100,000 records again → **duplicate data**.

### The Solution: Transactional Checkpoints

Each mega batch is wrapped in a database transaction:

```sql
BEGIN TRANSACTION;
  -- Step 1: Insert batch data (e.g., 100,000 records)
  INSERT INTO record VALUES (...);
  INSERT INTO record VALUES (...);
  ...
  
  -- Step 2: Save checkpoint state
  REPLACE INTO fit_data_checkpoint VALUES (
    file_num, file_pointer, record_count, ...
  );
  
COMMIT;
```

**Key principle:** Either **BOTH** succeed, or **BOTH** are rolled back.

### What Happens on Crash

| Scenario | Without Transaction | With Transaction |
|----------|-------------------|------------------|
| Crash before insert | No data, no checkpoint ✓ | No data, no checkpoint ✓ |
| Crash during insert (60k of 100k) | 60k records inserted, no checkpoint ✗ | **ROLLBACK** - nothing inserted ✓ |
| Crash after insert, before checkpoint | 100k records, no checkpoint ✗ | **ROLLBACK** - nothing inserted ✓ |
| Crash after checkpoint | 100k records + checkpoint ✓ | 100k records + checkpoint ✓ |

With transactions, **you can only crash in states where resume is safe**.

## Implementation Details

### Methods Added

#### `processMegaBatchWithCheckpoint($total_records_processed)`

The core transactional wrapper that replaces the old two-step checkpoint save:

```php
protected function processMegaBatchWithCheckpoint($total_records_processed) {
    $this->db->beginTransaction();
    
    try {
        // Step 1: Flush buffered data to database
        $this->storeMesg(null, null, true);
        
        // Step 2: Update checkpoint state
        $this->total_records_processed = $total_records_processed;
        
        // Step 3: Save checkpoint to database
        $this->saveCheckpoint($total_records_processed);
        
        // Step 4: Commit both together
        $this->db->commit();
        
        return true;
        
    } catch (Exception $e) {
        // Rollback everything on any failure
        $this->db->rollBack();
        throw $e;
    }
}
```

#### `verifyTransactionSupport()`

Checks that the database supports transactions and warns if not:

```php
protected function verifyTransactionSupport() {
    $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    if ($driver === 'mysql') {
        // Check if checkpoint table uses InnoDB
        $result = /* query information_schema.TABLES */;
        
        if ($result['ENGINE'] !== 'innodb') {
            $this->logger->warning(
                "Checkpoint table uses {$result['ENGINE']}. " .
                "InnoDB recommended for transactional safety."
            );
        }
    }
}
```

### Modified Methods

#### `createCheckpointTable()`

Now forces InnoDB engine for MySQL:

```php
$driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
$engine_clause = ($driver === 'mysql') ? 'ENGINE=InnoDB' : '';

$sql = "CREATE TABLE IF NOT EXISTS `{$this->checkpoint_table}` (
    ...
) {$engine_clause} DEFAULT CHARSET=utf8mb4";
```

#### `readDataRecords()`

Replaced inline checkpoint save with transactional method:

**Before:**
```php
if ($record_count - $this->last_checkpoint_record >= $this->mega_batch_size) {
    $this->storeMesg(null, null, true);        // Flush data
    $this->total_records_processed = $record_count;
    $this->saveCheckpoint($record_count);       // Save checkpoint
}
```

**After:**
```php
if ($record_count - $this->last_checkpoint_record >= $this->mega_batch_size) {
    $this->processMegaBatchWithCheckpoint($record_count);  // Atomic!
}
```

## Database Requirements

### MySQL/MariaDB

**Required:** InnoDB storage engine

```sql
-- Check your tables
SELECT TABLE_NAME, ENGINE 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE();

-- Convert to InnoDB if needed
ALTER TABLE fit_data_checkpoint ENGINE=InnoDB;
ALTER TABLE record ENGINE=InnoDB;
ALTER TABLE session ENGINE=InnoDB;
```

**Why InnoDB?**
- Supports ACID transactions
- Row-level locking
- Crash recovery
- Foreign key support

**What about MyISAM?**
- ❌ No transaction support
- ❌ No rollback capability
- ❌ Table-level locking only
- ⚠️ Will trigger warning in logs

### PostgreSQL

✓ Transactions supported by default (all tables)

### SQLite

✓ Transactions supported by default

## Verification

### Test Transaction Safety

```php
<?php
$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');

// Enable exceptions
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->beginTransaction();
    
    // Insert test data
    $pdo->exec("INSERT INTO record VALUES (...)");
    
    // Simulate crash before checkpoint
    throw new Exception("Simulated crash");
    
    // This never executes
    $pdo->exec("REPLACE INTO fit_data_checkpoint VALUES (...)");
    $pdo->commit();
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Transaction rolled back - no data inserted\n";
}

// Verify: record table should be empty
$count = $pdo->query("SELECT COUNT(*) FROM record")->fetchColumn();
echo "Records in table: {$count}\n";  // Should be 0
```

### Monitor Warnings

Watch your logs for transaction warnings:

```
WARNING: Checkpoint table 'fit_data_checkpoint' uses MyISAM engine. 
InnoDB is recommended for transactional safety. Duplicate records may occur on resume.
```

If you see this, convert to InnoDB immediately.

## Performance Impact

### Transaction Overhead

Transactions add minimal overhead:

- **Begin:** < 1ms
- **Commit:** 1-5ms (depends on disk I/O)
- **Rollback:** < 1ms (rare)

For mega batches of 100,000 records:
- Insert time: ~10-30 seconds
- Transaction overhead: < 0.1%

### Optimization Tips

1. **Use InnoDB** - MyISAM doesn't support transactions anyway
2. **Adjust mega_batch_size** - Larger batches = fewer commits
3. **innodb_flush_log_at_trx_commit**:
   ```sql
   -- Check current setting
   SHOW VARIABLES LIKE 'innodb_flush_log_at_trx_commit';
   
   -- 1 = Full ACID (safest, slower)
   -- 2 = Write to log, flush every second (fast, slight risk)
   SET GLOBAL innodb_flush_log_at_trx_commit = 1;
   ```

## Error Handling

### Transaction Failures

If a transaction fails, the entire mega batch is rolled back:

```
ERROR: Mega batch checkpoint failed at record 100000: Deadlock found
```

**What happens:**
- All 100,000 inserts are rolled back
- Checkpoint is NOT saved
- On next run, processing resumes from last successful checkpoint
- No duplicate records

### Recovery

The class automatically handles transaction failures:

```php
try {
    $this->processMegaBatchWithCheckpoint($record_count);
} catch (Exception $e) {
    // Transaction already rolled back
    // Re-throw with context
    throw new Exception(
        "Mega batch checkpoint failed at record {$record_count}: " .
        $e->getMessage()
    );
}
```

**Resume behavior:**
- Last successful checkpoint: Record 0
- Failed mega batch: Records 0-100,000
- On resume: Starts from record 0 (correct!)

## Best Practices

### 1. Always Use InnoDB

```sql
-- Set default engine for new tables
SET default_storage_engine=InnoDB;

-- Or specify in CREATE TABLE
CREATE TABLE record (...) ENGINE=InnoDB;
```

### 2. Choose Appropriate Batch Size

Balance checkpoint frequency vs transaction size:

| mega_batch_size | Checkpoint Frequency | Transaction Size | Recommendation |
|-----------------|---------------------|------------------|----------------|
| 10,000 | Every 10k records | Small | Too frequent |
| 50,000 | Every 50k records | Medium | Good for 10-50 MB files |
| 100,000 | Every 100k records | Large | Good for 50-200 MB files |
| 500,000 | Every 500k records | Very large | Only for huge files |

### 3. Monitor Disk Space

Transactions use disk space for rollback segments:

```sql
-- Check InnoDB status
SHOW ENGINE INNODB STATUS;

-- Monitor tablespace
SELECT 
    table_schema,
    SUM(data_length + index_length) / 1024 / 1024 AS size_mb
FROM information_schema.tables
WHERE engine = 'InnoDB'
GROUP BY table_schema;
```

### 4. Test Crash Recovery

Simulate crashes during development:

```php
// Add to your test script
if ($record_count === 50000) {
    throw new Exception("Test crash at 50k");
}
```

Then verify:
1. No duplicate records
2. Resume picks up correctly
3. Final data is complete

## Troubleshooting

### "Transaction rolled back" Errors

**Cause:** Database issue during mega batch processing

**Solutions:**
1. Check database connection stability
2. Verify sufficient disk space
3. Check InnoDB log size: `innodb_log_file_size`
4. Review MySQL error log

### Duplicate Records After Resume

**Symptom:** Same records appear multiple times

**Diagnosis:**
```sql
-- Check for duplicates
SELECT timestamp, COUNT(*) 
FROM record 
GROUP BY timestamp 
HAVING COUNT(*) > 1;
```

**Cause:** Database doesn't support transactions (MyISAM)

**Fix:**
```sql
-- Remove duplicates
CREATE TABLE record_clean AS 
SELECT DISTINCT * FROM record;

DROP TABLE record;
ALTER TABLE record_clean RENAME TO record;

-- Convert to InnoDB
ALTER TABLE record ENGINE=InnoDB;
```

### Performance Degradation

**Symptom:** Processing slower with transactions

**Diagnosis:**
```sql
-- Check if using InnoDB
SELECT ENGINE FROM information_schema.TABLES 
WHERE TABLE_NAME = 'record';

-- Check transaction isolation level
SELECT @@tx_isolation;
```

**Optimization:**
```sql
-- Use READ COMMITTED (less locking)
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;

-- Increase buffer pool
SET GLOBAL innodb_buffer_pool_size = 2G;
```

## Summary

✅ **With Transactions:**
- Atomic mega batch + checkpoint
- No duplicate records on crash
- Safe resume from any interruption
- Minimal performance overhead

❌ **Without Transactions:**
- Separate insert + checkpoint operations
- Duplicate records on crash
- Unsafe resume states
- Data corruption risk

**Bottom line:** Always use InnoDB for MySQL. The transactional checkpoint implementation makes crash recovery completely safe.
