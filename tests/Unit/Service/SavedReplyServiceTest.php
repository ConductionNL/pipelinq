<?php

/**
 * Unit tests for SavedReplyService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\SavedReplyController;
use OCA\Pipelinq\Service\SavedReplyService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Saved replies on offer for a channel: active ones, the party's language
 * first, placeholders filled from the record.
 */
class SavedReplyServiceTest extends TestCase {

	private const REPLIES = [
		['id' => 'r-en', 'title' => 'Thanks', 'body' => 'Thanks {{contact.name}}, about {{ticket.title}}. {{agent.name}}', 'channels' => ['portal', 'email'], 'language' => 'en'],
		['id' => 'r-nl', 'title' => 'Bedankt', 'body' => 'Beste {{contact.name}}, {{client.name}} {{unknown.key}}', 'channels' => ['portal'], 'language' => 'nl'],
		['id' => 'r-off', 'title' => 'Old', 'body' => 'x', 'channels' => ['portal'], 'active' => false],
		['id' => 'r-sms', 'title' => 'Sms', 'body' => 'x', 'channels' => ['sms']],
	];

	public function testItOffersTheChannelsActiveRepliesTheLanguageFirstAndFilled(): void {
		$options = $this->service()->forChannel(channel: 'portal', ticketId: 't-1');

		$this->assertSame(['r-nl', 'r-en'], array_column($options, 'uuid'));
		$this->assertSame('Beste Jan Jansen, Acme {{unknown.key}}', $options[0]['values']['body']);
		$this->assertSame('Thanks Jan Jansen, about Parking permit. Agent Smith', $options[1]['values']['body']);
		$this->assertSame('Bedankt', $options[0]['name']);
	}

	public function testWithoutALanguageTheTitlesOrderThem(): void {
		$offered = SavedReplyService::forLanguage(replies: self::REPLIES, channel: 'portal', language: '');

		$this->assertSame(['r-nl', 'r-en'], array_column($offered, 'id'));
	}

	public function testAnEmptyValueLeavesThePlaceholder(): void {
		$this->assertSame('Hi {{contact.name}}', SavedReplyService::fill(body: 'Hi {{contact.name}}', values: ['contact.name' => ' ']));
	}

	public function testTheControllerAnswersNothingWithoutAChannel(): void {
		$controller = new SavedReplyController($this->createMock(IRequest::class), $this->service());

		$this->assertSame(['results' => []], $controller->index()->getData());
		$this->assertCount(2, $controller->index(channel: 'portal', ticket: 't-1')->getData()['results']);
	}

	private function service(): SavedReplyService {
		$records = [
			'sch-ticket' => ['t-1' => ['title' => 'Parking permit', 'contact' => 'c-1', 'client' => 'k-1']],
			'sch-contact' => ['c-1' => ['name' => 'Jan Jansen', 'correspondenceLanguage' => 'NL']],
			'sch-client' => ['k-1' => ['name' => 'Acme']],
		];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($records): ?ObjectEntity {
			if (isset($records[$schema][$id]) === false) {
				return null;
			}
			$entity = new ObjectEntity();
			$entity->setObject($records[$schema][$id]);
			return $entity;
		});
		$objectService->method('findAll')->willReturn(self::REPLIES);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ([
			'register' => 'reg', 'savedReply_schema' => 'sch-reply', 'ticket_schema' => 'sch-ticket', 'contact_schema' => 'sch-contact', 'client_schema' => 'sch-client',
		][$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Agent Smith');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SavedReplyService($objectService, $appConfig, $session);
	}
}
