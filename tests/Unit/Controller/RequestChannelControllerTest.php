<?php

/**
 * Unit tests for RequestChannelController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\RequestChannelController;
use OCA\Pipelinq\Service\SystemTagService;
use OCA\Pipelinq\Service\VocabularyUsage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RequestChannelController.
 */
class RequestChannelControllerTest extends TestCase {
	/**
	 * The controller under test.
	 *
	 * @var RequestChannelController
	 */
	private RequestChannelController $controller;

	/**
	 * Mock system tag service.
	 *
	 * @var SystemTagService
	 */
	private SystemTagService $tagService;

	/**
	 * The usage counter.
	 *
	 * @var VocabularyUsage
	 */
	private VocabularyUsage $usage;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$this->tagService = $this->createMock(SystemTagService::class);
		$this->usage = $this->createMock(VocabularyUsage::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('test-user');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new RequestChannelController($request, $this->tagService, $this->usage, $this->l10n(),
			$this->userSession,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Test index returns tags.
	 *
	 * @return void
	 */
	public function testIndexReturnsTags(): void {
		$this->tagService->method('getTags')->willReturn([
			['id' => 1, 'name' => 'Email'],
		]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}//end testIndexReturnsTags()

	/**
	 * Test destroy returns success.
	 *
	 * @return void
	 */
	public function testDestroyReturnsSuccess(): void {
		$this->tagService->expects($this->once())->method('removeTag');

		$response = $this->controller->destroy('5');

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}//end testDestroyReturnsSuccess()

	/**
	 * Test destroy returns error on exception.
	 *
	 * @return void
	 */
	public function testDestroyReturnsErrorOnException(): void {
		$this->tagService->method('removeTag')
			->willThrowException(new \RuntimeException('Not found'));

		$response = $this->controller->destroy('99');

		$this->assertSame(500, $response->getStatus());
	}//end testDestroyReturnsErrorOnException()

	/**
	 * An IL10N double that fills the placeholders.
	 *
	 * @return IL10N The double.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $vars = []): string => (string) preg_replace_callback(
				'/%(\d+)\$s/',
				static fn (array $m): string => (string) ($vars[((int) $m[1]) - 1] ?? ''),
				$text
			)
		);
		return $l10n;
	}//end l10n()

	/**
	 * A value still in use is not removed, and the answer says why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-a-vocabulary-value-in-use-cannot-be-removed
	 */
	public function testDestroyRefusesAValueStillInUse(): void {
		$this->tagService->method('getTags')->willReturn([['id' => 5, 'name' => 'Email']]);
		$this->usage->expects($this->once())->method('countUses')
			->with('ticket_schema', 'channel', 'Email', ['ticketType' => 'request'])
			->willReturn(3);
		$this->tagService->expects($this->never())->method('removeTag');

		$response = $this->controller->destroy('5');

		$this->assertSame(409, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('Email is still used by 3 requests. Give them another channel first.', $response->getData()['message']);
	}//end testDestroyRefusesAValueStillInUse()

	/**
	 * A value nothing uses is removed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-a-vocabulary-value-in-use-cannot-be-removed
	 */
	public function testDestroyRemovesAnUnusedValue(): void {
		$this->tagService->method('getTags')->willReturn([['id' => 5, 'name' => 'Email']]);
		$this->usage->method('countUses')->willReturn(0);
		$this->tagService->expects($this->once())->method('removeTag')->with(objectType: $this->anything(), tagId: 5);

		$response = $this->controller->destroy('5');

		$this->assertTrue($response->getData()['success']);
	}//end testDestroyRemovesAnUnusedValue()
}//end class
