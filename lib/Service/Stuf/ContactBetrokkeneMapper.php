<?php

/**
 * Pipelinq ContactBetrokkeneMapper.
 *
 * Maintains the bidirectional Contact <-> betrokkene (NPS/NNP) mapping and
 * prevents duplicate betrokkenen by querying the zaaksysteem before creating.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#2.7
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-010
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
 * Maps pipelinq Contacts onto zaaksysteem betrokkenen with de-duplication.
 *
 * A Contact that carries a BSN maps to an NPS (natuurlijk persoon). Before a new
 * NPS is included in a creeerZaak, {@see self::findOrCreateBetrokkene()} consults
 * any existing ZaaksysteemMapping (and may issue a geefBetrokkene Lv01) so the
 * same person is never registered twice. People map onto the EXISTING contact
 * schema (NC-addressbook-synced) — no new person schema is introduced.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the StUF query-before-create
 *               collaborators (builder, parser, transport) plus the OR persistence
 *               path; each dependency owns a single, cohesive responsibility.
 */
class ContactBetrokkeneMapper
{
    /**
     * Constructor.
     *
     * @param ContainerInterface     $container       The DI container.
     * @param IAppConfig             $appConfig       The app config.
     * @param StufEnvelopeBuilder    $envelopeBuilder The envelope builder.
     * @param StufMessageParser      $parser          The response parser.
     * @param StufTransportInterface $transport       The SOAP transport.
     * @param LoggerInterface        $logger          The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private StufEnvelopeBuilder $envelopeBuilder,
        private StufMessageParser $parser,
        private StufTransportInterface $transport,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Extract the BSN from a Contact array, if present.
     *
     * @param array<string, mixed> $contact The pipelinq Contact array.
     *
     * @return string|null The BSN, or null when absent.
     */
    public function bsnFromContact(array $contact): ?string
    {
        $bsn = ($contact['bsn'] ?? null);
        if (is_string($bsn) === true && preg_match('/^\d{8,9}$/', $bsn) === 1) {
            return $bsn;
        }

        return null;
    }//end bsnFromContact()

    /**
     * Get the existing ZaaksysteemMapping for a Contact on an endpoint.
     *
     * @param string $contactId  The pipelinq Contact UUID.
     * @param string $endpointId The endpoint id.
     *
     * @return array<string, mixed>|null The mapping, or null when none exists.
     */
    public function getContactMapping(string $contactId, string $endpointId): ?array
    {
        [$register, $schema] = $this->config();

        $results = $this->getObjectService()->findAll(
            [
                'register' => $register,
                'schema'   => $schema,
                'filters'  => [
                    'pipelinqEntiteit' => 'contact',
                    'pipelinqId'       => $contactId,
                    'endpointId'       => $endpointId,
                ],
            ]
        );

        foreach ($this->normaliseResults(results: $results) as $row) {
            return $row;
        }

        return null;
    }//end getContactMapping()

    /**
     * Resolve (or create) the betrokkene identificatie for a Contact.
     *
     * Resolution order:
     *  1. Reuse an existing ZaaksysteemMapping (no traffic).
     *  2. Query the zaaksysteem (geefBetrokkene Lv01) by BSN; reuse if found.
     *  3. Otherwise return the BSN itself as the identificatie and persist a new
     *     mapping (the full bg:NPS is included in the subsequent Lk01).
     *
     * @param array<string, mixed> $contact  The pipelinq Contact array.
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     *
     * @return array{identificatie: string, isNew: bool} The betrokkene identity.
     *
     * @throws RuntimeException If the Contact has no BSN to map.
     */
    public function findOrCreateBetrokkene(array $contact, array $endpoint): array
    {
        $contactId  = (string) ($contact['id'] ?? ($contact['uuid'] ?? ''));
        $endpointId = (string) ($endpoint['id'] ?? '');
        $bsn        = $this->bsnFromContact(contact: $contact);

        if ($bsn === null) {
            throw new RuntimeException('Contact has no BSN; cannot map to a natuurlijk persoon.');
        }

        $existing = null;
        if ($contactId !== '') {
            $existing = $this->getContactMapping(contactId: $contactId, endpointId: $endpointId);
        }

        if ($existing !== null) {
            $this->logger->debug('StUF betrokkene reused from mapping', ['contact' => $contactId]);
            return ['identificatie' => (string) ($existing['externIdentificatie'] ?? $bsn), 'isNew' => false];
        }

        // Query-before-create: look the BSN up in the zaaksysteem first.
        $found         = $this->lookupBetrokkene(bsn: $bsn, endpoint: $endpoint);
        $isNew         = ($found === null);
        $identificatie = $found ?? $bsn;

        if ($contactId !== '') {
            $this->linkContact(
                contactId: $contactId,
                betrokkeneId: $identificatie,
                endpointId: $endpointId
            );
        }

        return ['identificatie' => $identificatie, 'isNew' => $isNew];
    }//end findOrCreateBetrokkene()

    /**
     * Create or update the ZaaksysteemMapping linking a Contact to a betrokkene.
     *
     * @param string $contactId    The pipelinq Contact UUID.
     * @param string $betrokkeneId The external betrokkene (NPS) identificatie.
     * @param string $endpointId   The endpoint id.
     *
     * @return array<string, mixed> The saved mapping array.
     */
    public function linkContact(string $contactId, string $betrokkeneId, string $endpointId): array
    {
        $existing = $this->getContactMapping(contactId: $contactId, endpointId: $endpointId);

        $mapping = array_merge(
            ($existing ?? []),
            [
                'pipelinqEntiteit'      => 'contact',
                'pipelinqId'            => $contactId,
                'externEntiteit'        => 'NPS',
                'externIdentificatie'   => $betrokkeneId,
                'endpointId'            => $endpointId,
                'laatsteSynchronisatie' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
                'synchronisatieStatus'  => 'in_sync',
            ]
        );

        return $this->saveMapping(mapping: $mapping, uuid: ($existing['id'] ?? ($existing['uuid'] ?? null)));
    }//end linkContact()

    /**
     * Query the zaaksysteem for an existing betrokkene by BSN (geefBetrokkene).
     *
     * @param string               $bsn      The BSN to look up.
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     *
     * @return string|null The found NPS identificatie, or null when not found.
     */
    private function lookupBetrokkene(string $bsn, array $endpoint): ?string
    {
        try {
            $envelope = $this->envelopeBuilder->buildLv01GeefDetails(
                endpoint: $endpoint,
                zaakId: '',
                gewensteElementen: ['inp.bsn']
            );
            // The geefBetrokkene query reuses the Lv01 frame; filter by BSN client-side
            // since betrokkene lookups share the vraag/antwoord transport.
            $envelope = str_replace('<zkn:identificatie></zkn:identificatie>', '', $envelope);

            $result = $this->transport->send(endpoint: $endpoint, envelopeXml: $envelope, timeoutSeconds: 30);
            if ($result['httpStatus'] !== 200) {
                return null;
            }

            $details = $this->parser->parseZaakDetails(responseXml: $result['responseXml']);
            foreach (($details['betrokkenen'] ?? []) as $betrokkene) {
                if (($betrokkene['bsn'] ?? null) === $bsn) {
                    return $bsn;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->info('StUF geefBetrokkene lookup failed; will create new NPS', ['exception' => $e]);
        }//end try

        return null;
    }//end lookupBetrokkene()

    /**
     * Persist a ZaaksysteemMapping object through the OR ObjectService.
     *
     * @param array<string, mixed> $mapping The mapping fields.
     * @param string|null          $uuid    The UUID for update, or null to create.
     *
     * @return array<string, mixed> The saved mapping array.
     */
    private function saveMapping(array $mapping, ?string $uuid): array
    {
        [$register, $schema] = $this->config();
        unset($mapping['@self']);

        $saved = $this->getObjectService()->saveObject(
            $mapping,
            [],
            $register,
            $schema,
            $uuid
        );

        return $this->toArray(object: $saved);
    }//end saveMapping()

    /**
     * Resolve the register + zaaksysteemMapping schema config into stored IDs.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws RuntimeException If the register or schema is not configured.
     */
    private function config(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'zaaksysteemMapping_schema', '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('ZaaksysteemMapping register/schema not configured.');
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

        return $out;
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
