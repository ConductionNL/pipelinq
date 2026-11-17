<?php

/**
 * Pipelinq ClientIdentifierService.
 *
 * The official numbers of an organisation client (KvK, RSIN, VAT, DUNS, OIN):
 * their formats, checked on every save, and their register checks, run on
 * request. KvK is checked through OpenRegister's KvK leaf; VAT through
 * shillinq's VIES service when shillinq is installed. pipelinq keeps no
 * register client of its own (ADR-012, one path per external register).
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ClientIdentifierServiceInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Formats and register checks of a client's identifiers.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
class ClientIdentifierService implements ClientIdentifierServiceInterface {

	/**
	 * The schemes an identifier may carry.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMES = ['kvk', 'rsin', 'vat', 'duns', 'oin'];

	/**
	 * Not checked yet, or not checkable here.
	 *
	 * @var string
	 */
	public const STATUS_UNCHECKED = 'unchecked';

	/**
	 * The register knows the number.
	 *
	 * @var string
	 */
	public const STATUS_VALID = 'valid';

	/**
	 * The register does not know the number.
	 *
	 * @var string
	 */
	public const STATUS_INVALID = 'invalid';

	/**
	 * The register could not be reached.
	 *
	 * @var string
	 */
	public const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * The source a KvK check names.
	 *
	 * @var string
	 */
	public const SOURCE_KVK = 'KvK';

	/**
	 * The source a VAT check names.
	 *
	 * @var string
	 */
	public const SOURCE_VIES = 'VIES';

	/**
	 * The source a VAT check names when shillinq is absent.
	 *
	 * @var string
	 */
	public const SOURCE_NO_SHILLINQ = 'shillinq not installed';

	/**
	 * shillinq's VIES contract, resolved lazily.
	 *
	 * @var string
	 */
	public const VIES_CLASS = 'OCA\\Shillinq\\Kernel\\Contracts\\ViesServiceInterface';

	/**
	 * The digit-only formats, by scheme.
	 *
	 * @var array<string, string>
	 */
	private const DIGIT_FORMATS = [
		'kvk' => '/^[0-9]{8}$/',
		'rsin' => '/^[0-9]{9}$/',
		'duns' => '/^[0-9]{9}$/',
		'oin' => '/^[0-9]{20}$/',
	];

	/**
	 * The national part of an EU VAT number, by country prefix (VIES formats).
	 *
	 * @var array<string, string>
	 */
	private const VAT_PATTERNS = [
		'AT' => 'U[0-9]{8}',
		'BE' => '[01][0-9]{9}',
		'BG' => '[0-9]{9,10}',
		'CY' => '[0-9]{8}[A-Z]',
		'CZ' => '[0-9]{8,10}',
		'DE' => '[0-9]{9}',
		'DK' => '[0-9]{8}',
		'EE' => '[0-9]{9}',
		'EL' => '[0-9]{9}',
		'ES' => '[0-9A-Z][0-9]{7}[0-9A-Z]',
		'FI' => '[0-9]{8}',
		'FR' => '[0-9A-HJ-NP-Z]{2}[0-9]{9}',
		'HR' => '[0-9]{11}',
		'HU' => '[0-9]{8}',
		'IE' => '([0-9]{7}[A-W][A-I]?|[0-9][A-Z+*][0-9]{5}[A-W])',
		'IT' => '[0-9]{11}',
		'LT' => '([0-9]{9}|[0-9]{12})',
		'LU' => '[0-9]{8}',
		'LV' => '[0-9]{11}',
		'MT' => '[0-9]{8}',
		'NL' => '[0-9]{9}B[0-9]{2}',
		'PL' => '[0-9]{10}',
		'PT' => '[0-9]{9}',
		'RO' => '[0-9]{2,10}',
		'SE' => '[0-9]{12}',
		'SI' => '[0-9]{8}',
		'SK' => '[0-9]{10}',
		'XI' => '([0-9]{9}|[0-9]{12}|GD[0-9]{3}|HA[0-9]{3})',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The object store, read and written as the user.
	 * @param IClientService         $clientService The HTTP client factory for the KvK leaf.
	 * @param IURLGenerator          $urlGenerator  Resolves the KvK leaf URL.
	 * @param ContainerInterface     $container     Resolves shillinq's VIES service lazily.
	 * @param IAppManager            $appManager    Whether shillinq is enabled.
	 * @param IAppConfig             $appConfig     The client register and schema.
	 * @param ITimeFactory           $time          The clock for checkedAt.
	 * @param IL10N                  $l10n          The user's language.
	 * @param LoggerInterface        $logger        The logger.
	 * @param string                 $viesClass     shillinq's VIES contract; a test names a double.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly string $viesClass = self::VIES_CLASS,
	) {
	}//end __construct()

	/**
	 * The number as it is compared and stored: without spaces and dots, VAT in capitals.
	 *
	 * @param string $scheme The scheme.
	 * @param string $value  The number as typed.
	 *
	 * @return string The normalised number.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public static function normalise(string $scheme, string $value): string {
		$plain = str_replace([' ', '.', '-'], '', trim($value));
		if ($scheme === 'vat') {
			return strtoupper($plain);
		}

		return $plain;
	}//end normalise()

	/**
	 * Whether nine digits pass the eleven test the RSIN shares with the BSN.
	 *
	 * The first eight digits weigh 9 down to 2, the last weighs -1; the sum must
	 * be a multiple of eleven.
	 *
	 * @param string $digits Nine digits.
	 *
	 * @return bool True when the number passes.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public static function passesElevenTest(string $digits): bool {
		if (preg_match('/^[0-9]{9}$/', $digits) !== 1) {
			return false;
		}

		$sum = 0;
		for ($position = 0; $position < 8; $position++) {
			$sum += ((int)$digits[$position] * (9 - $position));
		}

		$sum -= (int)$digits[8];
		return ($sum % 11) === 0;
	}//end passesElevenTest()

	/**
	 * Why a number does not match its scheme's format, or null when it does.
	 *
	 * @param string $scheme The scheme: kvk, rsin, vat, duns or oin.
	 * @param string $value  The number.
	 *
	 * @return string|null The problem, in the user's language, or null.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function validateFormat(string $scheme, string $value): ?string {
		if (in_array($scheme, self::SCHEMES, true) === false) {
			return $this->l10n->t('"%s" is not a kind of number pipelinq keeps.', [$scheme]);
		}

		$number = self::normalise(scheme: $scheme, value: $value);
		if ($scheme === 'vat') {
			return $this->vatProblem(number: $number);
		}

		if (preg_match(self::DIGIT_FORMATS[$scheme], $number) !== 1) {
			return [
				'kvk' => $this->l10n->t('A KvK number has 8 digits.'),
				'rsin' => $this->l10n->t('An RSIN has 9 digits.'),
				'duns' => $this->l10n->t('A DUNS number has 9 digits.'),
				'oin' => $this->l10n->t('An OIN has 20 digits.'),
			][$scheme];
		}

		if ($scheme === 'rsin' && self::passesElevenTest(digits: $number) === false) {
			return $this->l10n->t('The RSIN %s is not valid: it fails the eleven test.', [$number]);
		}

		return null;
	}//end validateFormat()

	/**
	 * Every format problem in a client's identifiers list.
	 *
	 * @param mixed $identifiers The client's `identifiers` value.
	 *
	 * @return array<int, string> The problems; empty when the list is valid.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function problemsIn(mixed $identifiers): array {
		if ($identifiers === null || $identifiers === []) {
			return [];
		}

		if (is_array($identifiers) === false || array_is_list($identifiers) === false) {
			return [$this->l10n->t('The identifiers must be a list.')];
		}

		$problems = [];
		foreach ($identifiers as $identifier) {
			if (is_array($identifier) === false) {
				$problems[] = $this->l10n->t('Each identifier needs a kind of number and a number.');
				continue;
			}

			$problem = $this->validateFormat(scheme: (string)($identifier['scheme'] ?? ''), value: (string)($identifier['value'] ?? ''));
			if ($problem !== null) {
				$problems[] = $problem;
			}
		}

		return array_values(array_unique($problems));
	}//end problemsIn()

	/**
	 * Check a KvK number against the KvK register, through OpenRegister's KvK leaf.
	 *
	 * Found: valid. Not found (404, or an answer without the number): invalid.
	 * Not reachable (a 5xx, or no answer): unavailable.
	 *
	 * @param string $value The KvK number.
	 *
	 * @return array{checkStatus: string, checkedAt: string, checkSource: string} The outcome.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.2
	 */
	public function checkKvk(string $value): array {
		return [
			'checkStatus' => $this->kvkCompany(value: $value)['checkStatus'],
			'checkedAt' => $this->now(),
			'checkSource' => self::SOURCE_KVK,
		];
	}//end checkKvk()

	/**
	 * Look a KvK number up through OpenRegister's KvK leaf.
	 *
	 * Found: valid, with the register's row. Not found (404, or an answer
	 * without the number): invalid. Not reachable (a 5xx, or no answer):
	 * unavailable.
	 *
	 * @param string $value The KvK number.
	 *
	 * @return array{checkStatus: string, company: array<string, mixed>|null} The outcome and the row.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function kvkCompany(string $value): array {
		$number = self::normalise(scheme: 'kvk', value: $value);
		$url = $this->urlGenerator->getAbsoluteURL('/apps/openregister/api/integrations/kvk/company?' . http_build_query(['kvkNumber' => $number]));
		$answer = (new CompanyRegistryHttp(clientService: $this->clientService, logger: $this->logger))->leafAnswer(url: $url);

		if ($answer['status'] === 404) {
			return ['checkStatus' => self::STATUS_INVALID, 'company' => null];
		}

		if ($answer['status'] !== 200) {
			return ['checkStatus' => self::STATUS_UNAVAILABLE, 'company' => null];
		}

		foreach (($answer['rows'] ?? []) as $row) {
			if ((string)($row['kvkNummer'] ?? '') === $number) {
				return ['checkStatus' => self::STATUS_VALID, 'company' => $row];
			}
		}

		return ['checkStatus' => self::STATUS_INVALID, 'company' => null];
	}//end kvkCompany()

	/**
	 * Check a VAT number through shillinq's VIES service; unchecked without shillinq.
	 *
	 * @param string $value The VAT number.
	 *
	 * @return array{checkStatus: string, checkedAt: string, checkSource: string, validUntil?: string} The outcome.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.3
	 */
	public function checkVat(string $value): array {
		$vies = $this->vies();
		if ($vies === null) {
			return ['checkStatus' => self::STATUS_UNCHECKED, 'checkedAt' => $this->now(), 'checkSource' => self::SOURCE_NO_SHILLINQ];
		}

		try {
			$result = $vies->validate(administrationId: '', vatId: self::normalise(scheme: 'vat', value: $value));
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: the VIES check failed', ['exception' => $e->getMessage()]);
			return ['checkStatus' => self::STATUS_UNAVAILABLE, 'checkedAt' => $this->now(), 'checkSource' => self::SOURCE_VIES];
		}

		$status = self::STATUS_INVALID;
		if (($result['outage'] ?? false) === true) {
			$status = self::STATUS_UNAVAILABLE;
		} else if (($result['valid'] ?? false) === true) {
			$status = self::STATUS_VALID;
		}

		$outcome = ['checkStatus' => $status, 'checkedAt' => $this->now(), 'checkSource' => self::SOURCE_VIES];
		$validUntil = trim((string)($result['validUntil'] ?? ''));
		if ($status === self::STATUS_VALID && $validUntil !== '') {
			$outcome['validUntil'] = $validUntil;
		}

		return $outcome;
	}//end checkVat()

	/**
	 * Check one identifier of a client against its register and store the outcome.
	 *
	 * The client is read and written as the calling user, so OpenRegister's
	 * object authorisation decides: a client the user may not read answers 403,
	 * and the write of the outcome needs the user's update right on it.
	 *
	 * @param string $clientId The client's id.
	 * @param string $scheme   The identifier's scheme.
	 * @param string $value    The identifier's number.
	 *
	 * @return array{status: int, identifier?: array<string, mixed>, message?: string} The HTTP status and the checked identifier.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	public function check(string $clientId, string $scheme, string $value): array {
		if (in_array($scheme, ['kvk', 'vat'], true) === false) {
			return ['status' => 400, 'message' => $this->l10n->t('Only a KvK number and a VAT number can be checked against a register.')];
		}

		$location = $this->location();
		if ($location === null) {
			return ['status' => 404, 'message' => $this->l10n->t('Client not found')];
		}

		$client = $this->readClient(clientId: $clientId, register: $location[0], schema: $location[1]);
		if (isset($client['status']) === true) {
			return $client;
		}

		$identifiers = (array)($client['data']['identifiers'] ?? []);
		$number = self::normalise(scheme: $scheme, value: $value);
		$index = self::indexOf(identifiers: $identifiers, scheme: $scheme, number: $number);
		if ($index === null) {
			return ['status' => 404, 'message' => $this->l10n->t('This client has no such number.')];
		}

		if ($scheme === 'kvk') {
			$outcome = $this->checkKvk(value: $number);
		} else {
			$outcome = $this->checkVat(value: $number);
		}

		$checked = array_merge($identifiers[$index], $outcome);
		if (isset($outcome['validUntil']) === false) {
			unset($checked['validUntil']);
		}

		$identifiers[$index] = $checked;
		return $this->store(clientId: $clientId, location: $location, data: $client['data'], identifiers: $identifiers, checked: $checked);
	}//end check()

	/**
	 * Write the checked list back as the user.
	 *
	 * @param string                           $clientId    The client's id.
	 * @param array{0: string, 1: string}      $location    The register and schema.
	 * @param array<string, mixed>             $data        The client as read.
	 * @param array<int, array<string, mixed>> $identifiers The checked list.
	 * @param array<string, mixed>             $checked     The checked identifier.
	 *
	 * @return array{status: int, identifier?: array<string, mixed>, message?: string} The outcome.
	 */
	private function store(string $clientId, array $location, array $data, array $identifiers, array $checked): array {
		$data['identifiers'] = $identifiers;
		unset($data['@self'], $data['id']);
		try {
			$this->objectService->saveObject(
				object: $data,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $location[0], schema: $location[1]), uuid: $clientId)
			);
		} catch (NotAuthorizedException $e) {
			return ['status' => 403, 'message' => $this->l10n->t('You may not change this client.')];
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: storing an identifier check failed', ['clientId' => $clientId, 'exception' => $e->getMessage()]);
			return ['status' => 500, 'message' => $this->l10n->t('The outcome of the check could not be saved.')];
		}

		return ['status' => 200, 'identifier' => $checked];
	}//end store()

	/**
	 * The position of an identifier in the list, or null.
	 *
	 * @param array<int|string, mixed> $identifiers The list.
	 * @param string                   $scheme      The scheme.
	 * @param string                   $number      The normalised number.
	 *
	 * @return int|null The index.
	 */
	private static function indexOf(array $identifiers, string $scheme, string $number): ?int {
		foreach (array_values($identifiers) as $index => $identifier) {
			if (is_array($identifier) === true
				&& ($identifier['scheme'] ?? null) === $scheme
				&& self::normalise(scheme: $scheme, value: (string)($identifier['value'] ?? '')) === $number
			) {
				return $index;
			}
		}

		return null;
	}//end indexOf()

	/**
	 * The client as the user may read it, or a refusal.
	 *
	 * @param string $clientId The client's id.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array{status?: int, message?: string, data?: array<string, mixed>} The client's data, or a status.
	 */
	private function readClient(string $clientId, string $register, string $schema): array {
		if (trim($clientId) === '' || $register === '' || $schema === '') {
			return ['status' => 404, 'message' => $this->l10n->t('Client not found')];
		}

		try {
			$object = $this->objectService->find(id: $clientId, options: new ObjectFindOptions(register: $register, schema: $schema));
		} catch (NotAuthorizedException $e) {
			return ['status' => 403, 'message' => $this->l10n->t('You may not open this client.')];
		} catch (Throwable $e) {
			$this->logger->debug('Pipelinq: client not read for an identifier check', ['exception' => $e->getMessage()]);
			return ['status' => 404, 'message' => $this->l10n->t('Client not found')];
		}

		if ($object === null) {
			return ['status' => 404, 'message' => $this->l10n->t('Client not found')];
		}

		return ['data' => $object->getObject()];
	}//end readClient()

	/**
	 * The client register and schema, or null while the app is not configured.
	 *
	 * Fails closed: without both there is no client to read, so nothing is checked.
	 *
	 * @return array{0: string, 1: string}|null The register and schema.
	 */
	private function location(): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(AppIdentity::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return [$register, $schema];
	}//end location()

	/**
	 * Whether the calling user may open the client: a read with their rights.
	 *
	 * @param string $clientId The client's id.
	 *
	 * @return bool True when the client exists and the user may read it.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	public function canAccessClient(string $clientId): bool {
		$location = $this->location();
		if ($location === null) {
			return false;
		}

		return isset($this->readClient(clientId: $clientId, register: $location[0], schema: $location[1])['data']) === true;
	}//end canAccessClient()

	/**
	 * Why a VAT number does not match its country's format, or null.
	 *
	 * @param string $number The normalised VAT number.
	 *
	 * @return string|null The problem.
	 */
	private function vatProblem(string $number): ?string {
		$country = substr($number, 0, 2);
		if (isset(self::VAT_PATTERNS[$country]) === false) {
			return $this->l10n->t('A VAT number starts with the two letters of an EU country, such as NL or DE.');
		}

		if (preg_match('/^' . self::VAT_PATTERNS[$country] . '$/', substr($number, 2)) !== 1) {
			return $this->l10n->t('%s is not a valid VAT number for country %s.', [$number, $country]);
		}

		return null;
	}//end vatProblem()

	/**
	 * shillinq's VIES service, or null when shillinq is not installed.
	 *
	 * @return object|null The service.
	 */
	private function vies(): ?object {
		if ($this->appManager->isEnabledForAnyone('shillinq') === false) {
			return null;
		}

		if (interface_exists($this->viesClass) === false && class_exists($this->viesClass) === false) {
			return null;
		}

		try {
			$service = $this->container->get($this->viesClass);
		} catch (Throwable $e) {
			$this->logger->debug('Pipelinq: shillinq VIES service not resolvable', ['exception' => $e->getMessage()]);
			return null;
		}

		if (is_object($service) === false || method_exists($service, 'validate') === false) {
			return null;
		}

		return $service;
	}//end vies()

	/**
	 * Now, in UTC, as an ISO-8601 date-time.
	 *
	 * @return string The time.
	 */
	private function now(): string {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'))->format('c');
	}//end now()
}//end class
