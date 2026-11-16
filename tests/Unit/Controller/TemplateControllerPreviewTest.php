<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\TemplateController;
use OCA\Pipelinq\Kernel\Contracts\ArticleServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ComplianceServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PhysicalAddressRendererInterface;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http;
use OCP\Defaults;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Show, preview and the result mapping of the template routes.
 *
 * The services behind the controller are mocked; the block renderer is the
 * real one, so the theming colour is checked in the HTML it produces.
 *
 * @covers \OCA\Pipelinq\Controller\TemplateController
 * @uses \OCA\Pipelinq\Controller\CrmCallerGate
 * @uses \OCA\Pipelinq\Service\Marketing\MailBlockRenderer
 */
class TemplateControllerPreviewTest extends TestCase {

	/**
	 * The compliance service.
	 *
	 * @var ComplianceServiceInterface&MockObject
	 */
	private ComplianceServiceInterface&MockObject $compliance;

	/**
	 * The article service.
	 *
	 * @var ArticleServiceInterface&MockObject
	 */
	private ArticleServiceInterface&MockObject $articles;

	/**
	 * The ids the article service was asked to load.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $loaded = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed> $params     Request parameters.
	 * @param bool                 $privileged Whether the caller is privileged.
	 * @param ?Defaults            $defaults   Theming defaults.
	 *
	 * @return TemplateController
	 */
	private function controller(array $params = [], bool $privileged = true, ?Defaults $defaults = null): TemplateController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('marketer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);

		$this->compliance = $this->createMock(ComplianceServiceInterface::class);
		$this->articles   = $this->createMock(ArticleServiceInterface::class);
		$this->articles->method('loadArticlesByIds')->willReturnCallback(
			function (array $articleIds): array {
				$this->loaded[] = $articleIds;
				return [
					['title' => 'Spring news', 'summary' => 'What changed', 'body' => 'ignored'],
					['title' => 'No summary'],
				];
			}
		);
		$this->articles->method('expandArticlesMarker')->willReturnCallback(
			static fn (string $body, array $articles, string $format): string => $body.'|'.$format.'+'.count($articles)
		);

		$address = $this->createMock(PhysicalAddressRendererInterface::class);
		$address->method('render')->willReturnCallback(
			static fn (string $body, string $footerOverride, string $format): string => $body.'#'.$footerOverride
		);

		if ($defaults === null) {
			return new TemplateController($request, $this->compliance, $this->articles, $session, $policy, $address);
		}

		return new TemplateController($request, $this->compliance, $this->articles, $session, $policy, $address, defaults: $defaults);
	}//end controller()

	/**
	 * Show returns the stored template.
	 *
	 * @return void
	 */
	public function testShowReturnsTheTemplate(): void {
		$controller = $this->controller();
		$this->compliance->method('getTemplateById')->with('welcome')->willReturn(['id' => 'welcome', 'subject' => 'Hi']);

		$response = $controller->show('welcome');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'welcome', 'subject' => 'Hi'], $response->getData());
	}//end testShowReturnsTheTemplate()

	/**
	 * Show and preview answer 404 for an unknown template.
	 *
	 * @return void
	 */
	public function testUnknownTemplatesAreNotFound(): void {
		$controller = $this->controller();
		$this->compliance->method('getTemplateById')->willReturn(null);

		$this->assertSame(['error' => 'Not found'], $controller->show('gone')->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('gone')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->preview('gone')->getStatus());
		$this->assertSame([], $this->loaded);
	}//end testUnknownTemplatesAreNotFound()

	/**
	 * An unprivileged caller gets 403 from show and preview.
	 *
	 * @return void
	 */
	public function testAnUnprivilegedCallerIsRefused(): void {
		$controller = $this->controller(privileged: false);
		$this->compliance->expects($this->never())->method('getTemplateById');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->show('welcome')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->preview('welcome')->getStatus());
	}//end testAnUnprivilegedCallerIsRefused()

	/**
	 * Preview expands both bodies with the template's articles and footer.
	 *
	 * @return void
	 */
	public function testPreviewExpandsBothBodies(): void {
		$controller = $this->controller();
		$this->compliance->method('getTemplateById')->willReturn(
			[
				'subject' => 'Newsletter',
				'bodyHtml' => '<p>H</p>',
				'bodyText' => 'T',
				'articleIds' => ['a1', 'a2'],
				'footerOverride' => 'Street 1',
			]
		);

		$data = $controller->preview('news')->getData();

		$this->assertSame(['a1', 'a2'], $this->loaded[0]);
		$this->assertSame(
			[
				'subject' => 'Newsletter',
				'bodyHtml' => '<p>H</p>|html+2#Street 1',
				'bodyText' => 'T|text+2#Street 1',
				'articles' => [
					['title' => 'Spring news', 'summary' => 'What changed'],
					['title' => 'No summary', 'summary' => ''],
				],
			],
			$data
		);
	}//end testPreviewExpandsBothBodies()

	/**
	 * A template with missing fields and a non-list of article ids previews empty bodies.
	 *
	 * @return void
	 */
	public function testPreviewToleratesAnIncompleteTemplate(): void {
		$controller = $this->controller();
		$this->compliance->method('getTemplateById')->willReturn(['articleIds' => 'a1']);

		$data = $controller->preview('bare')->getData();

		$this->assertSame([], $this->loaded[0]);
		$this->assertSame('', $data['subject']);
		$this->assertSame('|html+2#', $data['bodyHtml']);
		$this->assertSame('|text+2#', $data['bodyText']);
	}//end testPreviewToleratesAnIncompleteTemplate()

	/**
	 * A missing template on update is 404; any other service error is 400.
	 *
	 * @return void
	 */
	public function testServiceErrorsMapToTheirStatus(): void {
		$controller = $this->controller(['subject' => 'New']);
		$this->compliance->method('patchTemplate')->willReturnOnConsecutiveCalls(
			['error' => 'Template not found'],
			['error' => 'Subject is required'],
			['template' => ['id' => 't', 'subject' => 'New']],
		);

		$missing = $controller->update('t');
		$invalid = $controller->update('t');
		$saved   = $controller->update('t');

		$this->assertSame([Http::STATUS_NOT_FOUND, ['error' => 'Template not found']], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'Subject is required']], [$invalid->getStatus(), $invalid->getData()]);
		$this->assertSame([Http::STATUS_OK, ['id' => 't', 'subject' => 'New']], [$saved->getStatus(), $saved->getData()]);
	}//end testServiceErrorsMapToTheirStatus()

	/**
	 * The article ids sent with a save are passed on to the service.
	 *
	 * @return void
	 */
	public function testSaveCarriesTheArticleIds(): void {
		$controller = $this->controller(['subject' => 'S', 'articleIds' => ['a9']]);
		$this->compliance->expects($this->once())->method('patchTemplate')->with(
			't',
			$this->callback(static fn (array $patch): bool => ($patch['articleIds'] ?? null) === ['a9'] && $patch['subject'] === 'S')
		)->willReturn(['template' => []]);

		$controller->update('t');
	}//end testSaveCarriesTheArticleIds()

	/**
	 * A Blocks save without a block list still renders the footer, in the theming colour.
	 *
	 * @return void
	 */
	public function testBlocksSaveUsesTheThemingColour(): void {
		$defaults = $this->createMock(Defaults::class);
		$defaults->method('getColorPrimary')->willReturn('#123abc');
		$controller = $this->controller(['editorMode' => 'blocks', 'blocks' => 'not-a-list'], defaults: $defaults);

		$this->compliance->expects($this->once())->method('patchTemplate')->with(
			't',
			$this->callback(
				static fn (array $patch): bool => $patch['editorMode'] === 'blocks'
					&& count($patch['blocks']) === 1
					&& $patch['blocks'][0]['type'] === 'footer'
					&& str_contains($patch['bodyHtml'], '<table role="presentation"')
			)
		)->willReturn(['template' => []]);

		$controller->update('t');

		$rendered = $this->controller(
			['blocks' => [['type' => 'button', 'props' => ['label' => 'Go', 'href' => 'https://www.example.nl']]]],
			defaults: $defaults
		)->render()->getData();
		$this->assertStringContainsString('#123abc', $rendered['renderedHtml']);
	}//end testBlocksSaveUsesTheThemingColour()
}//end class
