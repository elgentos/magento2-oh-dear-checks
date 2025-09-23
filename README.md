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

## Configuration

You can disable any check by adding configuration to your `env.php`:

```php
'ohdear' => [
    'Elgentos\\OhDearChecks\\Checks\\SansecShield' => [
        'enabled' => false
    ]
]
```

## Contributing

Feel free to submit pull requests with additional security and health checks for Magento 2.
