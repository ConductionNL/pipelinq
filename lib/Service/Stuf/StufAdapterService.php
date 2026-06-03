<?php

/**
 * Pipelinq StufAdapterService.
 *
 * Orchestrates the StUF ZKN/BG adapter operations: build envelope, circuit-check,
 * send (with retry + idempotency), parse, audit-log, and persist mappings.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#2.1
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\CircuitOpenException;
use OCA\Pipelinq\Exception\StufTimeoutException;
use OCA\Pipelinq\Exception\StufTransportException;
use OCA\Pipelinq\Exception\VrijBerichtNotRegisteredException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Main StUF adapter orchestrator.
 *
 * Public entry points (creeerZaak, actualiseerZaak, geefZaakDetails, vrijBericht,
 * genereerZaakIdentificatie) each: consult the circuit breaker, build the
 * envelope, send over the abstract transport (mockable; no live endpoint needed),
 * parse the response, write exactly one StufMessage audit row, and persist the
 * resulting ZaaksysteemMapping. Transient failures retry with exponential backoff
 * reusing the same referentienummer for idempotency; permanent failures and an
 * open circuit raise a needs-input signal via the NotificationService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Orchestrator: it deliberately
 *               wires the StUF collaborators (builder, transport, parser, audit
 *               handler, circuit breaker, contact mapper, coexistence guard) that
 *               each own a single responsibility; the coupling lives here by design.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The five public operations each
 *               run the full build→circuit→send→parse→audit→map pipeline; splitting
 *               them would scatter one cohesive protocol across classes.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)   The constructor injects the seven
 *               single-responsibility collaborators plus container/appConfig/logger.
 */
class StufAdapterService
{
    /**
     * Retry backoff schedule in seconds for transient kennisgeving failures.
     *
     * @var array<int, int>
     */
    private const RETRY_BACKOFF = [5, 30, 120, 600];

    /**
     * Constructor.
     *
     * @param ContainerInterface      $container        The DI container.
     * @param IAppConfig              $appConfig        The app config.
     * @param StufEnvelopeBuilder     $envelopeBuilder  The envelope builder.
     * @param StufTransportInterface  $transport        The SOAP transport (abstract; mockable).
     * @param StufMessageParser       $parser           The response parser.
     * @param StufMessageHandler      $messageHandler   The audit-log handler.
     * @param CircuitBreakerService   $circuitBreaker   The circuit breaker.
     * @param ContactBetrokkeneMapper $betrokkeneMapper The contact mapper.
     * @param ZgwCoexistenceGuard     $coexistence      The ZGW coexistence guard.
     * @param LoggerInterface         $logger           The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private StufEnvelopeBuilder $envelopeBuilder,
        private StufTransportInterface $transport,
        private StufMessageParser $parser,
        private StufMessageHandler $messageHandler,
        private CircuitBreakerService $circuitBreaker,
        private ContactBetrokkeneMapper $betrokkeneMapper,
        private ZgwCoexistenceGuard $coexistence,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a zaak from a Request (Lk01 creeerZaak).
     *
     * @param array<string, mixed> $request  The pipelinq Request array.
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     * @param array<string, mixed> $opts     Options (betrokkenen, documents, existingMappings, includeDocuments).
     *
     * @return array{success: bool, zaakIdentificatie: ?string, referentienummer: string, stufMessageId: ?string} The result.
     *
     * @throws CircuitOpenException     If the endpoint circuit is open.
     * @throws StufTransportException   If the send ultimately fails.
     */
    public function creeerZaak(array $request, array $endpoint, array $opts=[]): array
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        $this->assertCircuitClosed(endpointId: $endpointId, endpoint: $endpoint);

        // ZGW coexistence: if this Request already has a zaak mapping, no-op.
        $existingMappings = ($opts['existingMappings'] ?? []);
        if ($this->coexistence->mayRegisterZaak(existingMappings: $existingMappings) === false) {
            $existingId = $this->coexistence->existingZaakIdentificatie(existingMappings: $existingMappings);
            return [
                'success'           => true,
                'zaakIdentificatie' => $existingId,
                'referentienummer'  => '',
                'stufMessageId'     => null,
            ];
        }

        // Pre-allocate the zaak id when the endpoint demands it (vooraf strategy).
        $zaakId = null;
        if ((string) ($endpoint['zaakIdentificatieStrategie'] ?? 'achteraf') === 'vooraf') {
            $zaakId = $this->genereerZaakIdentificatie(endpoint: $endpoint);
        }

        $betrokkenen = $this->resolveBetrokkenen(opts: $opts, endpoint: $endpoint);
        $documents   = [];
        if ((bool) ($opts['includeDocuments'] ?? false) === true) {
            $documents = ($opts['documents'] ?? []);
        }

        $referentienummer = $this->envelopeBuilder->generateReferentienummer();
        $envelope         = $this->envelopeBuilder->buildLk01CreeerZaak(
            request: $request,
            endpoint: $endpoint,
            zaakId: $zaakId,
            betrokkenen: $betrokkenen,
            documents: $documents
        );

        $message = $this->messageHandler->logOutbound(
            [
                'endpointId'            => $endpointId,
                'berichtSoort'          => 'Lk01',
                'entiteittype'          => 'ZAK',
                'functie'               => 'creeerZaak',
                'referentienummer'      => $referentienummer,
                'zaakIdentificatie'     => $zaakId,
                'envelopeXml'           => $envelope,
                'gerelateerdeRequestId' => (string) ($request['id'] ?? ($request['uuid'] ?? '')),
            ]
        );

        $result = $this->sendWithRetry(endpoint: $endpoint, envelope: $envelope, message: $message);

        $bevestiging = $this->parser->parseBevestiging(responseXml: $result['responseXml']);
        $finalZaakId = ($zaakId ?? ($bevestiging['zaakIdentificatie'] ?? null));

        $this->messageHandler->correlateInbound(
            crossRefnummer: $referentienummer,
            responseXml: $result['responseXml'],
            newStatus: 'bevestigd'
        );

        if ($finalZaakId !== null && $finalZaakId !== '') {
            $this->persistZaakMapping(
                requestId: (string) ($request['id'] ?? ($request['uuid'] ?? '')),
                zaakId: $finalZaakId,
                endpointId: $endpointId
            );
        }

        $this->circuitBreaker->resetEndpoint(endpointId: $endpointId);

        return [
            'success'           => true,
            'zaakIdentificatie' => $finalZaakId,
            'referentienummer'  => $referentienummer,
            'stufMessageId'     => ($message['id'] ?? ($message['uuid'] ?? null)),
        ];
    }//end creeerZaak()

    /**
     * Update an existing zaak (Lk02 actualiseerZaak).
     *
     * @param string               $zaakId      The external zaak identificatie.
     * @param array<string, mixed> $wijzigingen The field changes.
     * @param array<string, mixed> $endpoint    The resolved endpoint config.
     *
     * @return array{success: bool, referentienummer: string} The result.
     *
     * @throws CircuitOpenException   If the endpoint circuit is open.
     * @throws StufTransportException If the send fails.
     */
    public function actualiseerZaak(string $zaakId, array $wijzigingen, array $endpoint): array
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        $this->assertCircuitClosed(endpointId: $endpointId, endpoint: $endpoint);

        $referentienummer = $this->envelopeBuilder->generateReferentienummer();
        $envelope         = $this->envelopeBuilder->buildLk02ActualiseerZaak(
            endpoint: $endpoint,
            zaakId: $zaakId,
            wijzigingen: $wijzigingen
        );

        $message = $this->messageHandler->logOutbound(
            [
                'endpointId'        => $endpointId,
                'berichtSoort'      => 'Lk02',
                'entiteittype'      => 'ZAK',
                'functie'           => 'actualiseerZaak',
                'referentienummer'  => $referentienummer,
                'zaakIdentificatie' => $zaakId,
                'envelopeXml'       => $envelope,
            ]
        );

        $result = $this->sendWithRetry(endpoint: $endpoint, envelope: $envelope, message: $message);

        $responseXml = $result['responseXml'];

        // A Fo02 fault surfaces a functional error: flag the mapping and escalate.
        if ($this->looksLikeFault(responseXml: $responseXml) === true) {
            $fout = $this->parser->parseError(responseXml: $responseXml);
            $this->messageHandler->correlateInbound(
                crossRefnummer: $referentienummer,
                responseXml: $responseXml,
                newStatus: 'fout',
                fout: $fout
            );
            $this->raiseNeedsInput(type: 'stuf_functional_error', endpointId: $endpointId, detail: $fout);

            return ['success' => false, 'referentienummer' => $referentienummer];
        }

        $this->messageHandler->correlateInbound(
            crossRefnummer: $referentienummer,
            responseXml: $responseXml,
            newStatus: 'bevestigd'
        );
        $this->circuitBreaker->resetEndpoint(endpointId: $endpointId);

        return ['success' => true, 'referentienummer' => $referentienummer];
    }//end actualiseerZaak()

    /**
     * Query zaak details synchronously (Lv01 -> La01, default 30s timeout).
     *
     * @param string               $zaakId   The zaak identificatie to query.
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     * @param int                  $timeout  The timeout in seconds.
     *
     * @return array<string, mixed>|null The hydrated Zaak object, or null on no-data.
     *
     * @throws CircuitOpenException  If the endpoint circuit is open.
     * @throws StufTimeoutException  If no La01 arrives within the timeout.
     */
    public function geefZaakDetails(string $zaakId, array $endpoint, int $timeout=30): ?array
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        $this->assertCircuitClosed(endpointId: $endpointId, endpoint: $endpoint);

        $referentienummer = $this->envelopeBuilder->generateReferentienummer();
        $envelope         = $this->envelopeBuilder->buildLv01GeefDetails(
            endpoint: $endpoint,
            zaakId: $zaakId,
            gewensteElementen: ['omschrijving', 'startdatum', 'einddatum']
        );

        $message = $this->messageHandler->logOutbound(
            [
                'endpointId'        => $endpointId,
                'berichtSoort'      => 'Lv01',
                'entiteittype'      => 'ZAK',
                'functie'           => 'geefZaakDetails',
                'referentienummer'  => $referentienummer,
                'zaakIdentificatie' => $zaakId,
                'envelopeXml'       => $envelope,
            ]
        );

        try {
            // Synchronous query: NO automatic retry on timeout (spec REQ-STUF-003).
            $result = $this->transport->send(endpoint: $endpoint, envelopeXml: $envelope, timeoutSeconds: $timeout);
        } catch (StufTransportException $e) {
            $this->messageHandler->transitionStatus(message: $message, newStatus: 'fout');
            $this->raiseNeedsInput(
                type: 'stuf_timeout',
                endpointId: $endpointId,
                detail: ['stufMessageId' => ($message['id'] ?? null), 'zaakId' => $zaakId]
            );
            throw new StufTimeoutException(message: 'geefZaakDetails timed out for '.$zaakId, code: 0, previous: $e);
        }

        $this->messageHandler->correlateInbound(
            crossRefnummer: $referentienummer,
            responseXml: $result['responseXml'],
            newStatus: 'bevestigd'
        );

        return $this->parser->parseZaakDetails(responseXml: $result['responseXml']);
    }//end geefZaakDetails()

    /**
     * Send a registered vrijBericht (Du01).
     *
     * @param string               $name     The vrijBericht template name.
     * @param array<string, mixed> $payload  The payload field values.
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     *
     * @return array{success: bool, referentienummer: string, stufMessageId: ?string} The result.
     *
     * @throws VrijBerichtNotRegisteredException If no template is registered for the name.
     * @throws CircuitOpenException              If the endpoint circuit is open.
     */
    public function vrijBericht(string $name, array $payload, array $endpoint): array
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        $this->assertVrijBerichtRegistered(name: $name, payload: $payload, endpoint: $endpoint);
        $this->assertCircuitClosed(endpointId: $endpointId, endpoint: $endpoint);

        $referentienummer = $this->envelopeBuilder->generateReferentienummer();
        $envelope         = $this->envelopeBuilder->buildVrijBericht(endpoint: $endpoint, name: $name, payload: $payload);

        $message = $this->messageHandler->logOutbound(
            [
                'endpointId'        => $endpointId,
                'berichtSoort'      => 'Du01',
                'entiteittype'      => 'ZAK',
                'functie'           => $name,
                'referentienummer'  => $referentienummer,
                'zaakIdentificatie' => (string) ($payload['zaakIdentificatie'] ?? ''),
                'envelopeXml'       => $envelope,
            ]
        );

        $result = $this->sendWithRetry(endpoint: $endpoint, envelope: $envelope, message: $message);
        $this->messageHandler->correlateInbound(
            crossRefnummer: $referentienummer,
            responseXml: $result['responseXml'],
            newStatus: 'bevestigd'
        );
        $this->circuitBreaker->resetEndpoint(endpointId: $endpointId);

        return [
            'success'          => true,
            'referentienummer' => $referentienummer,
            'stufMessageId'    => ($message['id'] ?? ($message['uuid'] ?? null)),
        ];
    }//end vrijBericht()

    /**
     * Request a pre-allocated zaak identificatie (Du01 genereerZaakIdentificatie).
     *
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     *
     * @return string The allocated zaak identificatie.
     *
     * @throws StufTransportException If the allocation fails or returns no id.
     */
    public function genereerZaakIdentificatie(array $endpoint): string
    {
        $endpointId       = (string) ($endpoint['id'] ?? '');
        $referentienummer = $this->envelopeBuilder->generateReferentienummer();
        $envelope         = $this->envelopeBuilder->buildDu01GenereerZaakId(endpoint: $endpoint);

        $message = $this->messageHandler->logOutbound(
            [
                'endpointId'       => $endpointId,
                'berichtSoort'     => 'Du01',
                'entiteittype'     => 'ZAK',
                'functie'          => 'genereerZaakIdentificatie',
                'referentienummer' => $referentienummer,
                'envelopeXml'      => $envelope,
            ]
        );

        $result      = $this->sendWithRetry(endpoint: $endpoint, envelope: $envelope, message: $message);
        $bevestiging = $this->parser->parseBevestiging(responseXml: $result['responseXml']);

        $zaakId = ($bevestiging['zaakIdentificatie'] ?? null);
        if ($zaakId === null || $zaakId === '') {
            throw new StufTransportException(message: 'genereerZaakIdentificatie returned no zaak identificatie.');
        }

        $this->messageHandler->correlateInbound(
            crossRefnummer: $referentienummer,
            responseXml: $result['responseXml'],
            newStatus: 'bevestigd'
        );

        return $zaakId;
    }//end genereerZaakIdentificatie()

    /**
     * Send with exponential-backoff retry on transient failures, idempotent on
     * the same referentienummer (the envelope is reused verbatim across retries).
     *
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     * @param string               $envelope The envelope to send.
     * @param array<string, mixed> $message  The StufMessage audit row.
     *
     * @return array{httpStatus: int, responseXml: string, durationMs: int} The transport result.
     *
     * @throws StufTransportException If all attempts fail.
     */
    private function sendWithRetry(array $endpoint, string $envelope, array $message): array
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        $attempts   = (count(self::RETRY_BACKOFF) + 1);
        $lastError  = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $result = $this->transport->send(endpoint: $endpoint, envelopeXml: $envelope, timeoutSeconds: 30);
                $status = $result['httpStatus'];

                if ($status >= 200 && $status < 300) {
                    return $result;
                }

                if ($this->isTransientStatus(status: $status) === false) {
                    throw new StufTransportException(message: 'StUF permanent HTTP error '.$status);
                }

                $lastError = ['httpStatus' => $status, 'transient' => true];
                $this->messageHandler->recordRetry(
                    message: $message,
                    attempt: $attempt,
                    httpStatus: $status,
                    fout: $lastError,
                    durationMs: $result['durationMs']
                );
            } catch (StufTransportException $e) {
                $lastError = ['httpStatus' => 0, 'message' => $e->getMessage(), 'transient' => true];
                $this->messageHandler->recordRetry(
                    message: $message,
                    attempt: $attempt,
                    httpStatus: 0,
                    fout: $lastError,
                    durationMs: 0
                );
            }//end try

            // Trip the breaker on this failure; if it opens, stop retrying.
            $opened = $this->circuitBreaker->recordFailure(endpointId: $endpointId);
            if ($opened === true) {
                $this->raiseNeedsInput(type: 'stuf_circuit_open', endpointId: $endpointId, detail: $lastError);
                break;
            }

            if ($attempt < $attempts) {
                $this->backoffSleep(seconds: self::RETRY_BACKOFF[($attempt - 1)]);
            }
        }//end for

        $this->messageHandler->transitionStatus(message: $message, newStatus: 'fout');
        throw new StufTransportException(message: 'StUF send failed after retries.');
    }//end sendWithRetry()

    /**
     * Sleep for the backoff interval (overridable in tests via app config).
     *
     * When `stuf.retry.sleep_enabled` is false (test/default-off), the wall-clock
     * sleep is skipped so unit tests exercise the retry logic without delay.
     *
     * @param int $seconds The seconds to sleep.
     *
     * @return void
     */
    private function backoffSleep(int $seconds): void
    {
        $enabled = $this->appConfig->getValueString(Application::APP_ID, 'stuf.retry.sleep_enabled', 'no');
        if ($enabled === 'yes' && $seconds > 0) {
            sleep($seconds);
        }
    }//end backoffSleep()

    /**
     * Resolve the betrokkenen for a creeerZaak from the supplied options.
     *
     * When the caller passes pre-built betrokkenen they are used verbatim. When
     * Contacts are supplied instead, each is resolved through the
     * {@see ContactBetrokkeneMapper} (query-before-create), de-duplicating against
     * any existing NPS in the zaaksysteem so a person is never registered twice.
     *
     * @param array<string, mixed> $opts     The creeerZaak options.
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     *
     * @return array<int, array<string, mixed>> The betrokkene specs for the envelope.
     */
    private function resolveBetrokkenen(array $opts, array $endpoint): array
    {
        $betrokkenen = ($opts['betrokkenen'] ?? []);
        if (is_array($betrokkenen) === false) {
            $betrokkenen = [];
        }

        $contacts = ($opts['contacts'] ?? []);
        if (is_array($contacts) === false) {
            return $betrokkenen;
        }

        foreach ($contacts as $contact) {
            if (is_array($contact) === false) {
                continue;
            }

            $bsn = $this->betrokkeneMapper->bsnFromContact(contact: $contact);
            if ($bsn === null) {
                continue;
            }

            // The findOrCreateBetrokkene call consults existing mappings + a
            // geefBetrokkene lookup so a person is not registered twice (REQ-STUF-010).
            $resolved      = $this->betrokkeneMapper->findOrCreateBetrokkene(contact: $contact, endpoint: $endpoint);
            $betrokkenen[] = [
                'bsn'  => $bsn,
                'naam' => (string) ($contact['name'] ?? ''),
                'id'   => $resolved['identificatie'],
            ];
        }

        return $betrokkenen;
    }//end resolveBetrokkenen()

    /**
     * Persist (or update) a Request -> zaak ZaaksysteemMapping.
     *
     * @param string $requestId  The pipelinq Request UUID.
     * @param string $zaakId     The external zaak identificatie.
     * @param string $endpointId The endpoint id.
     *
     * @return void
     */
    private function persistZaakMapping(string $requestId, string $zaakId, string $endpointId): void
    {
        if ($requestId === '') {
            return;
        }

        $mapping = [
            'pipelinqEntiteit'      => 'request',
            'pipelinqId'            => $requestId,
            'externEntiteit'        => 'ZAK',
            'externIdentificatie'   => $zaakId,
            'endpointId'            => $endpointId,
            'laatsteSynchronisatie' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'synchronisatieStatus'  => 'in_sync',
        ];

        try {
            [$register, $schema] = $this->mappingConfig();
            $this->getObjectService()->saveObject($mapping, [], $register, $schema, null);
        } catch (Throwable $e) {
            // The zaak already exists in the zaaksysteem and the StufMessage audit
            // row is authoritative; a mapping-write failure must not crash the call
            // nor double-register. Escalate so a beheerder can reconcile the link.
            $this->logger->error(
                'StUF zaak created but ZaaksysteemMapping persistence failed',
                ['request' => $requestId, 'zaak' => $zaakId, 'exception' => $e]
            );
            $this->raiseNeedsInput(
                type: 'stuf_mapping_persist_failed',
                endpointId: $endpointId,
                detail: ['requestId' => $requestId, 'zaakIdentificatie' => $zaakId]
            );
        }//end try
    }//end persistZaakMapping()

    /**
     * Assert the circuit is closed; raise needs-input and throw when open.
     *
     * @param string               $endpointId The endpoint id.
     * @param array<string, mixed> $endpoint   The endpoint config (for the event payload).
     *
     * @return void
     *
     * @throws CircuitOpenException When the circuit is open.
     */
    private function assertCircuitClosed(string $endpointId, array $endpoint): void
    {
        if ($this->circuitBreaker->checkEndpoint(endpointId: $endpointId) === false) {
            $this->raiseNeedsInput(
                type: 'stuf_circuit_open',
                endpointId: $endpointId,
                detail: ['naam' => ($endpoint['naam'] ?? $endpointId)]
            );
            throw new CircuitOpenException(message: 'Circuit open for endpoint '.$endpointId);
        }
    }//end assertCircuitClosed()

    /**
     * Assert a vrijBericht template is registered and its required fields present.
     *
     * @param string               $name     The template name.
     * @param array<string, mixed> $payload  The payload.
     * @param array<string, mixed> $endpoint The endpoint config.
     *
     * @return void
     *
     * @throws VrijBerichtNotRegisteredException When no template is registered.
     */
    private function assertVrijBerichtRegistered(string $name, array $payload, array $endpoint): void
    {
        $templates = ($endpoint['vrijeBerichten'] ?? []);
        if (is_array($templates) === false || isset($templates[$name]) === false) {
            throw new VrijBerichtNotRegisteredException(message: 'No vrijBericht template registered for "'.$name.'".');
        }

        $required = ($templates[$name]['verplicht'] ?? []);
        if (is_array($required) === true) {
            foreach ($required as $field) {
                if (isset($payload[$field]) === false) {
                    throw new VrijBerichtNotRegisteredException(
                        message: 'vrijBericht "'.$name.'" missing required field "'.$field.'".'
                    );
                }
            }
        }
    }//end assertVrijBerichtRegistered()

    /**
     * Whether an HTTP status is transient (retryable).
     *
     * @param int $status The HTTP status.
     *
     * @return bool True when transient.
     */
    private function isTransientStatus(int $status): bool
    {
        return $status === 0 || $status === 408 || $status === 429 || ($status >= 500 && $status < 600);
    }//end isTransientStatus()

    /**
     * Heuristic: does the response look like a StUF Fo02 foutbericht.
     *
     * @param string $responseXml The response XML.
     *
     * @return bool True when a fault is present.
     */
    private function looksLikeFault(string $responseXml): bool
    {
        return str_contains($responseXml, 'Fo02') === true || str_contains($responseXml, 'stuf:fout') === true;
    }//end looksLikeFault()

    /**
     * Raise a needs-input signal for a beheerder (ADR-031 crashes-to-needs-input).
     *
     * @param string $type       The needs-input type code.
     * @param string $endpointId The endpoint id.
     * @param mixed  $detail     Additional structured detail.
     *
     * @return void
     */
    private function raiseNeedsInput(string $type, string $endpointId, mixed $detail): void
    {
        $this->logger->warning(
            'StUF needs-input raised',
            ['type' => $type, 'endpoint' => $endpointId, 'detail' => $detail]
        );

        try {
            $notifications = $this->container->get(\OCA\Pipelinq\Service\NotificationService::class);
            if (method_exists($notifications, 'notifyNeedsInput') === true) {
                $notifications->notifyNeedsInput($type, $endpointId, $detail);
            }
        } catch (Throwable $e) {
            // Notification delivery is best-effort; the warning log above is the
            // authoritative trace. Never let a notification failure mask the cause.
            $this->logger->debug('StUF needs-input notification not delivered', ['exception' => $e]);
        }
    }//end raiseNeedsInput()

    /**
     * Resolve the register + zaaksysteemMapping schema config into stored IDs.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws RuntimeException If unconfigured.
     */
    private function mappingConfig(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'zaaksysteemMapping_schema', '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('ZaaksysteemMapping register/schema not configured.');
        }

        return [$register, $schema];
    }//end mappingConfig()

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
