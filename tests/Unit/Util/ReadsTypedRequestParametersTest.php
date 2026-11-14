<?php

/**
 * Tests for reading typed controller parameters from the request.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\ReadsTypedRequestParameters;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Every type the trait knows, with a fitting, a missing and a wrong value.
 *
 * @covers \OCA\Pipelinq\Util\ReadsTypedRequestParameters
 */
class ReadsTypedRequestParametersTest extends TestCase {
	private const SPEC = [
		'name' => ['string', 'anon'],
		'note' => ['?string', null],
		'tags' => ['array', []],
		'id' => ['string', null],
		'page' => ['int', 1],
	];

	/**
	 * Values per case, or null when the request is refused.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null}>
	 */
	public static function cases(): array {
		return [
			'all fitting'           => [['name' => 'a', 'note' => 'n', 'tags' => ['x'], 'id' => 'i'], ['name' => 'a', 'note' => 'n', 'tags' => ['x'], 'id' => 'i', 'page' => 1]],
			'defaults'              => [['id' => 'i'], ['name' => 'anon', 'note' => null, 'tags' => [], 'id' => 'i', 'page' => 1]],
			'null takes default'    => [['name' => null, 'tags' => null, 'id' => 'i'], ['name' => 'anon', 'note' => null, 'tags' => [], 'id' => 'i', 'page' => 1]],
			'required missing'      => [['name' => 'a'], null],
			'integer string'        => [['name' => 1, 'id' => 'i'], null],
			'array as string'       => [['note' => ['n'], 'id' => 'i'], null],
			'string as array'       => [['tags' => 'x', 'id' => 'i'], null],
			'integer string page'   => [['id' => 'i', 'page' => '7'], ['name' => 'anon', 'note' => null, 'tags' => [], 'id' => 'i', 'page' => 7]],
			'text page is zero'     => [['id' => 'i', 'page' => 'abc'], ['name' => 'anon', 'note' => null, 'tags' => [], 'id' => 'i', 'page' => 0]],
			'float page truncated'  => [['id' => 'i', 'page' => 2.9], ['name' => 'anon', 'note' => null, 'tags' => [], 'id' => 'i', 'page' => 2]],
			'array page is one'     => [['id' => 'i', 'page' => ['x']], ['name' => 'anon', 'note' => null, 'tags' => [], 'id' => 'i', 'page' => 1]],
			'empty array page zero' => [['id' => 'i', 'page' => []], ['name' => 'anon', 'note' => null, 'tags' => [], 'id' => 'i', 'page' => 0]],
			'empty array kept'      => [['tags' => [], 'id' => ''], ['name' => 'anon', 'note' => null, 'tags' => [], 'id' => '', 'page' => 1]],
		];
	}//end cases()

	/**
	 * Each case reads the expected values, or is refused.
	 *
	 * @param array<string, mixed>      $params   The request parameters.
	 * @param array<string, mixed>|null $expected The values, or null.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testReadsOrRefuses(array $params, ?array $expected): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (isset($params[$key]) === true ? $params[$key] : $default)
		);

		$reader = new class($request) {
			use ReadsTypedRequestParameters;

			/**
			 * @param IRequest $request The request.
			 */
			public function __construct(public IRequest $request) {
			}

			/**
			 * @param array<string, array{0: string, 1: mixed}> $spec The spec.
			 *
			 * @return array<string, mixed>|null
			 */
			public function read(array $spec): ?array {
				return $this->typedRequestParameters(spec: $spec);
			}
		};

		$this->assertSame($expected, $reader->read(spec: self::SPEC));
	}//end testReadsOrRefuses()
}//end class
