<?php

/**
 * Pins the full validation result for a broad, generated set of BSN candidates.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-001-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BsnValidationService;
use PHPUnit\Framework\TestCase;

/**
 * Malformed candidates plus 300 spread 9-digit numbers, each checked against a reference 11-proef.
 *
 * The numbers are generated, not stored, so the repository carries no list of checksum-valid BSNs.
 */
class BsnValidationGoldenTest extends TestCase {

	/**
	 * The candidates, in a fixed order.
	 *
	 * @return array<int, string>
	 */
	public static function candidates(): array {
		$inputs = ['', '1', '1234', '12345', '12345678', '1234567890', 'abcdefghi', ' 12345678', '12345678a', '-12345678', '111222333', '123456782', '000000000', '999999999', '100000009'];
		for ($i = 0; $i < 300; $i++) {
			$inputs[] = str_pad((string)(($i * 7919 * 104729 + 12345) % 1000000000), 9, '0', STR_PAD_LEFT);
		}

		return $inputs;
	}//end candidates()

	/**
	 * The result the formal check must give, written out independently.
	 *
	 * @param string $input The candidate.
	 *
	 * @return array<string, mixed>
	 */
	private static function reference(string $input): array {
		if (preg_match('/^[0-9]{9}$/', $input) !== 1) {
			return [
				'isFormalValid'   => false,
				'elevenTestScore' => -1,
				'errorCode'       => 'length',
				'errorMessage'    => 'Een BSN bestaat uit exact 9 cijfers.',
				'maskedBsn'       => BsnValidationService::mask(bsnInput: $input),
			];
		}

		$weights = [9, 8, 7, 6, 5, 4, 3, 2, -1];
		$sum     = 0;
		foreach ($weights as $i => $weight) {
			$sum += ((int)$input[$i] * $weight);
		}

		$modulo = $sum % 11;
		return [
			'isFormalValid'   => $modulo === 0,
			'elevenTestScore' => $modulo,
			'errorCode'       => $modulo === 0 ? null : 'checksum',
			'errorMessage'    => $modulo === 0 ? null : 'Dit BSN voldoet niet aan de 11-proef (controlesom fout).',
			'maskedBsn'       => BsnValidationService::mask(bsnInput: $input),
		];
	}//end reference()

	/**
	 * Every candidate validates exactly as the reference says, and both outcomes occur.
	 *
	 * @return void
	 */
	public function testEveryCandidate(): void {
		$service = new BsnValidationService();
		$outcomes = [];
		foreach (self::candidates() as $input) {
			$result = $service->validate(bsnInput: $input);
			$this->assertSame(self::reference(input: $input), $result, 'candidate #'.count($outcomes));
			$outcomes[(string)$result['errorCode']] = true;
		}

		$this->assertSame(['length' => true, '' => true, 'checksum' => true], $outcomes);
	}//end testEveryCandidate()
}//end class
