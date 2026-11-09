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

- **Module Installation**: Checks whether the `Sansec_Shield` module is installed
- **Module Enablement**: Verifies the module is enabled via `sansec_shield/general/enabled` configuration
- **License Configuration**: Makes sure that a license key is configured via `sansec_shield/general/license_key`

**Check Results:**
- ✅ **OK**: Module is installed, enabled, and has a license key configured
- ⚠️ **WARNING**: Module is installed and enabled but license key is missing
- ❌ **FAILED**: Module is not installed or not enabled

### Indexer Backlog Check

This check monitors every indexer in your Magento store, including the indexers of third-party modules:

- **Backlog Monitoring**: Tracks the number of pending items for each scheduled indexer
- **Invalid Indexers**: Reports indexers with the status `invalid`, in both schedule and realtime mode
- **Status Tracking**: Reports the current status of each indexer

**Check Results:**
- ✅ **OK**: All indexers are valid and up to date, or have minimal backlog (< 1,000 items)
- ⚠️ **WARNING**: High backlog detected (1,000 - 9,999 items), or one or more indexers are invalid
- ❌ **FAILED**: Critical backlog detected (≥ 10,000 items)

**Metadata Includes:**
- Backlog size per indexer
- IDs of invalid indexers
- Maximum backlog across all indexers
- Number of indexers with backlog

### Cron Check

This check reports how long ago the last cron job finished successfully. It reads `MAX(finished_at)` from `cron_schedule`. The module adds an index on `cron_schedule` (`status`, `finished_at`), so the query stays fast on a large table.

**Check Results:**
- ✅ **OK**: The last cron job finished less than 15 minutes ago
- ⚠️ **WARNING**: The last cron job finished 15 to 59 minutes ago, or no cron job finished yet
- ❌ **FAILED**: The last cron job finished 60 minutes ago or more

**Metadata Includes:**
- Time of the last finished cron job
- Minutes since the last finished cron job
- Warning and critical thresholds

### Search Engine Check

This check pings the configured search engine. It knows Magento's own OpenSearch and Elasticsearch clients, Amasty Elastic Search and Smile ElasticSuite.

**Check Results:**
- ✅ **OK**: The search engine answers
- ❌ **FAILED**: The search engine does not answer, or the client throws an exception
- ⏭️ **SKIPPED**: No connection test exists for the configured engine

**Metadata Includes:**
- Configured engine
- Response time in ms

To test another engine, implement `Elgentos\OhDearChecks\Model\SearchEngine\ConnectionTesterInterface` and add it in `di.xml`. Return `null` for engines that your tester does not know.

```xml
<type name="Elgentos\OhDearChecks\Checks\SearchEngine">
    <arguments>
        <argument name="testers" xsi:type="array">
            <item name="mirasvit" xsi:type="object">Vendor\Module\Model\MirasvitConnectionTester</item>
        </argument>
    </arguments>
</type>
```

### Session Storage Check

This check writes a session to the session storage from `env.php` (`session/save`), reads it back and removes it. It uses the handler itself, not Magento's `SaveHandler`. When Redis is down, Magento falls back to file sessions. The shop then seems to work, but every node keeps its own sessions and customers get logged out.

**Check Results:**
- ✅ **OK**: The session storage writes and reads back a session
- ⚠️ **WARNING**: The session Redis evicted keys, or database sessions are used with `session.gc_probability = 0`, so the `session` table is never cleaned
- ❌ **FAILED**: The session storage cannot write and read back a session
- ⏭️ **SKIPPED**: No storage test exists for the handler (for example memcached)

Redis counts `evicted_keys` from the start of the server. The warning stays until Redis restarts or you run `CONFIG RESETSTAT`.

**Metadata Includes:**
- Session handler in use
- Configured handler, but only for a value that differs from the handler in use
- For Redis: `maxmemory_policy`, evicted keys and memory use

### Public Files Check

This check looks at the top level of `pub/` and `pub/media/` for files that anyone can download. SQL dumps are left to the `PublicSqlFiles` check of the Vendic module.

**Check Results:**
- ✅ **OK**: No sensitive files or archives found
- ⚠️ **WARNING**: Archives or backups found (`.zip`, `.tar.gz`, `.tgz`, `.gz`, `.bz2`, `.7z`, `.rar`, `.bak`, `.old`, `.dump`)
- ❌ **FAILED**: Project files or debug tools found (`.git`, `.env`, `auth.json`, `env.php`, `composer.json`, `composer.lock`, `info.php`, `phpinfo.php`, `adminer.php`)

**Metadata Includes:**
- Sensitive files found
- Archives found

### HTTP Cache Hosts Check

This check verifies that HTTP cache hosts (Varnish) are properly configured for cache clearing:

- **Configuration Check**: Verifies that `http_cache_hosts` is configured in `env.php`
- **Validation**: Makes sure that at least one host is configured with both `host` and `port` parameters

**Check Results:**
- ✅ **OK**: HTTP cache hosts are properly configured
- ❌ **FAILED**: HTTP cache hosts are not configured or misconfigured

**Metadata Includes:**
- Configuration status
- Number of configured hosts
- List of configured hosts (without sensitive details)

**Example Configuration in env.php:**
```php
'http_cache_hosts' => [
    [
        'host' => '127.0.0.1',
        'port' => '6081'
    ]
]
```

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
    'Elgentos\\OhDearChecks\\Checks\\HttpCacheHosts' => [
        'enabled' => false
    ],
    'Elgentos\\OhDearChecks\\Checks\\Cron' => [
        // Optional: minutes since the last finished cron job
        'warning_minutes' => 15,
        'critical_minutes' => 60
    ],
    'Elgentos\\OhDearChecks\\Checks\\SearchEngine' => [
        'enabled' => false
    ],
    'Elgentos\\OhDearChecks\\Checks\\SessionStorage' => [
        'enabled' => false
    ],
    'Elgentos\\OhDearChecks\\Checks\\PublicFiles' => [
        // Optional: paths relative to the Magento root that the check skips
        'ignore' => [
            'pub/media/catalog.zip'
        ]
    ]
]
```

### Indexer Backlog Configuration Options

- **`indexer_ids`** (array): List of indexer IDs to monitor. If not specified, the check monitors every indexer that Magento knows, including third-party indexers.
- **`warning_threshold`** (int): Global warning threshold. Default: 1,000 items. A per-indexer threshold overrides this value.
- **`critical_threshold`** (int): Global critical threshold. Default: 10,000 items. A per-indexer threshold overrides this value.
- **`thresholds`** (array): Per-indexer threshold configuration. Each indexer can have its own `warning` and `critical` values that override the global defaults.

**Priority Order:**
1. Per-indexer threshold (if configured)
2. Global threshold (if configured)
3. Default threshold (1,000 for warning, 10,000 for critical)

## Credits

The Cron, Search Engine, Session Storage and Public Files checks are based on [fruitcake/magento2-health-check](https://github.com/fruitcake/magento2-health-check), MIT License, Copyright (c) Fruitcake.

## Contributing

Feel free to submit pull requests with additional security and health checks for Magento 2.
