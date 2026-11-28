<?php

/**
 * Unit tests for MoveBrpConnectionToIntegriq.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Event\BrpConnectionHandoverEvent;
use OCA\Pipelinq\Repair\MoveBrpConnectionToIntegriq;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The BRP connection settings move to integriq once, and pipelinq's keys are
 * cleared only when integriq confirms every part.
 */
class MoveBrpConnectionToIntegriqTest extends TestCase {

	private const CERT = "-----BEGIN CERTIFICATE-----\nMIIBcert\n-----END CERTIFICATE-----\n";

	private const KEY = "-----BEGIN PRIVATE KEY-----\nMIIEkey\n-----END PRIVATE KEY-----\n";

	/**
	 * The stored app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The keys deleted from the app config.
	 *
	 * @var list<string>
	 */
	private array $deleted = [];

	/**
	 * The report lines, as "info: ..." or "warning: ...".
	 *
	 * @var list<string>
	 */
	private array $report = [];

	/**
	 * Temporary files to remove after the test.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	/**
	 * The handover the dispatcher received, if any.
	 *
	 * @var BrpConnectionHandoverEvent|null
	 */
	private ?BrpConnectionHandoverEvent $handed = null;

	/**
	 * Remove the temporary certificate files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A temporary file with the given contents.
	 *
	 * @param string $contents The contents.
	 *
	 * @return string The path.
	 */
	private function file(string $contents): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'brp');
		file_put_contents($path, $contents);
		$this->files[] = $path;
		return $path;
	}//end file()

	/**
	 * pipelinq's full BRP connection: settings, encrypted secret and readable certificate files.
	 *
	 * @return void
	 */
	private function fullConnection(): void {
		$this->config = [
			'brp.base_url'                => 'https://brp.example/haalcentraal/api/brp',
			'brp.oauth_endpoint'          => 'https://auth.example/token',
			'brp.client_id'               => 'pipelinq',
			'brp.client_secret_encrypted' => 'enc:s3cret',
			'brp.cert_path'               => $this->file(self::CERT),
			'brp.key_path'                => $this->file(self::KEY),
			'brp.ca_bundle'               => '',
		];
	}//end fullConnection()

	/**
	 * The repair step over the recorded config, with integriq installed or not,
	 * and a dispatcher that hands the event to the given listener.
	 *
	 * @param bool          $integriq Whether integriq is installed.
	 * @param callable|null $listener Answers the handover, as integriq would.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return MoveBrpConnectionToIntegriq The step.
	 */
	private function step(bool $integriq, ?callable $listener=null, ?LoggerInterface $logger=null): MoveBrpConnectionToIntegriq {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				self::assertSame('pipelinq', $app);
				$this->deleted[] = $key;
			}
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(
			static function (string $cipher): string {
				if (str_starts_with($cipher, 'enc:') === false) {
					throw new RuntimeException('HMAC does not match');
				}

				return substr($cipher, 4);
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => ($integriq === true && $app === 'integriq')
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (BrpConnectionHandoverEvent $event) use ($listener): void {
				$this->handed = $event;
				if ($listener !== null) {
					$listener($event);
				}
			}
		);

		return new MoveBrpConnectionToIntegriq(
			appConfig: $appConfig,
			crypto: $crypto,
			dispatcher: $dispatcher,
			appManager: $appManager,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end step()

	/**
	 * An output that records every line.
	 *
	 * @return IOutput&MockObject The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->report[] = 'info: '.$line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->report[] = 'warning: '.$line;
		});
		return $output;
	}//end recordingOutput()

	/**
	 * A listener that takes every part.
	 *
	 * @return callable The listener.
	 */
	private static function takesEverything(): callable {
		return static function (BrpConnectionHandoverEvent $event): void {
			foreach ($event->getParts() as $part) {
				$event->answer($part, BrpConnectionHandoverEvent::TAKEN);
			}
		};
	}//end takesEverything()

	/**
	 * The step names its change.
	 *
	 * @return void
	 */
	public function testNamesTheChange(): void {
		self::assertStringContainsString('brp-monitor-moves-to-integriq', $this->step(integriq: true)->getName());
	}//end testNamesTheChange()

	/**
	 * Settings, the decrypted secret and the PEM contents go to integriq; once it
	 * takes every part, every pipelinq connection key is cleared.
	 *
	 * @return void
	 */
	public function testHandsEverythingOverAndClearsTheKeysOnceIntegriqTakesIt(): void {
		$this->fullConnection();

		$this->step(integriq: true, listener: self::takesEverything())->run($this->recordingOutput());

		self::assertNotNull($this->handed);
		self::assertSame(
			['baseUrl' => 'https://brp.example/haalcentraal/api/brp', 'oauthEndpoint' => 'https://auth.example/token', 'clientId' => 'pipelinq'],
			$this->handed->getSettings()
		);
		self::assertSame(['clientSecret' => 's3cret', 'certificate' => self::CERT, 'privateKey' => self::KEY], $this->handed->getSecrets());
		self::assertSame(
			['brp.base_url', 'brp.oauth_endpoint', 'brp.client_id', 'brp.client_secret_encrypted', 'brp.cert_path', 'brp.key_path', 'brp.ca_bundle'],
			$this->deleted
		);
		self::assertContains('info: brp.base_url: taken by integriq', $this->report);
		self::assertContains('info: brp.key_path: taken by integriq', $this->report);
		self::assertContains('info: integriq confirmed every part; the BRP connection keys are cleared from pipelinq.', $this->report);
		self::assertStringNotContainsString('s3cret', implode("\n", $this->report));
		self::assertStringNotContainsString('BEGIN', implode("\n", $this->report));
	}//end testHandsEverythingOverAndClearsTheKeysOnceIntegriqTakesIt()

	/**
	 * A base URL integriq already has is kept there, the conflict is reported,
	 * and the keys are still cleared: integriq's value is the one that counts.
	 *
	 * @return void
	 */
	public function testReportsAValueIntegriqKeptAsAConflict(): void {
		$this->fullConnection();
		$listener = static function (BrpConnectionHandoverEvent $event): void {
			foreach ($event->getParts() as $part) {
				$event->answer($part, BrpConnectionHandoverEvent::TAKEN);
			}

			$event->answer('baseUrl', BrpConnectionHandoverEvent::KEPT, 'the source has another base URL');
		};

		$this->step(integriq: true, listener: $listener)->run($this->recordingOutput());

		self::assertContains(
			'warning: brp.base_url: integriq kept the value it already had (conflict) (the source has another base URL)',
			$this->report
		);
		self::assertCount(7, $this->deleted);
	}//end testReportsAValueIntegriqKeptAsAConflict()

	/**
	 * Without integriq nothing is dispatched or cleared, and the report says why.
	 *
	 * @return void
	 */
	public function testKeepsEverythingWithoutIntegriq(): void {
		$this->fullConnection();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->stringContains('integriq is not installed'));

		$this->step(integriq: false, listener: self::takesEverything(), logger: $logger)->run($this->recordingOutput());

		self::assertNull($this->handed);
		self::assertSame([], $this->deleted);
		self::assertSame(['warning: integriq is not installed: the BRP connection settings stay in pipelinq until it is.'], $this->report);
	}//end testKeepsEverythingWithoutIntegriq()

	/**
	 * Without any connection key set there is nothing to move: no event, no report.
	 *
	 * @return void
	 */
	public function testDoesNothingWithoutSettings(): void {
		$this->step(integriq: true, listener: self::takesEverything())->run($this->recordingOutput());

		self::assertNull($this->handed);
		self::assertSame([], $this->deleted);
		self::assertSame([], $this->report);
	}//end testDoesNothingWithoutSettings()

	/**
	 * With no listener answering, every key stays and each is reported unanswered.
	 *
	 * @return void
	 */
	public function testKeepsTheKeysWhenIntegriqDoesNotAnswer(): void {
		$this->fullConnection();

		$this->step(integriq: true)->run($this->recordingOutput());

		self::assertNotNull($this->handed);
		self::assertSame([], $this->deleted);
		self::assertContains('warning: brp.client_secret_encrypted: integriq did not answer', $this->report);
		self::assertContains('warning: The BRP connection keys stay in pipelinq until integriq confirms every part.', $this->report);
	}//end testKeepsTheKeysWhenIntegriqDoesNotAnswer()

	/**
	 * One part integriq cannot take keeps every key.
	 *
	 * @return void
	 */
	public function testKeepsTheKeysWhenAPartFails(): void {
		$this->fullConnection();
		$listener = static function (BrpConnectionHandoverEvent $event): void {
			foreach ($event->getParts() as $part) {
				$event->answer($part, BrpConnectionHandoverEvent::TAKEN);
			}

			$event->answer('privateKey', BrpConnectionHandoverEvent::FAILED, 'keepiq refused the secret');
		};

		$this->step(integriq: true, listener: $listener)->run($this->recordingOutput());

		self::assertSame([], $this->deleted);
		self::assertContains('warning: brp.key_path: integriq could not take it (keepiq refused the secret)', $this->report);
	}//end testKeepsTheKeysWhenAPartFails()

	/**
	 * An unreadable certificate file is reported and not handed over; even when
	 * integriq takes the rest, every key stays.
	 *
	 * @return void
	 */
	public function testKeepsTheKeysWhenACertificateFileCannotBeRead(): void {
		$this->fullConnection();
		$this->config['brp.ca_bundle'] = '/nonexistent/brp-ca.pem';
		$this->config['brp.key_path']  = $this->file("  \n");

		$this->step(integriq: true, listener: self::takesEverything())->run($this->recordingOutput());

		self::assertSame(['clientSecret', 'certificate'], array_keys($this->handed->getSecrets()));
		self::assertSame([], $this->deleted);
		self::assertContains('warning: brp.ca_bundle: not handed over, the file it names cannot be read', $this->report);
		self::assertContains('warning: brp.key_path: not handed over, the file it names cannot be read', $this->report);
		self::assertStringNotContainsString('/nonexistent', implode("\n", $this->report));
	}//end testKeepsTheKeysWhenACertificateFileCannotBeRead()

	/**
	 * A client secret that cannot be decrypted is reported, left out, and keeps every key.
	 *
	 * @return void
	 */
	public function testKeepsTheKeysWhenTheSecretCannotBeDecrypted(): void {
		$this->fullConnection();
		$this->config['brp.client_secret_encrypted'] = 'garbled';

		$this->step(integriq: true, listener: self::takesEverything())->run($this->recordingOutput());

		self::assertArrayNotHasKey('clientSecret', $this->handed->getSecrets());
		self::assertSame([], $this->deleted);
		self::assertContains('warning: brp.client_secret_encrypted: not handed over, the stored client secret cannot be decrypted', $this->report);
	}//end testKeepsTheKeysWhenTheSecretCannotBeDecrypted()

	/**
	 * A listener that throws keeps every key and the failure is logged by class, not message.
	 *
	 * @return void
	 */
	public function testKeepsTheKeysWhenTheHandoverThrows(): void {
		$this->fullConnection();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('MoveBrpConnectionToIntegriq: the handover to integriq failed: RuntimeException');
		$listener = static function (): void {
			throw new RuntimeException('secret s3cret leaked in a message');
		};

		$this->step(integriq: true, listener: $listener, logger: $logger)->run($this->recordingOutput());

		self::assertSame([], $this->deleted);
		self::assertSame(['warning: The handover to integriq failed; the BRP connection settings stay in pipelinq.'], $this->report);
	}//end testKeepsTheKeysWhenTheHandoverThrows()

	/**
	 * Only the keys that are set are handed over and reported; a second run
	 * after the keys are cleared does nothing.
	 *
	 * @return void
	 */
	public function testHandsOverOnlyWhatIsSetAndIsIdempotent(): void {
		$this->config = ['brp.base_url' => 'https://brp.example/api'];

		$this->step(integriq: true, listener: self::takesEverything())->run($this->recordingOutput());

		self::assertSame(['baseUrl' => 'https://brp.example/api'], $this->handed->getSettings());
		self::assertSame([], $this->handed->getSecrets());
		self::assertSame(['info: brp.base_url: taken by integriq', 'info: integriq confirmed every part; the BRP connection keys are cleared from pipelinq.'], $this->report);

		$this->config  = [];
		$this->handed  = null;
		$this->report  = [];
		$this->step(integriq: true, listener: self::takesEverything())->run($this->recordingOutput());

		self::assertNull($this->handed);
		self::assertSame([], $this->report);
	}//end testHandsOverOnlyWhatIsSetAndIsIdempotent()
}//end class
