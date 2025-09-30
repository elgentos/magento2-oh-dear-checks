<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Checks;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\DeploymentConfig;
use Vendic\OhDear\Api\CheckInterface;
use Vendic\OhDear\Api\Data\CheckResultInterface;
use Vendic\OhDear\Api\Data\CheckStatus;
use Vendic\OhDear\Model\CheckResultFactory;

class IndexerBacklog implements CheckInterface
{
    // Default indexer IDs to check (can be overridden in env.php)
    private const DEFAULT_INDEXER_IDS = [
        'catalog_product_price',
        'catalog_category_product',
        'catalogsearch_fulltext',
        'catalog_product_attribute',
        'cataloginventory_stock',
        'inventory',
        'catalogrule_rule',
        'catalogrule_product',
        'customer_grid',
        'design_config_grid',
        'targetrule_product_rule',
        'targetrule_rule_product',
    ];

    // Default thresholds for determining check status (can be overridden in env.php)
    private const DEFAULT_WARNING_THRESHOLD = 1000;
    private const DEFAULT_CRITICAL_THRESHOLD = 10000;

    public function __construct(
        private IndexerRegistry $indexerRegistry,
        private ProductCollectionFactory $productCollectionFactory,
        private CheckResultFactory $checkResultFactory,
        private DeploymentConfig $deploymentConfig,
    ) {
    }

    public function run(): CheckResultInterface
    {
        $checkResult = $this->checkResultFactory->create();
        $checkResult->setName('indexer_backlog');
        $checkResult->setLabel('Indexer Backlog');

        $warningThreshold = $this->getWarningThreshold();
        $criticalThreshold = $this->getCriticalThreshold();

        $backlogData = $this->getAllIndexerBacklogs();
        $totalProducts = $this->getTotalProducts();

        // Calculate statistics
        $maxBacklog = 0;
        $totalBacklog = 0;
        $indexersWithBacklog = 0;

        foreach ($backlogData as $indexerId => $data) {
            $backlog = $data['backlog'];
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
            'total_products' => $totalProducts,
            'max_backlog' => $maxBacklog,
            'total_backlog' => $totalBacklog,
            'indexers_with_backlog' => $indexersWithBacklog,
            'warning_threshold' => $warningThreshold,
            'critical_threshold' => $criticalThreshold,
        ]);

        // Determine status based on backlog size
        if ($maxBacklog >= $criticalThreshold) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setNotificationMessage(
                sprintf('Critical indexer backlog detected: %d items in backlog', $maxBacklog)
            );
            $checkResult->setShortSummary(sprintf('Critical backlog: %d items', $maxBacklog));
        } elseif ($maxBacklog >= $warningThreshold) {
            $checkResult->setStatus(CheckStatus::STATUS_WARNING);
            $checkResult->setNotificationMessage(
                sprintf('High indexer backlog detected: %d items in backlog', $maxBacklog)
            );
            $checkResult->setShortSummary(sprintf('High backlog: %d items', $maxBacklog));
        } elseif ($indexersWithBacklog > 0) {
            $checkResult->setStatus(CheckStatus::STATUS_OK);
            $checkResult->setNotificationMessage(
                sprintf('%d indexer(s) have backlog but within acceptable range', $indexersWithBacklog)
            );
            $checkResult->setShortSummary(sprintf('%d indexer(s) with minor backlog', $indexersWithBacklog));
        } else {
            $checkResult->setStatus(CheckStatus::STATUS_OK);
            $checkResult->setNotificationMessage('All indexers are up to date');
            $checkResult->setShortSummary('All indexers up to date');
        }

        return $checkResult;
    }

    /**
     * Get backlog data for all indexers
     *
     * @return array
     */
    private function getAllIndexerBacklogs(): array
    {
        $backlogData = [];
        $totalProducts = $this->getTotalProducts();
        $indexerIds = $this->getIndexerIds();

        foreach ($indexerIds as $indexerId) {
            try {
                $indexer = $this->indexerRegistry->get($indexerId);

                // Only check scheduled indexers
                if (!$indexer->isScheduled()) {
                    continue;
                }

                $view = $indexer->getView();
                $state = $view->getState();
                $changelog = $view->getChangelog();

                $backlog = $changelog->getVersion() - $state->getVersionId();

                // Calculate percentage for product-related indexers
                $percentage = null;
                if ($totalProducts > 0 && $this->isProductRelatedIndexer($indexerId)) {
                    $percentage = round(($backlog / $totalProducts) * 100, 2);
                }

                $backlogData[$indexerId] = [
                    'title' => $indexer->getTitle(),
                    'backlog' => max(0, $backlog), // Ensure non-negative
                    'percentage' => $percentage,
                    'status' => $indexer->getStatus(),
                ];
            } catch (\Exception $e) {
                // Skip indexers that don't exist or have errors
                continue;
            }
        }

        return $backlogData;
    }

    /**
     * Get total number of products in the store
     *
     * @return int
     */
    private function getTotalProducts(): int
    {
        try {
            $collection = $this->productCollectionFactory->create();
            return $collection->getSize();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Check if indexer is product-related
     *
     * @param string $indexerId
     * @return bool
     */
    private function isProductRelatedIndexer(string $indexerId): bool
    {
        $productRelatedIndexers = [
            'catalog_product_price',
            'catalog_category_product',
            'catalogsearch_fulltext',
            'catalog_product_attribute',
            'cataloginventory_stock',
            'inventory',
        ];

        return in_array($indexerId, $productRelatedIndexers, true);
    }

    /**
     * Get the list of indexer IDs to check from configuration or defaults
     *
     * @return array
     */
    private function getIndexerIds(): array
    {
        $config = $this->deploymentConfig->get('ohdear/Elgentos\OhDearChecks\Checks\IndexerBacklog/indexer_ids');
        
        if (is_array($config) && !empty($config)) {
            return $config;
        }

        return self::DEFAULT_INDEXER_IDS;
    }

    /**
     * Get the warning threshold from configuration or default
     *
     * @return int
     */
    private function getWarningThreshold(): int
    {
        $config = $this->deploymentConfig->get('ohdear/Elgentos\OhDearChecks\Checks\IndexerBacklog/warning_threshold');
        
        if (is_numeric($config) && $config > 0) {
            return (int) $config;
        }

        return self::DEFAULT_WARNING_THRESHOLD;
    }

    /**
     * Get the critical threshold from configuration or default
     *
     * @return int
     */
    private function getCriticalThreshold(): int
    {
        $config = $this->deploymentConfig->get('ohdear/Elgentos\OhDearChecks\Checks\IndexerBacklog/critical_threshold');
        
        if (is_numeric($config) && $config > 0) {
            return (int) $config;
        }

        return self::DEFAULT_CRITICAL_THRESHOLD;
    }
}
