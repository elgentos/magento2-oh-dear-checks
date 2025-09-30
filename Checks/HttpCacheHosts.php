<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Checks;

use Magento\Framework\App\DeploymentConfig;
use Vendic\OhDear\Api\CheckInterface;
use Vendic\OhDear\Api\Data\CheckResultInterface;
use Vendic\OhDear\Api\Data\CheckStatus;
use Vendic\OhDear\Model\CheckResultFactory;

class HttpCacheHosts implements CheckInterface
{
    private const CONFIG_PATH = 'http_cache_hosts';

    public function __construct(
        private DeploymentConfig $deploymentConfig,
        private CheckResultFactory $checkResultFactory,
    ) {
    }

    public function run(): CheckResultInterface
    {
        $checkResult = $this->checkResultFactory->create();
        $checkResult->setName('http_cache_hosts');
        $checkResult->setLabel('HTTP Cache Hosts Configuration');

        $httpCacheHosts = $this->getHttpCacheHosts();
        $isConfigured = $this->isConfigured($httpCacheHosts);
        $hostsCount = is_array($httpCacheHosts) ? count($httpCacheHosts) : 0;

        $checkResult->setMeta([
            'configured' => $isConfigured,
            'hosts_count' => $hostsCount,
            'hosts' => $httpCacheHosts ?: [],
        ]);

        if (!$isConfigured) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setNotificationMessage('HTTP cache hosts are not configured. Varnish cache clearing will not work.');
            $checkResult->setShortSummary('HTTP cache hosts not configured');
            return $checkResult;
        }

        $checkResult->setStatus(CheckStatus::STATUS_OK);
        $checkResult->setNotificationMessage(sprintf('HTTP cache hosts are properly configured with %d host(s)', $hostsCount));
        $checkResult->setShortSummary(sprintf('%d cache host(s) configured', $hostsCount));

        return $checkResult;
    }

    /**
     * Get HTTP cache hosts configuration
     *
     * @return array|null
     */
    private function getHttpCacheHosts(): ?array
    {
        $config = $this->deploymentConfig->get(self::CONFIG_PATH);
        return is_array($config) ? $config : null;
    }

    /**
     * Check if HTTP cache hosts are properly configured
     *
     * @param array|null $httpCacheHosts
     * @return bool
     */
    private function isConfigured(?array $httpCacheHosts): bool
    {
        if (!is_array($httpCacheHosts) || empty($httpCacheHosts)) {
            return false;
        }

        // Validate that at least one host has both 'host' and 'port' configured
        foreach ($httpCacheHosts as $hostConfig) {
            if (is_array($hostConfig) && 
                !empty($hostConfig['host']) && 
                !empty($hostConfig['port'])) {
                return true;
            }
        }

        return false;
    }
}
