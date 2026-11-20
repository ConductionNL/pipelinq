<?php

/**
 * Where a website chat is kept: the lookups past RBAC and the writes through OpenRegister.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebChat;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Service\WebChat\WebChatStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * REQ-WCH-002 on the store.
 *
 * @covers \OCA\Pipelinq\Service\WebChat\WebChatStore
 */
class WebChatStoreTest extends TestCase {

	/**
	 * The calls the fake object service received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * A store over a fake object service answering these rows.
	 *
	 * @param list<array<string, mixed>>  $rows  What findAll answers.
	 * @param array<string, mixed>|null   $found What find answers.
	 *
	 * @return WebChatStore
	 */
	private function store(array $rows=[], ?array $found=null): WebChatStore {
		$test    = $this;
		$objects = new class($rows, $found, $test) {
			/**
			 * Constructor.
			 *
			 * @param list<array<string, mixed>> $rows  The rows.
			 * @param array<string, mixed>|null  $found The row find answers.
			 * @param WebChatStoreTest           $test  The test that records the calls.
			 */
			public function __construct(private array $rows, private ?array $found, private WebChatStoreTest $test) {
			}

			/**
			 * Record and answer the rows.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Multitenancy.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				$this->test->record(['findAll', $config, $_rbac, $_multitenancy]);
				return $this->rows;
			}

			/**
			 * Record and answer the row.
			 *
			 * @param string                 $id            The id.
			 * @param ObjectFindOptions|null $options       The options.
			 * @param bool                   $_rbac         RBAC.
			 * @param bool                   $_multitenancy Multitenancy.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, ?ObjectFindOptions $options=null, bool $_rbac=true, bool $_multitenancy=true): ?array {
				$this->test->record(['find', $id, $options->schema, $_rbac]);
				return $this->found;
			}

			/**
			 * Record and answer the row written.
			 *
			 * @param array<string, mixed> $object  The row.
			 * @param ObjectSaveOptions    $options The options.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options=new ObjectSaveOptions()): array {
				$this->test->record(['save', $object, $options->target->register, $options->target->schema, $options->uuid]);
				return array_merge($object, ['id' => ($options->uuid ?? 'new-id')]);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		return new WebChatStore(container: $container, appConfig: $config);
	}//end store()

	/**
	 * Record a call.
	 *
	 * @param array<int, mixed> $call The call.
	 *
	 * @return void
	 */
	public function record(array $call): void {
		$this->calls[] = $call;
	}//end record()

	/**
	 * Only an active channel with exactly this token is found, read past RBAC; a malformed token reads nothing.
	 *
	 * @return void
	 */
	public function testOnlyAnActiveChannelWithThisTokenIsFound(): void {
		$token = 'abcdefghijklmnop1234';
		$rows  = [
			['id' => 'a', 'channelToken' => strtoupper($token), 'active' => true],
			['id' => 'b', 'channelToken' => $token, 'active' => false],
			['id' => 'c', 'channelToken' => $token, 'active' => true],
		];

		$this->assertSame('c', $this->store(rows: $rows)->activeChannel(token: $token)['id']);
		$this->assertSame(['findAll', ['filters' => ['register' => 'pipelinq', 'schema' => 'webChatChannel', 'channelToken' => $token], 'sort' => [], 'limit' => 5], false, false], $this->calls[0]);
		$this->assertNull($this->store(rows: [$rows[1]])->activeChannel(token: $token));

		$this->calls = [];
		$this->assertNull($this->store(rows: $rows)->activeChannel(token: 'short'));
		$this->assertNull($this->store(rows: $rows)->activeChannel(token: 'abcdefghijklmnop12;-'));
		$this->assertSame([], $this->calls);
	}//end testOnlyAnActiveChannelWithThisTokenIsFound()

	/**
	 * A chat's messages are its own, oldest first.
	 *
	 * @return void
	 */
	public function testMessagesAreTheChatsOwnOldestFirst(): void {
		$rows = [
			['id' => 'm2', 'conversationId' => 'x', 'sentAt' => '2026-11-17T10:00:02+00:00'],
			['id' => 'm9', 'conversationId' => 'y', 'sentAt' => '2026-11-17T10:00:00+00:00'],
			['id' => 'm1', 'conversationId' => 'x', 'sentAt' => '2026-11-17T10:00:01+00:00'],
		];

		$this->assertSame(['m1', 'm2'], array_column($this->store(rows: $rows)->messages(conversationId: 'x'), 'id'));
		$this->assertSame(['conversationId' => 'x', 'channel' => 'chat'], array_slice($this->calls[0][1]['filters'], 2));
	}//end testMessagesAreTheChatsOwnOldestFirst()

	/**
	 * The visitor's lookup reads past RBAC, the colleague's through it.
	 *
	 * @return void
	 */
	public function testTheVisitorLookupSkipsRbacAndTheColleaguesDoesNot(): void {
		$store = $this->store(found: ['channel' => 'chat']);

		$this->assertSame(['channel' => 'chat', 'id' => 'c-1'], $store->conversation(id: 'c-1'));
		$this->assertSame(['channel' => 'chat', 'id' => 'c-1'], $store->readableConversation(id: 'c-1'));
		$this->assertSame([['find', 'c-1', 'channelConversation', false], ['find', 'c-1', 'channelConversation', true]], $this->calls);
		$this->assertNull($store->conversation(id: ''));
		$this->assertNull($this->store(found: null)->readableConversation(id: 'c-1'));
	}//end testTheVisitorLookupSkipsRbacAndTheColleaguesDoesNot()

	/**
	 * A conversation is written without its id fields, under its uuid; a message as a new row.
	 *
	 * @return void
	 */
	public function testWritesGoToTheRightSchema(): void {
		$store = $this->store();

		$saved = $store->saveConversation(conversation: ['@self' => [], 'id' => 'c-1', 'status' => 'open'], id: 'c-1');
		$store->addMessage(message: ['body' => 'Hoi']);

		$this->assertSame(['status' => 'open', 'id' => 'c-1'], $saved);
		$this->assertSame(['save', ['status' => 'open'], 'pipelinq', 'channelConversation', 'c-1'], $this->calls[0]);
		$this->assertSame(['save', ['body' => 'Hoi'], 'pipelinq', 'channelMessage', null], $this->calls[1]);
	}//end testWritesGoToTheRightSchema()

	/**
	 * The id of a row is its id, its uuid or its @self id.
	 *
	 * @return void
	 */
	public function testTheIdOfARow(): void {
		$store = $this->store();

		$this->assertSame('a', $store->idOf(row: ['id' => 'a', 'uuid' => 'b']));
		$this->assertSame('b', $store->idOf(row: ['id' => '', 'uuid' => 'b']));
		$this->assertSame('c', $store->idOf(row: ['@self' => ['id' => 'c']]));
		$this->assertSame('', $store->idOf(row: ['@self' => 'x']));
	}//end testTheIdOfARow()

	/**
	 * Without OpenRegister a read fails loudly and agent profiles read as none.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegister(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('absent'));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$store = new WebChatStore(container: $container, appConfig: $config);

		$this->assertSame([], $store->agentProfiles());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available.');
		$store->messages(conversationId: 'x');
	}//end testWithoutOpenRegister()
}//end class
