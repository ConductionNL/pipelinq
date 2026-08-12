<?php

/**
 * Unit tests for SlaEngineService outbound sms/whatsapp escalation dispatch.
 *
 * Exercises the dispatch matrix wired by outbound-messaging-provider-wiring:
 * sms/whatsapp escalations to `notify: customer` dispatch through the channel
 * adapters (resolved lazily via the container); every outcome maps onto the
 * `notifiedActors` marker vocabulary and no Throwable escapes the sweep.
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
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Coverage for the sms/whatsapp escalation dispatch legs + marker vocabulary.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One test per dispatch-matrix cell.
 */
class SlaEngineDispatchTest extends TestCase {
	/**
	 * Build an engine whose container resolves the given services.
	 *
	 * @param array<string, object> $services FQCN → service stub.
	 *
	 * @return SlaEngineService The engine under test.
	 */
	private function makeEngine(array $services): SlaEngineService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'register') {
					return 'pipelinq';
				}

				return $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$holidays = new HolidayCalendarService($appConfig, $logger);

		return new SlaEngineService(
			$holidays,
			new BusinessHoursCalculator($holidays, $appConfig, $logger),
			$container,
			$appConfig,
			$this->createMock(INotificationManager::class),
			$logger,
		);
	}//end makeEngine()

	/**
	 * A stub OpenRegister ObjectService returning the seeded objects by id.
	 *
	 * @param array<string, array<string, mixed>> $store Objects keyed by id.
	 *
	 * @return object The stub.
	 */
	private function objectStore(array $store): object {
		return new class($store) {
			/** @var array<string, array<string, mixed>> */
			private array $store;

			/**
			 * @param array<string, array<string, mixed>> $store Objects.
			 */
			public function __construct(array $store) {
				$this->store = $store;
			}

			/**
			 * @param string $id Object id.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}
		};
	}//end objectStore()

	/**
	 * A stub SMS/WhatsApp adapter returning a fixed outcome from send().
	 *
	 * @param string $status Outcome status.
	 * @param bool $withinWindow WhatsApp session-window answer.
	 * @param bool $throwOnSend Whether send() throws.
	 *
	 * @return object The stub.
	 */
	private function adapterStub(string $status, bool $withinWindow = false, bool $throwOnSend = false): object {
		return new class($status, $withinWindow, $throwOnSend) {
			public function __construct(
				private string $status,
				private bool $withinWindow,
				private bool $throwOnSend,
			) {
			}

			/**
			 * @param array<string, mixed> $contact Contact.
			 * @param string $body Body.
			 * @param string|null $providerHint Hint.
			 * @param string|null $templateId Template.
			 * @param array<int, string> $parameters Parameters.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return array<string, mixed>
			 */
			public function send(
				array $contact = [],
				string $body = '',
				?string $providerHint = null,
				?string $templateId = null,
				array $parameters = [],
				array $context = [],
			): array {
				if ($this->throwOnSend === true) {
					throw new \RuntimeException('provider blew up');
				}

				return ['status' => $this->status, 'messageId' => 'msg-1'];
			}

			/**
			 * @param string $contactId Contact id.
			 *
			 * @return bool
			 */
			public function isWithinSessionWindow(string $contactId): bool {
				return $this->withinWindow;
			}
		};
	}//end adapterStub()

	/**
	 * Invoke the private dispatchNotification and return the markers.
	 *
	 * @param SlaEngineService $engine Engine.
	 * @param string $channel Channel.
	 * @param string $notify Notify role.
	 * @param array<string, mixed> $step Escalation step.
	 *
	 * @return array<int, string> Notified actor markers.
	 */
	private function dispatch(SlaEngineService $engine, string $channel, string $notify, array $step = []): array {
		$method = new ReflectionMethod(SlaEngineService::class, 'dispatchNotification');
		$method->setAccessible(true);
		return $method->invokeArgs(
			$engine,
			[$channel, $notify, ['name' => 'Test policy'], 'request', 'req-1', 1, $step]
		);
	}//end dispatch()

	/**
	 * A seeded object store with a request → contact + client chain.
	 *
	 * @return object The store.
	 */
	private function seededStore(): object {
		return $this->objectStore([
			'req-1' => ['contact' => 'contact-1', 'client' => 'client-1'],
			'contact-1' => ['uuid' => 'contact-1', 'phoneNumber' => '+31611111111', 'client' => 'client-1'],
		]);
	}//end seededStore()

	/**
	 * SMS + customer + a sending adapter → the contact id marker.
	 *
	 * @return void
	 */
	public function testSmsCustomerSent(): void {
		$engine = $this->makeEngine([
			'OCA\OpenRegister\Service\ObjectService' => $this->seededStore(),
			'OCA\\Pipelinq\\Service\\SmsAdapter' => $this->adapterStub(status: 'sent'),
		]);

		$this->assertSame(['contact-1'], $this->dispatch($engine, 'sms', 'customer'));
	}//end testSmsCustomerSent()

	/**
	 * WhatsApp + customer + template → the contact id marker.
	 *
	 * @return void
	 */
	public function testWhatsAppCustomerTemplateSent(): void {
		$engine = $this->makeEngine([
			'OCA\OpenRegister\Service\ObjectService' => $this->seededStore(),
			'OCA\\Pipelinq\\Service\\WhatsAppAdapter' => $this->adapterStub(status: 'sent'),
		]);

		$this->assertSame(['contact-1'], $this->dispatch($engine, 'whatsapp', 'customer', ['templateId' => 'tpl-1']));
	}//end testWhatsAppCustomerTemplateSent()

	/**
	 * Consent-missing outcome maps to the consent-missing marker.
	 *
	 * @return void
	 */
	public function testConsentMissingMarker(): void {
		$engine = $this->makeEngine([
			'OCA\OpenRegister\Service\ObjectService' => $this->seededStore(),
			'OCA\\Pipelinq\\Service\\SmsAdapter' => $this->adapterStub(status: 'consentMissing'),
		]);

		$this->assertSame(['consent-missing:sms'], $this->dispatch($engine, 'sms', 'customer'));
	}//end testConsentMissingMarker()

	/**
	 * WhatsApp with no template and no open session window fails closed.
	 *
	 * @return void
	 */
	public function testTemplateMissingMarker(): void {
		$engine = $this->makeEngine([
			'OCA\OpenRegister\Service\ObjectService' => $this->seededStore(),
			'OCA\\Pipelinq\\Service\\WhatsAppAdapter' => $this->adapterStub(status: 'sent', withinWindow: false),
		]);

		$this->assertSame(['template-missing:whatsapp'], $this->dispatch($engine, 'whatsapp', 'customer'));
	}//end testTemplateMissingMarker()

	/**
	 * A non-customer sms/whatsapp escalation is unsupported (no dispatch).
	 *
	 * @return void
	 */
	public function testUnsupportedRoleMarker(): void {
		$engine = $this->makeEngine([
			'OCA\OpenRegister\Service\ObjectService' => $this->seededStore(),
			'OCA\\Pipelinq\\Service\\SmsAdapter' => $this->adapterStub(status: 'sent'),
		]);

		$this->assertSame(['unsupported:sms:team-lead'], $this->dispatch($engine, 'sms', 'team-lead'));
	}//end testUnsupportedRoleMarker()

	/**
	 * An absent adapter degrades to the failed marker (no Throwable escapes).
	 *
	 * @return void
	 */
	public function testAdapterAbsentDegrades(): void {
		$engine = $this->makeEngine([
			'OCA\OpenRegister\Service\ObjectService' => $this->seededStore(),
		]);

		$this->assertSame(['failed:sms'], $this->dispatch($engine, 'sms', 'customer'));
	}//end testAdapterAbsentDegrades()

	/**
	 * A Throwable from the adapter never escapes the sweep.
	 *
	 * @return void
	 */
	public function testAdapterThrowableDegrades(): void {
		$engine = $this->makeEngine([
			'OCA\OpenRegister\Service\ObjectService' => $this->seededStore(),
			'OCA\\Pipelinq\\Service\\SmsAdapter' => $this->adapterStub(status: 'sent', throwOnSend: true),
		]);

		$this->assertSame(['failed:sms'], $this->dispatch($engine, 'sms', 'customer'));
	}//end testAdapterThrowableDegrades()

	/**
	 * Email + webhook keep the deferred marker.
	 *
	 * @return void
	 */
	public function testEmailAndWebhookDeferred(): void {
		$engine = $this->makeEngine([]);

		$this->assertSame(['deferred:email:team-lead'], $this->dispatch($engine, 'email', 'team-lead'));
		$this->assertSame(['deferred:webhook:webhook'], $this->dispatch($engine, 'webhook', 'webhook'));
	}//end testEmailAndWebhookDeferred()

	/**
	 * An unresolvable customer (no linked contact/client) → unresolved marker.
	 *
	 * @return void
	 */
	public function testUnresolvedCustomer(): void {
		$engine = $this->makeEngine([
			'OCA\OpenRegister\Service\ObjectService' => $this->objectStore(['req-1' => []]),
			'OCA\\Pipelinq\\Service\\SmsAdapter' => $this->adapterStub(status: 'sent'),
		]);

		$this->assertSame(['unresolved:customer'], $this->dispatch($engine, 'sms', 'customer'));
	}//end testUnresolvedCustomer()
}//end class
