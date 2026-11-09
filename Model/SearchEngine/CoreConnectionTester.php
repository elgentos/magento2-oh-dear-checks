<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Model\SearchEngine;

use Magento\AdvancedSearch\Model\Client\ClientResolver;

/**
 * Magento's own OpenSearch and Elasticsearch clients.
 */
class CoreConnectionTester implements ConnectionTesterInterface
{
    public function __construct(
        private ClientResolver $clientResolver,
    ) {
    }

    public function test(string $engine): ?bool
    {
        try {
            $client = $this->clientResolver->create($engine);
        } catch (\LogicException $e) {
            // The resolver throws for engines it has no client factory for
            return null;
        }

        return (bool) $client->testConnection();
    }
}
