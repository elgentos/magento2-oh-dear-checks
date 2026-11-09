<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Checks;

use Elgentos\OhDearChecks\Model\RedisInfo;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Session\SaveHandlerFactory;
use Magento\Framework\Session\SaveHandlerInterface;
use Vendic\OhDear\Api\CheckInterface;
use Vendic\OhDear\Api\Data\CheckResultInterface;
use Vendic\OhDear\Api\Data\CheckStatus;
use Vendic\OhDear\Model\CheckResultFactory;

/**
 * Writes, reads and removes a session in the storage from env.php (session/save).
 *
 * Uses the handler itself, not Magento's SaveHandler: that one quietly falls back to files
 * when Redis is down, so every node keeps its own sessions and customers get logged out.
 */
class SessionStorage implements CheckInterface
{
    public function __construct(
        private DeploymentConfig $deploymentConfig,
        private SaveHandlerFactory $saveHandlerFactory,
        private SessionConfig $sessionConfig,
        private RedisInfo $redisInfo,
        private CheckResultFactory $checkResultFactory,
    ) {
    }

    public function run(): CheckResultInterface
    {
        $checkResult = $this->checkResultFactory->create();
        $checkResult->setName('session_storage');
        $checkResult->setLabel('Session Storage');

        $configured = (string) ($this->deploymentConfig->get('session/save') ?: SaveHandlerInterface::DEFAULT_HANDLER);
        $handler = $this->getEffectiveHandler($configured);
        $meta = ['handler' => $handler] + ($handler !== $configured ? ['configured' => $configured] : []);
        $checkResult->setMeta($meta);

        $error = null;
        try {
            $works = match ($handler) {
                'files' => $this->testFiles(),
                'redis', 'db' => $this->testHandler($handler),
                default => null,
            };
        } catch (\Throwable $e) {
            $works = false;
            $error = $e->getMessage();
        }

        if ($works === null) {
            $checkResult->setStatus(CheckStatus::STATUS_SKIPPED);
            $checkResult->setNotificationMessage(sprintf('No storage test available for session handler "%s"', $handler));
            $checkResult->setShortSummary('No test for ' . $handler);
            return $checkResult;
        }

        if (!$works) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setNotificationMessage(
                sprintf('Session storage "%s" cannot write and read back a session', $handler)
                . ($error !== null ? ': ' . $error : '')
            );
            $checkResult->setShortSummary('Session storage ' . $handler . ' not working');
            return $checkResult;
        }

        // The db handler only deletes old sessions in gc(), which PHP never calls with a probability of 0
        // (the Debian/Ubuntu default: their cron job only cleans session files)
        if ($handler === 'db' && (int) ini_get('session.gc_probability') <= 0) {
            $checkResult->setMeta($meta + ['gc_probability' => 0]);
            $checkResult->setStatus(CheckStatus::STATUS_WARNING);
            $checkResult->setNotificationMessage(
                'Session garbage collection is off (session.gc_probability = 0), so the session table is never cleaned'
            );
            $checkResult->setShortSummary('Session garbage collection off');
            return $checkResult;
        }

        $checkResult->setStatus(CheckStatus::STATUS_OK);
        $checkResult->setNotificationMessage(sprintf('Session storage "%s" works', $handler));
        $checkResult->setShortSummary('Session storage ' . $handler . ' OK');

        if ($handler === 'redis') {
            $this->checkRedisEvictions($checkResult, $meta);
        }

        return $checkResult;
    }

    /**
     * An evicted session is a customer who is logged out or loses the cart, so any eviction warns.
     * evicted_keys counts since the Redis server started, so the warning stays until a restart or CONFIG RESETSTAT.
     */
    private function checkRedisEvictions(CheckResultInterface $checkResult, array $meta): void
    {
        $info = $this->redisInfo->get(
            (string) ($this->deploymentConfig->get('session/redis/host') ?: '127.0.0.1'),
            (int) ($this->deploymentConfig->get('session/redis/port') ?: 6379),
            (string) $this->deploymentConfig->get('session/redis/password'),
            (int) $this->deploymentConfig->get('session/redis/database')
        );
        if ($info === null) {
            return;
        }

        $evicted = (int) ($info['evicted_keys'] ?? 0);
        $meta['maxmemory_policy'] = (string) ($info['maxmemory_policy'] ?? '');
        $meta['evicted_keys'] = $evicted;
        $maxMemory = (float) ($info['maxmemory'] ?? 0);
        if ($maxMemory > 0) {
            $meta['memory_used_percentage'] = round((float) ($info['used_memory'] ?? 0) / $maxMemory * 100, 1);
        }
        $checkResult->setMeta($meta);

        if ($evicted > 0) {
            $checkResult->setStatus(CheckStatus::STATUS_WARNING);
            $checkResult->setNotificationMessage(sprintf(
                'Session Redis evicted %d keys: sessions may be lost. Use a Redis that does not evict sessions',
                $evicted
            ));
            $checkResult->setShortSummary(sprintf('Session Redis evicted %d keys', $evicted));
        }
    }

    /**
     * Magento has its own handlers for "db" and "redis" only (SaveHandlerFactory in app/etc/di.xml). Anything else,
     * e.g. "database", uses PHP's own handler, and only "memcached" is passed on to php.ini: the rest keeps
     * PHP's session.save_handler, usually files.
     */
    private function getEffectiveHandler(string $configured): string
    {
        if (in_array($configured, ['db', 'redis', 'memcached'], true)) {
            return $configured;
        }

        $handler = (string) ini_get('session.save_handler');
        if ($handler === 'user' || $handler === '') {
            // "user" once Magento registered its own handler with session_set_save_handler(): PHP's own handler,
            // which Magento's Native handler wraps, is the one from php.ini or the PHP-FPM pool
            $handler = (string) (ini_get_all('session')['session.save_handler']['global_value'] ?? '');
            if ($handler === 'user' || $handler === '') {
                $handler = (string) get_cfg_var('session.save_handler');
            }
        }

        return $handler !== '' && $handler !== 'user' ? $handler : SaveHandlerInterface::DEFAULT_HANDLER;
    }

    private function testHandler(string $handler): bool
    {
        $storage = $this->saveHandlerFactory->create($handler);
        $sessionId = 'elgentos_ohdear_' . bin2hex(random_bytes(8));
        $value = (string) time();

        $storage->open('', 'ohdear');
        try {
            return $storage->write($sessionId, $value) && $storage->read($sessionId) === $value;
        } finally {
            $storage->destroy($sessionId);
            $storage->close();
        }
    }

    /**
     * PHP's own files handler only works inside an active session, so write a session file the way it does:
     * in the save path Magento gives PHP (env.php, php.ini or var/session), and with
     * "N;/path" in N levels of subdirectories named after the first characters of the session id.
     */
    private function testFiles(): bool
    {
        $savePath = (string) $this->sessionConfig->getSavePath();
        $parts = explode(';', $savePath);
        $path = rtrim((string) array_pop($parts), '/');
        $depth = count($parts) > 0 ? (int) $parts[0] : 0;

        // Hex characters only, which exist as subdirectory names whatever session.sid_bits_per_character is
        $sessionId = bin2hex(random_bytes(16));
        $dir = $path;
        for ($i = 0; $i < $depth; $i++) {
            $dir .= '/' . $sessionId[$i];
        }

        $file = $dir . '/sess_' . $sessionId;
        $value = (string) time();
        try {
            return @file_put_contents($file, $value) !== false && @file_get_contents($file) === $value;
        } finally {
            @unlink($file);
        }
    }
}
