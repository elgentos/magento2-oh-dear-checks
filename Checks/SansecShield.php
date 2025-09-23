<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Checks;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\ModuleListInterface;
use Vendic\OhDear\Api\CheckInterface;
use Vendic\OhDear\Api\Data\CheckResultInterface;
use Vendic\OhDear\Api\Data\CheckStatus;
use Vendic\OhDear\Model\CheckResultFactory;

class SansecShield implements CheckInterface
{
    private const MODULE_NAME = 'Sansec_Shield';
    private const CONFIG_PATH_ENABLED = 'sansec_shield/general/enabled';
    private const CONFIG_PATH_LICENSE_KEY = 'sansec_shield/general/license_key';

    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private ModuleListInterface $moduleList,
        private CheckResultFactory $checkResultFactory,
    ) {
    }

    public function run(): CheckResultInterface
    {
        $checkResult = $this->checkResultFactory->create();
        $checkResult->setName('sansec_shield');
        $checkResult->setLabel('Sansec Shield Security');

        $isModuleInstalled = $this->isModuleInstalled();
        $isModuleEnabled = $this->isModuleEnabled();
        $isLicenseConfigured = $this->isLicenseConfigured();

        $checkResult->setMeta([
            'module_installed' => $isModuleInstalled,
            'module_enabled' => $isModuleEnabled,
            'license_configured' => $isLicenseConfigured,
            'enabled_config_value' => $this->scopeConfig->getValue(self::CONFIG_PATH_ENABLED),
            'license_key_present' => !empty($this->scopeConfig->getValue(self::CONFIG_PATH_LICENSE_KEY))
        ]);

        // Check if module is installed
        if (!$isModuleInstalled) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setNotificationMessage('Sansec Shield module is not installed');
            $checkResult->setShortSummary('Sansec Shield not installed');
            return $checkResult;
        }

        // Check if module is enabled in configuration
        if (!$isModuleEnabled) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setNotificationMessage('Sansec Shield module is installed but not enabled in configuration');
            $checkResult->setShortSummary('Sansec Shield disabled');
            return $checkResult;
        }

        // Check if license key is configured
        if (!$isLicenseConfigured) {
            $checkResult->setStatus(CheckStatus::STATUS_WARNING);
            $checkResult->setNotificationMessage('Sansec Shield is enabled but license key is not configured');
            $checkResult->setShortSummary('Sansec Shield license missing');
            return $checkResult;
        }

        // All checks passed
        $checkResult->setStatus(CheckStatus::STATUS_OK);
        $checkResult->setNotificationMessage('Sansec Shield is properly installed, enabled, and configured');
        $checkResult->setShortSummary('Sansec Shield properly configured');

        return $checkResult;
    }

    /**
     * Check if Sansec Shield module is installed
     */
    private function isModuleInstalled(): bool
    {
        return $this->moduleList->has(self::MODULE_NAME);
    }

    /**
     * Check if Sansec Shield module is enabled in configuration
     */
    private function isModuleEnabled(): bool
    {
        $enabledValue = $this->scopeConfig->getValue(self::CONFIG_PATH_ENABLED);
        return $enabledValue === '1' || $enabledValue === 1 || $enabledValue === true;
    }

    /**
     * Check if Sansec Shield license key is configured
     */
    private function isLicenseConfigured(): bool
    {
        $licenseKey = $this->scopeConfig->getValue(self::CONFIG_PATH_LICENSE_KEY);
        return !empty($licenseKey) && trim($licenseKey) !== '';
    }
}
