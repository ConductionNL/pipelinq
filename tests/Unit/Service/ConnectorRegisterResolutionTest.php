<?php

/**
 * Every read of Integriq's Source register uses the slug this instance carries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCA\Pipelinq\Service\ConnectorSourceRegister;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Export\ExportUploadService;
use OCA\Pipelinq\Service\Marketing\Transport\ConnectorSourceTransport;
use OCA\Pipelinq\Service\Marketing\Transport\RenderedMail;
use OCA\Pipelinq\Tests\Unit\Support\FakeSlugResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Migrated and unmigrated instances, told apart.
 *
 * ## Why the migrated case is the only one that matters
 *
 * Reinstating the pinned literal reddens the migrated-instance tests below and
 * the static guard in `RegisterSlugPinTest`. It does NOT redden the
 * unmigrated-instance tests, because on an unmigrated instance the pinned
 * literal happens to be the right answer. That is why this defect survived in
 * five places: every test anyone had written was, in effect, the unmigrated
 * case, and most did not look at the register argument at all.
 *
 * Watched failing, not assumed. Two mutations were run and both were measured,
 * not predicted.
 *
 * MUTATION A, the resolver that invents a slug: `ConnectorSourceRegister::slugOrNull()`
 * made to `return 'openconnector';` unconditionally. 14 tests reddened, 10 of them
 * in this file: the four `AMigratedInstance` cases, the four
 * `AnInstanceWithoutTheRegister` cases (which stopped skipping and read anyway),
 * `testTheAppIdAndTheRegisterSlugAreAnsweredSeparately` and
 * `testAnAbsentRegisterIsLoggedAsAWarningNamingTheCandidates`. Four more reddened
 * in the suites that predate this work and now describe a migrated instance
 * (`BlastServiceTest`, `ExportDestinationServiceTest` twice,
 * `ExportUploadServiceTest`). The four `AnUnmigratedInstance` cases stayed GREEN,
 * exactly as they should: on an unmigrated instance `openconnector` is correct.
 * `RegisterSlugPinTest` ALSO stayed green, and that is not a gap in it — it reads
 * lines, not data flow, and a `return` statement is not register position. This is
 * the half of the defect only a behavioural test can see.
 *
 * MUTATION B, the literal put back: `private const OPENCONNECTOR_REGISTER_SLUG =
 * 'openconnector';` reinstated in `ExportUploadService` and used at the read.
 * Exactly three reddened, and they were the right three:
 * `testAMigratedInstanceReadsExportCredentialsWithItsNewSlug`,
 * `ExportUploadServiceTest::testUploadResolvesLegacyCredentialsFromRawSourceRead`,
 * and `RegisterSlugPinTest::testNoSourceFilePinsASupersededRegisterSlug`, which
 * named `lib/Service/Export/ExportUploadService.php:66`, the slug and the
 * canonical replacement. `testAnUnmigratedInstanceReadsExportCredentialsWithItsOldSlug`
 * stayed green there too. This is the half only the static guard can see.
 *
 * Both files were restored from byte copies and verified identical by md5sum and
 * diff; the suite is back to 2886 tests, 0 failures.
 *
 * ## Why the app-id check is not enough
 *
 * Pipelinq already resolves the connector app across the rename, in
 * `FleetAppId`, and that resolution was answering correctly on the very
 * instances these reads were failing on. The app id and the register slug are
 * moved by two different repair steps and either can run first, so an instance
 * can answer `integriq` to `IAppManager` while its register row still reads
 * `openconnector`, or the reverse.
 * `testTheAppIdAndTheRegisterSlugAreAnsweredSeparately` holds that distinction
 * in place.
 */
class ConnectorRegisterResolutionTest extends TestCase {

	/**
	 * The register slug the last read was made with.
	 *
	 * @var string|null
	 */
	private ?string $readRegister = null;

	/**
	 * How many reads reached an object service.
	 *
	 * @var int
	 */
	private int $readCount = 0;

	/**
	 * Reset the capture between cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->readRegister = null;
		$this->readCount = 0;
	}//end setUp()

	/**
	 * ConnectorEgress reads the source with the slug a migrated instance carries.
	 *
	 * @return void
	 */
	public function testAMigratedInstanceReadsTheEgressSourceWithItsNewSlug(): void {
		$result = $this->readThroughEgress(presentSlugs: ['integriq']);

		$this->assertSame('integriq', $this->readRegister);
		$this->assertSame(1, $this->readCount);
		$this->assertTrue($result->succeeded);
	}//end testAMigratedInstanceReadsTheEgressSourceWithItsNewSlug()

	/**
	 * An unmigrated instance still reads with the old slug.
	 *
	 * This case passes both before and after the fix. It is here to prove the
	 * change did not simply swap one literal for another, which would have moved
	 * the breakage to the other half of the estate rather than removing it.
	 *
	 * @return void
	 */
	public function testAnUnmigratedInstanceReadsTheEgressSourceWithItsOldSlug(): void {
		$result = $this->readThroughEgress(presentSlugs: ['openconnector']);

		$this->assertSame('openconnector', $this->readRegister);
		$this->assertTrue($result->succeeded);
	}//end testAnUnmigratedInstanceReadsTheEgressSourceWithItsOldSlug()

	/**
	 * An instance carrying the register under NEITHER slug reads nothing.
	 *
	 * The absence has to stop the read. Before this branch the read went out with
	 * `openconnector` regardless, matched nothing, and `read()` reported the
	 * source as unresolvable — which is what it also reports when the source id
	 * is genuinely wrong. Same words, two different repairs.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutTheRegisterReadsNothingForEgress(): void {
		$result = $this->readThroughEgress(presentSlugs: []);

		$this->assertSame(0, $this->readCount, 'Nothing may be read when the register is absent.');
		$this->assertFalse($result->succeeded);
		$this->assertSame(EgressResult::UNAVAILABLE, $result->failure);
	}//end testAnInstanceWithoutTheRegisterReadsNothingForEgress()

	/**
	 * Export credentials are read with the slug a migrated instance carries.
	 *
	 * @return void
	 */
	public function testAMigratedInstanceReadsExportCredentialsWithItsNewSlug(): void {
		$this->uploadOneFile(presentSlugs: ['integriq']);

		$this->assertSame('integriq', $this->readRegister);
		$this->assertSame(1, $this->readCount);
	}//end testAMigratedInstanceReadsExportCredentialsWithItsNewSlug()

	/**
	 * An unmigrated instance reads export credentials with the old slug.
	 *
	 * @return void
	 */
	public function testAnUnmigratedInstanceReadsExportCredentialsWithItsOldSlug(): void {
		$this->uploadOneFile(presentSlugs: ['openconnector']);

		$this->assertSame('openconnector', $this->readRegister);
	}//end testAnUnmigratedInstanceReadsExportCredentialsWithItsOldSlug()

	/**
	 * With the register absent, no credential read is attempted at all.
	 *
	 * The upload still fails closed with an empty credential map, which is the
	 * pre-existing behaviour and the right one. What changes is that the reason
	 * is now written to the log instead of being inferred from a read that
	 * matched nothing.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutTheRegisterResolvesNoExportCredentials(): void {
		$this->uploadOneFile(presentSlugs: []);

		$this->assertSame(0, $this->readCount, 'No credential read may be attempted when the register is absent.');
	}//end testAnInstanceWithoutTheRegisterResolvesNoExportCredentials()

	/**
	 * A blast send resolves its source with the slug a migrated instance carries.
	 *
	 * @return void
	 */
	public function testAMigratedInstanceSendsThroughTheSourceReadWithItsNewSlug(): void {
		$result = $this->sendOneMail(presentSlugs: ['integriq']);

		$this->assertSame('integriq', $this->readRegister);
		$this->assertTrue($result->accepted);
	}//end testAMigratedInstanceSendsThroughTheSourceReadWithItsNewSlug()

	/**
	 * An unmigrated instance sends through a source read with the old slug.
	 *
	 * @return void
	 */
	public function testAnUnmigratedInstanceSendsThroughTheSourceReadWithItsOldSlug(): void {
		$result = $this->sendOneMail(presentSlugs: ['openconnector']);

		$this->assertSame('openconnector', $this->readRegister);
		$this->assertTrue($result->accepted);
	}//end testAnUnmigratedInstanceSendsThroughTheSourceReadWithItsOldSlug()

	/**
	 * With the register absent the send is refused, under its own error code.
	 *
	 * `connector-register-absent` is a different repair from
	 * `connector-source-not-found`: one is a register that was never provisioned
	 * here, the other a source id that is wrong on the transport row. Before this
	 * change the first reported as the second, because a read against a slug
	 * nothing answers to returns no source.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutTheRegisterRefusesToSendAndSaysWhy(): void {
		$result = $this->sendOneMail(presentSlugs: []);

		$this->assertSame(0, $this->readCount, 'Nothing may be read when the register is absent.');
		$this->assertFalse($result->accepted);
		$this->assertSame('connector-register-absent', $result->error);
	}//end testAnInstanceWithoutTheRegisterRefusesToSendAndSaysWhy()

	/**
	 * The source rate limit is read with the slug a migrated instance carries.
	 *
	 * @return void
	 */
	public function testAMigratedInstanceReadsTheSourceRateLimitWithItsNewSlug(): void {
		$limit = $this->resolveRateLimit(presentSlugs: ['integriq']);

		$this->assertSame('integriq', $this->readRegister);
		$this->assertSame(5, $limit, 'The source declares 5/s and must be able to cap the caller.');
	}//end testAMigratedInstanceReadsTheSourceRateLimitWithItsNewSlug()

	/**
	 * An unmigrated instance reads the source rate limit with the old slug.
	 *
	 * @return void
	 */
	public function testAnUnmigratedInstanceReadsTheSourceRateLimitWithItsOldSlug(): void {
		$limit = $this->resolveRateLimit(presentSlugs: ['openconnector']);

		$this->assertSame('openconnector', $this->readRegister);
		$this->assertSame(5, $limit);
	}//end testAnUnmigratedInstanceReadsTheSourceRateLimitWithItsOldSlug()

	/**
	 * With the register absent the caller's own rate stands, and nothing is read.
	 *
	 * This is the site where the old behaviour was worst. A read that matched
	 * nothing returned no declared limit, so a blast fell back to the caller's
	 * rate and sent at it, against a provider that may allow a fraction of that.
	 * The rate is unchanged here; what is new is the warning that says the cap
	 * could not be read.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutTheRegisterKeepsTheCallersRate(): void {
		$limit = $this->resolveRateLimit(presentSlugs: []);

		$this->assertSame(0, $this->readCount, 'Nothing may be read when the register is absent.');
		$this->assertSame(50, $limit);
	}//end testAnInstanceWithoutTheRegisterKeepsTheCallersRate()

	/**
	 * The app id and the register slug are answered separately.
	 *
	 * `FleetAppId` reads `IAppManager`; the resolver reads
	 * `openregister_registers`. Two repair steps move them and either can run
	 * first, so an instance can answer `integriq` to one and `openconnector` to
	 * the other. This asserts the app-side answer never leaks into the register
	 * side: the same collaborator, asked about three different instances, gives
	 * three different answers, and the one it gives for the absent instance is
	 * null rather than the canonical app id.
	 *
	 * @return void
	 */
	public function testTheAppIdAndTheRegisterSlugAreAnsweredSeparately(): void {
		$this->assertSame(
			'integriq',
			FakeSlugResolver::connectorRegister(['integriq'])->slugOrNull(operation: 'test'),
			'A migrated instance answers with the new slug.'
		);
		$this->assertSame(
			'openconnector',
			FakeSlugResolver::connectorRegister(['openconnector'])->slugOrNull(operation: 'test'),
			'An unmigrated instance answers with the old slug, even though the app id has moved.'
		);
		$this->assertNull(
			FakeSlugResolver::connectorRegister([])->slugOrNull(operation: 'test'),
			'An instance without the register answers null, never the canonical app id.'
		);
	}//end testTheAppIdAndTheRegisterSlugAreAnsweredSeparately()

	/**
	 * The absence is written to the log, not merely returned.
	 *
	 * A null that nobody records is the defect in a new shape: the caller returns
	 * an empty result and the operator sees the same nothing as before. The
	 * warning is what makes an absent register distinguishable from an empty one.
	 *
	 * @return void
	 */
	public function testAnAbsentRegisterIsLoggedAsAWarningNamingTheCandidates(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$captured = '';
		$logger->method('warning')->willReturnCallback(
			function (string $message) use (&$captured): void {
				$captured = $message;
			}
		);

		$register = new ConnectorSourceRegister(new FakeSlugResolver([]), $logger);

		$this->assertNull($register->slugOrNull(operation: 'test', sourceId: 'oc-1'));
		$this->assertStringContainsString('integriq', $captured, 'The warning must name the candidates probed.');
		$this->assertStringContainsString('openconnector', $captured);
	}//end testAnAbsentRegisterIsLoggedAsAWarningNamingTheCandidates()

	/**
	 * Read one URL through ConnectorEgress against an instance carrying the given slugs.
	 *
	 * @param list<string> $presentSlugs The register slugs this instance carries.
	 *
	 * @return EgressResult The egress outcome.
	 */
	private function readThroughEgress(array $presentSlugs): EgressResult {
		$objectService = $this->capturingObjectService();
		$callService = new class {
			/**
			 * Answer any call with a 200.
			 *
			 * @param array<string, mixed>|object $source   The resolved source.
			 * @param string                      $endpoint The endpoint.
			 * @param string                      $method   The HTTP method.
			 * @param array<string, mixed>        $config   The call config.
			 *
			 * @return array<string, mixed> A call log.
			 */
			public function call(array|object $source, string $endpoint, string $method, array $config): array {
				return ['statusCode' => 200, 'response' => ['statusCode' => 200, 'body' => 'ok']];
			}//end call()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $callService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\Integriq\\Service\\CallService') {
					return $callService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('oc-1');

		$egress = new ConnectorEgress(
			container: $container,
			appConfig: $appConfig,
			connectorRegister: FakeSlugResolver::connectorRegister($presentSlugs),
			logger: $this->createMock(LoggerInterface::class)
		);

		return $egress->read(configKey: 'competitorSourceId', endpoint: '/feed');
	}//end readThroughEgress()

	/**
	 * Upload one file through ExportUploadService against the given instance.
	 *
	 * @param list<string> $presentSlugs The register slugs this instance carries.
	 *
	 * @return void
	 */
	private function uploadOneFile(array $presentSlugs): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (...$args) {
				$this->readCount++;
				// Positional: named arguments reach a mock callback in
				// declaration order, and `register` is the FOURTH parameter of
				// ObjectServiceInterface::find (id, _extend, files, register).
				$this->readRegister = ($args[3] ?? null);
				return null;
			}
		);

		$sink = new class implements \OCA\Pipelinq\Adapter\ExportSinkInterface {
			/**
			 * @return string The type this sink handles.
			 */
			public function getType(): string {
				return 's3';
			}//end getType()

			/**
			 * @param array<string, mixed> $credentials The credentials.
			 * @param array<string, mixed> $destination The destination.
			 *
			 * @return bool Always true.
			 */
			public function testConnection(array $credentials, array $destination): bool {
				return true;
			}//end testConnection()

			/**
			 * @param array<string, mixed> $credentials The credentials.
			 * @param array<string, mixed> $destination The destination.
			 * @param string               $remotePath  The remote path.
			 * @param string               $contents    The bytes.
			 *
			 * @return string The acknowledgement.
			 */
			public function upload(array $credentials, array $destination, string $remotePath, string $contents): string {
				return 'ack';
			}//end upload()
		};

		$service = new ExportUploadService(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $this->createMock(IAppConfig::class),
			objectService: $objectService,
			sinks: new ExportSinkRegistry([$sink]),
			connectorRegister: FakeSlugResolver::connectorRegister($presentSlugs),
			logger: $this->createMock(LoggerInterface::class)
		);
		$service->setSleepBetweenRetries(false);

		$service->uploadFiles(
			destination: ['type' => 's3', 'connectorSourceId' => 'oc-1'],
			files: [['contents' => 'a,b', 'size_bytes' => 3, 'rows' => 1, 'sha256' => '', 'compression_used' => 'none']]
		);
	}//end uploadOneFile()

	/**
	 * Send one mail through ConnectorSourceTransport against the given instance.
	 *
	 * @param list<string> $presentSlugs The register slugs this instance carries.
	 *
	 * @return \OCA\Pipelinq\Service\Marketing\Transport\SendResult The send outcome.
	 */
	private function sendOneMail(array $presentSlugs): \OCA\Pipelinq\Service\Marketing\Transport\SendResult {
		$objectService = $this->capturingObjectService();
		$callService = new class {
			/**
			 * Accept any send.
			 *
			 * @param array<string, mixed>|object $source   The resolved source.
			 * @param string                      $endpoint The endpoint.
			 * @param string                      $method   The HTTP method.
			 * @param array<string, mixed>        $config   The call config.
			 *
			 * @return array<string, mixed> A call log.
			 */
			public function call(array|object $source, string $endpoint, string $method, array $config): array {
				return ['statusCode' => 202, 'response' => ['statusCode' => 202, 'body' => '{}']];
			}//end call()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $callService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\Integriq\\Service\\CallService') {
					return $callService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$transport = new ConnectorSourceTransport(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			connectorRegister: FakeSlugResolver::connectorRegister($presentSlugs),
			connectorSourceId: 'oc-1',
			provider: 'sendgrid'
		);

		return $transport->send(
			new RenderedMail(
				fromEmail: 'noreply@example.test',
				fromName: 'Pipelinq',
				replyTo: 'reply@example.test',
				toEmail: 'user@example.test',
				subject: 'Hi',
				html: '<p>hi</p>',
				text: 'hi',
				headers: [],
				deliveryId: 'd-1'
			)
		);
	}//end sendOneMail()

	/**
	 * Resolve a provider transport's rate limit against the given instance.
	 *
	 * @param list<string> $presentSlugs The register slugs this instance carries.
	 *
	 * @return int The resolved rate limit.
	 */
	private function resolveRateLimit(array $presentSlugs): int {
		$objectService = $this->capturingObjectService(['rateLimitLimit' => 5, 'rateLimitWindow' => 1]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$service = new \OCA\Pipelinq\Service\Marketing\MailTransportService(
			container: $container,
			appConfig: $this->createMock(IAppConfig::class),
			mailer: $this->createMock(\OCP\Mail\IMailer::class),
			articleService: $this->createMock(\OCA\Pipelinq\Service\ArticleService::class),
			connectorRegister: FakeSlugResolver::connectorRegister($presentSlugs),
			logger: $this->createMock(LoggerInterface::class)
		);

		return $service->resolveRateLimit(
			transport: ['kind' => 'provider', 'connectorSourceId' => 'oc-1'],
			callerRate: 50
		);
	}//end resolveRateLimit()

	/**
	 * An object service that records the register slug each read was made with.
	 *
	 * Deliberately an anonymous class rather than a mock: the two container-based
	 * consumers resolve `OCA\OpenRegister\Service\ObjectService` by string and
	 * duck-type the result, so the double only has to answer `find()`.
	 *
	 * @param array<string, mixed> $source The source payload to answer with.
	 *
	 * @return object The capturing double.
	 */
	private function capturingObjectService(array $source=['uuid' => 'oc-1']): object {
		return new class ($this, $source) {

			/**
			 * Constructor.
			 *
			 * @param ConnectorRegisterResolutionTest $test   The test recording the reads.
			 * @param array<string, mixed>            $source The payload to answer with.
			 */
			public function __construct(private object $test, private array $source) {
			}//end __construct()

			/**
			 * Record the register the read was made with, then answer.
			 *
			 * @param string      $id       The object id.
			 * @param string|null $register The register slug.
			 * @param string|null $schema   The schema slug.
			 *
			 * @return array<string, mixed> The source payload.
			 */
			public function find(string $id, ?string $register=null, ?string $schema=null): array {
				$this->test->recordRead(register: $register);
				return $this->source;
			}//end find()
		};
	}//end capturingObjectService()

	/**
	 * Record one read, from a double.
	 *
	 * @param string|null $register The register slug the read was made with.
	 *
	 * @return void
	 */
	public function recordRead(?string $register): void {
		$this->readCount++;
		$this->readRegister = $register;
	}//end recordRead()
}//end class
