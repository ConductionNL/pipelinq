<?php

/**
 * Pipelinq StufMessageHandler.
 *
 * Persists the per-call StUF audit log (StufMessage) via OpenRegister and manages
 * outbound/inbound correlation, retry-history recording and status transitions.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#2.4
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-008
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
 * Manages StufMessage audit rows through the OpenRegister ObjectService.
 *
 * Exactly one StufMessage row is produced per envelope sent or received; a row
 * survives deletion of the ZaaksysteemMapping it references (GDPR accountability).
 * Only the real OR ObjectService API is used (find/findAll/saveObject).
 */
class StufMessageHandler
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (resolves ObjectService).
     * @param IAppConfig         $appConfig The app config (register/schema IDs).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Persist an outbound StufMessage with status "verzonden".
     *
     * @param array<string, mixed> $fields The message fields (endpointId, berichtSoort, referentienummer, envelopeXml, ...).
     *
     * @return array<string, mixed> The saved StufMessage as an array.
     */
    public function logOutbound(array $fields): array
    {
        $message = array_merge(
            [
                'richting'    => 'uitgaand',
                'status'      => 'verzonden',
                'verzondenOp' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
                'retries'     => [],
            ],
            $fields
        );

        $this->logger->debug(
            'StUF outbound logged',
            ['berichtSoort' => ($message['berichtSoort'] ?? null), 'endpoint' => ($message['endpointId'] ?? null)]
        );

        return $this->save(message: $message, uuid: null);
    }//end logOutbound()

    /**
     * Persist an inbound StufMessage row (e.g. an inbound kennisgeving).
     *
     * @param array<string, mixed> $fields The message fields.
     *
     * @return array<string, mixed> The saved StufMessage as an array.
     */
    public function logInboundMessage(array $fields): array
    {
        $message = array_merge(
            [
                'richting'    => 'inkomend',
                'status'      => 'bevestigd',
                'ontvangenOp' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            ],
            $fields
        );

        return $this->save(message: $message, uuid: null);
    }//end logInboundMessage()

    /**
     * Match an inbound response to a prior outbound by referentienummer.
     *
     * The inbound crossRefnummer equals the outbound referentienummer; on a match
     * the outbound row's status transitions and the response XML is captured.
     *
     * @param string     $crossRefnummer The inbound crossRefnummer.
     * @param string     $responseXml    The full inbound envelope XML.
     * @param string     $newStatus      The status to set on the matched outbound row.
     * @param array|null $fout           Optional fout payload when status is "fout".
     *
     * @return array<string, mixed>|null The updated outbound row, or null when unmatched.
     */
    public function correlateInbound(
        string $crossRefnummer,
        string $responseXml,
        string $newStatus,
        ?array $fout=null,
    ): ?array {
        $match = $this->findOutboundByReferentienummer(referentienummer: $crossRefnummer);
        if ($match === null) {
            $this->logger->info('StUF inbound has no matching outbound', ['crossRefnummer' => $crossRefnummer]);
            return null;
        }

        $match['responseEnvelopeXml'] = $responseXml;
        $match['status']      = $newStatus;
        $match['ontvangenOp'] = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        if ($fout !== null) {
            $match['fout'] = $fout;
        }

        return $this->save(message: $match, uuid: ($match['id'] ?? ($match['uuid'] ?? null)));
    }//end correlateInbound()

    /**
     * Append a retry attempt to a StufMessage's retries[] array.
     *
     * @param array<string, mixed> $message    The StufMessage array.
     * @param int                  $attempt    The 1-based attempt number.
     * @param int                  $httpStatus The HTTP status of the attempt.
     * @param array|null           $fout       The fout payload, if any.
     * @param int                  $durationMs The attempt duration in milliseconds.
     *
     * @return array<string, mixed> The updated StufMessage array.
     */
    public function recordRetry(
        array $message,
        int $attempt,
        int $httpStatus,
        ?array $fout,
        int $durationMs,
    ): array {
        $retries   = ($message['retries'] ?? []);
        $retries[] = [
            'poging'     => $attempt,
            'timestamp'  => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'httpStatus' => $httpStatus,
            'duurMs'     => $durationMs,
            'fout'       => $fout,
        ];

        $message['retries'] = $retries;

        return $this->save(message: $message, uuid: ($message['id'] ?? ($message['uuid'] ?? null)));
    }//end recordRetry()

    /**
     * Transition a StufMessage to a new status (verzonden → bevestigd / fout).
     *
     * @param array<string, mixed> $message   The StufMessage array.
     * @param string               $newStatus The new status.
     *
     * @return array<string, mixed> The updated StufMessage array.
     */
    public function transitionStatus(array $message, string $newStatus): array
    {
        $message['status'] = $newStatus;

        return $this->save(message: $message, uuid: ($message['id'] ?? ($message['uuid'] ?? null)));
    }//end transitionStatus()

    /**
     * Find the outbound StufMessage carrying a given referentienummer.
     *
     * @param string $referentienummer The referentienummer to match.
     *
     * @return array<string, mixed>|null The outbound row, or null when none.
     */
    public function findOutboundByReferentienummer(string $referentienummer): ?array
    {
        [$register, $schema] = $this->config();

        $results = $this->getObjectService()->findAll(
            [
                'register' => $register,
                'schema'   => $schema,
                'filters'  => [
                    'referentienummer' => $referentienummer,
                    'richting'         => 'uitgaand',
                ],
            ]
        );

        foreach ($this->normaliseResults(results: $results) as $row) {
            return $row;
        }

        return null;
    }//end findOutboundByReferentienummer()

    /**
     * Persist a StufMessage object through the OR ObjectService.
     *
     * @param array<string, mixed> $message The message fields.
     * @param string|null          $uuid    The UUID for update, or null to create.
     *
     * @return array<string, mixed> The saved row as an array.
     */
    private function save(array $message, ?string $uuid): array
    {
        [$register, $schema] = $this->config();
        unset($message['@self']);

        $saved = $this->getObjectService()->saveObject(
            $message,
            [],
            $register,
            $schema,
            $uuid
        );

        return $this->toArray(object: $saved);
    }//end save()

    /**
     * Resolve the register + StufMessage schema config into their stored IDs.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws RuntimeException If the register or schema is not configured.
     */
    private function config(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'stufMessage_schema', '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('StufMessage register/schema not configured.');
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
