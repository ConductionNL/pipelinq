<?php

/**
 * Unit tests for QuoteController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/products-quote-from-lead/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\QuoteController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\Letter\FilinqLetterAdapter;
use OCA\Pipelinq\Service\Quote\QuoteRefusedException;
use OCA\Pipelinq\Service\Quote\QuoteSender;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every status of the send endpoint, the recipient preview and the validity date.
 *
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */
class QuoteControllerTest extends TestCase {

	private const LEAD = '0e1f2a3b-4c5d-4e6f-8a9b-0c1d2e3f4a5b';

	/**
	 * The sender double.
	 *
	 * @var QuoteSender&MockObject
	 */
	private QuoteSender $sender;

	/**
	 * The adapter double.
	 *
	 * @var FilinqLetterAdapter&MockObject
	 */
	private FilinqLetterAdapter $letters;

	/**
	 * The leads the store holds, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $leads = [];

	/**
	 * Whether the caller is in a privileged group.
	 *
	 * @var bool
	 */
	private bool $privileged = false;

	/**
	 * A lead assigned to sanne.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sender  = $this->createMock(originalClassName: QuoteSender::class);
		$this->letters = $this->createMock(originalClassName: FilinqLetterAdapter::class);
		$this->leads   = [self::LEAD => ['title' => 'Nieuw kantoor', 'assignee' => 'sanne', 'client' => 'c-1']];
	}//end setUp()

	/**
	 * The controller over the real reader and the real access policy.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string|null          $uid    The signed-in user.
	 *
	 * @return QuoteController
	 */
	private function controller(array $params = [], ?string $uid = 'sanne'): QuoteController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => '7',
				'lead_schema' => '17',
				default => $default,
			}
		);

		$leads         = $this->leads;
		$objectService = $this->createMock(originalClassName: \OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id) use ($leads): ?ObjectEntity {
				if (isset($leads[$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string) $id);
				$entity->setObject($leads[$id]);
				return $entity;
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturn($this->privileged);

		return new QuoteController(
			request: $request,
			sender: $this->sender,
			letters: $this->letters,
			userSession: $session,
			reader: new CallerObjectReader(container: $container, appConfig: $config, logger: $this->createMock(originalClassName: LoggerInterface::class)),
			accessPolicy: new ObjectOwnerAccessPolicy(groupManager: $groups, appConfig: $config)
		);
	}//end controller()

	/**
	 * filinq is there and knows the quote template.
	 *
	 * @return void
	 */
	private function filinqPresent(): void {
		$this->letters->method('unavailableReason')->willReturn(null);
		$this->letters->method('findTemplate')->willReturnCallback(
			static fn (string $id): ?array => match ($id) {
				'tpl-q' => ['id' => 'tpl-q', 'name' => 'Offerte', 'description' => ''],
				default => null,
			}
		);
	}//end filinqPresent()

	/**
	 * Without a user nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutAUserIs401(): void {
		$this->sender->expects($this->never())->method('send');

		$this->assertSame(expected: 401, actual: $this->controller(params: ['templateId' => 'tpl-q'], uid: null)->send(id: self::LEAD)->getStatus());
		$this->assertSame(expected: 401, actual: $this->controller(uid: null)->recipient(id: self::LEAD)->getStatus());
	}//end testWithoutAUserIs401()

	/**
	 * A missing template or a bad date is a 400.
	 *
	 * @return void
	 */
	public function testAMissingTemplateOrABadDateIs400(): void {
		$this->filinqPresent();
		$this->sender->expects($this->never())->method('send');

		$this->assertSame(expected: 400, actual: $this->controller()->send(id: self::LEAD)->getStatus());
		$this->assertSame(
			expected: 400,
			actual: $this->controller(params: ['templateId' => 'tpl-q', 'validUntil' => '17-12-2026'])->send(id: self::LEAD)->getStatus()
		);
		$this->assertSame(
			expected: 400,
			actual: $this->controller(params: ['templateId' => 'tpl-q', 'validUntil' => '2020-01-01'])->send(id: self::LEAD)->getStatus()
		);
	}//end testAMissingTemplateOrABadDateIs400()

	/**
	 * Without filinq the send is a 503 that says why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function testWithoutFilinqIs503(): void {
		$this->letters->method('unavailableReason')->willReturn('filinq_absent: filinq is not installed');
		$this->sender->expects($this->never())->method('send');

		$response = $this->controller(params: ['templateId' => 'tpl-q'])->send(id: self::LEAD);

		$this->assertSame(expected: 503, actual: $response->getStatus());
		$this->assertSame(expected: 'filinq_absent: filinq is not installed', actual: $response->getData()['reason']);
	}//end testWithoutFilinqIs503()

	/**
	 * A lead that is absent or not the caller's answers 404, for both endpoints.
	 *
	 * @return void
	 */
	public function testALeadTheCallerMayNotReadIs404(): void {
		$this->filinqPresent();
		$this->sender->expects($this->never())->method('send');
		$this->sender->expects($this->never())->method('recipientFor');

		$this->assertSame(expected: 404, actual: $this->controller(params: ['templateId' => 'tpl-q'])->send(id: 'no-such-lead')->getStatus());
		$this->assertSame(expected: 404, actual: $this->controller(params: ['templateId' => 'tpl-q'], uid: 'piet')->send(id: self::LEAD)->getStatus());
		$this->assertSame(expected: 404, actual: $this->controller(uid: 'piet')->recipient(id: self::LEAD)->getStatus());
	}//end testALeadTheCallerMayNotReadIs404()

	/**
	 * A privileged user may send a quote from someone else's lead.
	 *
	 * @return void
	 */
	public function testAPrivilegedUserMaySendFromAnotherLead(): void {
		$this->filinqPresent();
		$this->privileged = true;
		$this->sender->expects($this->once())->method('send')->willReturn(['number' => 'OFF-2026-0042']);

		$this->assertSame(expected: 200, actual: $this->controller(params: ['templateId' => 'tpl-q'], uid: 'manager')->send(id: self::LEAD)->getStatus());
	}//end testAPrivilegedUserMaySendFromAnotherLead()

	/**
	 * A template that is not pipelinq's is a 404.
	 *
	 * @return void
	 */
	public function testAnUnknownTemplateIs404(): void {
		$this->filinqPresent();
		$this->sender->expects($this->never())->method('send');

		$response = $this->controller(params: ['templateId' => 'tpl-other'])->send(id: self::LEAD);

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertSame(expected: 'Template not found', actual: $response->getData()['message']);
	}//end testAnUnknownTemplateIs404()

	/**
	 * The lead, the template and the date reach the sender; its answer is the response.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function testSendsTheQuote(): void {
		$this->filinqPresent();
		$this->sender->expects($this->once())->method('send')
			->with(
				self::LEAD,
				['title' => 'Nieuw kantoor', 'assignee' => 'sanne', 'client' => 'c-1', 'id' => self::LEAD],
				'tpl-q',
				'2099-12-17'
			)
			->willReturn(['number' => 'OFF-2026-0042', 'contactMomentId' => 'cm-1']);

		$response = $this->controller(params: ['templateId' => 'tpl-q', 'validUntil' => '2099-12-17'])->send(id: self::LEAD);

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['number' => 'OFF-2026-0042', 'contactMomentId' => 'cm-1'], actual: $response->getData());
	}//end testSendsTheQuote()

	/**
	 * A refusal from the sender keeps its status, message and details.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatusAndDetails(): void {
		$this->filinqPresent();
		$this->sender->method('send')->willThrowException(
			new QuoteRefusedException(
				message: 'The quote was made and filed, but the mail could not be sent.',
				status: 502,
				details: ['path' => '/Pipelinq/q.pdf']
			)
		);

		$response = $this->controller(params: ['templateId' => 'tpl-q'])->send(id: self::LEAD);

		$this->assertSame(expected: 502, actual: $response->getStatus());
		$this->assertSame(
			expected: ['message' => 'The quote was made and filed, but the mail could not be sent.', 'path' => '/Pipelinq/q.pdf'],
			actual: $response->getData()
		);
	}//end testARefusalKeepsItsStatusAndDetails()

	/**
	 * The recipient preview answers who the quote would go to, or null.
	 *
	 * @return void
	 */
	public function testTheRecipientPreview(): void {
		$this->sender->method('recipientFor')->willReturnOnConsecutiveCalls(
			['email' => 'kees@degraaf.example', 'name' => 'Kees Bakker', 'source' => 'contact'],
			null
		);

		$this->assertSame(
			expected: ['recipient' => ['email' => 'kees@degraaf.example', 'name' => 'Kees Bakker', 'source' => 'contact']],
			actual: $this->controller()->recipient(id: self::LEAD)->getData()
		);
		$this->assertSame(expected: ['recipient' => null], actual: $this->controller()->recipient(id: self::LEAD)->getData());
	}//end testTheRecipientPreview()

	/**
	 * The validity date: thirty days without one, the date asked for, never the past.
	 *
	 * @return void
	 */
	public function testTheValidityDate(): void {
		$today = new DateTimeImmutable('2026-11-17');

		$this->assertSame(expected: '2026-12-17', actual: QuoteController::validUntil(value: '', today: $today));
		$this->assertSame(expected: '2026-11-17', actual: QuoteController::validUntil(value: '2026-11-17', today: $today));
		$this->assertNull(actual: QuoteController::validUntil(value: '2026-11-16', today: $today));
		$this->assertNull(actual: QuoteController::validUntil(value: '2026-02-30', today: $today));
	}//end testTheValidityDate()
}//end class
