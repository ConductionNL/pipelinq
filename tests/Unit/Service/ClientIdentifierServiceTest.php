<?php

/**
 * Unit tests for ClientIdentifierService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Service\ClientIdentifierChecks;
use OCA\Pipelinq\Service\ClientIdentifierFormat;
use OCA\Pipelinq\Service\ClientIdentifierService;
use OCA\Pipelinq\Service\ClientIdentifierStore;
use OCA\Pipelinq\Service\KvkCompanyLookup;
use OCA\Pipelinq\Service\ViesVatLookup;
use OCA\Pipelinq\Service\CompanyRegistryHttp;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A VIES service as shillinq's contract shapes it, answering what the test sets.
 */
class FakeViesService {
	/**
	 * The VAT numbers asked for.
	 *
	 * @var array<int, string>
	 */
	public array $asked = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $answer What validate() answers.
	 */
	public function __construct(private array $answer) {
	}//end __construct()

	/**
	 * Answer a check.
	 *
	 * @param string $administrationId The administration.
	 * @param string $vatId            The VAT number.
	 *
	 * @return array<string, mixed> The answer.
	 */
	public function validate(string $administrationId, string $vatId): array {
		$this->asked[] = $vatId;
		return $this->answer;
	}//end validate()
}//end class

/**
 * Formats, KvK and VAT checks and the stored outcome of a check.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
#[CoversClass(ClientIdentifierService::class)]
#[CoversClass(ClientIdentifierFormat::class)]
#[CoversClass(ClientIdentifierChecks::class)]
#[CoversClass(ClientIdentifierStore::class)]
#[CoversClass(KvkCompanyLookup::class)]
#[CoversClass(ViesVatLookup::class)]
#[UsesClass(CompanyRegistryHttp::class)]
class ClientIdentifierServiceTest extends TestCase {

	/**
	 * 2026-11-17T09:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1794906000;

	/**
	 * The URLs the HTTP client was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $urls = [];

	/**
	 * The objects saved, as [data, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $saved = [];

	/**
	 * The register and schema the app config answers; empty while unconfigured.
	 *
	 * @var string
	 */
	private string $configured = '5';

	/**
	 * What saving a client throws, or null when it saves.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $saveFails = null;

	/**
	 * A service over the given HTTP answer, clients and VIES service.
	 *
	 * @param int|RuntimeException                $http     The leaf's status, or the exception it throws.
	 * @param array<int, array<string, mixed>>    $rows     The leaf's rows.
	 * @param array<string, array<string, mixed>> $clients  Client id to data.
	 * @param object|null                         $vies     shillinq's VIES service, or null when shillinq is absent.
	 * @param bool                                $mayRead  Whether the user may read the clients.
	 *
	 * @return ClientIdentifierService The service.
	 */
	private function service(
		int|RuntimeException $http = 200,
		array $rows = [],
		array $clients = [],
		?object $vies = null,
		bool $mayRead = true,
	): ClientIdentifierService {
		$response = $this->createMock(originalClassName: IResponse::class);
		$status = 0;
		if (is_int($http) === true) {
			$status = $http;
		}

		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn((string)json_encode(['results' => $rows]));
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($http, $response) {
				$this->urls[] = $url;
				if ($http instanceof RuntimeException) {
					throw $http;
				}

				return $response;
			}
		);
		$clientService = $this->createConfiguredMock(originalClassName: IClientService::class, configuration: ['newClient' => $client]);

		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example' . $path);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use ($clients, $mayRead) {
				if ($mayRead === false) {
					throw new NotAuthorizedException('no');
				}

				if (isset($clients[$id]) === false) {
					return null;
				}

				return $this->createConfiguredMock(originalClassName: ObjectEntityInterface::class, configuration: ['getObject' => $clients[$id]]);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) {
				if ($this->saveFails !== null) {
					throw $this->saveFails;
				}

				$this->saved[] = [$object, $options->uuid];
				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (): ?object => $vies);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => $app === 'shillinq' && $vies !== null);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$format = new ClientIdentifierFormat(l10n: $l10n);
		$checks = new ClientIdentifierChecks(
			format: $format,
			kvk: new KvkCompanyLookup(clientService: $clientService, urlGenerator: $urls, logger: $logger),
			vies: new ViesVatLookup(container: $container, appManager: $apps, logger: $logger, viesClass: FakeViesService::class),
			time: $this->createConfiguredMock(originalClassName: ITimeFactory::class, configuration: ['getTime' => self::NOW]),
		);
		$store = new ClientIdentifierStore(
			objectService: $objects,
			appConfig: $this->createConfiguredMock(originalClassName: IAppConfig::class, configuration: ['getValueString' => $this->configured]),
			l10n: $l10n,
			logger: $logger,
		);

		return new ClientIdentifierService(format: $format, checks: $checks, store: $store, l10n: $l10n);
	}//end service()

	/**
	 * Each scheme accepts its own format.
	 *
	 * @return void
	 */
	public function testValidNumbersPassPerScheme(): void {
		$service = $this->service();

		$this->assertNull(actual: $service->validateFormat(scheme: 'kvk', value: '12345678'));
		$this->assertNull(actual: $service->validateFormat(scheme: 'kvk', value: '1234 5678'));
		$this->assertNull(actual: $service->validateFormat(scheme: 'rsin', value: '111222333'));
		$this->assertNull(actual: $service->validateFormat(scheme: 'vat', value: 'NL123456789B01'));
		$this->assertNull(actual: $service->validateFormat(scheme: 'vat', value: 'de123456789'));
		$this->assertNull(actual: $service->validateFormat(scheme: 'vat', value: 'ATU12345678'));
		$this->assertNull(actual: $service->validateFormat(scheme: 'duns', value: '150483782'));
		$this->assertNull(actual: $service->validateFormat(scheme: 'oin', value: '00000001001234567000'));
	}//end testValidNumbersPassPerScheme()

	/**
	 * Each scheme refuses another format, with a reason.
	 *
	 * @return void
	 */
	public function testInvalidNumbersAreRefusedPerScheme(): void {
		$service = $this->service();

		$this->assertSame(expected: 'A KvK number has 8 digits.', actual: $service->validateFormat(scheme: 'kvk', value: '1234'));
		$this->assertSame(expected: 'An RSIN has 9 digits.', actual: $service->validateFormat(scheme: 'rsin', value: '12345678'));
		$this->assertSame(
			expected: 'The RSIN 123456789 is not valid: it fails the eleven test.',
			actual: $service->validateFormat(scheme: 'rsin', value: '123456789')
		);
		$this->assertSame(
			expected: 'NL123456789 is not a valid VAT number for country NL.',
			actual: $service->validateFormat(scheme: 'vat', value: 'NL123456789')
		);
		$this->assertSame(
			expected: 'A VAT number starts with the two letters of an EU country, such as NL or DE.',
			actual: $service->validateFormat(scheme: 'vat', value: 'US123456789')
		);
		$this->assertSame(expected: 'A DUNS number has 9 digits.', actual: $service->validateFormat(scheme: 'duns', value: '15048378A'));
		$this->assertSame(expected: 'An OIN has 20 digits.', actual: $service->validateFormat(scheme: 'oin', value: '123'));
		$this->assertSame(
			expected: '"iban" is not a kind of number pipelinq keeps.',
			actual: $service->validateFormat(scheme: 'iban', value: 'NL91ABNA0417164300')
		);
	}//end testInvalidNumbersAreRefusedPerScheme()

	/**
	 * The eleven test weighs 9 down to 2 and the last digit -1.
	 *
	 * @return void
	 */
	public function testTheElevenTest(): void {
		$format = new ClientIdentifierFormat(l10n: $this->createMock(originalClassName: IL10N::class));
		$this->assertTrue(condition: $format->passesElevenTest(digits: '111222333'));
		$this->assertTrue(condition: $format->passesElevenTest(digits: '123456782'));
		$this->assertFalse(condition: $format->passesElevenTest(digits: '123456789'));
		$this->assertFalse(condition: $format->passesElevenTest(digits: '12345678'));
	}//end testTheElevenTest()

	/**
	 * A list's problems are collected once each; an empty list has none.
	 *
	 * @return void
	 */
	public function testProblemsInAListAreCollected(): void {
		$service = $this->service();

		$this->assertSame(expected: [], actual: $service->problemsIn(identifiers: null));
		$this->assertSame(expected: [], actual: $service->problemsIn(identifiers: [['scheme' => 'kvk', 'value' => '12345678']]));
		$this->assertSame(
			expected: ['A KvK number has 8 digits.', 'Each identifier needs a kind of number and a number.'],
			actual: $service->problemsIn(identifiers: [['scheme' => 'kvk', 'value' => '1'], ['scheme' => 'kvk', 'value' => '2'], 'kvk'])
		);
		$this->assertSame(expected: ['The identifiers must be a list.'], actual: $service->problemsIn(identifiers: ['a' => ['scheme' => 'kvk']]));
	}//end testProblemsInAListAreCollected()

	/**
	 * A number the KvK leaf answers is valid, and the leaf is asked for that number.
	 *
	 * @return void
	 */
	public function testAKvkNumberTheRegisterKnowsIsValid(): void {
		$service = $this->service(http: 200, rows: [['kvkNummer' => '12345678', 'naam' => 'Bakkerij de Korenschoof']]);

		$this->assertSame(
			expected: ['checkStatus' => 'valid', 'checkedAt' => '2026-11-17T09:00:00+00:00', 'checkSource' => 'KvK'],
			actual: $service->checkKvk(value: '1234 5678')
		);
		$this->assertSame(expected: ['https://nc.example/apps/openregister/api/integrations/kvk/company?kvkNumber=12345678'], actual: $this->urls);
	}//end testAKvkNumberTheRegisterKnowsIsValid()

	/**
	 * A 404, or a 200 without the number, is invalid.
	 *
	 * @return void
	 */
	public function testAKvkNumberTheRegisterDoesNotKnowIsInvalid(): void {
		$this->assertSame(expected: 'invalid', actual: $this->service(http: 404)->checkKvk(value: '12345678')['checkStatus']);
		$this->assertSame(
			expected: 'invalid',
			actual: $this->service(http: 200, rows: [['kvkNummer' => '87654321']])->checkKvk(value: '12345678')['checkStatus']
		);
	}//end testAKvkNumberTheRegisterDoesNotKnowIsInvalid()

	/**
	 * A 503, or no answer at all, is unavailable rather than invalid.
	 *
	 * @return void
	 */
	public function testAnUnreachableKvkRegisterIsUnavailable(): void {
		$this->assertSame(expected: 'unavailable', actual: $this->service(http: 503)->checkKvk(value: '12345678')['checkStatus']);
		$this->assertSame(
			expected: 'unavailable',
			actual: $this->service(http: new RuntimeException('connection refused'))->checkKvk(value: '12345678')['checkStatus']
		);
	}//end testAnUnreachableKvkRegisterIsUnavailable()

	/**
	 * The KvK row becomes client fields: place, legal form code and SBI descriptions, main activity first.
	 *
	 * @return void
	 */
	public function testKvkFactsAreClientFields(): void {
		$row = [
			'kvkNummer' => '12345678',
			'rechtsvorm' => 'Besloten Vennootschap',
			'adres' => ['binnenlandsAdres' => ['plaats' => 'Zuiddrecht']],
			'sbiActiviteiten' => [
				['sbiOmschrijving' => 'Winkels in brood en banket', 'indHoofdactiviteit' => 'Nee'],
				['sbiOmschrijving' => 'Brood- en banketbakkerijen', 'indHoofdactiviteit' => 'Ja'],
				['sbiOmschrijving' => 'Winkels in brood en banket', 'indHoofdactiviteit' => 'Nee'],
			],
		];

		$this->assertSame(
			expected: [
				'checkStatus' => 'valid',
				'facts' => [
					'place' => 'Zuiddrecht',
					'legalForm' => 'bv',
					'industry' => ['Brood- en banketbakkerijen', 'Winkels in brood en banket'],
				],
			],
			actual: $this->service(http: 200, rows: [$row])->kvkFacts(value: '12345678')
		);
	}//end testKvkFactsAreClientFields()

	/**
	 * A fact the KvK does not give is left out; an unknown or unreachable number has no facts.
	 *
	 * @return void
	 */
	public function testKvkFactsLeaveOutWhatTheRegisterDoesNotGive(): void {
		$this->assertSame(
			expected: ['checkStatus' => 'valid', 'facts' => ['place' => 'Zuiddrecht']],
			actual: $this->service(
				http: 200,
				rows: [['kvkNummer' => '12345678', 'rechtsvorm' => 'Onbekend', 'adres' => ['plaats' => 'Zuiddrecht']]]
			)->kvkFacts(value: '12345678')
		);
		$this->assertSame(expected: ['checkStatus' => 'invalid', 'facts' => null], actual: $this->service(http: 404)->kvkFacts(value: '12345678'));
		$this->assertSame(
			expected: ['checkStatus' => 'unavailable', 'facts' => null],
			actual: $this->service(http: 503)->kvkFacts(value: '12345678')
		);
	}//end testKvkFactsLeaveOutWhatTheRegisterDoesNotGive()

	/**
	 * Without shillinq a VAT number stays unchecked and says why.
	 *
	 * @return void
	 */
	public function testWithoutShillinqAVatNumberStaysUnchecked(): void {
		$this->assertSame(
			expected: ['checkStatus' => 'unchecked', 'checkedAt' => '2026-11-17T09:00:00+00:00', 'checkSource' => 'shillinq not installed'],
			actual: $this->service(vies: null)->checkVat(value: 'DE123456789')
		);
	}//end testWithoutShillinqAVatNumberStaysUnchecked()

	/**
	 * With shillinq the VIES answer decides, and a valid answer keeps its validity.
	 *
	 * @return void
	 */
	public function testWithShillinqViesDecides(): void {
		$vies = new FakeViesService(['valid' => true, 'outage' => false, 'validUntil' => '2026-11-18T09:00:00+00:00']);
		$this->assertSame(
			expected: [
				'checkStatus' => 'valid',
				'checkedAt' => '2026-11-17T09:00:00+00:00',
				'checkSource' => 'VIES',
				'validUntil' => '2026-11-18T09:00:00+00:00',
			],
			actual: $this->service(vies: $vies)->checkVat(value: 'de 123456789')
		);
		$this->assertSame(expected: ['DE123456789'], actual: $vies->asked);

		$this->assertSame(
			expected: 'invalid',
			actual: $this->service(vies: new FakeViesService(['valid' => false, 'outage' => false]))->checkVat(value: 'DE123456789')['checkStatus']
		);
		$this->assertSame(
			expected: 'unavailable',
			actual: $this->service(vies: new FakeViesService(['valid' => false, 'outage' => true]))->checkVat(value: 'DE123456789')['checkStatus']
		);
	}//end testWithShillinqViesDecides()

	/**
	 * A check writes its outcome onto that identifier only, as the user.
	 *
	 * @return void
	 */
	public function testACheckStoresItsOutcomeOnTheIdentifier(): void {
		$client = [
			'name' => 'Bakkerij de Korenschoof',
			'type' => 'organization',
			'identifiers' => [
				['scheme' => 'vat', 'value' => 'NL123456789B01', 'checkStatus' => 'unchecked'],
				['scheme' => 'kvk', 'value' => '12345678', 'checkStatus' => 'unchecked'],
			],
		];
		$service = $this->service(http: 200, rows: [['kvkNummer' => '12345678']], clients: ['c1' => $client]);

		$result = $service->check(clientId: 'c1', scheme: 'kvk', value: '12345678');

		$this->assertSame(expected: 200, actual: $result['status']);
		$this->assertSame(
			expected: ['scheme' => 'kvk', 'value' => '12345678', 'checkStatus' => 'valid', 'checkedAt' => '2026-11-17T09:00:00+00:00', 'checkSource' => 'KvK'],
			actual: $result['identifier']
		);
		$this->assertCount(expectedCount: 1, haystack: $this->saved);
		$this->assertSame(expected: 'c1', actual: $this->saved[0][1]);
		$this->assertSame(expected: $client['identifiers'][0], actual: $this->saved[0][0]['identifiers'][0]);
		$this->assertSame(expected: 'valid', actual: $this->saved[0][0]['identifiers'][1]['checkStatus']);
		$this->assertSame(expected: 'Bakkerij de Korenschoof', actual: $this->saved[0][0]['name']);
	}//end testACheckStoresItsOutcomeOnTheIdentifier()

	/**
	 * A client the user may not read answers 403 and nothing is looked up or written.
	 *
	 * @return void
	 */
	public function testAClientTheUserMayNotReadIsRefused(): void {
		$result = $this->service(clients: ['c1' => ['identifiers' => [['scheme' => 'kvk', 'value' => '12345678']]]], mayRead: false)
			->check(clientId: 'c1', scheme: 'kvk', value: '12345678');

		$this->assertSame(expected: 403, actual: $result['status']);
		$this->assertSame(expected: [], actual: $this->urls);
		$this->assertSame(expected: [], actual: $this->saved);
	}//end testAClientTheUserMayNotReadIsRefused()

	/**
	 * canAccessClient() is a read with the caller's rights.
	 *
	 * @return void
	 */
	public function testCanAccessClientReadsWithTheCallersRights(): void {
		$this->assertTrue(condition: $this->service(clients: ['c1' => ['name' => 'Bakkerij']])->canAccessClient(clientId: 'c1'));
		$this->assertFalse(condition: $this->service(clients: ['c1' => ['name' => 'Bakkerij']])->canAccessClient(clientId: 'c2'));
		$this->assertFalse(condition: $this->service(clients: ['c1' => ['name' => 'Bakkerij']], mayRead: false)->canAccessClient(clientId: 'c1'));
	}//end testCanAccessClientReadsWithTheCallersRights()

	/**
	 * An unknown client or number answers 404; a scheme without a register answers 400.
	 *
	 * @return void
	 */
	public function testUnknownClientsNumbersAndSchemesAreRefused(): void {
		$service = $this->service(clients: ['c1' => ['identifiers' => [['scheme' => 'kvk', 'value' => '12345678']]]]);

		$this->assertSame(expected: 404, actual: $service->check(clientId: 'nope', scheme: 'kvk', value: '12345678')['status']);
		$this->assertSame(expected: 404, actual: $service->check(clientId: 'c1', scheme: 'kvk', value: '87654321')['status']);
		$this->assertSame(expected: 400, actual: $service->check(clientId: 'c1', scheme: 'duns', value: '150483782')['status']);
		$this->assertSame(expected: [], actual: $this->saved);
	}//end testUnknownClientsNumbersAndSchemesAreRefused()
	/**
	 * Without a client register and schema nothing is read: the check answers 404.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredAppFindsNoClient(): void {
		$this->configured = '';
		$service = $this->service(clients: ['c1' => ['identifiers' => [['scheme' => 'kvk', 'value' => '12345678']]]]);

		$this->assertSame(expected: 404, actual: $service->check(clientId: 'c1', scheme: 'kvk', value: '12345678')['status']);
		$this->assertFalse(condition: $service->canAccessClient(clientId: 'c1'));
		$this->assertSame(expected: [], actual: $this->urls);
	}//end testAnUnconfiguredAppFindsNoClient()

	/**
	 * A refused write answers 403, a failed write 500; neither claims the check was stored.
	 *
	 * @return void
	 */
	public function testARefusedOrFailedWriteIsReported(): void {
		$client = ['identifiers' => [['scheme' => 'kvk', 'value' => '12345678']]];

		$this->saveFails = new NotAuthorizedException('no');
		$refused = $this->service(http: 200, rows: [['kvkNummer' => '12345678']], clients: ['c1' => $client])
			->check(clientId: 'c1', scheme: 'kvk', value: '12345678');
		$this->assertSame(expected: ['status' => 403, 'message' => 'You may not change this client.'], actual: $refused);

		$this->saveFails = new RuntimeException('disk full');
		$failed = $this->service(http: 200, rows: [['kvkNummer' => '12345678']], clients: ['c1' => $client])
			->check(clientId: 'c1', scheme: 'kvk', value: '12345678');
		$this->assertSame(expected: ['status' => 500, 'message' => 'The outcome of the check could not be saved.'], actual: $failed);
		$this->assertSame(expected: [], actual: $this->saved);
	}//end testARefusedOrFailedWriteIsReported()
}//end class
