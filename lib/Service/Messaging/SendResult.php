<?php

/**
 * Pipelinq SendResult.
 *
 * Immutable value object describing the outcome of an outbound provider send.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

/**
 * Normalised result of an outbound send attempt against a provider.
 *
 * `transientFailure` distinguishes retryable (5xx / network) failures — which
 * the orchestrator may fail over to a lower-priority provider — from permanent
 * failures (4xx), which must not be retried (REQ-004).
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — immutable result flag, not a behaviour switch
 * @SuppressWarnings(PHPMD.ShortMethodName)     — ok() is a deliberate, readable factory name
 * @spec                                        openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.5
 */
class SendResult
{
    /**
     * Constructor.
     *
     * @param bool        $success           Whether the send was accepted by the provider.
     * @param string|null $externalMessageId The provider's message id, when accepted.
     * @param bool        $transientFailure  Whether a failure is retryable (5xx/network).
     * @param string|null $errorCode         A short, non-sensitive error code.
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $externalMessageId=null,
        public readonly bool $transientFailure=false,
        public readonly ?string $errorCode=null,
    ) {
    }//end __construct()

    /**
     * Build a successful result.
     *
     * @param string|null $externalMessageId The provider's message id.
     *
     * @return self The success result.
     */
    public static function ok(?string $externalMessageId): self
    {
        return new self(success: true, externalMessageId: $externalMessageId);
    }//end ok()

    /**
     * Build a transient (retryable) failure result.
     *
     * @param string $errorCode A short, non-sensitive error code.
     *
     * @return self The transient-failure result.
     */
    public static function transient(string $errorCode): self
    {
        return new self(success: false, transientFailure: true, errorCode: $errorCode);
    }//end transient()

    /**
     * Build a permanent (non-retryable) failure result.
     *
     * @param string $errorCode A short, non-sensitive error code.
     *
     * @return self The permanent-failure result.
     */
    public static function permanent(string $errorCode): self
    {
        return new self(success: false, transientFailure: false, errorCode: $errorCode);
    }//end permanent()
}//end class
