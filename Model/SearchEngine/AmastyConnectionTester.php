<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Model\SearchEngine;

use Magento\Framework\ObjectManagerInterface;

/**
 * Amasty Elastic Search (amasty_elastic, amasty_elastic_8, amasty_elastic_opensearch) keeps its own
 * connection settings and clients. Amasty is optional, so its repository is fetched only when installed:
 * a constructor dependency on it would break setup:di:compile in shops without Amasty.
 */
class AmastyConnectionTester implements ConnectionTesterInterface
{
    private const REPOSITORY = 'Amasty\ElasticSearch\Model\Client\ClientRepositoryInterface';

    public function __construct(
        private ObjectManagerInterface $objectManager,
    ) {
    }

    public function test(string $engine): ?bool
    {
        if (!str_starts_with($engine, 'amasty_elastic') || !interface_exists(self::REPOSITORY)) {
            return null;
        }

        return (bool) $this->objectManager->get(self::REPOSITORY)->get($engine)->ping();
    }
}
