<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Checks;

use Magento\Framework\App\DeploymentConfig;
use Vendic\OhDear\Api\CheckInterface;
use Vendic\OhDear\Api\Data\CheckResultInterface;
use Vendic\OhDear\Api\Data\CheckStatus;
use Vendic\OhDear\Model\CheckResultFactory;

class OpcacheHitRate implements CheckInterface
{
    // Default thresholds for determining check status (can be overridden in env.php)
    // Thresholds represent minimum acceptable hit rate percentage
    private const DEFAULT_WARNING_THRESHOLD = 95.0;
    private const DEFAULT_CRITICAL_THRESHOLD = 90.0;

    public function __construct(
        private CheckResultFactory $checkResultFactory,
        private DeploymentConfig $deploymentConfig,
    ) {
    }

    public function run(): CheckResultInterface
    {
        $checkResult = $this->checkResultFactory->create();
        $checkResult->setName('opcache_hit_rate');
        $checkResult->setLabel('OPcache Hit Rate');

        // Check if OPcache is enabled
        if (!function_exists('opcache_get_status')) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setNotificationMessage('OPcache extension is not available');
            $checkResult->setShortSummary('OPcache not available');
            $checkResult->setMeta([
                'opcache_available' => false,
            ]);
            return $checkResult;
        }

        $status = @opcache_get_status(false);

        if ($status === false || !isset($status['opcache_statistics'])) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setNotificationMessage('OPcache is not enabled');
            $checkResult->setShortSummary('OPcache disabled');
            $checkResult->setMeta([
                'opcache_available' => true,
                'opcache_enabled' => false,
            ]);
            return $checkResult;
        }

        $stats = $status['opcache_statistics'];
        $hits = $stats['hits'] ?? 0;
        $misses = $stats['misses'] ?? 0;
        $blacklistMisses = $stats['blacklist_misses'] ?? 0;
        $numCachedScripts = $stats['num_cached_scripts'] ?? 0;
        $hitRate = $stats['opcache_hit_rate'] ?? 0.0;

        $warningThreshold = $this->getWarningThreshold();
        $criticalThreshold = $this->getCriticalThreshold();

        // Set metadata
        $checkResult->setMeta([
            'opcache_available' => true,
            'opcache_enabled' => true,
            'hits' => $hits,
            'misses' => $misses,
            'blacklist_misses' => $blacklistMisses,
            'num_cached_scripts' => $numCachedScripts,
            'hit_rate' => $hitRate,
            'warning_threshold' => $warningThreshold,
            'critical_threshold' => $criticalThreshold,
        ]);

        // Determine status based on hit rate
        if ($hitRate < $criticalThreshold) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setNotificationMessage(
                sprintf('Critical: OPcache hit rate is %.2f%% (below %.2f%%)', $hitRate, $criticalThreshold)
            );
            $checkResult->setShortSummary(sprintf('Hit rate: %.2f%%', $hitRate));
        } elseif ($hitRate < $warningThreshold) {
            $checkResult->setStatus(CheckStatus::STATUS_WARNING);
            $checkResult->setNotificationMessage(
                sprintf('Warning: OPcache hit rate is %.2f%% (below %.2f%%)', $hitRate, $warningThreshold)
            );
            $checkResult->setShortSummary(sprintf('Hit rate: %.2f%%', $hitRate));
        } else {
            $checkResult->setStatus(CheckStatus::STATUS_OK);
            $checkResult->setNotificationMessage(
                sprintf('OPcache hit rate is healthy at %.2f%%', $hitRate)
            );
            $checkResult->setShortSummary(sprintf('Hit rate: %.2f%%', $hitRate));
        }

        return $checkResult;
    }

    /**
     * Get the warning threshold from configuration or default
     *
     * @return float
     */
    private function getWarningThreshold(): float
    {
        $config = $this->deploymentConfig->get('ohdear/Elgentos\OhDearChecks\Checks\OpcacheHitRate/warning_threshold');
        
        if (is_numeric($config) && $config > 0) {
            return (float) $config;
        }

        return self::DEFAULT_WARNING_THRESHOLD;
    }

    /**
     * Get the critical threshold from configuration or default
     *
     * @return float
     */
    private function getCriticalThreshold(): float
    {
        $config = $this->deploymentConfig->get('ohdear/Elgentos\OhDearChecks\Checks\OpcacheHitRate/critical_threshold');
        
        if (is_numeric($config) && $config > 0) {
            return (float) $config;
        }

        return self::DEFAULT_CRITICAL_THRESHOLD;
    }
}
