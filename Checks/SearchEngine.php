<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Checks;

use Elgentos\OhDearChecks\Model\SearchEngine\ConnectionTesterInterface;
use Magento\Framework\Search\EngineResolverInterface;
use Vendic\OhDear\Api\CheckInterface;
use Vendic\OhDear\Api\Data\CheckResultInterface;
use Vendic\OhDear\Api\Data\CheckStatus;
use Vendic\OhDear\Model\CheckResultFactory;

/**
 * Pings the configured search engine (OpenSearch, Elasticsearch, Amasty Elastic Search or Smile ElasticSuite).
 */
class SearchEngine implements CheckInterface
{
    /**
     * @param ConnectionTesterInterface[] $testers asked in order; the first that knows the engine decides
     */
    public function __construct(
        private EngineResolverInterface $engineResolver,
        private CheckResultFactory $checkResultFactory,
        private array $testers = [],
    ) {
    }

    public function run(): CheckResultInterface
    {
        $checkResult = $this->checkResultFactory->create();
        $checkResult->setName('search_engine');
        $checkResult->setLabel('Search Engine');

        $engine = (string) $this->engineResolver->getCurrentSearchEngine();
        $error = null;
        $start = hrtime(true);

        try {
            $reachable = $this->test($engine);
        } catch (\Throwable $e) {
            $reachable = false;
            $error = $e->getMessage();
        }

        $checkResult->setMeta([
            'engine' => $engine,
            'response_time_ms' => round((hrtime(true) - $start) / 1e6, 1),
        ]);

        if ($reachable === null) {
            $checkResult->setStatus(CheckStatus::STATUS_SKIPPED);
            $checkResult->setNotificationMessage(sprintf('No connection test available for search engine "%s"', $engine));
            $checkResult->setShortSummary('No test for ' . $engine);
            return $checkResult;
        }

        if (!$reachable) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setNotificationMessage(
                sprintf('Search engine "%s" is not reachable', $engine) . ($error !== null ? ': ' . $error : '')
            );
            $checkResult->setShortSummary($engine . ' not reachable');
            return $checkResult;
        }

        $checkResult->setStatus(CheckStatus::STATUS_OK);
        $checkResult->setNotificationMessage(sprintf('Search engine "%s" is reachable', $engine));
        $checkResult->setShortSummary($engine . ' reachable');

        return $checkResult;
    }

    private function test(string $engine): ?bool
    {
        foreach ($this->testers as $tester) {
            if (!$tester instanceof ConnectionTesterInterface) {
                continue;
            }
            $reachable = $tester->test($engine);
            if ($reachable !== null) {
                return $reachable;
            }
        }

        return null;
    }
}
