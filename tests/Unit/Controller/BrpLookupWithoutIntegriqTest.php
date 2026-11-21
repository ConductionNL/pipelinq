<?php

/**
 * A BRP lookup without a working integriq source says where the connection lives.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#scenario-without-integriq-a-lookup-fails-and-says-why
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BrpController;
use OCA\Pipelinq\Kernel\Contracts\HaalCentraalClientInterface;
use OCA\Pipelinq\Service\BsnAudit\LookupResult;
use OCA\Pipelinq\Service\PersonLookup\PersonFetch;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * BrpController's answer to a failed lookup.
 */
class BrpLookupWithoutIntegriqTest extends TestCase {

	/**
	 * The controller's answer to a fetch that failed with the given status.
	 *
	 * @param string   $outcome      The lookup outcome.
	 * @param int|null $responseCode The failed lookup's status.
	 *
	 * @return JSONResponse The answer.
	 */
	private function answerTo(string $outcome, ?int $responseCode): JSONResponse {
		$reflection = new ReflectionClass(objectOrClass: BrpController::class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$reflection->getProperty(name: 'l10n')->setValue($controller, $l10n);

		$fetch = new PersonFetch(
			person: null,
			result: new LookupResult(outcome: $outcome, responseCode: $responseCode),
			fromCache: false,
			durationMs: null
		);

		return $reflection->getMethod(name: 'lookupResponse')->invoke($controller, $fetch, 'req-1');
	}//end answerTo()

	/**
	 * A lookup that failed for want of a working integriq source names integriq.
	 *
	 * @return void
	 */
	public function testNoSourceNamesIntegriq(): void {
		$response = $this->answerTo(outcome: 'error', responseCode: HaalCentraalClientInterface::NO_SOURCE_STATUS);

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		self::assertSame(
			expected: [
				'errorCode' => 'brp-unavailable',
				'errorMessage' => 'BRP lookups go through integriq. Ask your administrator to set up the BRP connection there.',
				'responseInCache' => false,
				'lookupRequestId' => 'req-1',
			],
			actual: $response->getData()
		);
	}//end testNoSourceNamesIntegriq()

	/**
	 * Any other failure keeps the message that the BRP cannot be reached.
	 *
	 * @return void
	 */
	public function testOtherFailuresSayTheBrpCannotBeReached(): void {
		self::assertSame(
			expected: 'BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.',
			actual: $this->answerTo(outcome: 'timeout', responseCode: 0)->getData()['errorMessage']
		);
		self::assertSame(
			expected: 'BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.',
			actual: $this->answerTo(outcome: 'error', responseCode: 500)->getData()['errorMessage']
		);
	}//end testOtherFailuresSayTheBrpCannotBeReached()
}//end class
