<?php

/**
 * Unit tests for TemplateController's save paths.
 *
 * pipelinq#2075: a marketer could fill in the Reply-to email on a mail
 * template, save, and the value was gone. The controller's body collector
 * did not read `replyTo`, and the template service did not store it. These
 * tests drive the controller over the REAL ComplianceService, with only the
 * object store and the platform services doubled, and read back what was
 * written.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\TemplateController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\Marketing\PhysicalAddressRenderer;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory object store for campaign templates.
 */
class TemplateStoreDouble {
	/**
	 * Stored templates keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $store = [];

	/**
	 * Every payload handed to saveObject(), in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Return a stored template.
	 *
	 * @param string $id The template uuid.
	 * @param mixed $register The register.
	 * @param mixed $schema The schema.
	 *
	 * @return array<string, mixed>|null The template, or null.
	 */
	public function find(string $id, $register = null, $schema = null): ?array {
		return ($this->store[$id] ?? null);
	}//end find()

	/**
	 * Store a template and return it.
	 *
	 * @param array<string, mixed> $object The payload.
	 * @param mixed $register The register.
	 * @param mixed $schema The schema.
	 * @param string|null $uuid The uuid, for an update.
	 *
	 * @return array<string, mixed> The stored template.
	 */
	public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
		$uuid = ($uuid ?? ('tpl-' . count($this->saved)));
		$object['uuid'] = $uuid;
		$this->saved[] = $object;
		$this->store[$uuid] = $object;

		return $object;
	}//end saveObject()
}//end class

/**
 * Tests for the template create and update paths.
 */
class TemplateControllerTest extends TestCase {

	/**
	 * A compliant email body: it carries the unsubscribe link and a place for the address.
	 *
	 * @var string
	 */
	private const BODY = '<p>Hello</p><p>{{physical_address}}</p><p>{{unsubscribe_link}}</p>';

	/**
	 * The physical address every email template must carry.
	 *
	 * @var string
	 */
	private const ADDRESS = 'Voorbeeldstraat 1, 1234 AB Amsterdam';

	/**
	 * The object store the real ComplianceService writes to.
	 *
	 * @var TemplateStoreDouble
	 */
	private TemplateStoreDouble $objects;

	/**
	 * Build the controller over the real ComplianceService.
	 *
	 * @param array<string, mixed> $params The request body.
	 *
	 * @return TemplateController The controller under test.
	 */
	private function controller(array $params): TemplateController {
		$this->objects = ($this->objects ?? new TemplateStoreDouble());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objects;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$compliance = new ComplianceService(
			$container,
			$appConfig,
			$this->createMock(SegmentService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SegmentSignalService::class),
			new \OCA\Pipelinq\Service\IntegriqMarketingConsent(\OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq::client($appConfig), new \OCA\Pipelinq\Service\ContactAddressLookup($container, $appConfig, new \Psr\Log\NullLogger())),
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('marketer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn(true);

		// The articles expansion passes the body through: no article is picked.
		$articles = $this->createMock(ArticleService::class);
		$articles->method('loadArticlesByIds')->willReturn([]);
		$articles->method('expandArticlesMarker')->willReturnArgument(0);

		return new TemplateController(
			$request,
			$compliance,
			$articles,
			$session,
			$policy,
			new PhysicalAddressRenderer(),
		);
	}//end controller()

	/**
	 * Creating a template stores the reply-to the marketer entered.
	 *
	 * @return void
	 */
	public function testCreateStoresTheReplyTo(): void {
		$response = $this->controller(
			[
				'name' => 'Renewal reminder',
				'channel' => 'email',
				'bodyHtml' => self::BODY,
				'footerOverride' => self::ADDRESS,
				'replyTo' => 'reply@example.nl',
			]
		)->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertCount(1, $this->objects->saved);
		$this->assertSame('reply@example.nl', ($this->objects->saved[0]['replyTo'] ?? null));
	}//end testCreateStoresTheReplyTo()

	/**
	 * Saving an existing template stores a changed reply-to.
	 *
	 * @return void
	 */
	public function testUpdateStoresTheReplyTo(): void {
		$this->objects = new TemplateStoreDouble();
		$this->objects->store['tpl-1'] = [
			'uuid' => 'tpl-1',
			'name' => 'Renewal reminder',
			'channel' => 'email',
			'bodyHtml' => self::BODY,
			'footerOverride' => self::ADDRESS,
			'replyTo' => '',
		];

		$response = $this->controller(
			[
				'name' => 'Renewal reminder',
				'channel' => 'email',
				'bodyHtml' => self::BODY,
				'footerOverride' => self::ADDRESS,
				'replyTo' => 'renewals@example.nl',
			]
		)->update('tpl-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('renewals@example.nl', ($this->objects->store['tpl-1']['replyTo'] ?? null));
	}//end testUpdateStoresTheReplyTo()

	/**
	 * A PATCH that carries only the name keeps every field it leaves out.
	 *
	 * @return void
	 */
	public function testPartialUpdateKeepsTheFieldsItLeavesOut(): void {
		$this->objects = new TemplateStoreDouble();
		$this->objects->store['tpl-1'] = [
			'uuid' => 'tpl-1',
			'name' => 'Renewal reminder',
			'channel' => 'email',
			'bodyHtml' => self::BODY,
			'footerOverride' => self::ADDRESS,
			'replyTo' => 'renewals@example.nl',
		];

		$response = $this->controller(['name' => 'Renewal reminder 2027'])->update('tpl-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$stored = $this->objects->store['tpl-1'];
		$this->assertSame('Renewal reminder 2027', ($stored['name'] ?? null));
		$this->assertSame(self::BODY, ($stored['bodyHtml'] ?? null));
		$this->assertSame(self::ADDRESS, ($stored['footerOverride'] ?? null));
		$this->assertSame('renewals@example.nl', ($stored['replyTo'] ?? null));
	}//end testPartialUpdateKeepsTheFieldsItLeavesOut()

	/**
	 * Validating a template that does not exist is a 404.
	 *
	 * @return void
	 */
	public function testValidateReturnsNotFoundForAnUnknownTemplate(): void {
		$this->objects = new TemplateStoreDouble();

		$response = $this->controller([])->validate('tpl-missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testValidateReturnsNotFoundForAnUnknownTemplate()

	/**
	 * An email template without a physical address fails validation, and nothing is saved.
	 *
	 * @return void
	 */
	public function testValidateRefusesAnEmailTemplateWithoutAnAddress(): void {
		$this->objects = new TemplateStoreDouble();
		$this->objects->store['tpl-1'] = [
			'uuid' => 'tpl-1',
			'name' => 'Renewal reminder',
			'channel' => 'email',
			'bodyHtml' => self::BODY,
			'footerOverride' => '',
		];

		$response = $this->controller([])->validate('tpl-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['valid']);
		$this->assertIsString($data['error']);
		$this->assertSame([], $this->objects->saved);
	}//end testValidateRefusesAnEmailTemplateWithoutAnAddress()

	/**
	 * A compliant email template passes validation.
	 *
	 * @return void
	 */
	public function testValidateAcceptsACompliantTemplate(): void {
		$this->objects = new TemplateStoreDouble();
		$this->objects->store['tpl-1'] = [
			'uuid' => 'tpl-1',
			'name' => 'Renewal reminder',
			'channel' => 'email',
			'bodyHtml' => self::BODY,
			'footerOverride' => self::ADDRESS,
		];

		$response = $this->controller([])->validate('tpl-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['valid' => true, 'error' => null], $response->getData());
	}//end testValidateAcceptsACompliantTemplate()
	/**
	 * Blocks are rendered on save, and the result passes compliance.
	 *
	 * The heading carries a script: it is stored as text. No body is sent;
	 * the footer block brings the unsubscribe token by itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-saved-blocks-become-mail-safe-html-req-mbe-003
	 */
	public function testABlocksTemplateIsRenderedOnSaveAndPassesCompliance(): void {
		$response = $this->controller(
			[
				'name' => 'Autumn news',
				'channel' => 'email',
				'editorMode' => 'blocks',
				'bodyHtml' => '<p>ignored</p>',
				'blocks' => [
					['id' => 'h', 'type' => 'heading', 'props' => ['text' => '<script>alert(1)</script>', 'level' => 1]],
					['id' => 'f', 'type' => 'footer', 'props' => ['text' => 'Sent by Zuiddrecht']],
				],
				'footerOverride' => self::ADDRESS,
			]
		)->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$saved = $this->objects->saved[0];
		$this->assertSame('blocks', $saved['editorMode']);
		$this->assertSame(['heading', 'footer'], array_column($saved['blocks'], 'type'));
		$this->assertStringContainsString('{{unsubscribe_link}}', $saved['bodyHtml']);
		$this->assertStringNotContainsString('<script', $saved['bodyHtml']);
		$this->assertStringNotContainsString('ignored', $saved['bodyHtml']);
		$this->assertStringContainsString('Sent by Zuiddrecht', $saved['bodyText']);
	}//end testABlocksTemplateIsRenderedOnSaveAndPassesCompliance()

	/**
	 * A template saved without blocks stays an HTML template.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-html-templates-keep-working-req-mbe-004
	 */
	public function testAnHtmlTemplateKeepsItsBody(): void {
		$this->controller(
			['name' => 'Plain', 'channel' => 'email', 'bodyHtml' => self::BODY, 'footerOverride' => self::ADDRESS]
		)->create();

		$saved = $this->objects->saved[0];
		$this->assertSame('html', $saved['editorMode']);
		$this->assertSame([], $saved['blocks']);
		$this->assertSame(self::BODY, $saved['bodyHtml']);
	}//end testAnHtmlTemplateKeepsItsBody()

	/**
	 * The preview renders unsaved blocks and places the address as the send does.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-the-preview-shows-what-will-be-sent-req-mbe-002
	 */
	public function testRenderPreviewsUnsavedBlocks(): void {
		$response = $this->controller(
			[
				'blocks' => [['id' => 'b', 'type' => 'button', 'props' => ['label' => 'Read more', 'href' => 'https://www.example.nl']]],
				'footerOverride' => self::ADDRESS,
			]
		)->render();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertStringContainsString('href="https://www.example.nl"', $data['bodyHtml']);
		$this->assertStringContainsString('Voorbeeldstraat 1', $data['bodyHtml']);
		$this->assertStringContainsString('Read more: https://www.example.nl', $data['bodyText']);
		$this->assertStringContainsString('{{physical_address}}', $data['renderedHtml']);
		$this->assertStringNotContainsString('Voorbeeldstraat 1', $data['renderedHtml']);
		$this->assertCount(0, $this->objects->saved);
	}//end testRenderPreviewsUnsavedBlocks()
}//end class
