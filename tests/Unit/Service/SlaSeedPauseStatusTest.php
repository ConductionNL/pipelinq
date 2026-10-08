<?php

/**
 * The seeded request SLA policies pause on the ticket's own waiting status.
 *
 * The seeds paused on 'awaiting-customer', which the ticket status enum has
 * never held, so the timer kept running while a handler waited for the
 * customer. pipelinq#2038 added 'awaiting_customer' to the enum; the SLA
 * listener compares the object's status to pauseConditions with a strict
 * in_array, so the seed must spell it the same way.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Seeded request-policy pause statuses against the ticket status enum.
 */
class SlaSeedPauseStatusTest extends TestCase {
	/**
	 * Every seeded request policy that pauses for the customer names a status
	 * the ticket can actually be in.
	 *
	 * @return void
	 */
	public function testRequestPoliciesPauseOnTheTicketWaitingStatus(): void {
		$settings = dirname(__DIR__, 3) . '/lib/Settings/register.d';
		$sla = json_decode((string)file_get_contents($settings . '/55-sla-engine.json'), true);
		$ticket = json_decode((string)file_get_contents($settings . '/99-unify-ticket-supertype.json'), true);
		$statuses = $ticket['components']['schemas']['ticket']['properties']['status']['enum'];

		$this->assertContains('awaiting_customer', $statuses);

		$checked = 0;
		foreach ($sla['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') !== 'slaPolicy' || ($object['appliesTo'] ?? '') !== 'request') {
				continue;
			}

			$checked++;
			$pause = ($object['pauseConditions'] ?? []);
			$slug = $object['@self']['slug'];
			$this->assertNotContains('awaiting-customer', $pause, "{$slug} pauses on a status no ticket has");
			$this->assertContains('awaiting_customer', $pause, "{$slug} does not pause while waiting for the customer");
		}

		// Positive control: the seeds were found.
		$this->assertGreaterThan(0, $checked);
	}//end testRequestPoliciesPauseOnTheTicketWaitingStatus()

	/**
	 * No seeded request policy pauses on a status the ticket cannot hold.
	 * 'on-hold' was one: the ticket enum has no hold status, so it could never
	 * match and only suggested a pause that does not exist.
	 *
	 * @return void
	 */
	public function testRequestPoliciesPauseOnlyOnTicketStatuses(): void {
		$settings = dirname(__DIR__, 3) . '/lib/Settings/register.d';
		$sla = json_decode((string)file_get_contents($settings . '/55-sla-engine.json'), true);
		$ticket = json_decode((string)file_get_contents($settings . '/99-unify-ticket-supertype.json'), true);
		$statuses = $ticket['components']['schemas']['ticket']['properties']['status']['enum'];

		foreach ($sla['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') !== 'slaPolicy' || in_array(($object['appliesTo'] ?? ''), ['request', 'complaint'], true) === false) {
				continue;
			}

			foreach (($object['pauseConditions'] ?? []) as $pause) {
				$this->assertContains($pause, $statuses, "{$object['@self']['slug']} pauses on '{$pause}', which no ticket can hold");
			}
		}
	}//end testRequestPoliciesPauseOnlyOnTicketStatuses()
}//end class
