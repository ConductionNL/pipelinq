<?php

/**
 * Unit tests for how ContactVcardService matches an existing Nextcloud contact for a create form.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ContactObjectReader;
use OCA\Pipelinq\Service\ContactVcardPropertyBuilder;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\ContactVcardWriterService;
use OCP\Contacts\IManager as IContactsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the searches, their order, the first uid taken and a failing search.
 */
final class ContactVcardMatchTest extends TestCase {
	/**
	 * Match a form against canned search results.
	 *
	 * @param array<string, mixed> $form    The create form.
	 * @param array<string, mixed> $answers Search term => results, or a Throwable to throw.
	 *
	 * @return array<int, mixed> [matched uid, events].
	 */
	private function match(array $form, array $answers): array {
		$events = [];
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('search')->willReturnCallback(
			static function (string $term, array $props, array $options) use ($answers, &$events): array {
				$events[] = ['search', $term, $props, $options];
				$answer = ($answers[$term] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$events): void {
				$events[] = ['warning', $message, $context];
			}
		);
		$service = new ContactVcardService(
			$contacts,
			$this->createMock(ContactVcardWriterService::class),
			$this->createMock(ContactVcardPropertyBuilder::class),
			$logger,
			$this->createMock(ContactObjectReader::class)
		);
		$uid = (new \ReflectionMethod($service, 'matchExistingContact'))->invoke($service, $form);
		return [$uid, $events];
	}//end match()

	/**
	 * Email first, then the organisation name; the first hit with a uid wins.
	 *
	 * @return void
	 */
	public function testMatchesByEmailThenOrganisationName(): void {
		$email = ['search', 'jan@acme.nl', ['EMAIL', 'UID'], ['limit' => 1]];
		$org = ['search', 'Acme', ['ORG', 'UID'], ['limit' => 1]];
		$organisation = ['email' => 'jan@acme.nl', 'type' => 'organization', 'name' => 'Acme'];

		$this->assertSame(['u-mail', [$email]], $this->match($organisation, ['jan@acme.nl' => [['UID' => 'u-mail']], 'Acme' => [['UID' => 'u-org']]]));
		$this->assertSame(['u-org', [$email, $org]], $this->match($organisation, ['jan@acme.nl' => [['UID' => ''], ['FN' => 'x']], 'Acme' => [['UID' => ''], ['UID' => 'u-org']]]));
		$this->assertSame([null, [$email, $org]], $this->match($organisation, []));
		$this->assertSame([null, [$email]], $this->match(['email' => 'jan@acme.nl', 'type' => 'person', 'name' => 'Acme'], []));
		$this->assertSame([null, [$org]], $this->match(['type' => 'organization', 'name' => 'Acme'], []));
		$this->assertSame([null, []], $this->match(['type' => 'organization', 'name' => ''], []));
	}//end testMatchesByEmailThenOrganisationName()

	/**
	 * A failing search is logged and the next one still runs.
	 *
	 * @return void
	 */
	public function testAFailingSearchIsLoggedAndSkipped(): void {
		$this->assertSame(
			[
				'u-org',
				[
					['search', 'jan@acme.nl', ['EMAIL', 'UID'], ['limit' => 1]],
					['warning', 'Pipelinq: contact match search failed', ['term' => 'jan@acme.nl', 'exception' => 'carddav down']],
					['search', 'Acme', ['ORG', 'UID'], ['limit' => 1]],
				],
			],
			$this->match(
				['email' => 'jan@acme.nl', 'type' => 'organization', 'name' => 'Acme'],
				['jan@acme.nl' => new RuntimeException('carddav down'), 'Acme' => [['UID' => 'u-org']]]
			)
		);
	}//end testAFailingSearchIsLoggedAndSkipped()
}//end class
