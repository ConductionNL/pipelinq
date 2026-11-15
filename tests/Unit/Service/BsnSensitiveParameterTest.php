<?php

/**
 * Tests that a plaintext BSN never shows up in an exception's trace.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Berichtenbox\CaseStatusMessage;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BrpLeafResults;
use OCA\Pipelinq\Service\BsnAudit\LookupSubject;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\EncryptionService;
use OCA\Pipelinq\Service\External\Berichtenbox\BerichtenboxAdapterInterface;
use OCA\Pipelinq\Service\External\Berichtenbox\LogBerichtenboxAdapter;
use OCA\Pipelinq\Service\BsnLookup;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\LogiusConnector;
use OCA\Pipelinq\Service\MailboxResolver;
use OCA\Pipelinq\Service\OptOutService;
use OCA\Pipelinq\Service\PersonLookup\LookupQuery;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionParameter;
use RuntimeException;
use Throwable;

/**
 * Every parameter that takes a plaintext BSN is a #[\SensitiveParameter],
 * so PHP replaces it in a trace with a SensitiveParameterValue.
 */
class BsnSensitiveParameterTest extends TestCase {

	private const BSN = '999993653';

	/**
	 * The trace setting before the test.
	 *
	 * @var string|false
	 */
	private string|false $ignoreArgs = false;

	/**
	 * Record arguments in traces, as a development or debug instance does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->ignoreArgs = ini_get('zend.exception_ignore_args');
		ini_set('zend.exception_ignore_args', '0');
	}//end setUp()

	/**
	 * Put the trace setting back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ini_set('zend.exception_ignore_args', (string)$this->ignoreArgs);
	}//end tearDown()

	/**
	 * Every parameter that takes a plaintext BSN.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: string}>
	 */
	public static function plaintextBsnParameters(): array {
		$params = [
			[EncryptionService::class, 'encrypt', 'plaintext'],
			[EncryptionService::class, 'hashBsn', 'plaintext'],
			[EncryptionService::class, 'bsnEquals', 'candidate'],
			[CaseStatusMessage::class, '__construct', 'bsn'],
			[BerichtenboxService::class, 'cryptoShred', 'bsn'],
			[BerichtenboxService::class, 'dispatchToBerichtenbox', 'bsnPlain'],
			[BerichtenboxAdapterInterface::class, 'checkMailbox', 'bsn'],
			[LogBerichtenboxAdapter::class, 'checkMailbox', 'bsn'],
			[LogiusConnector::class, 'checkMailboxExists', 'bsn'],
			[MailboxResolver::class, 'resolve', 'bsn'],
			[HaalCentraalClient::class, 'lookupPersoon', 'bsn'],
			[BsnLookup::class, '__construct', 'bsn'],
			[HaalCentraalClient::class, 'lookupRequestOptions', 'bsn'],
			[BrpLeafResults::class, 'askedPerson', 'bsn'],
			[BsnValidationService::class, 'validate', 'bsnInput'],
			[BsnValidationService::class, 'elevenTestModulo', 'bsnInput'],
			[BsnValidationService::class, 'mask', 'bsnInput'],
			[BsnValidationService::class, 'hash', 'bsnInput'],
			[BsnAuditService::class, 'resolveSubjectHash', 'rawBsn'],
			[BsnAuditService::class, 'pseudonymise', 'rawBsn'],
			[LookupSubject::class, '__construct', 'rawBsn'],
			[LookupQuery::class, '__construct', 'rawBsn'],
			[BrpCacheService::class, 'get', 'rawBsn'],
			[BrpCacheService::class, 'invalidate', 'rawBsn'],
			[OptOutService::class, 'hasOptOut', 'rawBsn'],
			[OptOutService::class, 'getOptOut', 'rawBsn'],
			[OptOutService::class, 'recordFromBrpResponse', 'rawBsn'],
			[OptOutService::class, 'recordLocalOptOut', 'rawBsn'],
		];

		$cases = [];
		foreach ($params as $param) {
			$cases[$param[0].'::'.$param[1].'($'.$param[2].')'] = $param;
		}

		return $cases;
	}//end plaintextBsnParameters()

	/**
	 * The parameter carries #[\SensitiveParameter].
	 *
	 * @param class-string $class  The class.
	 * @param string       $method The method.
	 * @param string       $param  The parameter.
	 *
	 * @return void
	 */
	#[DataProvider('plaintextBsnParameters')]
	public function testThePlaintextBsnParameterIsSensitive(string $class, string $method, string $param): void {
		$parameter = new ReflectionParameter([$class, $method], $param);

		$this->assertCount(expectedCount: 1, haystack: $parameter->getAttributes(\SensitiveParameter::class));
	}//end testThePlaintextBsnParameterIsSensitive()

	/**
	 * An encryption service whose instance secret cannot be read. The
	 * exception is made inside the call, so its trace holds the calling frames.
	 *
	 * @return EncryptionService
	 */
	private function failingEncryption(): EncryptionService {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static function (): never {
				throw new RuntimeException('config unreadable');
			}
		);

		return new EncryptionService(config: $config, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end failingEncryption()

	/**
	 * A thrown exception's trace through the app's own frames, as a logger
	 * would write it: the trace string and every argument of those frames.
	 *
	 * @param callable $call The call that throws.
	 *
	 * @return string The trace.
	 */
	private function traceOf(callable $call): string {
		try {
			$call();
		} catch (Throwable $e) {
			$args = [];
			foreach ($e->getTrace() as $frame) {
				if (str_starts_with(haystack: ($frame['class'] ?? ''), needle: 'OCA\\Pipelinq\\Service\\') === true) {
					$args[] = ($frame['args'] ?? []);
				}
			}

			return $e->getTraceAsString().print_r($args, true);
		}

		$this->fail(message: 'The call was expected to throw.');
	}//end traceOf()

	/**
	 * A failed encryption leaves the BSN out of the trace, and keeps the
	 * tenant, so the trace does carry arguments.
	 *
	 * @return void
	 */
	public function testAFailedEncryptionTraceCarriesNoBsn(): void {
		$service = $this->failingEncryption();

		$trace = $this->traceOf(call: fn () => $service->encrypt(plaintext: self::BSN, tenantId: 'tenant-1'));

		$this->assertStringNotContainsString(needle: self::BSN, haystack: $trace);
		$this->assertStringContainsString(needle: 'tenant-1', haystack: $trace);
		$this->assertStringContainsString(needle: 'SensitiveParameterValue', haystack: $trace);
	}//end testAFailedEncryptionTraceCarriesNoBsn()

	/**
	 * A failed BSN hash leaves the BSN out of the trace.
	 *
	 * @return void
	 */
	public function testAFailedHashTraceCarriesNoBsn(): void {
		$service = $this->failingEncryption();

		$trace = $this->traceOf(call: fn () => $service->bsnEquals(candidate: self::BSN, hash: 'abc', tenantId: 'tenant-1'));

		$this->assertStringNotContainsString(needle: self::BSN, haystack: $trace);
		$this->assertStringContainsString(needle: 'tenant-1', haystack: $trace);
	}//end testAFailedHashTraceCarriesNoBsn()
}//end class
