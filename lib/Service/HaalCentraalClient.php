<?php

/**
 * Pipelinq HaalCentraalClient.
 *
 * Looks up a person in the BRP (Haal Centraal Personen v2.0) through
 * integriq's `brp-haalcentraal` source, reached over the OpenRegister BRP
 * leaf. pipelinq holds no connection settings, OAuth2 credentials or mTLS
 * material and never calls Haal Centraal itself: without a working source a
 * lookup fails with a message that names integriq. The HAL+JSON person is
 * normalised to a BrpPersoon-shaped array ready for OR persistence.
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\HaalCentraalClientInterface;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * BRP person lookup through integriq's source.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
 */
class HaalCentraalClient implements HaalCentraalClientInterface {
	/**
	 * The app that owns the BRP connection.
	 *
	 * @var string
	 */
	private const CONNECTION_APP = 'integriq';

	/**
	 * Lookup timeout (5s per REQ-BSN-003-01).
	 */
	private const LOOKUP_TIMEOUT_SECONDS = 5;

	/**
	 * Sentinel key used in the array returned by {@see lookupViaOpenRegister()} for
	 * a 200 with zero persons (BSN not found in BRP). Distinguishes "the source
	 * answered, nobody there" (not-found, return null) from "the source is not
	 * usable" (null, the lookup fails). Never returned from {@see lookupPersoon()}.
	 *
	 * @var string
	 */
	private const OR_EMPTY_SENTINEL_KEY = '__or_brp_empty__';

	/**
	 * The request options of the OpenRegister BRP leaf call (internal,
	 * OCS-APIREQUEST, allow_local_address).
	 *
	 * @var array<string, mixed>
	 */
	private const LEAF_REQUEST_OPTIONS = [
		'timeout' => self::LOOKUP_TIMEOUT_SECONDS,
		'connect_timeout' => 2,
		'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
		'nextcloud' => ['allow_local_address' => true],
	];

	/**
	 * Constructor.
	 *
	 * @param IClientService  $clientService NC HTTP client factory.
	 * @param LoggerInterface $logger        Logger (never includes raw BSN).
	 * @param IURLGenerator   $urlGenerator  URL generator (resolves the BRP leaf endpoint).
	 * @param IAppManager     $appManager    Tells whether integriq is installed.
	 */
	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private IURLGenerator $urlGenerator,
		private IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Lookup a person by BSN. Returns a BrpPersoon-shaped array or null when not found.
	 *
	 * The lookup goes through integriq's `brp-haalcentraal` source over the
	 * OpenRegister BRP leaf. On HTTP 200 the leaf returns the raw HAL+JSON
	 * person under `results` plus the Wet-BRP audit metadata under `meta`
	 * ({ correlationId, durationMs, status }); the person is normalised and the
	 * meta attached as `_correlationId` / `_responseDurationMs` /
	 * `_responseStatus`, so the caller persists the `brpLookupVerzoek` audit
	 * record. There is no fallback: without integriq, or when the source does
	 * not answer, the lookup throws with {@see NO_SOURCE_MESSAGE} and makes no
	 * direct call to Haal Centraal.
	 *
	 * @param string $bsn Raw 9-digit BSN.
	 * @param string|null $requestIdContext Optional context UUID for correlation logging.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @throws HaalCentraalException When integriq is absent or its source cannot answer.
	 *
	 * @spec openspec/specs/brp-lookup/spec.md
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-01
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          BsnValidationService::mask is a pure stateless helper.
	 */
	public function lookupPersoon(
		#[SensitiveParameter]
		string $bsn,
		?string $requestIdContext = null,
	): ?array {
		$lookup = new BsnLookup(bsn: $bsn, maskedBsn: BsnValidationService::mask($bsn));

		$viaOr = null;
		if ($this->isConfigured() === true) {
			$viaOr = $this->lookupViaOpenRegister(lookup: $lookup);
		}

		if ($viaOr === null) {
			$this->logger->warning(
				'BRP lookup failed: no working integriq source',
				['bsn' => $lookup->maskedBsn, 'requestId' => $requestIdContext]
			);
			throw new HaalCentraalException(self::NO_SOURCE_MESSAGE, self::NO_SOURCE_STATUS);
		}

		// Sentinel for a 200 with zero persons (BSN not in BRP): not found.
		if (isset($viaOr[self::OR_EMPTY_SENTINEL_KEY]) === true) {
			return null;
		}

		return $viaOr;
	}//end lookupPersoon()

	/**
	 * Lookup a person via the OpenRegister BRP leaf (ADR-022, safe-partial).
	 *
	 * Calls `GET /apps/openregister/api/integrations/brp/person?bsn=<bsn>`
	 * server-side (internal, OCS-APIREQUEST, allow_local_address). The BSN is
	 * passed in the query (the leaf places it in the upstream request BODY and
	 * never logs it). On HTTP 200 the leaf returns
	 * `{ results: [<raw HAL+JSON person, 0..1>], total, meta: { correlationId,
	 * durationMs, status } }`. This method:
	 *   - maps the asked person through {@see normalisePerson()}, and
	 *   - attaches the audit metadata from `meta`: `meta.correlationId` to
	 *     `_correlationId`, `meta.durationMs` to `_responseDurationMs`,
	 *     `meta.status` to `_responseStatus`, so the caller persists the
	 *     `brpLookupVerzoek` audit record.
	 *
	 * Returns:
	 *   - the normalised person array (with `_correlationId` /
	 *     `_responseDurationMs` / `_responseStatus`) on a 200 with a person,
	 *   - {@see OR_EMPTY_SENTINEL_KEY} on a 200 with zero persons (BSN not
	 *     found; the caller maps that to not-found / null), or
	 *   - null when the `brp-haalcentraal` source is not usable (503 with
	 *     `details.cause`, any non-200, or the leaf absent / connection
	 *     refused); the caller then fails the lookup.
	 *
	 * The raw BSN is NEVER logged here (only the masked BSN).
	 *
	 * @param BsnLookup $lookup The BSN (placed in the leaf query; never logged) and its masked form.
	 *
	 * @return array<string,mixed>|null The normalised+stamped person, the empty sentinel, or null when the source is not usable.
	 *
	 * @spec openspec/specs/brp-lookup/spec.md
	 */
	private function lookupViaOpenRegister(BsnLookup $lookup): ?array {
		$payload = $this->leafPayload(lookup: $lookup);
		if ($payload === null) {
			return null;
		}

		if (empty($payload['results']) === true) {
			// The source answered 200 but nobody is there: not found.
			// Return the sentinel using a named key so PHPStan can type it correctly.
			return [self::OR_EMPTY_SENTINEL_KEY => true];
		}

		$first = (new BrpLeafResults())->askedPerson(results: $payload['results'], bsn: $lookup->bsn);
		if (is_array($first) === false) {
			return null;
		}

		$person = (new BrpLeafResults())->withLeafMeta(person: $this->normalisePerson(raw: $first), payload: $payload);
		$this->logLeafSuccess(person: $person, lookup: $lookup);
		return $person;
	}//end lookupViaOpenRegister()

	/**
	 * Log a successful leaf lookup with the masked BSN and the audit metadata.
	 *
	 * @param array<string, mixed> $person The stamped person.
	 * @param BsnLookup            $lookup The BSN asked for.
	 *
	 * @return void
	 */
	private function logLeafSuccess(array $person, BsnLookup $lookup): void {
		$this->logger->info(
			'BRP OR leaf lookup succeeded',
			[
				'bsn' => $lookup->maskedBsn,
				'correlationId' => $person['_correlationId'],
				'duration_ms' => $person['_responseDurationMs'],
				'source' => 'openregister-leaf',
			]
		);
	}//end logLeafSuccess()

	/**
	 * The leaf's payload when it answered 200 with a `results` list.
	 *
	 * @param BsnLookup $lookup The BSN asked for.
	 *
	 * @return array<string, mixed>|null The payload, or null when the leaf is not usable.
	 */
	private function leafPayload(BsnLookup $lookup): ?array {
		$response = $this->leafResponse(lookup: $lookup);
		if ($response === null || (int)$response->getStatusCode() !== 200) {
			return null;
		}

		$payload = json_decode((string)$response->getBody(), true);
		if ((new BrpLeafResults())->hasResultList(payload: $payload) === false) {
			return null;
		}

		return $payload;
	}//end leafPayload()

	/**
	 * Ask the leaf for the person.
	 *
	 * @param BsnLookup $lookup The BSN asked for.
	 *
	 * @return IResponse|null The response, or null when the leaf is unavailable (logged with the masked BSN).
	 */
	private function leafResponse(BsnLookup $lookup): ?IResponse {
		$params = http_build_query(['bsn' => $lookup->bsn]);
		$url = $this->urlGenerator->getAbsoluteURL('/apps/openregister/api/integrations/brp/person?' . $params);

		try {
			return $this->clientService->newClient()->get($url, self::LEAF_REQUEST_OPTIONS);
		} catch (Throwable $e) {
			// 503 (source not usable) surfaces as a client exception here, as
			// does connection-refused / OR absent; the lookup then fails.
			// Never log the raw BSN; only the masked variant.
			$this->logger->debug(
				'BRP leaf unavailable',
				['bsn' => $lookup->maskedBsn, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end leafResponse()

	/**
	 * Whether a BRP lookup can be made: integriq, which owns the connection, is installed.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
	 */
	public function isConfigured(): bool {
		return $this->appManager->isInstalled(self::CONNECTION_APP);
	}//end isConfigured()

	/**
	 * Normalise a HaalCentraal person payload into the BrpPersoon schema shape.
	 *
	 * @param array<string,mixed> $raw The raw HaalCentraal person payload.
	 *
	 * @return array<string,mixed>
	 */
	private function normalisePerson(array $raw): array {
		$birth = $this->arrayField(raw: $raw, key: 'geboorte');

		return $this->nameFields(name: $this->personName(raw: $raw)) + [
			'dateOfBirth' => (string)($birth['datum']['datum'] ?? $birth['datum'] ?? ''),
			'birthPlace' => $this->valueOrSubKey(value: ($birth['plaats'] ?? ''), subKey: 'omschrijving'),
			'birthCountry' => $this->valueOrSubKey(value: ($birth['land'] ?? ''), subKey: 'code'),
			'gender' => self::mapGeslacht(code: $this->valueOrSubKey(value: ($raw['gender'] ?? ''), subKey: 'code')),
			'residence' => self::mapResidence(residence: $this->arrayField(raw: $raw, key: 'residence')),
			'indicationSecret' => (string)($raw['indicationSecret'] ?? '0'),
			'sourceSystem' => 'HaalCentraal-BRP-v2.0',
		];
	}//end normalisePerson()

	/**
	 * The person's name block, from `name` or from the Dutch v2 `naam`.
	 *
	 * @param array<string, mixed> $raw The raw person.
	 *
	 * @return array<string, mixed> The name block.
	 */
	private function personName(array $raw): array {
		$name = [];
		if (is_array($raw['name'] ?? null) === true) {
			$name = $raw['name'];
		} else if (is_array($raw['naam'] ?? null) === true) {
			// HaalCentraal Personen v2 spells the name in Dutch (`naam`).
			$name = [
				'givenNames' => ($raw['naam']['voornamen'] ?? ''),
				'initials'   => ($raw['naam']['voorletters'] ?? ''),
				'namePrefix' => ($raw['naam']['voorvoegsel'] ?? ''),
				'surname'    => ($raw['naam']['geslachtsnaam'] ?? ''),
				'adellijkeTitelPredicaat' => ($raw['naam']['adellijkeTitelPredicaat'] ?? ''),
			];
		}

		return $name;
	}//end personName()

	/**
	 * The normalised name fields of a person.
	 *
	 * @param array<string, mixed> $name The name block.
	 *
	 * @return array<string, string> The name fields.
	 */
	private function nameFields(array $name): array {
		return [
			'givenNames' => (string)($name['givenNames'] ?? ''),
			'initials' => (string)($name['initials'] ?? ''),
			'namePrefix' => (string)($name['namePrefix'] ?? ''),
			'surname' => (string)($name['surname'] ?? ''),
			'nobiliaryTitle' => (string)($name['adellijkeTitelPredicaat'] ?? ''),
		];
	}//end nameFields()

	/**
	 * A raw field when it is an array, else an empty array.
	 *
	 * @param array<string, mixed> $raw The raw person.
	 * @param string $key The field name.
	 *
	 * @return array<string, mixed> The field, or [].
	 */
	private function arrayField(array $raw, string $key): array {
		$field = [];
		if (is_array($raw[$key] ?? null) === true) {
			$field = $raw[$key];
		}

		return $field;
	}//end arrayField()

	/**
	 * A scalar value as string, or the given sub-key when the value is an object-like array.
	 *
	 * @param mixed $value The raw value.
	 * @param string $subKey The sub-key to read when the value is an array.
	 *
	 * @return string The value.
	 */
	private function valueOrSubKey(mixed $value, string $subKey): string {
		if (is_array($value) === true) {
			$value = ($value[$subKey] ?? '');
		}

		return (string)$value;
	}//end valueOrSubKey()

	/**
	 * Map HaalCentraal geslacht codes to schema enum values.
	 *
	 * @param string $code The HaalCentraal geslacht code.
	 *
	 * @return string
	 */
	private static function mapGeslacht(string $code): string {
		$code = strtoupper($code);
		if ($code === 'M' || $code === 'MAN') {
			return 'male';
		}

		if ($code === 'V' || $code === 'VROUW' || $code === 'F') {
			return 'female';
		}

		return 'unknown';
	}//end mapGeslacht()

	/**
	 * Map a HaalCentraal verblijfplaats subtree to the schema shape.
	 *
	 * @param array<string,mixed> $residence The HaalCentraal verblijfplaats subtree.
	 *
	 * @return array<string,mixed>
	 */
	private static function mapResidence(array $residence): array {
		$adres = $residence;
		if (is_array($residence['verblijfadres'] ?? null) === true) {
			$adres = $residence['verblijfadres'];
		}

		$land = $residence['land'] ?? ($adres['land'] ?? null);

		$houseNumber = null;
		if (isset($adres['huisnummer']) === true) {
			$houseNumber = (int)$adres['huisnummer'];
		}

		if (is_array($land) === true) {
			$land = ($land['omschrijving'] ?? $land['code'] ?? '');
		}

		$landValue = (string)($land ?? '');

		return [
			'straat' => (string)($adres['officieleStraatnaam'] ?? $adres['straat'] ?? ''),
			'huisnummer' => $houseNumber,
			'huisletter' => (string)($adres['huisletter'] ?? ''),
			'huisnummertoevoeging' => (string)($adres['huisnummertoevoeging'] ?? ''),
			'postcode' => (string)($adres['postcode'] ?? ''),
			'woonplaats' => (string)($adres['woonplaats'] ?? $adres['woonplaatsnaam'] ?? ''),
			'land' => $landValue,
		];
	}//end mapVerblijfplaats()
}//end class
