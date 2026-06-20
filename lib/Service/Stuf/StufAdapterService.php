<?php

/**
 * Pipelinq StufAdapterService.
 *
 * The orchestrator. Each public method takes a pipelinq entity and a
 * StufEndpoint, builds the right envelope through StufEnvelopeBuilder,
 * sends it via StufHttpClient, logs the round-trip into StufMessage,
 * persists the resulting ZaaksysteemMapping when applicable, and drives the
 * circuit breaker.
 *
 * Retries are handled here (5s, 30s, 2m, 10m) and reuse the same
 * referentienummer for idempotency — the queued retry job calls back into
 * `retrySend()` which short-circuits on circuit-open and feeds the retry
 * log on the existing StufMessage row.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-002
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-003
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-004
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-005
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-007
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-012
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use DateTimeImmutable;
use DateTimeZone;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates StUF operations against legacy zaaksystemen.
 */
class StufAdapterService
{
    /**
     * Exponential-backoff schedule for kennisgeving retries (seconds).
     */
    public const RETRY_BACKOFF_SECONDS = [5, 30, 120, 600];

    /**
     * Constructor.
     *
     * @param StufEnvelopeBuilder     $builder        The envelope builder.
     * @param StufHttpClient          $httpClient     The HTTP transport.
     * @param StufMessageHandler      $messageHandler The audit log handler.
     * @param StufMessageParser       $parser         The response parser.
     * @param CircuitBreakerService   $circuitBreaker The circuit breaker.
     * @param ContactBetrokkeneMapper $contactMapper  The contact mapper.
     * @param StufRegisterAccess      $register       The register access helper.
     * @param NeedsInputDispatcher    $needsInput     The needs-input dispatcher.
     * @param IJobList                $jobList        The background job list (for retry scheduling).
     * @param LoggerInterface         $logger         The logger.
     */
    public function __construct(
        private StufEnvelopeBuilder $builder,
        private StufHttpClient $httpClient,
        private StufMessageHandler $messageHandler,
        private StufMessageParser $parser,
        private CircuitBreakerService $circuitBreaker,
        private ContactBetrokkeneMapper $contactMapper,
        private StufRegisterAccess $register,
        private NeedsInputDispatcher $needsInput,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a zaak in the zaaksysteem from a pipelinq Request.
     *
     * @param array $request  The Request array (id, type, omschrijving, startdatum, betrokkenen, documenten).
     * @param array $endpoint The StufEndpoint.
     * @param array $opts     Options: includeDocuments (bool), payloadLimitBytes (int).
     *
     * @return array{success:bool,referentienummer:string,stufMessageId:string,zaakIdentificatie:?string,mappingId:?string,fout:?array<string,mixed>}
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-004
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-005
     */
    public function creeerZaak(array $request, array $endpoint, ?array $opts=[]): array
    {
        $opts = ($opts ?? []);
        if ($this->circuitBreaker->checkEndpoint(endpoint: $endpoint) === false) {
            $this->needsInput->dispatch(type: 'stuf_circuit_open', context: ['endpointId' => ($endpoint['id'] ?? '')]);
            throw new CircuitOpenException(message: 'Circuit breaker is open');
        }

        $zaakId = null;
        if (($endpoint['zaakIdentificatieStrategie'] ?? '') === 'vooraf') {
            $zaakId = $this->genereerZaakIdentificatie(endpoint: $endpoint);
            // Anticipatory mapping per REQ-STUF-005 scenario.
            $this->persistRequestMapping(request: $request, externId: $zaakId, endpoint: $endpoint);
        }

        $envelope = $this->builder->buildLk01CreeerZaak(
            request: $request,
            endpoint: $endpoint,
            zaakId: $zaakId,
            opts: $opts
        );

        $referentienummer = $this->extractReferentienummer(envelope: $envelope);
        $msg = $this->messageHandler->logOutbound(
            endpoint: $endpoint,
            envelopeXml: $envelope,
            referentienummer: $referentienummer,
            berichtSoort: 'Lk01',
            functie: 'creeerZaak',
            zaakId: $zaakId,
            requestId: (string) ($request['id'] ?? '')
        );

        $result = $this->dispatch(
            endpoint: $endpoint,
            envelope: $envelope,
            message: $msg,
            functie: 'creeerZaak',
            timeoutSeconds: StufHttpClient::DEFAULT_TIMEOUT_SECONDS
        );

        $serverZaakId = ($result['zaakIdentificatie'] ?? $zaakId);
        $mapping      = null;
        if ($result['success'] === true && $serverZaakId !== null && $serverZaakId !== '') {
            $mapping = $this->persistRequestMapping(request: $request, externId: $serverZaakId, endpoint: $endpoint);
        }

        $zaakIdentificatie = $serverZaakId;
        if ($serverZaakId === '') {
            $zaakIdentificatie = null;
        }

        return [
            'success'           => $result['success'],
            'referentienummer'  => $referentienummer,
            'stufMessageId'     => (string) ($result['messageId'] ?? ($msg['id'] ?? '')),
            'zaakIdentificatie' => $zaakIdentificatie,
            'mappingId'         => ($mapping['id'] ?? null),
            'fout'              => ($result['fout'] ?? null),
        ];
    }//end creeerZaak()

    /**
     * Update an existing zaak via Lk02.
     *
     * @param array $request  The Request with updated fields.
     * @param array $endpoint The StufEndpoint.
     *
     * @return array{success:bool,referentienummer:string,stufMessageId:string,fout:?array<string,mixed>}
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-002
     */
    public function actualiseerZaak(array $request, array $endpoint): array
    {
        if ($this->circuitBreaker->checkEndpoint(endpoint: $endpoint) === false) {
            throw new CircuitOpenException(message: 'Circuit breaker is open');
        }

        $mapping = $this->register->findOne(
            schema: StufRegisterAccess::SCHEMA_MAPPING,
            filters: [
                'pipelinqEntiteit' => 'request',
                'pipelinqId'       => (string) ($request['id'] ?? ''),
                'endpointId'       => (string) ($endpoint['id'] ?? ''),
            ]
        );
        if ($mapping === null) {
            $this->logger->warning(
                message: 'StUF actualiseerZaak: no mapping for request {id}',
                context: ['id' => ($request['id'] ?? '')]
            );
            return [
                'success'          => false,
                'referentienummer' => '',
                'stufMessageId'    => '',
                'fout'             => [
                    'code'         => 'NO_MAPPING',
                    'omschrijving' => 'Geen mapping voor request',
                    'details'      => '',
                    'soort'        => 'permanent',
                ],
            ];
        }

        $envelope         = $this->builder->buildLk02ActualiseerZaak(request: $request, mapping: $mapping, endpoint: $endpoint);
        $referentienummer = $this->extractReferentienummer(envelope: $envelope);
        $msg = $this->messageHandler->logOutbound(
            endpoint: $endpoint,
            envelopeXml: $envelope,
            referentienummer: $referentienummer,
            berichtSoort: 'Lk02',
            functie: 'actualiseerZaak',
            zaakId: (string) ($mapping['externIdentificatie'] ?? ''),
            requestId: (string) ($request['id'] ?? '')
        );

        $result = $this->dispatch(
            endpoint: $endpoint,
            envelope: $envelope,
            message: $msg,
            functie: 'actualiseerZaak',
            timeoutSeconds: StufHttpClient::DEFAULT_TIMEOUT_SECONDS
        );

        return [
            'success'          => $result['success'],
            'referentienummer' => $referentienummer,
            'stufMessageId'    => (string) ($result['messageId'] ?? ($msg['id'] ?? '')),
            'fout'             => ($result['fout'] ?? null),
        ];
    }//end actualiseerZaak()

    /**
     * Synchronously query zaak details (Lv01 → La01, up to 30s).
     *
     * @param string $zaakId            The zaak identificatie.
     * @param array  $endpoint          The StufEndpoint.
     * @param array  $gewensteElementen Optional gewenste zkn elements to scope.
     *
     * @return array|null The Zaak object array, or null on parse failure.
     *
     * @throws TimeoutException When the response does not arrive within the timeout.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-003
     */
    public function geefZaakDetails(string $zaakId, array $endpoint, array $gewensteElementen=[]): ?array
    {
        if ($this->circuitBreaker->checkEndpoint(endpoint: $endpoint) === false) {
            throw new CircuitOpenException(message: 'Circuit breaker is open');
        }

        $envelope         = $this->builder->buildLv01GeefDetails(zaakId: $zaakId, endpoint: $endpoint, gewensteElementen: $gewensteElementen);
        $referentienummer = $this->extractReferentienummer(envelope: $envelope);
        $msg = $this->messageHandler->logOutbound(
            endpoint: $endpoint,
            envelopeXml: $envelope,
            referentienummer: $referentienummer,
            berichtSoort: 'Lv01',
            functie: 'geefZaakDetails',
            zaakId: $zaakId
        );

        $response = $this->httpClient->send(
            endpoint: $endpoint,
            envelopeXml: $envelope,
            soapActionFunc: 'geefZaakDetails',
            timeoutSeconds: StufHttpClient::DEFAULT_TIMEOUT_SECONDS
        );

        if ($response['httpStatus'] === 0 && isset($response['fout']['code']) === true && $response['fout']['code'] === 'TIMEOUT') {
            $this->messageHandler->transitionStatus(
                msg: $msg,
                newStatus: 'fout',
                extras: ['fout' => $response['fout'], 'duurMs' => ($response['durationMs'] ?? 0)]
            );
            $this->needsInput->dispatch(
                type: 'stuf_timeout',
                context: ['endpointId' => ($endpoint['id'] ?? ''), 'stufMessageId' => ($msg['id'] ?? '')]
            );
            throw new TimeoutException(message: 'StUF geefZaakDetails timed out');
        }

        $detailStatus = 'fout';
        if ($response['httpStatus'] >= 200 && $response['httpStatus'] < 300) {
            $detailStatus = 'bevestigd';
        }

        $this->messageHandler->transitionStatus(
            msg: $msg,
            newStatus: $detailStatus,
            extras: [
                'httpStatus'          => ($response['httpStatus'] ?? 0),
                'duurMs'              => ($response['durationMs'] ?? 0),
                'responseEnvelopeXml' => ($response['responseXml'] ?? ''),
                'fout'                => ($response['fout'] ?? null),
            ]
        );

        if ($response['httpStatus'] < 200 || $response['httpStatus'] >= 300) {
            return null;
        }

        return $this->parser->parseZaakDetails(responseXml: (string) ($response['responseXml'] ?? ''));
    }//end geefZaakDetails()

    /**
     * Send a vrijBericht (free message) using a registered template.
     *
     * @param string $name     The template name.
     * @param array  $payload  The payload values.
     * @param array  $endpoint The StufEndpoint.
     *
     * @return array{success:bool,referentienummer:string,stufMessageId:string,fout:?array<string,mixed>}
     *
     * @throws VrijBerichtNotRegisteredException If the template is not registered.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-007
     */
    public function vrijBericht(string $name, array $payload, array $endpoint): array
    {
        if ($this->circuitBreaker->checkEndpoint(endpoint: $endpoint) === false) {
            throw new CircuitOpenException(message: 'Circuit breaker is open');
        }

        $envelope         = $this->builder->buildDu01VrijBericht(name: $name, payload: $payload, endpoint: $endpoint);
        $referentienummer = $this->extractReferentienummer(envelope: $envelope);
        $zaakId           = (string) ($payload['zaakIdentificatie'] ?? '');
        $zaakIdArg        = $zaakId;
        if ($zaakId === '') {
            $zaakIdArg = null;
        }

        $msg = $this->messageHandler->logOutbound(
            endpoint: $endpoint,
            envelopeXml: $envelope,
            referentienummer: $referentienummer,
            berichtSoort: 'Du01',
            functie: $name,
            zaakId: $zaakIdArg
        );

        $result = $this->dispatch(
            endpoint: $endpoint,
            envelope: $envelope,
            message: $msg,
            functie: $name,
            timeoutSeconds: StufHttpClient::DEFAULT_TIMEOUT_SECONDS
        );

        return [
            'success'          => $result['success'],
            'referentienummer' => $referentienummer,
            'stufMessageId'    => (string) ($result['messageId'] ?? ($msg['id'] ?? '')),
            'fout'             => ($result['fout'] ?? null),
        ];
    }//end vrijBericht()

    /**
     * Request a pre-allocated zaak identificatie via Du01 → La01.
     *
     * @param array $endpoint The StufEndpoint.
     *
     * @return string The allocated zaak ID (empty on failure).
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-005
     */
    public function genereerZaakIdentificatie(array $endpoint): string
    {
        $envelope         = $this->builder->buildDu01GenereerZaakId(endpoint: $endpoint);
        $referentienummer = $this->extractReferentienummer(envelope: $envelope);
        $msg = $this->messageHandler->logOutbound(
            endpoint: $endpoint,
            envelopeXml: $envelope,
            referentienummer: $referentienummer,
            berichtSoort: 'Du01',
            functie: 'genereerZaakIdentificatie'
        );

        $response = $this->httpClient->send(
            endpoint: $endpoint,
            envelopeXml: $envelope,
            soapActionFunc: 'genereerZaakIdentificatie',
            timeoutSeconds: StufHttpClient::DEFAULT_TIMEOUT_SECONDS
        );

        $bv = $this->parser->parseBevestiging(responseXml: (string) ($response['responseXml'] ?? ''));

        $vrijStatus = 'fout';
        if ($response['httpStatus'] >= 200 && $response['httpStatus'] < 300) {
            $vrijStatus = 'bevestigd';
        }

        $this->messageHandler->transitionStatus(
            msg: $msg,
            newStatus: $vrijStatus,
            extras: [
                'httpStatus'          => ($response['httpStatus'] ?? 0),
                'duurMs'              => ($response['durationMs'] ?? 0),
                'responseEnvelopeXml' => ($response['responseXml'] ?? ''),
                'zaakIdentificatie'   => ($bv['zaakIdentificatie'] ?? ''),
            ]
        );

        return (string) ($bv['zaakIdentificatie'] ?? '');
    }//end genereerZaakIdentificatie()

    /**
     * Re-send an outbound message (called by the StufRetryJob).
     *
     * @param string $stufMessageId The audit message id.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
     */
    public function retrySend(string $stufMessageId): void
    {
        $msg = $this->register->findOne(
            schema: StufRegisterAccess::SCHEMA_MESSAGE,
            filters: ['id' => $stufMessageId]
        );
        if ($msg === null) {
            $this->logger->warning(message: 'StUF retry: message {id} not found', context: ['id' => $stufMessageId]);
            return;
        }

        $endpoint = $this->register->findOne(
            schema: StufRegisterAccess::SCHEMA_ENDPOINT,
            filters: ['id' => (string) ($msg['endpointId'] ?? '')]
        );
        if ($endpoint === null) {
            $this->logger->warning(message: 'StUF retry: endpoint {id} not found', context: ['id' => ($msg['endpointId'] ?? '')]);
            return;
        }

        if ($this->circuitBreaker->checkEndpoint(endpoint: $endpoint) === false) {
            $this->logger->info(message: 'StUF retry: circuit open, skip');
            return;
        }

        $envelope = (string) ($msg['envelopeXml'] ?? '');
        $functie  = (string) ($msg['functie'] ?? '');
        $attempt  = (count(value: (array) ($msg['retries'] ?? [])) + 1);

        $response = $this->httpClient->send(
            endpoint: $endpoint,
            envelopeXml: $envelope,
            soapActionFunc: $functie,
            timeoutSeconds: StufHttpClient::DEFAULT_TIMEOUT_SECONDS
        );

        $this->handleResponse(
            endpoint: $endpoint,
            response: $response,
            message: $msg,
            functie: $functie,
            attempt: $attempt
        );
    }//end retrySend()

    /**
     * Dispatch a kennisgeving envelope (Lk01/Lk02/Du01/Bv01-expecting) — send, parse, log, retry-on-transient.
     *
     * @param array  $endpoint       The StufEndpoint.
     * @param string $envelope       The envelope XML.
     * @param array  $message        The persisted StufMessage row.
     * @param string $functie        The functie for SOAPAction.
     * @param int    $timeoutSeconds The HTTP timeout.
     *
     * @return array{success:bool,messageId:string,zaakIdentificatie:?string,fout:?array<string,mixed>}
     */
    private function dispatch(array $endpoint, string $envelope, array $message, string $functie, int $timeoutSeconds): array
    {
        $response = $this->httpClient->send(
            endpoint: $endpoint,
            envelopeXml: $envelope,
            soapActionFunc: $functie,
            timeoutSeconds: $timeoutSeconds
        );
        return $this->handleResponse(endpoint: $endpoint, response: $response, message: $message, functie: $functie, attempt: 1);
    }//end dispatch()

    /**
     * Handle an HTTP response: parse Bv01/Fo02, classify, persist, and retry on transient.
     *
     * @param array  $endpoint The StufEndpoint.
     * @param array  $response The httpClient response.
     * @param array  $message  The StufMessage row.
     * @param string $functie  The functie.
     * @param int    $attempt  The current attempt number (1-indexed).
     *
     * @return array{success:bool,messageId:string,zaakIdentificatie:?string,fout:?array<string,mixed>}
     */
    private function handleResponse(array $endpoint, array $response, array $message, string $functie, int $attempt): array
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        $messageId  = (string) ($message['id'] ?? '');
        $httpStatus = (int) ($response['httpStatus'] ?? 0);
        $duration   = (int) ($response['durationMs'] ?? 0);
        $body       = (string) ($response['responseXml'] ?? '');
        $transport  = ($response['fout'] ?? null);

        if ($httpStatus >= 200 && $httpStatus < 300) {
            $bv     = $this->parser->parseBevestiging(responseXml: $body);
            $extras = [
                'httpStatus'          => $httpStatus,
                'duurMs'              => $duration,
                'responseEnvelopeXml' => $body,
            ];
            if (($bv['zaakIdentificatie'] ?? null) !== null) {
                $extras['zaakIdentificatie'] = $bv['zaakIdentificatie'];
            }

            $this->messageHandler->transitionStatus(msg: $message, newStatus: 'bevestigd', extras: $extras);
            $this->circuitBreaker->resetEndpoint(endpoint: $endpoint);
            return [
                'success'           => true,
                'messageId'         => $messageId,
                'zaakIdentificatie' => ($bv['zaakIdentificatie'] ?? null),
                'fout'              => null,
            ];
        }

        $fout = $transport;
        if ($fout === null && $body !== '') {
            $parsed = $this->parser->parseError(responseXml: $body);
            $fout   = [
                'code'         => ($parsed['code'] ?? ''),
                'omschrijving' => ($parsed['omschrijving'] ?? ''),
                'details'      => ($parsed['details'] ?? ''),
                'soort'        => ($parsed['soort'] ?? 'permanent'),
            ];
        }

        $isTransient = ($this->isTransientHttp(httpStatus: $httpStatus) === true || ($fout['soort'] ?? '') === 'transient');
        if ($isTransient === true && $attempt < (count(value: self::RETRY_BACKOFF_SECONDS) + 1)) {
            $this->messageHandler->recordRetry(
                msg: $message,
                attempt: $attempt,
                httpStatus: $httpStatus,
                fout: ($fout ?? []),
                durationMs: $duration
            );
            $this->circuitBreaker->recordFailure(endpoint: $endpoint, fout: ($fout ?? []));
            $this->scheduleRetry(messageId: $messageId, attempt: $attempt);
            return [
                'success'           => false,
                'messageId'         => $messageId,
                'zaakIdentificatie' => null,
                'fout'              => $fout,
            ];
        }

        // Permanent failure path.
        $this->messageHandler->transitionStatus(
            msg: $message,
            newStatus: 'fout',
            extras: [
                'httpStatus'          => $httpStatus,
                'duurMs'              => $duration,
                'responseEnvelopeXml' => $body,
                'fout'                => $fout,
            ]
        );
        $this->circuitBreaker->recordFailure(endpoint: $endpoint, fout: ($fout ?? []));
        $this->needsInput->dispatch(
            type: 'stuf_permanent_error',
            context: ['endpointId' => $endpointId, 'stufMessageId' => $messageId, 'fout' => ($fout ?? []), 'functie' => $functie]
        );
        return [
            'success'           => false,
            'messageId'         => $messageId,
            'zaakIdentificatie' => null,
            'fout'              => $fout,
        ];
    }//end handleResponse()

    /**
     * Persist a request → zaak mapping (idempotent).
     *
     * @param array  $request  The Request.
     * @param string $externId The external zaak identificatie.
     * @param array  $endpoint The endpoint.
     *
     * @return array The mapping row.
     */
    private function persistRequestMapping(array $request, string $externId, array $endpoint): array
    {
        $existing = $this->register->findOne(
            schema: StufRegisterAccess::SCHEMA_MAPPING,
            filters: [
                'pipelinqEntiteit' => 'request',
                'pipelinqId'       => (string) ($request['id'] ?? ''),
                'endpointId'       => (string) ($endpoint['id'] ?? ''),
            ]
        );
        $data     = ($existing ?? [
            'id'               => 'map-'.bin2hex(string: random_bytes(length: 6)),
            'pipelinqEntiteit' => 'request',
            'pipelinqId'       => (string) ($request['id'] ?? ''),
            'endpointId'       => (string) ($endpoint['id'] ?? ''),
            'externEntiteit'   => 'ZAK',
        ]);
        $data['externIdentificatie'] = $externId;
        $syncMoment = new DateTimeImmutable(
            datetime: 'now',
            timezone: new DateTimeZone(timezone: 'Europe/Amsterdam')
        );
        $data['laatsteSynchronisatie'] = $syncMoment->format(format: 'c');
        $data['synchronisatieStatus']  = 'in_sync';
        return $this->register->saveObject(schema: StufRegisterAccess::SCHEMA_MAPPING, data: $data);
    }//end persistRequestMapping()

    /**
     * Schedule a delayed retry via the background job list.
     *
     * @param string $messageId The StufMessage id.
     * @param int    $attempt   The attempt number that just failed (1-indexed).
     *
     * @return void
     */
    private function scheduleRetry(string $messageId, int $attempt): void
    {
        $delayIndex = max(0, ($attempt - 1));
        $delay      = (self::RETRY_BACKOFF_SECONDS[$delayIndex] ?? 600);
        try {
            $this->jobList->add(
                'OCA\\Pipelinq\\BackgroundJob\\StufRetryJob',
                ['stufMessageId' => $messageId, 'runAt' => (time() + $delay)]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(message: 'StUF retry scheduling failed: {error}', context: ['error' => $e->getMessage()]);
        }

        $this->logger->info(
            message: 'StUF retry scheduled for {id} after {delay}s (attempt {attempt})',
            context: ['id' => $messageId, 'delay' => $delay, 'attempt' => $attempt]
        );
    }//end scheduleRetry()

    /**
     * Classify an HTTP status code as transient (retry) or permanent.
     *
     * @param int $httpStatus The status code.
     *
     * @return bool
     */
    private function isTransientHttp(int $httpStatus): bool
    {
        if ($httpStatus === 0) {
            return true;
        }

        if ($httpStatus >= 500 && $httpStatus < 600) {
            return true;
        }

        if ($httpStatus === 408 || $httpStatus === 429) {
            return true;
        }

        return false;
    }//end isTransientHttp()

    /**
     * Extract the referentienummer from an envelope (best-effort).
     *
     * @param string $envelope The envelope XML.
     *
     * @return string The referentienummer (empty if not present).
     */
    private function extractReferentienummer(string $envelope): string
    {
        if (preg_match(pattern: '#<stuf:referentienummer>([^<]+)</stuf:referentienummer>#', subject: $envelope, matches: $matches) === 1) {
            return $matches[1];
        }

        return '';
    }//end extractReferentienummer()
}//end class
