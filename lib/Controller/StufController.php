<?php

/**
 * Pipelinq StufController.
 *
 * REST surface for the StUF-ZKN/BG adapter: outbound vrijBerichten,
 * inbound notification reception, endpoint listing and audit log query.
 *
 * The inbound endpoint is `#[PublicPage]` so the zaaksysteem can post
 * notifications without a user session. It enforces a WSSE UsernameToken
 * check (the same secret that we use outbound) before persisting the
 * envelope and forwarding it to the StufMessageHandler.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-001
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-002
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-007
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Stuf\CircuitBreakerService;
use OCA\Pipelinq\Service\Stuf\CircuitOpenException;
use OCA\Pipelinq\Service\Stuf\StufAdapterService;
use OCA\Pipelinq\Service\Stuf\StufException;
use OCA\Pipelinq\Service\Stuf\StufMessageHandler;
use OCA\Pipelinq\Service\Stuf\StufMessageParser;
use OCA\Pipelinq\Service\Stuf\StufRegisterAccess;
use OCA\Pipelinq\Service\Stuf\StufVaultService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the StUF-ZKN/BG adapter.
 */
class StufController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request        The request.
     * @param StufAdapterService    $adapter        The adapter.
     * @param StufRegisterAccess    $register       The register access helper.
     * @param StufMessageHandler    $messageHandler The audit log handler.
     * @param StufMessageParser     $parser         The message parser.
     * @param StufVaultService      $vault          The vault adapter.
     * @param CircuitBreakerService $circuitBreaker The circuit breaker.
     * @param IL10N                 $l10n           The localization service.
     * @param LoggerInterface       $logger         The logger.
     */
    public function __construct(
        IRequest $request,
        private readonly StufAdapterService $adapter,
        private readonly StufRegisterAccess $register,
        private readonly StufMessageHandler $messageHandler,
        private readonly StufMessageParser $parser,
        private readonly StufVaultService $vault,
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Send a vrijBericht to the named endpoint.
     *
     * Admin-only by NC framework default (no annotation = admin required, see
     * [[nc-security-defaults]]). Body: { endpointId, berichtNaam, payload }.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-007
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function outbound(): JSONResponse
    {
        $endpointId  = (string) $this->request->getParam(key: 'endpointId', default: '');
        $berichtNaam = (string) $this->request->getParam(key: 'berichtNaam', default: '');
        $payload     = (array) $this->request->getParam(key: 'payload', default: []);

        if ($endpointId === '' || $berichtNaam === '') {
            return new JSONResponse(['error' => $this->l10n->t('endpointId and berichtNaam are required')], Http::STATUS_BAD_REQUEST);
        }

        $endpoint = $this->register->findOne(
            schema: StufRegisterAccess::SCHEMA_ENDPOINT,
            filters: ['id' => $endpointId]
        );
        if ($endpoint === null) {
            return new JSONResponse(['error' => $this->l10n->t('Endpoint not found')], Http::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->adapter->vrijBericht(name: $berichtNaam, payload: $payload, endpoint: $endpoint);
            return new JSONResponse($result);
        } catch (CircuitOpenException $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Circuit breaker open for this endpoint'), 'errorCode' => 'CIRCUIT_OPEN'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        } catch (StufException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage(), 'errorCode' => 'STUF_VALIDATION'],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error(message: 'StUF outbound failed: {error}', context: ['error' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Internal error')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end outbound()

    /**
     * Receive an inbound SOAP envelope from the zaaksysteem.
     *
     * Public (no user session) but authenticates the caller via WSSE
     * UsernameToken matched against the StufEndpoint vault reference.
     * Persists the inbound envelope as a StufMessage row and, when the
     * envelope is a Bv01 bevestiging, transitions the matching outbound row
     * from "verzonden" → "bevestigd".
     *
     * @return DataResponse
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-001
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-002
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function inkomend(): DataResponse
    {
        $rawXml = (string) file_get_contents(filename: 'php://input');
        if ($rawXml === '') {
            return new DataResponse(data: 'empty body', statusCode: Http::STATUS_BAD_REQUEST);
        }

        $endpoint = $this->resolveInboundEndpoint(envelopeXml: $rawXml);
        if ($endpoint === null) {
            $this->logger->warning(message: 'StUF inkomend: could not resolve endpoint from envelope');
            return new DataResponse(data: 'unknown endpoint', statusCode: Http::STATUS_BAD_REQUEST);
        }

        if ($this->verifyWsse(envelopeXml: $rawXml, endpoint: $endpoint) === false) {
            $this->logger->warning(message: 'StUF inkomend: WSSE signature mismatch for endpoint {id}', context: ['id' => ($endpoint['id'] ?? '')]);
            // 422 (Unprocessable Entity) signals "invalid signature" without
            // surfacing an NC session-auth status to the upstream zaaksysteem.
            // Mirrors the marketing-blast webhook convention so the
            // semantic-auth gate remains unambiguous: this is WSSE signature
            // verification of a PublicPage webhook, not NC session auth.
            return new DataResponse(data: 'invalid signature', statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $berichtSoort = $this->detectBerichtSoort(envelopeXml: $rawXml);
        $crossRef     = $this->extractCrossRefnummer(envelopeXml: $rawXml);
        $functie      = $this->extractFunctie(envelopeXml: $rawXml);
        $zaakId       = ($this->parser->parseBevestiging(responseXml: $rawXml)['zaakIdentificatie'] ?? null);

        $this->messageHandler->logInbound(
            endpoint: $endpoint,
            responseXml: $rawXml,
            berichtSoort: $berichtSoort,
            crossRefnummer: $crossRef,
            zaakId: $zaakId,
            functie: $functie
        );

        if ($berichtSoort === 'Bv01' && $crossRef !== '') {
            $outbound = $this->messageHandler->findOutboundByReferentienummer(referentienummer: $crossRef);
            if ($outbound !== null) {
                $this->messageHandler->transitionStatus(
                    msg: $outbound,
                    newStatus: 'bevestigd',
                    extras: [
                        'responseEnvelopeXml' => $rawXml,
                        'zaakIdentificatie'   => ($zaakId ?? ($outbound['zaakIdentificatie'] ?? '')),
                    ]
                );
            }
        }

        return new DataResponse(data: 'ack', statusCode: Http::STATUS_OK);
    }//end inkomend()

    /**
     * List all configured StufEndpoint objects.
     *
     * Admin-only by NC framework default (no annotation = admin required, see
     * [[nc-security-defaults]]).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-011
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function endpoints(): JSONResponse
    {
        $items = $this->register->findAll(schema: StufRegisterAccess::SCHEMA_ENDPOINT, filters: [], limit: 500);
        $items = array_map(callback: [$this, 'enrichEndpointWithHealth'], array: $items);
        return new JSONResponse(['items' => $items, 'total' => count(value: $items)]);
    }//end endpoints()

    /**
     * Query the StufMessage audit log.
     *
     * Admin-only by NC framework default (no annotation = admin required, see
     * [[nc-security-defaults]]).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-008
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function messages(): JSONResponse
    {
        $endpointId   = (string) $this->request->getParam(key: 'endpointId', default: '');
        $berichtSoort = (string) $this->request->getParam(key: 'berichtSoort', default: '');
        $status       = (string) $this->request->getParam(key: 'status', default: '');
        $limit        = (int) $this->request->getParam(key: 'limit', default: 50);
        $limit        = max(1, min(500, $limit));

        $filters = [];
        if ($endpointId !== '') {
            $filters['endpointId'] = $endpointId;
        }

        if ($berichtSoort !== '') {
            $filters['berichtSoort'] = $berichtSoort;
        }

        if ($status !== '') {
            $filters['status'] = $status;
        }

        $items = $this->register->findAll(schema: StufRegisterAccess::SCHEMA_MESSAGE, filters: $filters, limit: $limit);
        return new JSONResponse(['items' => $items, 'total' => count(value: $items), 'limit' => $limit]);
    }//end messages()

    /**
     * Resolve the StufEndpoint from the envelope's ontvanger/zender (best-effort).
     *
     * @param string $envelopeXml The inbound envelope.
     *
     * @return array|null The endpoint or null.
     */
    private function resolveInboundEndpoint(string $envelopeXml): ?array
    {
        $zenderPattern = '#<stuf:zender>.*?<stuf:applicatie>([^<]+)</stuf:applicatie>.*?</stuf:zender>#s';
        if (preg_match(pattern: $zenderPattern, subject: $envelopeXml, matches: $matches) === 1) {
            $applicatie = trim(string: $matches[1]);
            $endpoint   = $this->register->findOne(
                schema: StufRegisterAccess::SCHEMA_ENDPOINT,
                filters: ['ontvangerApplicatie' => $applicatie]
            );
            if ($endpoint !== null) {
                return $endpoint;
            }
        }

        // Fallback: header X-Pipelinq-Endpoint-Id (used by callers we control).
        $headerId = (string) $this->request->getHeader(name: 'x-pipelinq-endpoint-id');
        if ($headerId !== '') {
            return $this->register->findOne(schema: StufRegisterAccess::SCHEMA_ENDPOINT, filters: ['id' => $headerId]);
        }

        return null;
    }//end resolveInboundEndpoint()

    /**
     * Verify the inbound WSSE UsernameToken matches the endpoint's stored credentials.
     *
     * @param string $envelopeXml The envelope XML.
     * @param array  $endpoint    The endpoint.
     *
     * @return bool
     */
    private function verifyWsse(string $envelopeXml, array $endpoint): bool
    {
        $auth         = ($endpoint['authenticatie'] ?? []);
        $expectedUser = (string) ($auth['gebruikersnaam'] ?? '');
        $expectedPasswordRef = (string) ($auth['wachtwoordKluisRef'] ?? '');
        $expectedPassword    = $this->vault->resolveSecret(reference: $expectedPasswordRef);

        if ($expectedUser === '' || $expectedPassword === '') {
            return false;
        }

        $username = '';
        if (preg_match(pattern: '#<wsse:Username>([^<]+)</wsse:Username>#', subject: $envelopeXml, matches: $matches) === 1) {
            $username = trim(string: $matches[1]);
        }

        $password = '';
        if (preg_match(pattern: '#<wsse:Password[^>]*>([^<]+)</wsse:Password>#', subject: $envelopeXml, matches: $matches) === 1) {
            $password = trim(string: $matches[1]);
        }

        return hash_equals(known_string: $expectedUser, user_string: $username)
            && hash_equals(known_string: $expectedPassword, user_string: $password);
    }//end verifyWsse()

    /**
     * Detect the bericht-soort (Bv01, Lk02, ...) from the envelope.
     *
     * @param string $envelopeXml The envelope.
     *
     * @return string
     */
    private function detectBerichtSoort(string $envelopeXml): string
    {
        if (preg_match(pattern: '#<stuf:berichtcode>([A-Za-z0-9]+)</stuf:berichtcode>#', subject: $envelopeXml, matches: $matches) === 1) {
            return $matches[1];
        }

        if (str_contains(haystack: $envelopeXml, needle: 'zakLk02') === true) {
            return 'Lk02';
        }

        if (str_contains(haystack: $envelopeXml, needle: 'zakLk01') === true) {
            return 'Lk01';
        }

        if (str_contains(haystack: $envelopeXml, needle: 'Bv01') === true) {
            return 'Bv01';
        }

        if (str_contains(haystack: $envelopeXml, needle: 'Fo02') === true) {
            return 'Fo02';
        }

        return 'Lk02';
    }//end detectBerichtSoort()

    /**
     * Extract crossRefnummer from an inbound envelope (best-effort).
     *
     * @param string $envelopeXml The envelope.
     *
     * @return string
     */
    private function extractCrossRefnummer(string $envelopeXml): string
    {
        if (preg_match(pattern: '#<stuf:crossRefnummer>([^<]+)</stuf:crossRefnummer>#', subject: $envelopeXml, matches: $matches) === 1) {
            return trim(string: $matches[1]);
        }

        if (preg_match(pattern: '#<stuf:referentienummer>([^<]+)</stuf:referentienummer>#', subject: $envelopeXml, matches: $matches) === 1) {
            return trim(string: $matches[1]);
        }

        return '';
    }//end extractCrossRefnummer()

    /**
     * Extract functie from an inbound envelope (best-effort).
     *
     * @param string $envelopeXml The envelope.
     *
     * @return string
     */
    private function extractFunctie(string $envelopeXml): string
    {
        if (preg_match(pattern: '#<stuf:functie>([^<]+)</stuf:functie>#', subject: $envelopeXml, matches: $matches) === 1) {
            return trim(string: $matches[1]);
        }

        return '';
    }//end extractFunctie()

    /**
     * Enrich an endpoint with its health snapshot (status badge + last 5 messages).
     *
     * @param array $endpoint The raw endpoint row.
     *
     * @return array
     */
    private function enrichEndpointWithHealth(array $endpoint): array
    {
        $snapshot = $this->circuitBreaker->snapshot(endpointId: (string) ($endpoint['id'] ?? ''));
        $recent   = $this->register->findAll(
            schema: StufRegisterAccess::SCHEMA_MESSAGE,
            filters: ['endpointId' => (string) ($endpoint['id'] ?? '')],
            limit: 5
        );
        $endpoint['health'] = [
            'state'        => $snapshot['state'],
            'failureCount' => $snapshot['failureCount'],
            'openedAt'     => $snapshot['openedAt'],
            'recentCount'  => count(value: $recent),
        ];
        return $endpoint;
    }//end enrichEndpointWithHealth()
}//end class
