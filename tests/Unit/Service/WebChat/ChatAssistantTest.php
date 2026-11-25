<?php

/**
 * The assistant of a website chat: hermiq's public chat entry point, resolved lazily.
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

use OCA\Pipelinq\Service\WebChat\ChatAssistant;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-WCH-003: the assistant answers only with hermiq present and the channel's assistant on.
 *
 * @covers \OCA\Pipelinq\Service\WebChat\ChatAssistant
 */
class ChatAssistantTest extends TestCase {

	/**
	 * The assistant over a container that answers this entry point, or throws when null.
	 *
	 * @param object|null $entryPoint The entry point.
	 *
	 * @return ChatAssistant
	 */
	private function assistant(?object $entryPoint): ChatAssistant {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($entryPoint === null) {
			$container->method('get')->willThrowException(new RuntimeException('not installed'));
		} else {
			$container->method('get')->with(ChatAssistant::ENTRY_POINT)->willReturn($entryPoint);
		}

		return new ChatAssistant(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end assistant()

	/**
	 * An entry point that answers one reply, or throws it when it is an exception.
	 *
	 * @param mixed $reply The reply.
	 *
	 * @return object
	 */
	private function entryPoint(mixed $reply): object {
		return new class($reply) {
			/**
			 * Constructor.
			 *
			 * @param mixed $reply The reply.
			 */
			public function __construct(private mixed $reply) {
			}

			/**
			 * Answer the reply.
			 *
			 * @param string $channelKey        The channel key.
			 * @param string $question          The question.
			 * @param string $locale            The language.
			 * @param string $sourceScope       The scope.
			 * @param string $conversationToken The token.
			 *
			 * @return mixed
			 */
			public function ask(string $channelKey, string $question, string $locale, string $sourceScope, string $conversationToken): mixed {
				if ($this->reply instanceof RuntimeException) {
					throw $this->reply;
				}

				return $this->reply;
			}
		};
	}//end entryPoint()

	/**
	 * Without hermiq, or with the assistant off, nobody answers.
	 *
	 * @return void
	 */
	public function testWithoutHermiqOrWithTheAssistantOffThereIsNoAssistant(): void {
		$this->assertFalse(condition: $this->assistant(entryPoint: null)->answersFor(channel: ['assistantEnabled' => true]));
		$this->assertNull(actual: $this->assistant(entryPoint: null)->ask(channelId: 'c', question: 'q', locale: 'nl', token: ''));
		$this->assertFalse(condition: $this->assistant(entryPoint: $this->entryPoint(reply: []))->answersFor(channel: ['assistantEnabled' => false]));
		$this->assertFalse(condition: $this->assistant(entryPoint: $this->entryPoint(reply: []))->answersFor(channel: []));
		$this->assertFalse(
			condition: $this->assistant(entryPoint: new \stdClass())->answersFor(channel: ['assistantEnabled' => true]),
			message: 'An object without ask() is no entry point.',
		);
		$this->assertTrue(condition: $this->assistant(entryPoint: $this->entryPoint(reply: []))->answersFor(channel: ['assistantEnabled' => true]));
	}//end testWithoutHermiqOrWithTheAssistantOffThereIsNoAssistant()

	/**
	 * An answer keeps its linked sources only, and the old token when hermiq gives none.
	 *
	 * @return void
	 */
	public function testAnAnswerKeepsItsLinkedSources(): void {
		$reply = (object)[
			'answer'     => ' Ja. ',
			'sources'    => [
				['title' => '', 'url' => 'https://www.gemeente.nl/a'],
				(object)['title' => 'B', 'url' => 'http://www.gemeente.nl/b'],
				['title' => 'C', 'url' => 'ftp://files'],
				'not-a-source',
			],
			'disclosure' => 'AI',
		];

		$this->assertSame(
			expected: [
				'answer'     => 'Ja.',
				'sources'    => [
					['title' => 'https://www.gemeente.nl/a', 'url' => 'https://www.gemeente.nl/a'],
					['title' => 'B', 'url' => 'http://www.gemeente.nl/b'],
				],
				'token'      => 'old',
				'disclosure' => 'AI',
			],
			actual: $this->assistant(entryPoint: $this->entryPoint(reply: $reply))->ask(channelId: 'c', question: 'q', locale: 'nl', token: 'old')
		);
		$this->assertSame(expected: 'app:pipelinq:c-9', actual: $this->assistant(entryPoint: null)->channelKey(channelId: 'c-9'));
	}//end testAnAnswerKeepsItsLinkedSources()

	/**
	 * An empty answer, a reply of another shape, or a failing entry point is no answer.
	 *
	 * @return void
	 */
	public function testNoAnswerIsNull(): void {
		foreach ([['answer' => '  '], 'text', new RuntimeException('model down')] as $reply) {
			$this->assertNull(actual: $this->assistant(entryPoint: $this->entryPoint(reply: $reply))->ask(
				channelId: 'c',
				question: 'q',
				locale: 'nl',
				token: '',
			));
		}
	}//end testNoAnswerIsNull()
}//end class
