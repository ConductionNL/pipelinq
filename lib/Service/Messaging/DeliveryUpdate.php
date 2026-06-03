<?php

/**
 * Pipelinq DeliveryUpdate.
 *
 * Immutable value object describing a normalised delivery-status webhook.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Messaging
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

/**
 * Normalised delivery-status update parsed from a provider status webhook.
 *
 * `costAmount` / `costCurrency` carry the provider-reported price when exposed
 * (Twilio); they are null for providers that do not expose per-message cost
 * (Meta), in which case the cost is estimated downstream (REQ-007).
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.5
 */
class DeliveryUpdate
{
    /**
     * Constructor.
     *
     * @param string      $externalMessageId The provider message id this update is for.
     * @param string      $status            Normalised status (queued|sent|delivered|read|failed|expired).
     * @param float|null  $costAmount        Provider-reported cost amount, if exposed.
     * @param string|null $costCurrency      ISO 4217 currency of $costAmount, if exposed.
     */
    public function __construct(
        public readonly string $externalMessageId,
        public readonly string $status,
        public readonly ?float $costAmount=null,
        public readonly ?string $costCurrency=null,
    ) {
    }//end __construct()

    /**
     * Whether the provider exposed a cost on this update.
     *
     * @return bool True when both amount and currency are present.
     */
    public function hasCost(): bool
    {
        return $this->costAmount !== null && $this->costCurrency !== null;
    }//end hasCost()
}//end class
