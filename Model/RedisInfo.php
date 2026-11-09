<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Model;

/**
 * Reads INFO from a Redis server with the Credis client that Magento ships.
 */
class RedisInfo
{
    private const TIMEOUT = 1.0;

    /**
     * @return array<string, mixed>|null null when Redis cannot be reached
     */
    public function get(string $host, int $port, ?string $password = null, int $database = 0): ?array
    {
        if (!class_exists(\Credis_Client::class)) {
            return null;
        }

        try {
            $client = new \Credis_Client($host, $port, self::TIMEOUT, '', $database, $password ?: null);
            $info = $client->info();
            $client->close();
        } catch (\Throwable) {
            return null;
        }

        return is_array($info) ? $info : null;
    }
}
