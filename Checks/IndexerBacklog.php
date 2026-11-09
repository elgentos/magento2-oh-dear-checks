<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Checks;

use Magento\Framework\Indexer\ConfigInterface as IndexerConfig;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\App\DeploymentConfig;
use Vendic\OhDear\Api\CheckInterface;
use Vendic\OhDear\Api\Data\CheckResultInterface;
use Vendic\OhDear\Api\Data\CheckStatus;
use Vendic\OhDear\Model\CheckResultFactory;

/**
 * Reports invalid indexers and the changelog backlog of scheduled indexers.
 *
 * Checks every indexer Magento knows, including those of third-party modules, unless
 * indexer_ids in env.php limits the list.
 */
class IndexerBacklog implements CheckInterface
{
    // Default thresholds for determining check status (can be overridden in env.php)
    private const DEFAULT_WARNING_THRESHOLD = 1000;
    private const DEFAULT_CRITICAL_THRESHOLD = 10000;

    public function __construct(
        private IndexerRegistry $indexerRegistry,
        private CheckResultFactory $checkResultFactory,
        private DeploymentConfig $deploymentConfig,
        private IndexerConfig $indexerConfig,
    ) {
    }

    public function run(): CheckResultInterface
    {
        $checkResult = $this->checkResultFactory->create();
        $checkResult->setName('indexer_backlog');
        $checkResult->setLabel('Indexer Backlog');

        [$backlogData, $invalidIndexers] = $this->collectIndexerData();

        // Calculate statistics and determine per-indexer status
        $maxBacklog = 0;
        $totalBacklog = 0;
        $indexersWithBacklog = 0;
        $worstStatus = $invalidIndexers ? CheckStatus::STATUS_WARNING : CheckStatus::STATUS_OK;
        $criticalIndexers = [];
        $warningIndexers = [];

        foreach ($backlogData as $indexerId => $data) {
            $backlog = $data['backlog'];
            $warningThreshold = $this->getWarningThreshold($indexerId);
            $criticalThreshold = $this->getCriticalThreshold($indexerId);

            // Add threshold info to backlog data
            $backlogData[$indexerId]['warning_threshold'] = $warningThreshold;
            $backlogData[$indexerId]['critical_threshold'] = $criticalThreshold;

            // Determine per-indexer status
            if ($backlog >= $criticalThreshold) {
                $backlogData[$indexerId]['check_status'] = CheckStatus::STATUS_FAILED;
                $worstStatus = CheckStatus::STATUS_FAILED;
                $criticalIndexers[] = $data['title'];
            } elseif ($backlog >= $warningThreshold) {
                $backlogData[$indexerId]['check_status'] = CheckStatus::STATUS_WARNING;
                if ($worstStatus !== CheckStatus::STATUS_FAILED) {
                    $worstStatus = CheckStatus::STATUS_WARNING;
                }
                $warningIndexers[] = $data['title'];
            } else {
                $backlogData[$indexerId]['check_status'] = CheckStatus::STATUS_OK;
            }

            if ($backlog > 0) {
                $indexersWithBacklog++;
                $totalBacklog += $backlog;
                if ($backlog > $maxBacklog) {
                    $maxBacklog = $backlog;
                }
            }
        }

        // Set metadata
        $checkResult->setMeta([
            'indexers' => $backlogData,
            'invalid_indexers' => array_keys($invalidIndexers),
            'max_backlog' => $maxBacklog,
            'total_backlog' => $totalBacklog,
            'indexers_with_backlog' => $indexersWithBacklog,
        ]);

        // Determine overall status based on worst per-indexer status
        $checkResult->setStatus($worstStatus);

        $messages = [];
        $summaries = [];
        if ($criticalIndexers) {
            $messages[] = sprintf('Critical backlog in indexer(s): %s', implode(', ', $criticalIndexers));
            $summaries[] = sprintf('%d critical indexer(s)', count($criticalIndexers));
        }
        if ($warningIndexers) {
            $messages[] = sprintf('High backlog in indexer(s): %s', implode(', ', $warningIndexers));
            $summaries[] = sprintf('%d warning indexer(s)', count($warningIndexers));
        }
        if ($invalidIndexers) {
            $messages[] = sprintf('Invalid indexer(s): %s', implode(', ', $invalidIndexers));
            $summaries[] = sprintf('%d invalid indexer(s)', count($invalidIndexers));
        }

        if ($messages) {
            $checkResult->setNotificationMessage(implode('. ', $messages));
            $checkResult->setShortSummary(implode(', ', $summaries));
        } elseif ($indexersWithBacklog > 0) {
            $checkResult->setNotificationMessage(
                sprintf('%d indexer(s) have backlog but within acceptable range', $indexersWithBacklog)
            );
            $checkResult->setShortSummary(sprintf('%d indexer(s) with minor backlog', $indexersWithBacklog));
        } else {
            $checkResult->setNotificationMessage('All indexers are up to date');
            $checkResult->setShortSummary('All indexers up to date');
        }

        return $checkResult;
    }

    /**
     * Backlog data of scheduled indexers and the titles of invalid indexers, both keyed by indexer ID
     *
     * @return array{0: array<string, array>, 1: array<string, string>}
     */
    private function collectIndexerData(): array
    {
        $backlogData = [];
        $invalidIndexers = [];

        foreach ($this->getIndexerIds() as $indexerId) {
            try {
                $indexer = $this->indexerRegistry->get($indexerId);

                if ($indexer->isInvalid()) {
                    $invalidIndexers[$indexerId] = (string) $indexer->getTitle();
                }

                // Only scheduled indexers have a changelog backlog
                if (!$indexer->isScheduled()) {
                    continue;
                }

                $view = $indexer->getView();
                $backlog = (int) $view->getChangelog()->getVersion() - (int) $view->getState()->getVersionId();

                $backlogData[$indexerId] = [
                    'title' => (string) $indexer->getTitle(),
                    'backlog' => max(0, $backlog), // Ensure non-negative
                    'status' => $indexer->getStatus(),
                ];
            } catch (\Exception $e) {
                // Skip indexers that don't exist or have errors
                continue;
            }
        }

        return [$backlogData, $invalidIndexers];
    }

    /**
     * Get the list of indexer IDs to check from configuration, or every indexer Magento knows
     *
     * @return string[]
     */
    private function getIndexerIds(): array
    {
        $config = $this->deploymentConfig->get('ohdear/Elgentos\OhDearChecks\Checks\IndexerBacklog/indexer_ids');

        if (is_array($config) && !empty($config)) {
            return array_values(array_map('strval', $config));
        }

        return array_map('strval', array_keys($this->indexerConfig->getIndexers()));
    }

    /**
     * Get the warning threshold from configuration or default
     *
     * @param string|null $indexerId Indexer ID for per-indexer threshold
     * @return int
     */
    private function getWarningThreshold(?string $indexerId = null): int
    {
        // Check for per-indexer threshold first
        if ($indexerId !== null) {
            $perIndexerConfig = $this->deploymentConfig->get(
                'ohdear/Elgentos\OhDearChecks\Checks\IndexerBacklog/thresholds/' . $indexerId . '/warning'
            );
            if (is_numeric($perIndexerConfig) && $perIndexerConfig > 0) {
                return (int) $perIndexerConfig;
            }
        }

        // Fall back to global threshold
        $config = $this->deploymentConfig->get('ohdear/Elgentos\OhDearChecks\Checks\IndexerBacklog/warning_threshold');

        if (is_numeric($config) && $config > 0) {
            return (int) $config;
        }

        return self::DEFAULT_WARNING_THRESHOLD;
    }

    /**
     * Get the critical threshold from configuration or default
     *
     * @param string|null $indexerId Indexer ID for per-indexer threshold
     * @return int
     */
    private function getCriticalThreshold(?string $indexerId = null): int
    {
        // Check for per-indexer threshold first
        if ($indexerId !== null) {
            $perIndexerConfig = $this->deploymentConfig->get(
                'ohdear/Elgentos\OhDearChecks\Checks\IndexerBacklog/thresholds/' . $indexerId . '/critical'
            );
            if (is_numeric($perIndexerConfig) && $perIndexerConfig > 0) {
                return (int) $perIndexerConfig;
            }
        }

        // Fall back to global threshold
        $config = $this->deploymentConfig->get('ohdear/Elgentos\OhDearChecks\Checks\IndexerBacklog/critical_threshold');

        if (is_numeric($config) && $config > 0) {
            return (int) $config;
        }

        return self::DEFAULT_CRITICAL_THRESHOLD;
    }
}
