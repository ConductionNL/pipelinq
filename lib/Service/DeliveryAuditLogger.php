<?php

/**
 * Pipelinq DeliveryAuditLogger.
 *
 * Append-only audit logging for the Berichtenbox bridge. Every delivery
 * lifecycle event is written as a new deliveryAuditLog object via the
 * OpenRegister ObjectService — never updated or deleted — satisfying the
 * Archiefwet immutability requirement. The retention end date is derived from
 * the zaak selectielijst class. Payloads are integrity-hashed with SHA-256.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-AUDIT-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Append-only delivery audit logger backed by OpenRegister objects.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-AUDIT-007
 */
class DeliveryAuditLogger
{
    /**
     * Default selectielijst retention in years when no class is provided.
     *
     * @var int
     */
    private const DEFAULT_RETENTION_YEARS = 10;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Append an audit log entry for a delivery event.
     *
     * @param string      $messageId      The message or reply UUID.
     * @param string      $event          The event type (queued|sent|read|fallback|failed|opted-out|reply-received|processing-error).
     * @param string      $payloadBody    The payload body to integrity-hash.
     * @param string      $actor          The actor (system or user id).
     * @param int|null    $retentionYears The retention class in years (null = default).
     * @param string|null $reason         Optional reason detail (no BSN material).
     *
     * @return void
     */
    public function log(
        string $messageId,
        string $event,
        string $payloadBody,
        string $actor='system',
        ?int $retentionYears=null,
        ?string $reason=null
    ): void {
        $now            = new DateTimeImmutable();
        $years          = ($retentionYears ?? self::DEFAULT_RETENTION_YEARS);
        $retentionUntil = $now->modify('+'.$years.' years');

        $entry = [
            'messageId'      => $messageId,
            'event'          => $event,
            'eventAt'        => $now->format(DateTimeInterface::ATOM),
            'actor'          => $actor,
            'payloadHash'    => hash('sha256', $payloadBody),
            'retentionUntil' => $retentionUntil->format(DateTimeInterface::ATOM),
        ];

        if ($reason !== null && $reason !== '') {
            $entry['reason'] = $reason;
        }

        try {
            [$register, $schema] = $this->config();
            // Always insert a new object (uuid: null) — never update an existing
            // id — to preserve append-only audit semantics (Archiefwet).
            $this->getObjectService()->saveObject(
                object: $entry,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: null
            );
        } catch (Throwable $e) {
            // Audit logging must not break the delivery path, but a failure here
            // is significant and is surfaced to the operator log.
            $this->logger->error(
                'Berichtenbox: failed to write delivery audit entry',
                [
                    'event'     => $event,
                    'messageId' => $messageId,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end log()

    /**
     * Resolve the OpenRegister register + deliveryAuditLog schema IDs.
     *
     * @return array{0: string, 1: string} The [register, schema] tuple.
     *
     * @throws RuntimeException When not configured.
     */
    private function config(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'deliveryAuditLog_schema', '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('Berichtenbox audit log register or schema not configured.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The ObjectService.
     *
     * @throws RuntimeException When OpenRegister is unavailable.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()
}//end class
