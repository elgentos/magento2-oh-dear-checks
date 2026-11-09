<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Checks;

use Magento\Framework\App\ResourceConnection;
use Vendic\OhDear\Api\CheckInterface;
use Vendic\OhDear\Api\Data\CheckResultInterface;
use Vendic\OhDear\Api\Data\CheckStatus;
use Vendic\OhDear\Model\CheckResultFactory;
use Vendic\OhDear\Utils\Configuration;

/**
 * Reports how long ago the last cron job finished successfully.
 *
 * The CRON_SCHEDULE_STATUS_FINISHED_AT index from etc/db_schema.xml keeps the query fast on a large table.
 */
class Cron implements CheckInterface
{
    private const DEFAULT_WARNING_MINUTES = 15;
    private const DEFAULT_CRITICAL_MINUTES = 60;

    public function __construct(
        private ResourceConnection $resourceConnection,
        private CheckResultFactory $checkResultFactory,
        private Configuration $configuration,
    ) {
    }

    public function run(): CheckResultInterface
    {
        $checkResult = $this->checkResultFactory->create();
        $checkResult->setName('cron');
        $checkResult->setLabel('Cron');

        try {
            $lastFinishedAt = $this->getLastFinishedAt();
        } catch (\Throwable $e) {
            $checkResult->setStatus(CheckStatus::STATUS_CRASHED);
            $checkResult->setNotificationMessage('Could not read cron_schedule: ' . $e->getMessage());
            $checkResult->setShortSummary('Cron check crashed');
            return $checkResult;
        }

        $warningMinutes = $this->getMinutes('warning_minutes', self::DEFAULT_WARNING_MINUTES);
        $criticalMinutes = $this->getMinutes('critical_minutes', self::DEFAULT_CRITICAL_MINUTES);

        if ($lastFinishedAt === null) {
            $checkResult->setStatus(CheckStatus::STATUS_WARNING);
            $checkResult->setNotificationMessage('No cron job has finished successfully yet');
            $checkResult->setShortSummary('No cron job finished');
            return $checkResult;
        }

        $minutes = max(0, (int) floor((time() - $lastFinishedAt->getTimestamp()) / 60));

        $checkResult->setMeta([
            'last_finished_at' => $lastFinishedAt->format(DATE_ATOM),
            'minutes_ago' => $minutes,
            'warning_minutes' => $warningMinutes,
            'critical_minutes' => $criticalMinutes,
        ]);
        $checkResult->setShortSummary(sprintf('Last cron job %d min ago', $minutes));
        $checkResult->setNotificationMessage(sprintf('Last cron job finished %d minutes ago', $minutes));

        if ($minutes >= $criticalMinutes) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
        } elseif ($minutes >= $warningMinutes) {
            $checkResult->setStatus(CheckStatus::STATUS_WARNING);
        } else {
            $checkResult->setStatus(CheckStatus::STATUS_OK);
        }

        return $checkResult;
    }

    private function getLastFinishedAt(): ?\DateTimeImmutable
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('cron_schedule'), ['last' => 'MAX(finished_at)'])
            ->where('status = ?', 'success');
        $last = $connection->fetchOne($select);

        if (!$last) {
            return null;
        }

        // Magento writes cron_schedule times in UTC and sets the connection time zone to UTC
        return new \DateTimeImmutable((string) $last, new \DateTimeZone('UTC'));
    }

    private function getMinutes(string $key, int $default): int
    {
        $value = $this->configuration->getCheckConfigValue($this, $key);

        return is_numeric($value) && $value > 0 ? (int) $value : $default;
    }
}
