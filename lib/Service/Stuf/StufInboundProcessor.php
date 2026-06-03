<?php

/**
 * Pipelinq StufInboundProcessor.
 *
 * Processes verified inbound StUF envelopes: XXE-safe parse, mapping lookup by
 * zaak identificatie, outbound correlation, and audit logging of the inbound row.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#3.1
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Processes inbound StUF kennisgevingen and bevestigingen.
 *
 * The envelope is parsed via {@see StufMessageParser} (which rejects DOCTYPE /
 * external entities), then matched to a ZaaksysteemMapping by externIdentificatie
 * so the linked pipelinq Request's status can be reconciled. An inbound
 * StufMessage row is always written, and any outbound row sharing the
 * referentienummer is correlated and transitioned.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Inbound reconciliation legitimately
 *               touches the parser, audit handler, mapping store and linked Request;
 *               each is a single-responsibility collaborator in one cohesive flow.
 */
class StufInboundProcessor
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container      The DI container.
     * @param IAppConfig         $appConfig      The app config.
     * @param StufMessageParser  $parser         The XXE-safe parser.
     * @param StufMessageHandler $messageHandler The audit-log handler.
     * @param LoggerInterface    $logger         The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private StufMessageParser $parser,
        private StufMessageHandler $messageHandler,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Process one inbound envelope.
     *
     * @param string $envelopeXml The raw inbound SOAP envelope.
     *
     * @return array{matchedMapping: bool, zaakIdentificatie: ?string} A processing summary.
     */
    public function process(string $envelopeXml): array
    {
        // XXE-safe parse: a DOCTYPE / external-entity envelope throws here.
        $keys           = $this->parser->parseInbound(responseXml: $envelopeXml);
        $zaakId         = ($keys['zaakIdentificatie'] ?? null);
        $berichtSoort   = ($keys['berichtSoort'] ?? 'Lk02');
        $crossRefnummer = ($keys['crossRefnummer'] ?? ($keys['referentienummer'] ?? ''));

        $mapping = null;
        if ($zaakId !== null) {
            $mapping = $this->findMappingByExternId(externId: $zaakId);
        }

        // Always record the inbound envelope in the audit log.
        $this->messageHandler->logInboundMessage(
            [
                'endpointId'            => ($mapping['endpointId'] ?? ''),
                'berichtSoort'          => $berichtSoort,
                'entiteittype'          => 'ZAK',
                'crossRefnummer'        => (string) $crossRefnummer,
                'zaakIdentificatie'     => (string) ($zaakId ?? ''),
                'envelopeXml'           => $envelopeXml,
                'verzondenOp'           => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
                'gerelateerdeRequestId' => (string) ($mapping['pipelinqId'] ?? ''),
            ]
        );

        // Correlate to any prior outbound message (bevestiging closing the loop).
        if ((string) $crossRefnummer !== '') {
            $this->messageHandler->correlateInbound(
                crossRefnummer: (string) $crossRefnummer,
                responseXml: $envelopeXml,
                newStatus: 'bevestigd'
            );
        }

        if ($mapping !== null) {
            $this->reconcileMapping(mapping: $mapping, envelopeXml: $envelopeXml);
        }

        $this->logger->info(
            'StUF inbound processed',
            ['zaak' => $zaakId, 'berichtSoort' => $berichtSoort, 'matched' => ($mapping !== null)]
        );

        return ['matchedMapping' => ($mapping !== null), 'zaakIdentificatie' => $zaakId];
    }//end process()

    /**
     * Reconcile the linked pipelinq Request from the inbound envelope.
     *
     * @param array<string, mixed> $mapping     The matched ZaaksysteemMapping.
     * @param string               $envelopeXml The inbound envelope.
     *
     * @return void
     */
    private function reconcileMapping(array $mapping, string $envelopeXml): void
    {
        $details   = $this->parser->parseZaakDetails(responseXml: $envelopeXml);
        $einddatum = ($details['einddatum'] ?? null);

        $mapping['laatsteSynchronisatie'] = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $mapping['synchronisatieStatus']  = 'in_sync';
        $this->saveMapping(mapping: $mapping);

        // Propagate a closure to the linked Request when the zaak gained an einddatum.
        $requestId = (string) ($mapping['pipelinqId'] ?? '');
        if ($requestId !== '' && (string) ($mapping['pipelinqEntiteit'] ?? '') === 'request' && $einddatum !== null) {
            $this->updateRequestStatus(requestId: $requestId, status: 'afgehandeld');
        }
    }//end reconcileMapping()

    /**
     * Find a ZaaksysteemMapping by its external identificatie.
     *
     * @param string $externId The external zaak identificatie.
     *
     * @return array<string, mixed>|null The mapping, or null when none.
     */
    private function findMappingByExternId(string $externId): ?array
    {
        [$register, $schema] = $this->config(schemaKey: 'zaaksysteemMapping_schema');

        $results = $this->getObjectService()->findAll(
            [
                'register' => $register,
                'schema'   => $schema,
                'filters'  => ['externIdentificatie' => $externId],
            ]
        );

        foreach ($this->normaliseResults(results: $results) as $row) {
            return $row;
        }

        return null;
    }//end findMappingByExternId()

    /**
     * Update the linked Request status field through the OR ObjectService.
     *
     * @param string $requestId The Request UUID.
     * @param string $status    The new status value.
     *
     * @return void
     */
    private function updateRequestStatus(string $requestId, string $status): void
    {
        try {
            [$register, $schema] = $this->config(schemaKey: 'request_schema');
            $request = $this->getObjectService()->find($requestId, $register, $schema);
            $data    = $this->toArray(object: $request);
            if ($data === []) {
                return;
            }

            $data['status'] = $status;
            unset($data['@self']);
            $this->getObjectService()->saveObject($data, [], $register, $schema, $requestId);
        } catch (\Throwable $e) {
            $this->logger->warning('StUF could not update linked Request status', ['request' => $requestId, 'exception' => $e]);
        }//end try
    }//end updateRequestStatus()

    /**
     * Persist a ZaaksysteemMapping update.
     *
     * @param array<string, mixed> $mapping The mapping array (with id/uuid).
     *
     * @return void
     */
    private function saveMapping(array $mapping): void
    {
        [$register, $schema] = $this->config(schemaKey: 'zaaksysteemMapping_schema');
        $uuid = ($mapping['id'] ?? ($mapping['uuid'] ?? null));
        unset($mapping['@self']);
        $this->getObjectService()->saveObject($mapping, [], $register, $schema, $uuid);
    }//end saveMapping()

    /**
     * Resolve the register + a schema config key into stored IDs.
     *
     * @param string $schemaKey The app-config schema key.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws RuntimeException If unconfigured.
     */
    private function config(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('StUF register/schema not configured: '.$schemaKey);
        }

        return [$register, $schema];
    }//end config()

    /**
     * Normalise an OR findAll result into a list of row arrays.
     *
     * @param mixed $results The ObjectService findAll result.
     *
     * @return array<int, array<string, mixed>> The row arrays.
     */
    private function normaliseResults(mixed $results): array
    {
        if (is_array($results) === false) {
            return [];
        }

        $rows = ($results['results'] ?? $results);
        if (is_array($rows) === false) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->toArray(object: $row);
        }

        return array_values(array_filter($out, static fn (array $r): bool => $r !== []));
    }//end normaliseResults()

    /**
     * Coerce an OR object/entity into an array.
     *
     * @param mixed $object The object, entity, or array.
     *
     * @return array<string, mixed> The array form.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return [];
    }//end toArray()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()
}//end class
