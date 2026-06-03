<?php

/**
 * Pipelinq MessagingResult.
 *
 * Immutable outcome of an orchestrated send, carrying an HTTP status and a
 * stable, non-sensitive error code for the controller to surface.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

/**
 * Orchestrated send outcome.
 *
 * `errorCode` is one of: sessionWindowExpired (409), templateNotApproved (422),
 * templateParameterMismatch (422), consentMissing (403), budgetExceeded (403),
 * providerUnavailable (502), deliveryFailed (502), invalidRequest (400).
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.1
 */
class MessagingResult
{
    /**
     * Constructor.
     *
     * @param bool                 $success           Whether the send succeeded.
     * @param int                  $statusCode        The HTTP status to surface.
     * @param string|null          $errorCode         The stable error code, when failed.
     * @param string|null          $externalMessageId The provider message id, when sent.
     * @param string|null          $messageId         The persisted contactmoment id, when logged.
     * @param array<string, mixed> $detail            Extra non-sensitive detail (e.g. expected/given).
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $statusCode=200,
        public readonly ?string $errorCode=null,
        public readonly ?string $externalMessageId=null,
        public readonly ?string $messageId=null,
        public readonly array $detail=[],
    ) {
    }//end __construct()

    /**
     * Build a success result.
     *
     * @param string|null $externalMessageId The provider message id.
     * @param string|null $messageId         The persisted contactmoment id.
     *
     * @return self The success result.
     */
    public static function sent(?string $externalMessageId, ?string $messageId): self
    {
        return new self(
            success: true,
            statusCode: 200,
            externalMessageId: $externalMessageId,
            messageId: $messageId
        );
    }//end sent()

    /**
     * Build a failure result.
     *
     * @param int                  $statusCode The HTTP status.
     * @param string               $errorCode  The stable error code.
     * @param array<string, mixed> $detail     Extra non-sensitive detail.
     *
     * @return self The failure result.
     */
    public static function fail(int $statusCode, string $errorCode, array $detail=[]): self
    {
        return new self(
            success: false,
            statusCode: $statusCode,
            errorCode: $errorCode,
            detail: $detail
        );
    }//end fail()
}//end class
