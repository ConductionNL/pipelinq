<?php

/**
 * Pipelinq DeliveryAuditLogger.
 *
 * Append-only audit logger for the Berichtenbox bridge (REQ-AUDIT-009).
 * Rows are INSERTed only; application code MUST NOT update or delete
 * an audit row. Retention is computed per the zaak's selectielijst
 * class (defaults to 5/10/20 years; the bridge maps zaaktype →
 * retention in selectielijst.php-like config when available).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-audit-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Append-only audit logger.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-audit-009
 */
class DeliveryAuditLogger
{
    /**
     * Default retention years when no selectielijst entry is provided.
     *
     * @var int
     */
    public const DEFAULT_RETENTION_YEARS = 10;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container.
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Log "queued" event.
     *
     * @param string                 $messageId      Message UUID.
     * @param string                 $payloadHash    SHA-256 of body.
     * @param DateTimeInterface|null $retentionUntil Optional explicit retention end.
     * @param string                 $actor          Actor (defaults to 'system').
     *
     * @return void
     */
    public function logQueued(
        string $messageId,
        string $payloadHash,
        ?DateTimeInterface $retentionUntil=null,
        string $actor='system'
    ): void {
        $this->writeEvent(messageId: $messageId, event: 'queued', payloadHash: $payloadHash, retentionUntil: $retentionUntil, actor: $actor);
    }//end logQueued()

    /**
     * Log "sent" event with Logius id.
     *
     * @param string                 $messageId       Message UUID.
     * @param string                 $logiusMessageId Logius id.
     * @param string                 $payloadHash     SHA-256 of body.
     * @param DateTimeInterface|null $retentionUntil  Optional retention.
     * @param string                 $actor           Actor.
     *
     * @return void
     */
    public function logSent(
        string $messageId,
        string $logiusMessageId,
        string $payloadHash,
        ?DateTimeInterface $retentionUntil=null,
        string $actor='system'
    ): void {
        $this->writeEvent(
            messageId: $messageId,
            event: 'sent',
            payloadHash: $payloadHash,
            retentionUntil: $retentionUntil,
            actor: $actor,
            extras: ['logiusMessageId' => $logiusMessageId]
        );
    }//end logSent()

    /**
     * Log "read" event.
     *
     * @param string                 $messageId      Message UUID.
     * @param string                 $payloadHash    SHA-256.
     * @param DateTimeInterface|null $retentionUntil Retention.
     * @param string                 $actor          Actor.
     *
     * @return void
     */
    public function logRead(
        string $messageId,
        string $payloadHash,
        ?DateTimeInterface $retentionUntil=null,
        string $actor='system'
    ): void {
        $this->writeEvent(messageId: $messageId, event: 'read', payloadHash: $payloadHash, retentionUntil: $retentionUntil, actor: $actor);
    }//end logRead()

    /**
     * Log a fallback event (email-after-5-days or no-mailbox).
     *
     * @param string                 $messageId      Message UUID.
     * @param string                 $reason         Sub-classifier.
     * @param string                 $payloadHash    SHA-256.
     * @param DateTimeInterface|null $retentionUntil Retention.
     * @param string                 $actor          Actor.
     *
     * @return void
     */
    public function logFallback(
        string $messageId,
        string $reason,
        string $payloadHash,
        ?DateTimeInterface $retentionUntil=null,
        string $actor='system'
    ): void {
        $this->writeEvent(
            messageId: $messageId,
            event: 'fallback',
            payloadHash: $payloadHash,
            retentionUntil: $retentionUntil,
            actor: $actor,
            extras: ['reason' => $reason]
        );
    }//end logFallback()

    /**
     * Log a failure.
     *
     * @param string                 $messageId      Message UUID.
     * @param string                 $reason         Failure reason.
     * @param string                 $payloadHash    SHA-256.
     * @param DateTimeInterface|null $retentionUntil Retention.
     * @param string                 $actor          Actor.
     *
     * @return void
     */
    public function logFailed(
        string $messageId,
        string $reason,
        string $payloadHash,
        ?DateTimeInterface $retentionUntil=null,
        string $actor='system'
    ): void {
        $this->writeEvent(
            messageId: $messageId,
            event: 'failed',
            payloadHash: $payloadHash,
            retentionUntil: $retentionUntil,
            actor: $actor,
            extras: ['reason' => $reason]
        );
    }//end logFailed()

    /**
     * Log a reply-received event.
     *
     * @param string                 $replyId        Reply UUID.
     * @param string                 $payloadHash    SHA-256.
     * @param DateTimeInterface|null $retentionUntil Retention.
     * @param string                 $actor          Actor.
     *
     * @return void
     */
    public function logReplyReceived(
        string $replyId,
        string $payloadHash,
        ?DateTimeInterface $retentionUntil=null,
        string $actor='system'
    ): void {
        $this->writeEvent(messageId: $replyId, event: 'reply-received', payloadHash: $payloadHash, retentionUntil: $retentionUntil, actor: $actor);
    }//end logReplyReceived()

    /**
     * Log a processing-error.
     *
     * @param string                 $messageId      Message UUID.
     * @param string                 $reason         Error.
     * @param string                 $payloadHash    SHA-256.
     * @param DateTimeInterface|null $retentionUntil Retention.
     * @param string                 $actor          Actor.
     *
     * @return void
     */
    public function logProcessingError(
        string $messageId,
        string $reason,
        string $payloadHash,
        ?DateTimeInterface $retentionUntil=null,
        string $actor='system'
    ): void {
        $this->writeEvent(
            messageId: $messageId,
            event: 'processing-error',
            payloadHash: $payloadHash,
            retentionUntil: $retentionUntil,
            actor: $actor,
            extras: ['reason' => $reason]
        );
    }//end logProcessingError()

    /**
     * Log opted-out.
     *
     * @param string                 $messageId      Message UUID.
     * @param string                 $payloadHash    SHA-256.
     * @param DateTimeInterface|null $retentionUntil Retention.
     * @param string                 $actor          Actor.
     *
     * @return void
     */
    public function logOptedOut(
        string $messageId,
        string $payloadHash,
        ?DateTimeInterface $retentionUntil=null,
        string $actor='system'
    ): void {
        $this->writeEvent(messageId: $messageId, event: 'opted-out', payloadHash: $payloadHash, retentionUntil: $retentionUntil, actor: $actor);
    }//end logOptedOut()

    /**
     * Calculate retentionUntil per the zaak's selectielijst class.
     *
     * Tenants override the per-zaaktype mapping via app config keys
     * `selectielijst.<zaaktype>` whose value is the retention period in
     * years. Defaults to {@see self::DEFAULT_RETENTION_YEARS}.
     *
     * @param string                 $zaaktype Zaaktype slug.
     * @param DateTimeInterface|null $from     Anchor (defaults to now).
     *
     * @return DateTimeImmutable
     *
     * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-audit-009
     *
     * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface is a native immutable factory; no DI alternative.
     */
    public function calculateRetentionUntil(string $zaaktype, ?DateTimeInterface $from=null): DateTimeImmutable
    {
        $years = (int) $this->appConfig->getValueString(
            Application::APP_ID,
            'selectielijst.'.$zaaktype,
            (string) self::DEFAULT_RETENTION_YEARS
        );
        if ($years <= 0) {
            $years = self::DEFAULT_RETENTION_YEARS;
        }

        $anchor = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($from instanceof DateTimeInterface) {
            $anchor = DateTimeImmutable::createFromInterface($from);
        }

        $retentionUntil = $anchor->modify('+'.$years.' years');
        assert($retentionUntil !== false);
        return $retentionUntil;
    }//end calculateRetentionUntil()

    /**
     * Compute the SHA-256 hex digest of a body string.
     *
     * @param string $body Body content.
     *
     * @return string Hex digest.
     */
    public function hashPayload(string $body): string
    {
        return hash('sha256', $body);
    }//end hashPayload()

    /**
     * Insert an audit row.
     *
     * @param string                 $messageId      Subject UUID.
     * @param string                 $event          Event tag.
     * @param string                 $payloadHash    Hash.
     * @param DateTimeInterface|null $retentionUntil Retention end.
     * @param string                 $actor          Actor.
     * @param array                  $extras         Optional reason/extra fields.
     *
     * @return void
     */
    private function writeEvent(
        string $messageId,
        string $event,
        string $payloadHash,
        ?DateTimeInterface $retentionUntil,
        string $actor,
        array $extras=[]
    ): void {
        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $retention = ($retentionUntil ?? $this->calculateRetentionUntil(zaaktype: ''));

        $row = array_merge(
            $extras,
            [
                'messageId'      => $messageId,
                'event'          => $event,
                'eventAt'        => $now->format(DATE_ATOM),
                'actor'          => $actor,
                'payloadHash'    => $payloadHash,
                'retentionUntil' => $retention->format(DATE_ATOM),
            ]
        );

        try {
            [$register, $schema] = $this->config();
            $this->getObjectService()->saveObject(
                object: $row,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: null
            );
        } catch (\Throwable $e) {
            // Audit logging should not break the dispatch path; we still
            // record the failure in the NC logger.
            $this->logger->error(
                'DeliveryAuditLogger write failed.',
                ['exception' => $e->getMessage(), 'event' => $event, 'messageId' => $messageId]
            );
        }
    }//end writeEvent()

    /**
     * Resolve OR ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService
     *
     * @throws RuntimeException If OR is unavailable.
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service unavailable.', 0, $e);
        }
    }//end getObjectService()

    /**
     * Resolve register + schema.
     *
     * @return array{0: string, 1: string}
     */
    private function config(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(
            Application::APP_ID,
            'deliveryAuditLog_schema',
            ''
        );
        if ($register === '' || $schema === '') {
            throw new RuntimeException('DeliveryAuditLog register or schema not configured.');
        }

        return [$register, $schema];
    }//end config()
}//end class
