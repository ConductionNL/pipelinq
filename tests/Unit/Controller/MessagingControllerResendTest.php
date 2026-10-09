<?php

/**
 * Send again on a failed WhatsApp or SMS message.
 *
 * Drives the real MessagingController over the real MessagingService. Only
 * OpenRegister's ObjectService (an in-memory store with OR's find/saveObject
 * signatures) and the channel adapters are replaced, so the refusals, the
 * resend through the normal send() and the two-way link between the rows are
 * the production code.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MessagingController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\MessagingService;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * MessagingController::resend() coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MessagingControllerResendTest extends TestCase {
	/**
	 * In-memory OpenRegister store.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * SMS adapter.
	 *
	 * @var SmsAdapter&MockObject
	 */
	private SmsAdapter $smsAdapter;

	/**
	 * WhatsApp adapter.
	 *
	 * @var WhatsAppAdapter&MockObject
	 */
	private WhatsAppAdapter $whatsAppAdapter;

	/**
	 * Session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Build the store and the adapters.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new class {
			/** @var array<string, array<string, mixed>> */
			public array $rows = [];

			/**
			 * OR's find(): null for an id the caller may not read.
			 *
			 * @param string $id Id.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->rows[$id] ?? null);
			}

			/**
			 * OR's saveObject().
			 *
			 * @param array<string, mixed> $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Id.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				$uuid = ($uuid ?? (string)($object['id'] ?? ('row-' . count($this->rows))));
				$object['id'] = $uuid;
				$this->rows[$uuid] = $object;
				return $object;
			}
		};

		$this->store->rows['contact-1'] = ['id' => 'contact-1', 'name' => 'Jan de Vries', 'phoneNumber' => '+31611111111'];
		$this->smsAdapter = $this->createMock(SmsAdapter::class);
		$this->whatsAppAdapter = $this->createMock(WhatsAppAdapter::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent-1');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * The controller over the real service.
	 *
	 * @param bool $privileged Whether the caller has CRM access.
	 *
	 * @return MessagingController
	 */
	private function controller(bool $privileged = true): MessagingController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->store;
				}
				throw new \RuntimeException('not registered: ' . $id);
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$service = new MessagingService(
			$container,
			$appConfig,
			$this->createMock(ChannelProviderRepository::class),
			$this->smsAdapter,
			$this->whatsAppAdapter,
			$this->createMock(ConsentService::class),
			$this->createMock(LoggerInterface::class),
		);

		return new MessagingController(
			$this->createMock(IRequest::class),
			$service,
			$this->createMock(ChannelProviderRepository::class),
			$this->createMock(ConsentService::class),
			$this->userSession,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $privileged]),
		);
	}//end controller()

	/**
	 * Seed one outbound SMS row.
	 *
	 * @param string $status Delivery status.
	 * @param array<string, mixed> $extra Extra fields.
	 *
	 * @return void
	 */
	private function seedSms(string $status, array $extra = []): void {
		$this->store->rows['msg-1'] = array_merge(
			[
				'id' => 'msg-1',
				'contactId' => 'contact-1',
				'channel' => 'sms',
				'direction' => 'outbound',
				'body' => 'We are open until five.',
				'deliveryStatus' => $status,
				'metadata' => ['error' => 'rejected'],
			],
			$extra
		);
	}//end seedSms()

	/**
	 * Without CRM access the caller is refused and nothing is sent.
	 *
	 * @return void
	 */
	public function testACallerWithoutCrmAccessIsRefused(): void {
		$this->seedSms(status: 'failed');
		$this->smsAdapter->expects($this->never())->method('send');

		$response = $this->controller(privileged: false)->resend(id: 'msg-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testACallerWithoutCrmAccessIsRefused()

	/**
	 * A message the caller cannot read answers 404.
	 *
	 * @return void
	 */
	public function testAnUnreadableMessageIsNotFound(): void {
		$this->smsAdapter->expects($this->never())->method('send');

		$response = $this->controller()->resend(id: 'msg-unknown');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnreadableMessageIsNotFound()

	/**
	 * A sent message is not sent again.
	 *
	 * @return void
	 */
	public function testASentMessageIsAConflict(): void {
		$this->seedSms(status: 'sent');
		$this->smsAdapter->expects($this->never())->method('send');

		$response = $this->controller()->resend(id: 'msg-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testASentMessageIsAConflict()

	/**
	 * An inbound message is never sent.
	 *
	 * @return void
	 */
	public function testAnInboundMessageIsAConflict(): void {
		$this->seedSms(status: 'failed', extra: ['direction' => 'inbound']);
		$this->smsAdapter->expects($this->never())->method('send');

		$response = $this->controller()->resend(id: 'msg-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testAnInboundMessageIsAConflict()

	/**
	 * A message that was already sent again is not sent a second time.
	 *
	 * @return void
	 */
	public function testAMessageAlreadySentAgainIsAConflict(): void {
		$this->seedSms(status: 'failed', extra: ['metadata' => ['resentAs' => 'msg-0']]);
		$this->smsAdapter->expects($this->never())->method('send');

		$response = $this->controller()->resend(id: 'msg-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testAMessageAlreadySentAgainIsAConflict()

	/**
	 * A failed SMS goes out again with the same text, and the two rows link.
	 *
	 * @return void
	 */
	public function testAFailedSmsIsSentAgainAndLinked(): void {
		$this->seedSms(status: 'failed');
		$this->smsAdapter->expects($this->once())->method('send')
			->with(
				$this->callback(static fn (array $contact): bool => ($contact['id'] ?? '') === 'contact-1'),
				'We are open until five.',
			)
			->willReturnCallback(
				function (): array {
					$this->store->rows['msg-2'] = ['id' => 'msg-2', 'channel' => 'sms', 'direction' => 'outbound', 'deliveryStatus' => 'sent', 'body' => 'We are open until five.'];
					return ['status' => 'sent', 'messageId' => 'msg-2'];
				}
			);

		$response = $this->controller()->resend(id: 'msg-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'sent', 'messageId' => 'msg-2'], $response->getData());
		$this->assertSame('failed', $this->store->rows['msg-1']['deliveryStatus']);
		$this->assertSame('msg-2', $this->store->rows['msg-1']['metadata']['resentAs']);
		$this->assertSame('rejected', $this->store->rows['msg-1']['metadata']['error']);
		$this->assertSame('msg-1', $this->store->rows['msg-2']['metadata']['resendOf']);
	}//end testAFailedSmsIsSentAgainAndLinked()

	/**
	 * A failed template send repeats the template with its parameters.
	 *
	 * @return void
	 */
	public function testAFailedTemplateSendRepeatsTheTemplate(): void {
		$this->store->rows['msg-1'] = [
			'id' => 'msg-1',
			'contactId' => 'contact-1',
			'channel' => 'whatsapp',
			'direction' => 'outbound',
			'body' => '[template:afspraak_nl]',
			'templateId' => 'tpl-9',
			'templateParameters' => ['Jan', 'vrijdag'],
			'deliveryStatus' => 'expired',
		];
		$this->whatsAppAdapter->expects($this->once())->method('send')
			->with($this->anything(), '', 'tpl-9', ['Jan', 'vrijdag'])
			->willReturn(['status' => 'sessionWindowExpired']);

		$response = $this->controller()->resend(id: 'msg-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('template-required', $response->getData()['status']);
		$this->assertArrayNotHasKey('metadata', $this->store->rows['msg-1']);
	}//end testAFailedTemplateSendRepeatsTheTemplate()
}//end class
