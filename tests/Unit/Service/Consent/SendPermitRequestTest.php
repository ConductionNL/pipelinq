<?php

/**
 * Unit tests for SendPermitRequest.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Consent
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

namespace OCA\Pipelinq\Tests\Unit\Service\Consent;

use OCA\Pipelinq\Service\Consent\SendPermitRequest;
use PHPUnit\Framework\TestCase;

/**
 * Whether a send-permit question is about one list or the whole channel.
 *
 * @covers \OCA\Pipelinq\Service\Consent\SendPermitRequest
 */
class SendPermitRequestTest extends TestCase {

	/**
	 * Only a non-empty list id makes the request list-scoped.
	 *
	 * @return void
	 */
	public function testOnlyANonEmptyListIdScopesTheRequest(): void {
		$this->assertTrue((new SendPermitRequest(contactId: 'c-1', channel: 'email', intent: 'marketing', listId: 'l-1'))->isListScoped());
		$this->assertFalse((new SendPermitRequest(contactId: 'c-1', channel: 'email', intent: 'marketing', listId: ''))->isListScoped());
		$this->assertFalse((new SendPermitRequest(contactId: 'c-1', channel: 'email', intent: 'marketing', listId: null))->isListScoped());
	}//end testOnlyANonEmptyListIdScopesTheRequest()
}//end class
