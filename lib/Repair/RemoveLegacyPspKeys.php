<?php

/**
 * Pipelinq RemoveLegacyPspKeys repair step.
 *
 * Deletes the PSP `apiKey` / `apiSecret` values Pipelinq used to hold.
 *
 * They were encrypted at rest with ICrypto, which is worth having but is not custody:
 * Pipelinq could decrypt them, so Pipelinq was the trust boundary for a key that moves
 * money. The outbound call now goes through OpenRegister's credential broker, which holds
 * the key and injects it server-side, and nothing reads these config rows any more.
 *
 * Leaving them behind would be the worst of both worlds — dead config that is still live
 * secret material, sitting in `oc_appconfig` waiting for the next database dump. Deleting
 * them is the point of the migration, not a tidy-up afterwards.
 *
 * Idempotent, and a no-op on an install that never configured a provider. `webhookSecret`
 * is deliberately NOT touched: it verifies an HMAC on an inbound webhook — a local verify
 * operation, not an outbound request header — so the broker cannot carry it and Pipelinq
 * still needs it.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-4-delete-the-legacy-keys
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes the retired, app-held PSP keys from app config.
 */
class RemoveLegacyPspKeys implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig The app config.
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Remove the legacy app-held PSP API keys (they live in the credential broker now)';
    }//end getName()

    /**
     * Delete every retired PSP credential row.
     *
     * @param IOutput $output The output interface.
     *
     * @return void
     *
     * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-4-delete-the-legacy-keys
     */
    public function run(IOutput $output): void
    {
        $removed = 0;

        foreach (PosPaymentService::PROVIDERS as $provider) {
            foreach (PosPaymentService::RETIRED_SECRET_FIELDS as $field) {
                $key = PosPaymentService::CONFIG_PREFIX.$provider.'.'.$field;

                $stored = $this->appConfig->getValueString(Application::APP_ID, $key, '');
                if ($stored === '') {
                    continue;
                }

                try {
                    $this->appConfig->deleteKey(Application::APP_ID, $key);
                    $removed++;
                } catch (Throwable $e) {
                    // Never fatal: failing to delete leaves the row exactly as it was,
                    // which is the pre-existing state, not a regression. But it IS a
                    // secret we meant to remove, so say so at error level.
                    $output->warning('Could not remove the legacy PSP key for '.$provider.': '.$e->getMessage());
                    $this->logger->error(
                        'Pipelinq: could not remove a legacy PSP key; it is still stored',
                        [
                            'provider' => $provider,
                            'field'    => $field,
                        ]
                    );
                }//end try
            }//end foreach
        }//end foreach

        if ($removed === 0) {
            $output->info('No legacy PSP keys stored; nothing to remove.');
            return;
        }

        $output->info(
            'Removed '.$removed.' legacy PSP key(s). Select a credential from the broker in the POS '
            .'payment settings to re-enable each provider.'
        );
    }//end run()
}//end class
