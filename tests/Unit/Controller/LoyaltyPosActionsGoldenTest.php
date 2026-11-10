<?php

/**
 * Golden test of the POS-facing loyalty actions of LoyaltyController.
 *
 * Pins, over mocked services, exactly what `useRedemptionCode`,
 * `redeemGiftCard`, `activateGiftCard` and `issueGiftCard` pass to their
 * service and answer, for every branch of their request handling.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\LoyaltyController;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\GiftCardService;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\LoyaltyProgrammeService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\RedemptionService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the service calls and answers of the POS-facing loyalty actions.
 */
final class LoyaltyPosActionsGoldenTest extends TestCase {
	/**
	 * The service calls of the current case, as [method, named arguments].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The controller for a CRM user, a query and canned service answers.
	 *
	 * @param array<string, mixed> $query   The request parameters.
	 * @param array<string, mixed> $answers Service method => answer, or a Throwable to throw.
	 *
	 * @return LoyaltyController The controller.
	 */
	private function controller(array $query, array $answers): LoyaltyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $query) === true ? $query[$key] : $default)
		);
		$redemptions = $this->createMock(RedemptionService::class);
		$giftCards = $this->createMock(GiftCardService::class);
		foreach ([[$redemptions, ['validateCode', 'markRedemptionUsed']], [$giftCards, ['redeemGiftCard', 'activateGiftCard', 'issueGiftCard']]] as [$mock, $methods]) {
			foreach ($methods as $method) {
				$mock->method($method)->willReturnCallback(
					function (mixed ...$args) use ($method, $answers): mixed {
						$this->calls[] = [$method, $args];
						$answer = [];
						if (array_key_exists($method, $answers) === true) {
							$answer = $answers[$method];
						}

						if ($answer instanceof \Throwable) {
							throw $answer;
						}

						return $answer;
					}
				);
			}
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('cashier-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new LoyaltyController(
			$request,
			$this->createMock(LoyaltyAccountService::class),
			$this->createMock(PointsLedgerService::class),
			$redemptions,
			$giftCards,
			$this->createMock(LoyaltyProgrammeService::class),
			new CrmPrivilegeCheck($session, $policy),
			$l10n
		);
	}//end controller()

	/**
	 * Run one case and answer [status, body, service calls].
	 *
	 * @param callable(LoyaltyController): JSONResponse $action  The action.
	 * @param array<string, mixed>                       $query   The request parameters.
	 * @param array<string, mixed>                       $answers The service answers.
	 *
	 * @return array<int, mixed> The outcome.
	 */
	private function outcome(callable $action, array $query, array $answers = []): array {
		$this->calls = [];
		$response = $action($this->controller(query: $query, answers: $answers));
		return [$response->getStatus(), $response->getData(), $this->calls];
	}//end outcome()

	/**
	 * Using a redemption code: invalid, missing, every id source, the POS reference and a failing mark.
	 *
	 * @return void
	 */
	public function testUseRedemptionCode(): void {
		$use = static fn (LoyaltyController $c): JSONResponse => $c->useRedemptionCode('CODE-1');
		$valid = static fn (array $redemption): array => ['validateCode' => ['valid' => true, 'redemption' => $redemption], 'markRedemptionUsed' => ['used' => true]];
		$validated = ['validateCode', ['CODE-1']];

		$this->assertSame(
			[400, ['error' => 'Code expired'], [$validated]],
			$this->outcome($use, [], ['validateCode' => ['valid' => false, 'reason' => 'Code expired']])
		);
		$this->assertSame(
			[400, ['error' => 'Invalid code'], [$validated]],
			$this->outcome($use, [], ['validateCode' => ['valid' => false]])
		);
		$this->assertSame(
			[400, ['error' => 'Redemption missing'], [$validated]],
			$this->outcome($use, [], ['validateCode' => ['valid' => true]])
		);
		$this->assertSame(
			[200, ['used' => true], [$validated, ['markRedemptionUsed', ['r-self', 'tx-9']]]],
			$this->outcome($use, ['posTransactionId' => 'tx-9'], $valid(['@self' => ['id' => 'r-self'], 'uuid' => 'r-uuid']))
		);
		$this->assertSame(
			[200, ['used' => true], [$validated, ['markRedemptionUsed', ['r-uuid', null]]]],
			$this->outcome($use, ['posTransactionId' => 42], $valid(['@self' => ['id' => ''], 'uuid' => 'r-uuid', 'id' => 'r-id']))
		);
		$this->assertSame(
			[200, ['used' => true], [$validated, ['markRedemptionUsed', ['r-id', null]]]],
			$this->outcome($use, [], $valid(['@self' => 'not-a-map', 'id' => 'r-id']))
		);
		$this->assertSame(
			[200, ['used' => true], [$validated, ['markRedemptionUsed', ['', null]]]],
			$this->outcome($use, [], $valid([]))
		);
		$this->assertSame(
			[400, ['error' => 'already used'], [$validated, ['markRedemptionUsed', ['r-id', null]]]],
			$this->outcome($use, [], ['validateCode' => ['valid' => true, 'redemption' => ['id' => 'r-id']], 'markRedemptionUsed' => new RuntimeException('already used')])
		);
	}//end testUseRedemptionCode()

	/**
	 * Redeeming a gift card: the required fields, the POS reference and a refused debit.
	 *
	 * @return void
	 */
	public function testRedeemGiftCard(): void {
		$redeem = static fn (LoyaltyController $c): JSONResponse => $c->redeemGiftCard();
		$required = [400, ['error' => 'giftCardId, pin and amount (>0) are required'], []];
		$this->assertSame($required, $this->outcome($redeem, ['pin' => '1234', 'amount' => '5']));
		$this->assertSame($required, $this->outcome($redeem, ['giftCardId' => 'g-1', 'amount' => '5']));
		$this->assertSame($required, $this->outcome($redeem, ['giftCardId' => 'g-1', 'pin' => '1234', 'amount' => '0']));
		$this->assertSame(
			[200, ['balance' => 5.0], [['redeemGiftCard', ['g-1', '1234', 5.5, 'tx-1']]]],
			$this->outcome($redeem, ['giftCardId' => 'g-1', 'pin' => '1234', 'amount' => '5.5', 'posTransactionId' => 'tx-1'], ['redeemGiftCard' => ['balance' => 5.0]])
		);
		$this->assertSame(
			[400, ['error' => 'Insufficient balance'], [['redeemGiftCard', ['g-1', '1234', 5.5, null]]]],
			$this->outcome($redeem, ['giftCardId' => 'g-1', 'pin' => '1234', 'amount' => 5.5, 'posTransactionId' => ['x']], ['redeemGiftCard' => new RuntimeException('Insufficient balance')])
		);
	}//end testRedeemGiftCard()

	/**
	 * Activating a gift card: activated, unknown, refused, and the POS reference.
	 *
	 * @return void
	 */
	public function testActivateGiftCard(): void {
		$activate = static fn (LoyaltyController $c): JSONResponse => $c->activateGiftCard('g-1');
		$this->assertSame(
			[200, ['status' => 'active'], [['activateGiftCard', ['g-1', 'tx-2']]]],
			$this->outcome($activate, ['posTransactionId' => 'tx-2'], ['activateGiftCard' => ['status' => 'active']])
		);
		$this->assertSame(
			[404, ['error' => 'Gift card not found'], [['activateGiftCard', ['g-1', null]]]],
			$this->outcome($activate, [], ['activateGiftCard' => null])
		);
		$this->assertSame(
			[400, ['error' => 'Card is blocked'], [['activateGiftCard', ['g-1', null]]]],
			$this->outcome($activate, ['posTransactionId' => 7], ['activateGiftCard' => new RuntimeException('Card is blocked')])
		);
	}//end testActivateGiftCard()

	/**
	 * Issuing a gift card: the balance check, every optional field's default, and a refused issuance.
	 *
	 * @return void
	 */
	public function testIssueGiftCard(): void {
		$issue = static fn (LoyaltyController $c): JSONResponse => $c->issueGiftCard();
		$this->assertSame(
			[400, ['error' => 'initialBalance must be a positive amount'], []],
			$this->outcome($issue, ['initialBalance' => '-1'])
		);
		$this->assertSame(
			[200, ['pin' => '0000'], [['issueGiftCard', [null, 25.0, 365, 'purchased', null]]]],
			$this->outcome($issue, ['initialBalance' => '25'], ['issueGiftCard' => ['pin' => '0000']])
		);
		$this->assertSame(
			[200, ['pin' => '0000'], [['issueGiftCard', [null, 25.0, 365, 'gifted', null]]]],
			$this->outcome($issue, ['initialBalance' => 25, 'programmeId' => '', 'expiryDays' => '0', 'channel' => 'gifted', 'issuedIn' => ''], ['issueGiftCard' => ['pin' => '0000']])
		);
		$this->assertSame(
			[200, ['pin' => '0000'], [['issueGiftCard', ['p-1', 10.0, 30, 'purchased', 'store-1']]]],
			$this->outcome($issue, ['initialBalance' => '10', 'programmeId' => 'p-1', 'expiryDays' => '30', 'issuedIn' => 'store-1'], ['issueGiftCard' => ['pin' => '0000']])
		);
		$this->assertSame(
			[200, ['pin' => '0000'], [['issueGiftCard', [null, 10.0, 365, 'purchased', null]]]],
			$this->outcome($issue, ['initialBalance' => '10', 'programmeId' => 9, 'expiryDays' => '-5', 'issuedIn' => ['x']], ['issueGiftCard' => ['pin' => '0000']])
		);
		$this->assertSame(
			[400, ['error' => 'No programme'], [['issueGiftCard', [null, 10.0, 365, 'purchased', null]]]],
			$this->outcome($issue, ['initialBalance' => '10'], ['issueGiftCard' => new RuntimeException('No programme')])
		);
	}//end testIssueGiftCard()
}//end class
