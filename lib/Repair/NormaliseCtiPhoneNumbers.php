<?php

/**
 * Pipelinq NormaliseCtiPhoneNumbers.
 *
 * Repair step that normalises stored contact / client phone numbers to E.164
 * so the CTI screen-pop matcher can hit on first-call lookup.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-7.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\CtiContactMatcher;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * One-shot repair step that walks the contact / client schemas and persists a
 * normalised `phoneE164` field. Re-running the step is a safe no-op as the
 * matcher skips records that already have a current `phoneE164`.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-7.1
 */
class NormaliseCtiPhoneNumbers implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param IAppManager       $appManager     The app manager.
     * @param CtiContactMatcher $contactMatcher The CTI contact matcher.
     * @param LoggerInterface   $logger         The logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly CtiContactMatcher $contactMatcher,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Normalise contact and client phone numbers to E.164 for CTI matching';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output The output interface.
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $output->warning('OpenRegister not installed — skipping CTI phone normalisation.');
            return;
        }

        try {
            $result = $this->contactMatcher->normaliseStoredPhoneNumbers();
            $output->info(
                sprintf(
                    'CTI phone normalisation: %d updated, %d skipped.',
                    $result['updated'],
                    $result['skipped']
                )
            );
            $this->logger->info('CTI phone normalisation completed', $result);
        } catch (\Throwable $e) {
            $output->warning('CTI phone normalisation failed: '.$e->getMessage());
            $this->logger->error('CTI phone normalisation failed', ['exception' => $e->getMessage()]);
        }
    }//end run()
}//end class
