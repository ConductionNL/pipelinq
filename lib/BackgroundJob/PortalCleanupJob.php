<?php

/**
 * Pipelinq PortalCleanupJob.
 *
 * Nightly background job that runs the portal account-closure cleanup: contacts
 * of closed accounts are pseudonymised once their retention obligations lapse
 * (AVG Art. 17, REQ-010). Scheduling lives here; the retention logic lives in
 * PortalCleanupService so it is independently unit-testable.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Portal\PortalCleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs portal closed-account cleanup nightly.
 */
class PortalCleanupJob extends TimedJob
{
    /**
     * Interval in seconds (24 hours).
     *
     * @var int
     */
    private const INTERVAL = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory         $time    The time factory.
     * @param PortalCleanupService $cleanup The cleanup service.
     * @param LoggerInterface      $logger  The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private PortalCleanupService $cleanup,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Run the cleanup pass.
     *
     * @param mixed $argument The job argument (unused; required by TimedJob).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is part of the
     *  TimedJob::run() contract.
     */
    protected function run(mixed $argument): void
    {
        try {
            $count = $this->cleanup->run();
            if ($count > 0) {
                $this->logger->info('Pipelinq portal: pseudonymised '.$count.' closed-account contacts');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Pipelinq portal: cleanup job failed', ['exception' => $e->getMessage()]);
        }
    }//end run()
}//end class
