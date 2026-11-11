<?php

/**
 * Golden test for PipelinqBsnIdentityVerifyProvider::verify().
 *
 * Pins every outcome of the fail-closed identity check, allowed and refused:
 * no BSN, an 11-proef failure, BRP unconfigured, a BRP error (logged), no BRP
 * match and a resolved person; and which subject key supplies the BSN.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Gdpr
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Gdpr;

use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\Gdpr\PipelinqBsnIdentityVerifyProvider;
use OCA\Pipelinq\Service\HaalCentraalClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every verification outcome.
 */
class BsnIdentityVerifyGoldenTest extends TestCase {
	/**
	 * Scenario table: case, 11-proef answer, BRP configured, BRP answer, expected status, message, BSN looked up.
	 *
	 * @return void
	 */
	public function testEveryOutcome(): void {
		$cases = [
			'no bsn' => [['subject' => 'abc', 'bsn' => '12345678'], true, true, ['naam' => 'x'], 'needs-more', 'No BSN present on the case; identity cannot be established yet.', null],
			'11-proef fails' => [['bsn' => '123456789'], false, true, ['naam' => 'x'], 'failed', 'BSN failed the 11-proef (*****6789).', null],
			'brp unconfigured' => [['subjectBsn' => ' 999999990 '], true, false, ['naam' => 'x'], 'needs-more', 'BSN is formally valid but the BRP (Haal Centraal) client is not configured; positive identity requires a BRP match.', null],
			'brp error' => [['subjectIdentifier' => 999999990], true, true, 'throw', 'failed', 'BRP lookup errored; identity not established (fail-closed).', '999999990'],
			'brp no match' => [['subjectId' => '999999990'], true, true, null, 'failed', 'No BRP record matched the supplied BSN.', '999999990'],
			'brp empty' => [['verzoekerBsn' => '999999990'], true, true, [], 'failed', 'No BRP record matched the supplied BSN.', '999999990'],
			'verified' => [['bsn' => ['nope'], 'subject' => '999999990'], true, true, ['naam' => 'Jan'], 'verified', 'BSN passed the 11-proef and a BRP record was resolved.', '999999990'],
		];
		foreach ($cases as $name => [$case, $valid, $configured, $person, $status, $message, $lookedUp]) {
			$validation = $this->createMock(BsnValidationService::class);
			$validation->method('validate')->willReturn(['isFormalValid' => $valid, 'maskedBsn' => '*****6789']);
			$lookups = [];
			$brp = $this->createMock(HaalCentraalClient::class);
			$brp->method('isConfigured')->willReturn($configured);
			$brp->method('lookupPersoon')->willReturnCallback(
				static function (string $bsn, string $requestIdContext = '') use ($person, &$lookups): ?array {
					$lookups[] = [$bsn, $requestIdContext];
					if ($person === 'throw') {
						throw new RuntimeException('brp down');
					}

					return $person;
				}
			);
			$warnings = [];
			$logger = $this->createMock(LoggerInterface::class);
			$logger->method('warning')->willReturnCallback(
				static function (string $m, array $c = []) use (&$warnings): void {
					$warnings[] = [$m, $c];
				}
			);

			$result = (new PipelinqBsnIdentityVerifyProvider($validation, $brp, $logger))->verify('case-1', $case);

			self::assertSame($status, $result->getStatus(), $name);
			self::assertSame($message, $result->getMessage(), $name);
			self::assertSame(PipelinqBsnIdentityVerifyProvider::PROVIDER_ID, $result->getProviderId(), $name);
			self::assertSame(($lookedUp === null ? [] : [[$lookedUp, 'case-1']]), $lookups, $name);
			$expectedWarnings = [];
			if ($person === 'throw') {
				$expectedWarnings[] = ['Pipelinq identity verify: BRP lookup failed', ['case' => 'case-1', 'exception' => 'brp down']];
			}

			self::assertSame($expectedWarnings, $warnings, $name);
		}//end foreach
	}//end testEveryOutcome()
}//end class
