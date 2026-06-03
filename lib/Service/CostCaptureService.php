<?php

/**
 * Pipelinq CostCaptureService.
 *
 * Captures outbound message cost from a delivery update (converting to EUR) or
 * estimates it from the price table when the provider exposes no cost.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Messaging\DeliveryUpdate;

/**
 * Resolves the EUR cost (and provenance flags) for a delivery update (REQ-007).
 *
 * Three outcomes:
 *  - exact: provider exposed cost in EUR (or convertible via ECB).
 *  - estimated: provider exposed no cost; estimated from the price table.
 *  - pending: provider exposed a non-EUR cost but no ECB rate is available;
 *    the source-currency amount is preserved for later reconciliation.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.5
 */
class CostCaptureService
{
    /**
     * Constructor.
     *
     * @param ExchangeRateService   $exchangeRate The ECB currency converter.
     * @param CostEstimationService $estimation   The price-table estimator.
     */
    public function __construct(
        private ExchangeRateService $exchangeRate,
        private CostEstimationService $estimation,
    ) {
    }//end __construct()

    /**
     * Resolve the cost fields to persist for a delivery update.
     *
     * @param DeliveryUpdate $update   The normalised delivery update.
     * @param string         $category The template category (for estimation).
     * @param string         $toNumber The recipient E.164 number (for country).
     *
     * @return array{costEur: float|null, estimated: bool, currencyPending: bool, sourceAmount: float|null, sourceCurrency: string|null}
     *         The resolved cost outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.5
     */
    public function resolve(DeliveryUpdate $update, string $category, string $toNumber): array
    {
        if ($update->hasCost() === true) {
            return $this->fromExposedCost(update: $update);
        }

        return $this->fromEstimate(category: $category, toNumber: $toNumber);
    }//end resolve()

    /**
     * Reconcile messages whose non-EUR cost was previously deferred.
     *
     * For each pending message, re-attempts the source-currency → EUR
     * conversion; on success, writes `costEur` and clears the pending marker
     * (REQ-007). Messages still lacking a rate are left pending.
     *
     * @param MessageLogService $messageLog The message repository.
     *
     * @return int The number of messages reconciled.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-7.3
     */
    public function reconcilePending(MessageLogService $messageLog): int
    {
        $reconciled = 0;
        foreach ($messageLog->messagesPendingCostReconciliation() as $message) {
            $meta = [];
            if (is_array(($message['channelMetadata'] ?? null)) === true) {
                $meta = $message['channelMetadata'];
            }

            $amount   = (float) ($meta['costSourceAmount'] ?? 0);
            $currency = (string) ($meta['costSourceCurrency'] ?? '');
            if ($amount <= 0.0 || $currency === '') {
                continue;
            }

            $eur = $this->exchangeRate->toEur(amount: $amount, currency: $currency);
            if ($eur === null) {
                continue;
            }

            $meta['costCurrencyPending'] = false;
            $externalId = (string) ($message['externalMessageId'] ?? '');
            if ($externalId === '') {
                continue;
            }

            if ($messageLog->updateByExternalId($externalId, ['costEur' => $eur, 'channelMetadata' => $meta]) === true) {
                $reconciled++;
            }
        }//end foreach

        return $reconciled;
    }//end reconcilePending()

    /**
     * Resolve cost from a provider-exposed amount/currency.
     *
     * @param DeliveryUpdate $update The delivery update carrying a cost.
     *
     * @return array{costEur: float|null, estimated: bool, currencyPending: bool, sourceAmount: float|null, sourceCurrency: string|null}
     *         The resolved cost outcome.
     */
    private function fromExposedCost(DeliveryUpdate $update): array
    {
        $amount   = (float) $update->costAmount;
        $currency = (string) $update->costCurrency;
        $eur      = $this->exchangeRate->toEur(amount: $amount, currency: $currency);

        if ($eur === null) {
            return [
                'costEur'         => null,
                'estimated'       => false,
                'currencyPending' => true,
                'sourceAmount'    => $amount,
                'sourceCurrency'  => $currency,
            ];
        }

        return [
            'costEur'         => $eur,
            'estimated'       => false,
            'currencyPending' => false,
            'sourceAmount'    => $amount,
            'sourceCurrency'  => $currency,
        ];
    }//end fromExposedCost()

    /**
     * Resolve cost by estimation from the price table.
     *
     * @param string $category The template category.
     * @param string $toNumber The recipient E.164 number.
     *
     * @return array{costEur: float|null, estimated: bool, currencyPending: bool, sourceAmount: float|null, sourceCurrency: string|null}
     *         The resolved cost outcome.
     */
    private function fromEstimate(string $category, string $toNumber): array
    {
        $country = $this->estimation->countryFromE164(e164: $toNumber);

        return [
            'costEur'         => $this->estimation->estimate(category: $category, country: $country),
            'estimated'       => true,
            'currencyPending' => false,
            'sourceAmount'    => null,
            'sourceCurrency'  => null,
        ];
    }//end fromEstimate()
}//end class
