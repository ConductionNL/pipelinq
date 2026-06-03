<?php

/**
 * Pipelinq BsnAuditService.
 *
 * Writes the immutable, append-only BSN audit trail (AVG art. 30). Every BRP
 * bevraging — success, failure, or refusal — is recorded with a MASKED BSN
 * (never plain-text) plus the actor, doelbinding and correlation id. The
 * pseudonymisation path (AVG art. 17) replaces the masked value with a keyed
 * SHA-256 digest while leaving the record otherwise intact (ADR-005).
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Bsn\BsnMasker;
use OCA\Pipelinq\Service\Bsn\BsnObjectStoreTrait;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for the immutable BSN audit trail (REQ-BSN-005).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires only the collaborators
 *  needed to persist an audit record and derive the pseudonymisation secret.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.4
 */
class BsnAuditService
{
    use BsnObjectStoreTrait;

    /**
     * Schema config key for the audit record.
     *
     * @var string
     */
    private const SCHEMA_KEY = 'bsnAuditRecord_schema';

    /**
     * App-config key holding the instance pseudonymisation secret.
     *
     * @var string
     */
    private const SECRET_KEY = 'bsn.pseudonymise_secret';

    /**
     * Default retention for an audit record (5 years per RvIG).
     *
     * @var string
     */
    private const RETENTION = 'P5Y';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (OR ObjectService).
     * @param IAppConfig         $appConfig The app config.
     * @param ISecureRandom      $random    Secure random for secret generation.
     * @param LoggerInterface    $logger    The logger (BSN-masked only).
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private ISecureRandom $random,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Record a BRP lookup in the immutable audit trail.
     *
     * The raw BSN is masked before it ever touches the record or the logs; it is
     * never persisted in plain text.
     *
     * @param array<string, mixed> $context The audit context. Keys: actie, bsn
     *                                      (raw, masked here), actor, actorRol,
     *                                      verzoekreden, doelbinding, uitkomst,
     *                                      responseCode, ipAdres, userAgent,
     *                                      haalcentraalCorrelationId,
     *                                      gekoppeldVerzoek, vogScreening.
     *
     * @return void
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.4
     */
    public function recordLookup(array $context): void
    {
        $rawBsn = (string) ($context['bsn'] ?? '');

        $record = [
            'actie'                     => (string) ($context['actie'] ?? 'brp-lookup-uitgevoerd'),
            'status'                    => 'vastgelegd',
            'bsnGemaskeerd'             => BsnMasker::mask($rawBsn),
            'actor'                     => (string) ($context['actor'] ?? ''),
            'actorRol'                  => (string) ($context['actorRol'] ?? ''),
            'tijdstip'                  => $this->now(),
            'verzoekreden'              => (string) ($context['verzoekreden'] ?? ''),
            'doelbinding'               => (string) ($context['doelbinding'] ?? ''),
            'uitkomst'                  => (string) ($context['uitkomst'] ?? ''),
            'responseCode'              => ($context['responseCode'] ?? null),
            'ipAdres'                   => (string) ($context['ipAdres'] ?? ''),
            'userAgent'                 => (string) ($context['userAgent'] ?? ''),
            'haalcentraalCorrelationId' => (string) ($context['haalcentraalCorrelationId'] ?? ''),
            'gekoppeldVerzoek'          => (string) ($context['gekoppeldVerzoek'] ?? ''),
            'vogScreening'              => (bool) ($context['vogScreening'] ?? false),
            'responseInCache'           => (bool) ($context['responseInCache'] ?? false),
            'responseDuurMs'            => ($context['responseDuurMs'] ?? null),
            'bewaartot'                 => $this->retentionUntil(),
        ];

        try {
            $this->save(schemaKey: self::SCHEMA_KEY, object: $record);
        } catch (\Throwable $e) {
            // An audit-write failure must surface (compliance), but never with a BSN.
            $this->logger->error('Pipelinq BRP: failed to write audit record', ['exception' => $e->getMessage()]);
            throw $e;
        }
    }//end recordLookup()

    /**
     * Count refusals (uitkomst=geweigerd-onbevoegd) for an actor since a moment.
     *
     * Used by the caller to optionally alert the FG on repeated refusals
     * (REQ-BSN-005-03). Reads only this app's audit schema.
     *
     * @param string $actor The actor UID.
     * @param string $since ISO 8601 lower bound.
     *
     * @return int The number of refusals at or after $since.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.4
     */
    public function countRefusals(string $actor, string $since): int
    {
        $rows  = $this->findAllBy(
            schemaKey: self::SCHEMA_KEY,
            filters: ['actor' => $actor, 'uitkomst' => 'geweigerd-onbevoegd']
        );
        $count = 0;
        foreach ($rows as $row) {
            if ((string) ($row['tijdstip'] ?? '') >= $since) {
                $count++;
            }
        }

        return $count;
    }//end countRefusals()

    /**
     * Pseudonymise every audit record for a BSN (AVG art. 17 — right to erasure).
     *
     * Replaces the masked value with a keyed SHA-256 digest so the records stay
     * traceable by actor/doelbinding/time without retaining identifying data.
     *
     * @param string $bsn The raw BSN being erased (never logged).
     *
     * @return int The number of records pseudonymised.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.4
     */
    public function pseudonymise(string $bsn): int
    {
        $masked = BsnMasker::mask($bsn);
        $hashed = BsnMasker::hash($bsn, $this->secret());
        $rows   = $this->findAllBy(schemaKey: self::SCHEMA_KEY, filters: ['bsnGemaskeerd' => $masked]);

        $updated = 0;
        foreach ($rows as $row) {
            $uuid = (string) ($row['id'] ?? $row['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $row['bsnGemaskeerd'] = $hashed;
            $this->save(schemaKey: self::SCHEMA_KEY, object: $row, uuid: $uuid);
            $updated++;
        }

        return $updated;
    }//end pseudonymise()

    /**
     * Resolve (and lazily generate) the instance pseudonymisation secret.
     *
     * @return string The HMAC secret.
     */
    private function secret(): string
    {
        $secret = $this->appConfig->getValueString(Application::APP_ID, self::SECRET_KEY, '');
        if ($secret === '') {
            $secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
            $this->appConfig->setValueString(Application::APP_ID, self::SECRET_KEY, $secret, false, true);
        }

        return $secret;
    }//end secret()

    /**
     * Current time as an ISO 8601 string.
     *
     * @return string The timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()

    /**
     * The retention horizon (now + 5 years) as an ISO 8601 string.
     *
     * @return string The bewaartot timestamp.
     */
    private function retentionUntil(): string
    {
        return (new DateTimeImmutable())->add(new DateInterval(self::RETENTION))->format(DateTimeInterface::ATOM);
    }//end retentionUntil()
}//end class
