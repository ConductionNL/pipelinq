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
use OCA\Pipelinq\Service\ClientIdentifierService;
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
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(is_int($http) === true ? $http : 0);
		$response->method('getBody')->willReturn((string)json_encode(['results' => $rows]));
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($http, $response) {
				$this->urls[] = $url;
				if ($http instanceof RuntimeException) {
					throw $http;
				}

				return $response;
			}
		);
		$clientService = $this->createConfiguredMock(IClientService::class, ['newClient' => $client]);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example' . $path);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use ($clients, $mayRead) {
				if ($mayRead === false) {
					throw new NotAuthorizedException('no');
				}

				if (isset($clients[$id]) === false) {
					return null;
				}

				return $this->createConfiguredMock(ObjectEntityInterface::class, ['getObject' => $clients[$id]]);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) {
				$this->saved[] = [$object, $options->uuid];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (): ?object => $vies);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => $app === 'shillinq' && $vies !== null);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new ClientIdentifierService(
			$objects,
			$clientService,
			$urls,
			$container,
			$apps,
			$this->createConfiguredMock(IAppConfig::class, ['getValueString' => '5']),
			$this->createConfiguredMock(ITimeFactory::class, ['getTime' => self::NOW]),
			$l10n,
			$this->createMock(LoggerInterface::class),
			FakeViesService::class,
		);
	}//end service()

	/**
	 * Each scheme accepts its own format.
	 *
	 * @return void
	 */
	public function testValidNumbersPassPerScheme(): void {
		$service = $this->service();

		$this->assertNull($service->validateFormat(scheme: 'kvk', value: '12345678'));
		$this->assertNull($service->validateFormat(scheme: 'kvk', value: '1234 5678'));
		$this->assertNull($service->validateFormat(scheme: 'rsin', value: '111222333'));
		$this->assertNull($service->validateFormat(scheme: 'vat', value: 'NL123456789B01'));
		$this->assertNull($service->validateFormat(scheme: 'vat', value: 'de123456789'));
		$this->assertNull($service->validateFormat(scheme: 'vat', value: 'ATU12345678'));
		$this->assertNull($service->validateFormat(scheme: 'duns', value: '150483782'));
		$this->assertNull($service->validateFormat(scheme: 'oin', value: '00000001001234567000'));
	}//end testValidNumbersPassPerScheme()

	/**
	 * Each scheme refuses another format, with a reason.
	 *
	 * @return void
	 */
	public function testInvalidNumbersAreRefusedPerScheme(): void {
		$service = $this->service();

		$this->assertSame('A KvK number has 8 digits.', $service->validateFormat(scheme: 'kvk', value: '1234'));
		$this->assertSame('An RSIN has 9 digits.', $service->validateFormat(scheme: 'rsin', value: '12345678'));
		$this->assertSame('The RSIN 123456789 is not valid: it fails the eleven test.', $service->validateFormat(scheme: 'rsin', value: '123456789'));
		$this->assertSame('NL123456789 is not a valid VAT number for country NL.', $service->validateFormat(scheme: 'vat', value: 'NL123456789'));
		$this->assertSame('A VAT number starts with the two letters of an EU country, such as NL or DE.', $service->validateFormat(scheme: 'vat', value: 'US123456789'));
		$this->assertSame('A DUNS number has 9 digits.', $service->validateFormat(scheme: 'duns', value: '15048378A'));
		$this->assertSame('An OIN has 20 digits.', $service->validateFormat(scheme: 'oin', value: '123'));
		$this->assertSame('"iban" is not a kind of number pipelinq keeps.', $service->validateFormat(scheme: 'iban', value: 'NL91ABNA0417164300'));
	}//end testInvalidNumbersAreRefusedPerScheme()

	/**
	 * The eleven test weighs 9 down to 2 and the last digit -1.
	 *
	 * @return void
	 */
	public function testTheElevenTest(): void {
		$this->assertTrue(ClientIdentifierService::passesElevenTest(digits: '111222333'));
		$this->assertTrue(ClientIdentifierService::passesElevenTest(digits: '123456782'));
		$this->assertFalse(ClientIdentifierService::passesElevenTest(digits: '123456789'));
		$this->assertFalse(ClientIdentifierService::passesElevenTest(digits: '12345678'));
	}//end testTheElevenTest()

	/**
	 * A list's problems are collected once each; an empty list has none.
	 *
	 * @return void
	 */
	public function testProblemsInAListAreCollected(): void {
		$service = $this->service();

		$this->assertSame([], $service->problemsIn(identifiers: null));
		$this->assertSame([], $service->problemsIn(identifiers: [['scheme' => 'kvk', 'value' => '12345678']]));
		$this->assertSame(
			['A KvK number has 8 digits.', 'Each identifier needs a kind of number and a number.'],
			$service->problemsIn(identifiers: [['scheme' => 'kvk', 'value' => '1'], ['scheme' => 'kvk', 'value' => '2'], 'kvk'])
		);
		$this->assertSame(['The identifiers must be a list.'], $service->problemsIn(identifiers: ['a' => ['scheme' => 'kvk']]));
	}//end testProblemsInAListAreCollected()

	/**
	 * A number the KvK leaf answers is valid, and the leaf is asked for that number.
	 *
	 * @return void
	 */
	public function testAKvkNumberTheRegisterKnowsIsValid(): void {
		$service = $this->service(http: 200, rows: [['kvkNummer' => '12345678', 'naam' => 'Bakkerij de Korenschoof']]);

		$this->assertSame(
			['checkStatus' => 'valid', 'checkedAt' => '2026-11-17T09:00:00+00:00', 'checkSource' => 'KvK'],
			$service->checkKvk(value: '1234 5678')
		);
		$this->assertSame(['https://nc.example/apps/openregister/api/integrations/kvk/company?kvkNumber=12345678'], $this->urls);
	}//end testAKvkNumberTheRegisterKnowsIsValid()

	/**
	 * A 404, or a 200 without the number, is invalid.
	 *
	 * @return void
	 */
	public function testAKvkNumberTheRegisterDoesNotKnowIsInvalid(): void {
		$this->assertSame('invalid', $this->service(http: 404)->checkKvk(value: '12345678')['checkStatus']);
		$this->assertSame('invalid', $this->service(http: 200, rows: [['kvkNummer' => '87654321']])->checkKvk(value: '12345678')['checkStatus']);
	}//end testAKvkNumberTheRegisterDoesNotKnowIsInvalid()

	/**
	 * A 503, or no answer at all, is unavailable rather than invalid.
	 *
	 * @return void
	 */
	public function testAnUnreachableKvkRegisterIsUnavailable(): void {
		$this->assertSame('unavailable', $this->service(http: 503)->checkKvk(value: '12345678')['checkStatus']);
		$this->assertSame('unavailable', $this->service(http: new RuntimeException('connection refused'))->checkKvk(value: '12345678')['checkStatus']);
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
			[
				'checkStatus' => 'valid',
				'facts' => [
					'place' => 'Zuiddrecht',
					'legalForm' => 'bv',
					'industry' => ['Brood- en banketbakkerijen', 'Winkels in brood en banket'],
				],
			],
			$this->service(http: 200, rows: [$row])->kvkFacts(value: '12345678')
		);
	}//end testKvkFactsAreClientFields()

	/**
	 * A fact the KvK does not give is left out; an unknown or unreachable number has no facts.
	 *
	 * @return void
	 */
	public function testKvkFactsLeaveOutWhatTheRegisterDoesNotGive(): void {
		$this->assertSame(
			['checkStatus' => 'valid', 'facts' => ['place' => 'Zuiddrecht']],
			$this->service(http: 200, rows: [['kvkNummer' => '12345678', 'rechtsvorm' => 'Onbekend', 'adres' => ['plaats' => 'Zuiddrecht']]])->kvkFacts(value: '12345678')
		);
		$this->assertSame(['checkStatus' => 'invalid', 'facts' => null], $this->service(http: 404)->kvkFacts(value: '12345678'));
		$this->assertSame(['checkStatus' => 'unavailable', 'facts' => null], $this->service(http: 503)->kvkFacts(value: '12345678'));
	}//end testKvkFactsLeaveOutWhatTheRegisterDoesNotGive()

	/**
	 * Without shillinq a VAT number stays unchecked and says why.
	 *
	 * @return void
	 */
	public function testWithoutShillinqAVatNumberStaysUnchecked(): void {
		$this->assertSame(
			['checkStatus' => 'unchecked', 'checkedAt' => '2026-11-17T09:00:00+00:00', 'checkSource' => 'shillinq not installed'],
			$this->service(vies: null)->checkVat(value: 'DE123456789')
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
			['checkStatus' => 'valid', 'checkedAt' => '2026-11-17T09:00:00+00:00', 'checkSource' => 'VIES', 'validUntil' => '2026-11-18T09:00:00+00:00'],
			$this->service(vies: $vies)->checkVat(value: 'de 123456789')
		);
		$this->assertSame(['DE123456789'], $vies->asked);

		$this->assertSame('invalid', $this->service(vies: new FakeViesService(['valid' => false, 'outage' => false]))->checkVat(value: 'DE123456789')['checkStatus']);
		$this->assertSame('unavailable', $this->service(vies: new FakeViesService(['valid' => false, 'outage' => true]))->checkVat(value: 'DE123456789')['checkStatus']);
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

		$this->assertSame(200, $result['status']);
		$this->assertSame(
			['scheme' => 'kvk', 'value' => '12345678', 'checkStatus' => 'valid', 'checkedAt' => '2026-11-17T09:00:00+00:00', 'checkSource' => 'KvK'],
			$result['identifier']
		);
		$this->assertCount(1, $this->saved);
		$this->assertSame('c1', $this->saved[0][1]);
		$this->assertSame($client['identifiers'][0], $this->saved[0][0]['identifiers'][0]);
		$this->assertSame('valid', $this->saved[0][0]['identifiers'][1]['checkStatus']);
		$this->assertSame('Bakkerij de Korenschoof', $this->saved[0][0]['name']);
	}//end testACheckStoresItsOutcomeOnTheIdentifier()

	/**
	 * A client the user may not read answers 403 and nothing is looked up or written.
	 *
	 * @return void
	 */
	public function testAClientTheUserMayNotReadIsRefused(): void {
		$result = $this->service(clients: ['c1' => ['identifiers' => [['scheme' => 'kvk', 'value' => '12345678']]]], mayRead: false)
			->check(clientId: 'c1', scheme: 'kvk', value: '12345678');

		$this->assertSame(403, $result['status']);
		$this->assertSame([], $this->urls);
		$this->assertSame([], $this->saved);
	}//end testAClientTheUserMayNotReadIsRefused()

	/**
	 * canAccessClient() is a read with the caller's rights.
	 *
	 * @return void
	 */
	public function testCanAccessClientReadsWithTheCallersRights(): void {
		$this->assertTrue($this->service(clients: ['c1' => ['name' => 'Bakkerij']])->canAccessClient(clientId: 'c1'));
		$this->assertFalse($this->service(clients: ['c1' => ['name' => 'Bakkerij']])->canAccessClient(clientId: 'c2'));
		$this->assertFalse($this->service(clients: ['c1' => ['name' => 'Bakkerij']], mayRead: false)->canAccessClient(clientId: 'c1'));
	}//end testCanAccessClientReadsWithTheCallersRights()

	/**
	 * An unknown client or number answers 404; a scheme without a register answers 400.
	 *
	 * @return void
	 */
	public function testUnknownClientsNumbersAndSchemesAreRefused(): void {
		$service = $this->service(clients: ['c1' => ['identifiers' => [['scheme' => 'kvk', 'value' => '12345678']]]]);

		$this->assertSame(404, $service->check(clientId: 'nope', scheme: 'kvk', value: '12345678')['status']);
		$this->assertSame(404, $service->check(clientId: 'c1', scheme: 'kvk', value: '87654321')['status']);
		$this->assertSame(400, $service->check(clientId: 'c1', scheme: 'duns', value: '150483782')['status']);
		$this->assertSame([], $this->saved);
	}//end testUnknownClientsNumbersAndSchemesAreRefused()
}//end class
