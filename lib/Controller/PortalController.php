<?php

/**
 * Pipelinq PortalController.
 *
 * Public, unauthenticated HTTP surface for the customer self-booking portal
 * (member 05 of the appointment-booking chain). Customers list bookable
 * services, query availability, create a booking, and reschedule/cancel via
 * HMAC-SHA256 signed deep-links. Logic is delegated to BookingService /
 * AvailabilityService / EligibilityService; this controller validates input,
 * signs / verifies links, and shapes JSON responses (ADR-003 thin-controller).
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
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AppointmentDepositService;
use OCA\Pipelinq\Service\BookingService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Public booking portal endpoints.
 *
 * All endpoints carry `@PublicPage` + `@NoCSRFRequired` + `@CORS` so external
 * portal frontends can call them without an authenticated NC session (ADR-005,
 * ADR-016). Errors emit static messages — never `$e->getMessage()` — so a
 * misconfiguration cannot leak stack traces to anonymous callers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Aggregates the services a
 *  customer-facing portal needs (booking, availability, OR object service,
 *  URL generator, signing helpers).
 * @SuppressWarnings(PHPMD.TooManyMethods)           The public booking surface (list, availability,
 *  book, reschedule, cancel) plus its signing/verification and OR-shaping private helpers form
 *  one cohesive portal controller.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same cohesion rationale — the endpoints and
 *  their input-validation / link-signing helpers belong together.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class PortalController extends Controller {
	/**
	 * Signing-key app-config key (per-instance HMAC secret, ADR-005).
	 *
	 * Lazily minted by the controller the first time a link is signed; reused
	 * across signs so a link issued today still verifies tomorrow.
	 *
	 * @var string
	 */
	private const SIGNING_KEY_CONFIG = 'appointment_portal_signing_key';

	/**
	 * Signed link expiry (30 days as per design.md).
	 *
	 * @var int
	 */
	private const LINK_TTL_DAYS = 30;

	/**
	 * Maximum length for free-form text fields submitted by anonymous users.
	 *
	 * @var int
	 */
	private const MAX_TEXT_LENGTH = 1000;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param BookingService $bookingService Booking lifecycle service (member 04).
	 * @param AppointmentDepositService $depositService Deposit payment orchestrator (member 08).
	 * @param IAppConfig $appConfig The app config.
	 * @param ContainerInterface $container The DI container (OpenRegister lookup).
	 * @param IAppManager $appManager The app manager (OR-availability guard).
	 * @param IURLGenerator $urlGenerator The URL generator (signed deep-links).
	 * @param ITimeFactory $time The time factory (signed-link expiry).
	 * @param ISecureRandom $secureRandom The CSPRNG (signing-key minting).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private BookingService $bookingService,
		private AppointmentDepositService $depositService,
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $time,
		private ISecureRandom $secureRandom,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /portal/services — list services available for online booking.
	 *
	 * @return JSONResponse The bookable services (200) or 503 when unconfigured.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function services(): JSONResponse {
		try {
			$services = $this->listBookableServices();
			return new JSONResponse(['services' => $services], Http::STATUS_OK);
		} catch (RuntimeException $e) {
			$this->logger->warning('Pipelinq portal: services lookup unavailable');
			return new JSONResponse(['error' => 'Service unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end services()

	/**
	 * GET /portal/availability — query free slots for a service on a date.
	 *
	 * Query parameters: `serviceId` (UUID/slug), `date` (`YYYY-MM-DD`).
	 *
	 * @return JSONResponse The free slots (200) or 400 on missing/invalid input.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function availability(): JSONResponse {
		$serviceId = trim((string)$this->request->getParam('serviceId', ''));
		$date = trim((string)$this->request->getParam('date', ''));
		if ($serviceId === '' || $date === '') {
			return new JSONResponse(['error' => 'serviceId and date are required'], Http::STATUS_BAD_REQUEST);
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return new JSONResponse(['error' => 'date must be YYYY-MM-DD'], Http::STATUS_BAD_REQUEST);
		}

		$slots = $this->bookingService->getAvailableSlots(serviceId: $serviceId, date: $date);
		return new JSONResponse(['slots' => $slots], Http::STATUS_OK);
	}//end availability()

	/**
	 * POST /portal/book — create a booking from anonymous portal input.
	 *
	 * Body: `customerName`, `email`, `phone`, `serviceId`, `startAt`, optional `notes`.
	 *
	 * The AnonRateLimit below is deliberately tight: a booking consumes a real
	 * slot, so an unbounded caller can exhaust availability for everyone else
	 * without ever authenticating.
	 *
	 * @return JSONResponse The booking confirmation (200) or 400 on invalid input.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	#[AnonRateLimit(limit: 20, period: 60)]
	public function book(): JSONResponse {
		$params = $this->collectBookParams();
		$error = $this->validateBookParams(params: $params);
		if ($error !== null) {
			return $error;
		}

		try {
			$created = $this->createBookingFromPortal(params: $params);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'Invalid booking request'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq portal: booking creation failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Booking could not be created'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$bookingId = $created['bookingId'];

		return new JSONResponse(
			[
				'bookingId' => $bookingId,
				'manageLinks' => $this->buildManageLinks(bookingId: $bookingId),
				'paymentRedirect' => $this->resolvePaymentRedirect(bookingId: $bookingId, created: $created),
				'message' => 'Booking received',
			],
			Http::STATUS_OK
		);
	}//end book()

	/**
	 * Resolve the payment-redirect URL for a freshly created booking.
	 *
	 * When the booked Service requires a deposit, opens an openconnector
	 * payment session via {@see AppointmentDepositService::createDepositSession}
	 * and returns its hosted checkout URL so the portal can forward the
	 * customer to pay. Returns null for non-deposit bookings, and also when
	 * openconnector is unavailable / unconfigured (the deposit service degrades
	 * to an empty session URL, and the 15-minute timeout job then releases the
	 * slot) — the portal surfaces a static fallback in that case.
	 *
	 * @param string $bookingId The freshly created booking UUID.
	 * @param array<string, mixed> $created The createBookingFromPortal result.
	 *
	 * @return string|null The hosted payment URL, or null when no charge is due / possible.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md#req-apt-010
	 */
	private function resolvePaymentRedirect(string $bookingId, array $created): ?string {
		$requiresDeposit = (bool)($created['requiresDeposit'] ?? false);
		$depositAmount = (float)($created['depositAmount'] ?? 0.0);
		if ($requiresDeposit === false || $depositAmount <= 0.0) {
			return null;
		}

		$amountCents = (int)round($depositAmount * 100);
		if ($amountCents <= 0) {
			return null;
		}

		$returnUrl = $this->urlGenerator->getAbsoluteURL(
			'/index.php/apps/pipelinq/portal/api/booking/' . rawurlencode($bookingId)
		);

		try {
			$session = $this->depositService->createDepositSession(
				bookingId: $bookingId,
				amountCents: $amountCents,
				currency: (string)($created['currency'] ?? 'EUR'),
				description: 'Deposit for ' . (string)($created['serviceName'] ?? 'appointment'),
				returnUrl: $returnUrl
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq portal: deposit session could not be opened',
				['booking' => $bookingId]
			);
			return null;
		}

		$sessionUrl = (string)$session['sessionUrl'];
		if ($sessionUrl === '') {
			return null;
		}

		return $sessionUrl;
	}//end resolvePaymentRedirect()

	/**
	 * GET /portal/booking/{bookingId} — fetch a booking (optionally signed).
	 *
	 * Without a signature: only safe public fields are returned.
	 * With a valid signature (`?token=...`): the same shape — the signature
	 * lets clients deep-link straight into the manage flow.
	 * With an expired/invalid signature: 410.
	 *
	 * @param string $bookingId The booking UUID.
	 *
	 * @return JSONResponse The booking (200), 404 if missing, 410 if link expired.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function getBooking(string $bookingId): JSONResponse {
		$token = trim((string)$this->request->getParam('token', ''));
		if ($token !== '') {
			$verdict = $this->validateSignedLink(token: $token, expectedBookingId: $bookingId);
			if ($verdict !== null) {
				return $verdict;
			}
		}

		$booking = $this->loadBookingPublic(bookingId: $bookingId);
		if ($booking === null) {
			return new JSONResponse(['error' => 'Booking not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($booking, Http::STATUS_OK);
	}//end getBooking()

	/**
	 * POST /portal/reschedule — reschedule a booking via signed link.
	 *
	 * Body: `token` (signed link), `newStartAt` (ISO-8601).
	 *
	 * @return JSONResponse The new booking id (200), 400 on invalid input, 410 on bad signature.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function reschedule(): JSONResponse {
		$token = trim((string)$this->request->getParam('token', ''));
		$newStartAt = trim((string)$this->request->getParam('newStartAt', ''));
		if ($token === '' || $newStartAt === '') {
			return new JSONResponse(['error' => 'token and newStartAt are required'], Http::STATUS_BAD_REQUEST);
		}

		$payload = $this->openSignedLink(token: $token);
		if (is_string($payload) === true) {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		$bookingId = (string)($payload['bookingId'] ?? '');
		$action = (string)($payload['action'] ?? '');
		if ($bookingId === '' || $action !== 'reschedule') {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		try {
			$newId = $this->bookingService->rescheduleBooking(bookingId: $bookingId, newStartAt: $newStartAt);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'Invalid reschedule request'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq portal: reschedule failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Reschedule failed'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['bookingId' => $newId, 'previousBookingId' => $bookingId], Http::STATUS_OK);
	}//end reschedule()

	/**
	 * POST /portal/cancel — cancel a booking via signed link.
	 *
	 * Body: `token` (signed link), optional `reason`.
	 *
	 * @return JSONResponse The cancellation receipt (200), 400 on invalid input, 410 on bad signature.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function cancel(): JSONResponse {
		$token = trim((string)$this->request->getParam('token', ''));
		$reason = (string)$this->request->getParam('reason', '');
		if ($token === '') {
			return new JSONResponse(['error' => 'token is required'], Http::STATUS_BAD_REQUEST);
		}

		$payload = $this->openSignedLink(token: $token);
		if (is_string($payload) === true) {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		$bookingId = (string)($payload['bookingId'] ?? '');
		$action = (string)($payload['action'] ?? '');
		$customerId = (string)($payload['customerId'] ?? '');
		if ($bookingId === '' || $action !== 'cancel') {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		try {
			$this->bookingService->cancelBooking(
				bookingId: $bookingId,
				reason: substr($reason, 0, self::MAX_TEXT_LENGTH),
				cancelledBy: $customerId
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'Invalid cancel request'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq portal: cancel failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Cancellation failed'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['bookingId' => $bookingId, 'cancelled' => true], Http::STATUS_OK);
	}//end cancel()

	/**
	 * Collect and normalise the `/portal/book` body.
	 *
	 * @return array<string, string>
	 */
	private function collectBookParams(): array {
		return [
			'customerName' => trim((string)$this->request->getParam('customerName', '')),
			'email' => trim((string)$this->request->getParam('email', '')),
			'phone' => trim((string)$this->request->getParam('phone', '')),
			'serviceId' => trim((string)$this->request->getParam('serviceId', '')),
			'startAt' => trim((string)$this->request->getParam('startAt', '')),
			'notes' => substr((string)$this->request->getParam('notes', ''), 0, self::MAX_TEXT_LENGTH),
		];
	}//end collectBookParams()

	/**
	 * Validate a `/portal/book` payload — static, never echoes input.
	 *
	 * @param array<string, string> $params The collected body.
	 *
	 * @return JSONResponse|null A 400 response on the first failure, null when valid.
	 */
	private function validateBookParams(array $params): ?JSONResponse {
		$required = ['customerName', 'email', 'phone', 'serviceId', 'startAt'];
		foreach ($required as $field) {
			if (($params[$field] ?? '') === '') {
				return new JSONResponse(['error' => 'Missing required field'], Http::STATUS_BAD_REQUEST);
			}
		}

		if (filter_var($params['email'], FILTER_VALIDATE_EMAIL) === false) {
			return new JSONResponse(['error' => 'Invalid email'], Http::STATUS_BAD_REQUEST);
		}

		if (strtotime($params['startAt']) === false) {
			return new JSONResponse(['error' => 'Invalid startAt'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end validateBookParams()

	/**
	 * Create a Booking from validated portal input.
	 *
	 * Resolves the service to discover its duration (so we can compute endAt
	 * server-side — anonymous callers MUST NOT be trusted to pick their own
	 * end time) and upserts a customer contact keyed by email. Delegates to
	 * BookingService for the actual create.
	 *
	 * @param array<string, string> $params The validated payload.
	 *
	 * @return array{bookingId: string, requiresDeposit: bool, depositAmount: float, currency: string, serviceName: string}
	 *
	 * @throws InvalidArgumentException If the service or input is unusable.
	 * @throws RuntimeException If OpenRegister is unavailable.
	 */
	private function createBookingFromPortal(array $params): array {
		$service = $this->loadService(serviceId: $params['serviceId']);
		if ($service === null) {
			throw new InvalidArgumentException('Unknown service');
		}

		if (($service['bookableOnline'] ?? false) !== true) {
			throw new InvalidArgumentException('Service is not bookable online');
		}

		$duration = (int)($service['durationMinutes'] ?? 0);
		if ($duration <= 0) {
			throw new InvalidArgumentException('Service duration is not configured');
		}

		$endAt = $this->computeEndAt(startAt: $params['startAt'], durationMinutes: $duration);

		$customerId = $this->upsertCustomer(
			name: $params['customerName'],
			email: $params['email'],
			phone: $params['phone']
		);

		$bookingId = $this->bookingService->createBooking(
			data: [
				'customerId' => $customerId,
				'serviceId' => $params['serviceId'],
				'startAt' => $params['startAt'],
				'endAt' => $endAt,
				'notes' => $params['notes'],
			],
			source: 'portal'
		);

		return [
			'bookingId' => $bookingId,
			'requiresDeposit' => (bool)($service['requiresDeposit'] ?? false),
			'depositAmount' => (float)($service['depositAmount'] ?? 0.0),
			'currency' => (string)($service['currency'] ?? 'EUR'),
			'serviceName' => (string)($service['name'] ?? 'appointment'),
		];
	}//end createBookingFromPortal()

	/**
	 * List Services with `bookableOnline: true` from OpenRegister.
	 *
	 * Returns a normalised public projection — customers see name/description/
	 * duration/price/deposit, never internal fields like `requiredSkills`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException If OpenRegister is unavailable.
	 */
	private function listBookableServices(): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'service_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Service register/schema not configured');
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'bookableOnline' => true,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: services findAll failed');
			return [];
		}

		$results = ($rows['results'] ?? $rows);
		if (is_array($results) === false) {
			return [];
		}

		$out = [];
		foreach ($results as $row) {
			$data = $this->toArray(object: $row);
			if ($data === null) {
				continue;
			}

			// Defence in depth: the OR filter is best-effort, never trust it.
			if (($data['bookableOnline'] ?? false) !== true) {
				continue;
			}

			$out[] = [
				'id' => $this->idOf(object: $data),
				'name' => (string)($data['name'] ?? ''),
				'description' => (string)($data['description'] ?? ''),
				'durationMinutes' => (int)($data['durationMinutes'] ?? 0),
				'price' => (float)($data['price'] ?? 0.0),
				'currency' => (string)($data['currency'] ?? 'EUR'),
				'requiresDeposit' => (bool)($data['requiresDeposit'] ?? false),
				'depositAmount' => (float)($data['depositAmount'] ?? 0.0),
			];
		}//end foreach

		return $out;
	}//end listBookableServices()

	/**
	 * Load a Service by id (best-effort — returns null on miss).
	 *
	 * @param string $serviceId The service UUID/slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadService(string $serviceId): ?array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'service_schema');
		if ($register === '' || $schema === '' || $serviceId === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $serviceId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: service load failed', ['service' => $serviceId]);
			return null;
		}

		return $this->toArray(object: $found);
	}//end loadService()

	/**
	 * Load a Booking projection safe for anonymous consumption.
	 *
	 * Strips internal-only fields (statusHistory, internalNotes, cancelledBy)
	 * and returns only what the portal UI needs to render a confirmation.
	 *
	 * @param string $bookingId The booking UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadBookingPublic(string $bookingId): ?array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'booking_schema');
		if ($register === '' || $schema === '' || $bookingId === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			return null;
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			return null;
		}

		return [
			'id' => $this->idOf(object: $data),
			'serviceId' => (string)($data['serviceId'] ?? ''),
			'startAt' => (string)($data['startAt'] ?? ''),
			'endAt' => (string)($data['endAt'] ?? ''),
			'status' => (string)($data['status'] ?? ''),
		];
	}//end loadBookingPublic()

	/**
	 * Upsert a customer contact by email.
	 *
	 * Looks up an existing contact by email; mints one when missing. Returns
	 * the contact UUID, which the BookingService stores on the Booking.
	 *
	 * @param string $name Display name.
	 * @param string $email Lookup key.
	 * @param string $phone Optional phone.
	 *
	 * @return string The contact UUID (empty when the schema is unconfigured).
	 *
	 * @throws RuntimeException If OpenRegister is unavailable.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Find-or-create with defensive OR-shape guards
	 *   (results/toArray/idOf null-checks) and two fault-tolerant try/catch fallbacks to the
	 *   email-token id; each guard maps one distinct fallback, not nested branching.
	 */
	private function upsertCustomer(string $name, string $email, string $phone): string {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'contact_schema');
		if ($register === '' || $schema === '') {
			// Without a customer mirror the booking still records the email
			// verbatim via the customerId field as a fallback. The BookingService
			// does not require a real OR entity, only a non-empty id token.
			return ('email:' . substr($email, 0, 240));
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'email' => $email,
					],
					'limit' => 1,
				]
			);
			$results = ($rows['results'] ?? $rows);
			if (is_array($results) === true && $results !== []) {
				$existing = $this->toArray(object: $results[0]);
				if ($existing !== null) {
					$id = $this->idOf(object: $existing);
					if ($id !== '') {
						return $id;
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: customer lookup failed');
		}//end try

		try {
			$saved = $this->getObjectService()->saveObject(
				object: [
					'name' => substr($name, 0, 255),
					'email' => $email,
					'phone' => substr($phone, 0, 64),
					'source' => 'portal',
				],
				extend: [],
				register: $register,
				schema: $schema,
				uuid: null
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: customer save failed');
			return ('email:' . substr($email, 0, 240));
		}

		$data = $this->toArray(object: $saved);
		if ($data === null) {
			return ('email:' . substr($email, 0, 240));
		}

		$id = $this->idOf(object: $data);
		if ($id === '') {
			return ('email:' . substr($email, 0, 240));
		}

		return $id;
	}//end upsertCustomer()

	/**
	 * Compute endAt = startAt + durationMinutes.
	 *
	 * @param string $startAt ISO-8601 timestamp.
	 * @param int $durationMinutes The service duration.
	 *
	 * @return string ISO-8601 timestamp.
	 */
	private function computeEndAt(string $startAt, int $durationMinutes): string {
		$startTimestamp = strtotime($startAt);
		if ($startTimestamp === false) {
			return $startAt;
		}

		$end = ($startTimestamp + ($durationMinutes * 60));
		return (new DateTimeImmutable('@' . $end))
			->setTimezone(new DateTimeZone('UTC'))
			->format('Y-m-d\TH:i:sP');
	}//end computeEndAt()

	/**
	 * Build the absolute manage-deep-link URLs for a booking.
	 *
	 * Two distinct signed tokens are issued (one per action) so a leaked
	 * reschedule link cannot be replayed as a cancel.
	 *
	 * @param string $bookingId The booking UUID.
	 *
	 * @return array{reschedule: string, cancel: string}
	 */
	private function buildManageLinks(string $bookingId): array {
		$customerId = $this->lookupCustomerForBooking(bookingId: $bookingId);
		$base = $this->urlGenerator->getAbsoluteURL('/index.php/apps/pipelinq/portal/api/booking/' . rawurlencode($bookingId));
		return [
			'reschedule' => ($base . '?token=' . rawurlencode($this->signLink(bookingId: $bookingId, action: 'reschedule', customerId: $customerId))),
			'cancel' => ($base . '?token=' . rawurlencode($this->signLink(bookingId: $bookingId, action: 'cancel', customerId: $customerId))),
		];
	}//end buildManageLinks()

	/**
	 * Sign a manage-deep-link with HMAC-SHA256 and the configured TTL.
	 *
	 * @param string $bookingId Booking UUID embedded in the payload.
	 * @param string $action `reschedule` or `cancel`.
	 * @param string $customerId Customer UUID echoed back on cancel for the audit trail.
	 *
	 * @return string The dotted `<payload>.<signature>` token.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function signLink(string $bookingId, string $action, string $customerId = ''): string {
		$issuedAt = $this->time->getTime();
		$expiresAt = ($issuedAt + (self::LINK_TTL_DAYS * 24 * 3600));

		$payload = [
			'bookingId' => $bookingId,
			'action' => $action,
			'customerId' => $customerId,
			'issuedAt' => $issuedAt,
			'expiresAt' => $expiresAt,
		];

		$encoded = $this->base64UrlEncode(data: (string)json_encode($payload));
		$signature = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		return ($encoded . '.' . $signature);
	}//end signLink()

	/**
	 * Validate a signed link issued by {@see signLink()}.
	 *
	 * Returns the decoded payload on success, or the string sentinel
	 * `'expired'` / `'invalid'` the caller maps to 410.
	 *
	 * @param string $token The presented token.
	 *
	 * @return array<string, mixed>|string The payload, `'expired'`, or `'invalid'`.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function openSignedLink(string $token): array|string {
		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return 'invalid';
		}

		[$encoded, $signature] = $parts;
		$expected = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		if (hash_equals($expected, $signature) === false) {
			return 'invalid';
		}

		$decoded = json_decode((string)$this->base64UrlDecode(data: $encoded), true);
		if (is_array($decoded) === false || isset($decoded['expiresAt']) === false) {
			return 'invalid';
		}

		if ((int)$decoded['expiresAt'] < $this->time->getTime()) {
			return 'expired';
		}

		return $decoded;
	}//end openSignedLink()

	/**
	 * Validate a signed link bound to a specific booking id.
	 *
	 * @param string $token The presented token.
	 * @param string $expectedBookingId The booking id the URL points at.
	 *
	 * @return JSONResponse|null A 410 response on failure, null when valid.
	 */
	private function validateSignedLink(string $token, string $expectedBookingId): ?JSONResponse {
		$payload = $this->openSignedLink(token: $token);
		if (is_string($payload) === true) {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		if ((string)($payload['bookingId'] ?? '') !== $expectedBookingId) {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		return null;
	}//end validateSignedLink()

	/**
	 * Resolve the customerId attached to a booking (best-effort, '' on miss).
	 *
	 * @param string $bookingId The booking UUID.
	 *
	 * @return string
	 */
	private function lookupCustomerForBooking(string $bookingId): string {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'booking_schema');
		if ($register === '' || $schema === '' || $bookingId === '') {
			return '';
		}

		try {
			$found = $this->getObjectService()->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			return '';
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			return '';
		}

		return (string)($data['customerId'] ?? '');
	}//end lookupCustomerForBooking()

	/**
	 * Resolve (or lazily mint) the per-instance HMAC signing key.
	 *
	 * @return string The signing key.
	 */
	private function signingKey(): string {
		$key = $this->appConfig->getValueString(Application::APP_ID, self::SIGNING_KEY_CONFIG, '');
		if ($key === '') {
			$key = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, self::SIGNING_KEY_CONFIG, $key, false, true);
		}

		return $key;
	}//end signingKey()

	/**
	 * Base64url-encode (RFC 4648 §5, no padding).
	 *
	 * @param string $data The raw data.
	 *
	 * @return string The encoded string.
	 */
	private function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}//end base64UrlEncode()

	/**
	 * Base64url-decode.
	 *
	 * @param string $data The encoded string.
	 *
	 * @return string The decoded data (empty on failure).
	 */
	private function base64UrlDecode(string $data): string {
		$decoded = base64_decode(strtr($data, '-_', '+/'), true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}//end base64UrlDecode()

	/**
	 * The pipelinq register id from app config.
	 *
	 * Fails closed: '' means "unconfigured" and every caller refuses the
	 * OpenRegister call on it. An empty register is NOT the same as none —
	 * ObjectService skips setRegister() for an empty value, so the query
	 * inherits whatever context an earlier call in the request left behind.
	 *
	 * @return string The configured register id, or '' when unconfigured.
	 */
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerId;
	}//end registerId()

	/**
	 * Resolve a schema id by app-config key.
	 *
	 * @param string $key The app-config key (e.g. `booking_schema`).
	 *
	 * @return string
	 */
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end schemaId()

	/**
	 * Pull the canonical id out of a normalised OpenRegister object.
	 *
	 * @param array<string, mixed>|object $object The object.
	 *
	 * @return string
	 */
	private function idOf(array|object $object): string {
		$arr = $this->toArray(object: $object);
		if ($arr === null) {
			return '';
		}

		if (isset($arr['@self']) === true && is_array($arr['@self']) === true) {
			$self = $arr['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($arr['id']) === true) {
			return (string)$arr['id'];
		}

		if (isset($arr['uuid']) === true) {
			return (string)$arr['uuid'];
		}

		return '';
	}//end idOf()

	/**
	 * Normalise an OpenRegister entity (or array) to a plain array.
	 *
	 * @param mixed $object Entity, array, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}

		return null;
	}//end toArray()

	/**
	 * Resolve the OpenRegister ObjectService via the DI container.
	 *
	 * @return object The ObjectService instance.
	 *
	 * @throws RuntimeException If OpenRegister is not installed/available.
	 */
	private function getObjectService(): object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new RuntimeException('OpenRegister is not installed.');
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			throw new RuntimeException('OpenRegister ObjectService is unavailable.');
		}
	}//end getObjectService()
}//end class
