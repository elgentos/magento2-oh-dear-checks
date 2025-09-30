# elgentos/magento2-oh-dear-checks

This Magento 2 module extends the [Vendic OhDear module](https://github.com/vendic/magento2-oh-dear) with additional application health checks.

## Installation

```bash
composer require elgentos/magento2-oh-dear-checks
bin/magento module:enable Elgentos_OhDearChecks
bin/magento setup:upgrade
```

## Available Checks

### Sansec Shield Security Check

This check verifies that the Sansec Shield security extension is properly installed, enabled, and configured:

- **Module Installation**: Checks if the `Sansec_Shield` module is installed
- **Module Enablement**: Verifies the module is enabled via `sansec_shield/general/enabled` configuration
- **License Configuration**: Ensures a license key is configured via `sansec_shield/general/license_key`

**Check Results:**
- ✅ **OK**: Module is installed, enabled, and has a license key configured
- ⚠️ **WARNING**: Module is installed and enabled but license key is missing
- ❌ **FAILED**: Module is not installed or not enabled

### Indexer Backlog Check

This check monitors the backlog size for all scheduled indexers in your Magento store:

- **Backlog Monitoring**: Tracks the number of pending items for each scheduled indexer
- **Status Tracking**: Reports the current status of each indexer

**Check Results:**
- ✅ **OK**: All indexers are up to date or have minimal backlog (< 1,000 items)
- ⚠️ **WARNING**: High backlog detected (1,000 - 9,999 items)
- ❌ **FAILED**: Critical backlog detected (≥ 10,000 items)

**Metadata Includes:**
- Backlog size per indexer
- Maximum backlog across all indexers
- Number of indexers with backlog

### OPcache Hit Rate Check

This check monitors the PHP OPcache hit rate to ensure optimal performance:

- **Hit Rate Monitoring**: Tracks the OPcache hit rate percentage
- **Cache Statistics**: Reports hits, misses, and cached scripts

**Check Results:**
- ✅ **OK**: Hit rate is healthy (≥ 95%)
- ⚠️ **WARNING**: Hit rate is suboptimal (90% - 94.99%)
- ❌ **FAILED**: Hit rate is critical (< 90%) or OPcache is not enabled

**Metadata Includes:**
- Hits count
- Misses count
- Blacklist misses count
- Number of cached scripts
- Current hit rate percentage

## Configuration

You can disable any check by adding configuration to your `env.php`:

```php
'ohdear' => [
    'Elgentos\\OhDearChecks\\Checks\\SansecShield' => [
        'enabled' => false
    ],
    'Elgentos\\OhDearChecks\\Checks\\IndexerBacklog' => [
        'enabled' => false,
        // Optional: customize which indexers to check
        'indexer_ids' => [
            'catalog_product_price',
            'catalog_category_product',
            'catalogsearch_fulltext',
            // ... add or remove indexer IDs as needed
        ],
        // Optional: global default thresholds (used when no per-indexer threshold is set)
        'warning_threshold' => 1000,
        'critical_threshold' => 10000,
        // Optional: per-indexer thresholds (override global defaults)
        'thresholds' => [
            'catalog_product_price' => [
                'warning' => 500,
                'critical' => 5000
            ],
            'catalogsearch_fulltext' => [
                'warning' => 2000,
                'critical' => 15000
            ],
            // ... configure thresholds for specific indexers
        ]
    ],
    'Elgentos\\OhDearChecks\\Checks\\OpcacheHitRate' => [
        'enabled' => false,
        // Optional: minimum acceptable hit rate thresholds (as percentages)
        'warning_threshold' => 95.0,   // Warning if hit rate falls below 95%
        'critical_threshold' => 90.0,  // Critical if hit rate falls below 90%
    ]
]
```

### Indexer Backlog Configuration Options

- **`indexer_ids`** (array): List of indexer IDs to monitor. If not specified, a default list of 10 common indexers is used.
- **`warning_threshold`** (int): Global warning threshold. Default: 1,000 items. Used when no per-indexer threshold is configured.
- **`critical_threshold`** (int): Global critical threshold. Default: 10,000 items. Used when no per-indexer threshold is configured.
- **`thresholds`** (array): Per-indexer threshold configuration. Each indexer can have its own `warning` and `critical` values that override the global defaults.

**Priority Order:**
1. Per-indexer threshold (if configured)
2. Global threshold (if configured)
3. Default threshold (1,000 for warning, 10,000 for critical)

### OPcache Hit Rate Configuration Options

- **`warning_threshold`** (float): Minimum acceptable hit rate percentage for warning status. Default: 95.0%. If hit rate falls below this value, a warning is triggered.
- **`critical_threshold`** (float): Minimum acceptable hit rate percentage for critical status. Default: 90.0%. If hit rate falls below this value, a critical alert is triggered.

**Note:** The thresholds represent the minimum acceptable hit rate. Lower values mean worse performance.

## Contributing

Feel free to submit pull requests with additional security and health checks for Magento 2.
