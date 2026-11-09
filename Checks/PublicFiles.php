<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Checks;

use Magento\Framework\App\Filesystem\DirectoryList;
use Vendic\OhDear\Api\CheckInterface;
use Vendic\OhDear\Api\Data\CheckResultInterface;
use Vendic\OhDear\Api\Data\CheckStatus;
use Vendic\OhDear\Model\CheckResultFactory;
use Vendic\OhDear\Utils\Configuration;

/**
 * Looks for files in pub/ and pub/media/ that anyone can download.
 *
 * Fails on project files and debug tools (.git, .env, auth.json, phpinfo.php, ...) and warns on archives
 * and backups. SQL dumps are left to Vendic's PublicSqlFiles check. Only the top level of each directory
 * is read, so the check stays fast.
 */
class PublicFiles implements CheckInterface
{
    private const SENSITIVE_NAMES = [
        '.git', '.env', 'auth.json', 'env.php', 'composer.json', 'composer.lock',
        'info.php', 'phpinfo.php', 'adminer.php',
    ];

    private const ARCHIVE_PATTERN = '/\.(tar|tar\.gz|tgz|gz|bz2|zip|7z|rar|bak|old|dump)$/i';

    private const SQL_PATTERN = '/\.sql(\.[a-z0-9]+)?$/i';

    public function __construct(
        private DirectoryList $directoryList,
        private CheckResultFactory $checkResultFactory,
        private Configuration $configuration,
    ) {
    }

    public function run(): CheckResultInterface
    {
        $checkResult = $this->checkResultFactory->create();
        $checkResult->setName('public_files');
        $checkResult->setLabel('Public Files');

        $pub = $this->directoryList->getPath(DirectoryList::PUB);
        $ignore = $this->getIgnoredPaths();
        $sensitive = [];
        $archives = [];

        foreach (['pub/' => $pub, 'pub/media/' => $pub . '/media'] as $prefix => $directory) {
            foreach (@scandir($directory) ?: [] as $name) {
                $relativePath = $prefix . $name;
                if (in_array($relativePath, $ignore, true)) {
                    continue;
                }
                if (in_array(strtolower($name), self::SENSITIVE_NAMES, true)) {
                    $sensitive[] = $relativePath;
                } elseif (preg_match(self::ARCHIVE_PATTERN, $name) && !preg_match(self::SQL_PATTERN, $name)) {
                    $archives[] = $relativePath;
                }
            }
        }

        $checkResult->setMeta([
            'sensitive_files' => $sensitive,
            'archive_files' => $archives,
        ]);

        if ($sensitive) {
            $checkResult->setStatus(CheckStatus::STATUS_FAILED);
            $checkResult->setNotificationMessage(
                'Sensitive files in public directory: ' . implode(', ', array_merge($sensitive, $archives))
            );
            $checkResult->setShortSummary(sprintf('%d sensitive public file(s)', count($sensitive)));
            return $checkResult;
        }

        if ($archives) {
            $checkResult->setStatus(CheckStatus::STATUS_WARNING);
            $checkResult->setNotificationMessage('Archives or backups in public directory: ' . implode(', ', $archives));
            $checkResult->setShortSummary(sprintf('%d public archive(s)', count($archives)));
            return $checkResult;
        }

        $checkResult->setStatus(CheckStatus::STATUS_OK);
        $checkResult->setNotificationMessage('No sensitive files or archives found in pub/ or pub/media/');
        $checkResult->setShortSummary('No public files found');

        return $checkResult;
    }

    /**
     * @return string[] paths relative to the Magento root, e.g. "pub/media/catalog.zip"
     */
    private function getIgnoredPaths(): array
    {
        $ignore = $this->configuration->getCheckConfigValue($this, 'ignore', []);

        return is_array($ignore) ? array_values(array_map('strval', $ignore)) : [];
    }
}
