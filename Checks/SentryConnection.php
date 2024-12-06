<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Checks;

use Magento\Framework\App\DeploymentConfig;
use Vendic\OhDear\Api\CheckInterface;
use Vendic\OhDear\Api\Data\CheckResultInterface;
use Vendic\OhDear\Api\Data\CheckStatus;
use Vendic\OhDear\Model\CheckResultFactory;

class SentryConnection implements CheckInterface
{
    public function __construct(
        private DeploymentConfig $deploymentConfig,
        private CheckResultFactory $checkResultFactory
    ) {
    }

    public function run(): CheckResultInterface
    {
        $deploymentConfig = $this->deploymentConfig;
        $options = [];
        /** @var CheckResultInterface $checkResult */

        $checkResult = $this->checkResultFactory->create();
        $checkResult->setName('sentry_connection');
        $checkResult->setLabel('Sentry connection');
        $checkResult->setMeta(
            [
                'dsn' => $deploymentConfig->get('sentry/dsn'),
                'environment' => $deploymentConfig->get('sentry/environment'),
                'log_level' => $deploymentConfig->get('sentry/log_level'),
                'mage_mode_development' => $deploymentConfig->get('sentry/mage_mode_development'),
            ]
        );

        if ($this->checkisSentryConfigured($deploymentConfig) === false) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setShortSummary('Sentry not configured');
            $checkResult->setNotificationMessage('Sentry is not configured');
        } else {
            $checkResult->setStatus(CheckStatus::STATUS_OK);
            $checkResult->setShortSummary('Sentry is configured');
            $checkResult->setNotificationMessage('Sentry is configured');
        }

        return $checkResult;
    }

    private function checkisSentryConfigured(DeploymentConfig $deploymentConfig): bool
    {
        if (
            !empty($deploymentConfig->get('sentry/dsn')) &&
            !empty($deploymentConfig->get('sentry/environment'))
        ) {
            return true;
        }

        return false;
    }
}
