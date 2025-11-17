# Quick Reference: Checkpoint & Restart

## Enable Checkpoints

```php
$options = array(
    'buffer_input_to_db'     => true,
    'file_id'                => 12345,
    'mega_batch_size'        => 100000,  // Checkpoint every 100k records
    'resume_from_checkpoint' => true,    // Auto-resume
    'database'               => array(
        'table_name'       => 'fit_data',
        'data_source_name' => 'mysql:host=localhost;dbname=mydb',
        'username'         => 'user',
        'password'         => 'pass',
    ),
);

$pFFA = new phpFITFileAnalysis('file.fit', $options);
```

## Key Concepts

| Concept | Description | Default |
|---------|-------------|---------|
| `mega_batch_size` | Records to process before saving checkpoint | `null` (disabled) |
| `buffer_size` | Records to buffer before DB write | `5000` |
| `resume_from_checkpoint` | Auto-resume from checkpoint | `false` |
| `file_id` | Unique identifier (required for checkpoints) | Required |

## Common Tasks

### Check if Checkpoint Exists
```php
$pFFA = new phpFITFileAnalysis(null, null);
if ($pFFA->checkpointExists($file_id)) {
    echo "Checkpoint exists\n";
}
```

### Delete Checkpoint
```php
$pFFA->deleteCheckpoint($file_id);
```

### Verify Transactional Support (MySQL)
```sql
-- Check if checkpoint table uses InnoDB
SELECT TABLE_NAME, ENGINE 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME LIKE 'fit_%checkpoint';

-- If MyISAM, convert to InnoDB for transaction safety
ALTER TABLE fit_data_checkpoint ENGINE=InnoDB;
```

### Process Without Checkpoints
```php
$options = array(
    'buffer_input_to_db' => true,
    'file_id'            => 12345,
    'database'           => [...],
    // No mega_batch_size = no checkpoints
);
```

## Recommended Batch Sizes

| File Size | mega_batch_size |
|-----------|-----------------|
| < 10 MB | `null` (none) |
| 10-50 MB | `25,000-50,000` |
| 50-200 MB | `50,000-100,000` |
| > 200 MB | `100,000-200,000` |

## Troubleshooting

**Not resuming?**
- Check `resume_from_checkpoint = true`
- Verify `file_id` matches
- Confirm database connection

**Too many checkpoints?**
- Increase `mega_batch_size`

**Out of memory?**
- Decrease `buffer_size`
- Increase `mega_batch_size` (less frequent saves)

## Important Notes

✅ **Only works with `buffer_input_to_db = true`**  
✅ **Requires unique `file_id` for each file**  
✅ **Checkpoint table: `{table_name}_checkpoint`**  
✅ **Auto-deleted on successful completion**  
✅ **Fully backward compatible**  

See `CHECKPOINT_USAGE.md` for complete documentation.
