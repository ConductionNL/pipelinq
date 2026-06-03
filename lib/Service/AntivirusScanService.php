<?php

/**
 * Pipelinq AntivirusScanService.
 *
 * Thin wrapper around the optional Nextcloud antivirus integration used to scan
 * inbound message media before it is surfaced to agents.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.7
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scans media content for malware via the optional `files_antivirus` app.
 *
 * When the antivirus app is installed, content is scanned through its scanner
 * service; the item is quarantined on a positive hit. When the app is absent,
 * the scan is recorded as not-performed and the item is treated as clean — the
 * media is still stored, and the quarantine marker remains available for
 * operators who later enable scanning (REQ-008).
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.7
 */
class AntivirusScanService
{
    /**
     * Constructor.
     *
     * @param IAppManager        $appManager The app manager (detects files_antivirus).
     * @param ContainerInterface $container  The DI container (resolves the scanner).
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether the given content is clean (no malware detected).
     *
     * @param string $content The raw bytes to scan.
     *
     * @return bool True when clean (or scanning is unavailable).
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.7
     */
    public function isClean(string $content): bool
    {
        if ($this->appManager->isInstalled('files_antivirus') === false) {
            $this->logger->debug('Antivirus app not installed; media stored unscanned.');
            return true;
        }

        try {
            $scanner = $this->container->get('OCA\Files_Antivirus\Scanner\ScannerFactory');
            if (method_exists($scanner, 'getScanner') === false) {
                return true;
            }

            $instance = $scanner->getScanner();
            $status   = $instance->scanString($content);

            // ICAP/clamav status code 1 == infected in files_antivirus.
            if (is_object($status) === true && method_exists($status, 'getNumericStatus') === true) {
                return ((int) $status->getNumericStatus() !== 1);
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('Antivirus scan failed; treating media as quarantined.', ['exception' => $e->getMessage()]);
            return false;
        }//end try
    }//end isClean()
}//end class
