<?php

/**
 * Unit tests for StringSimilarity.
 *
 * Exhaustively exercises the Jaro / Jaro-Winkler and TF-IDF cosine primitives:
 * identity, disjointness, the canonical reference values, the Winkler prefix
 * boost, symmetry, and the [0,1] bounds — the signals the probabilistic
 * duplicate detector relies on.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Mdm;

use OCA\Pipelinq\Service\Mdm\StringSimilarity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the string-similarity primitives.
 */
final class StringSimilarityTest extends TestCase
{
    /**
     * Identical strings score 1.0 for both Jaro and Jaro-Winkler.
     *
     * @return void
     */
    public function testIdenticalStringsScoreOne(): void
    {
        $this->assertSame(1.0, StringSimilarity::jaro('Voorbeeld', 'Voorbeeld'));
        $this->assertSame(1.0, StringSimilarity::jaroWinkler('Voorbeeld', 'Voorbeeld'));
    }//end testIdenticalStringsScoreOne()

    /**
     * Fully disjoint strings score 0.0.
     *
     * @return void
     */
    public function testDisjointStringsScoreZero(): void
    {
        $this->assertSame(0.0, StringSimilarity::jaro('abc', 'xyz'));
    }//end testDisjointStringsScoreZero()

    /**
     * The canonical MARTHA / MARHTA Jaro value (~0.944).
     *
     * @return void
     */
    public function testJaroReferenceValue(): void
    {
        $this->assertEqualsWithDelta(0.9444, StringSimilarity::jaro('MARTHA', 'MARHTA'), 0.001);
    }//end testJaroReferenceValue()

    /**
     * Jaro-Winkler applies the common-prefix boost over plain Jaro.
     *
     * @return void
     */
    public function testJaroWinklerPrefixBoost(): void
    {
        $jaro   = StringSimilarity::jaro('MARTHA', 'MARHTA');
        $winkler= StringSimilarity::jaroWinkler('MARTHA', 'MARHTA');
        $this->assertGreaterThan($jaro, $winkler);
        $this->assertEqualsWithDelta(0.9611, $winkler, 0.001);
    }//end testJaroWinklerPrefixBoost()

    /**
     * Near-identical business names score above the dedup name threshold.
     *
     * @return void
     */
    public function testCloseBusinessNamesAreHighlySimilar(): void
    {
        $score = StringSimilarity::jaroWinkler('Jansens Bouw BV', "Jansen's Bouw B.V.");
        $this->assertGreaterThan(0.85, $score);
    }//end testCloseBusinessNamesAreHighlySimilar()

    /**
     * Similarity is symmetric.
     *
     * @return void
     */
    public function testSymmetry(): void
    {
        $this->assertSame(
            StringSimilarity::jaroWinkler('dixon', 'dicksonx'),
            StringSimilarity::jaroWinkler('dicksonx', 'dixon')
        );
    }//end testSymmetry()

    /**
     * TF-IDF cosine is 1.0 for identical token bags and 0.0 for disjoint ones.
     *
     * @return void
     */
    public function testTfidfIdentityAndDisjoint(): void
    {
        $this->assertEqualsWithDelta(
            1.0,
            StringSimilarity::tfidfCosine('Bedrijfsplein 10 Utrecht', 'Bedrijfsplein 10 Utrecht'),
            0.0001
        );
        $this->assertSame(
            0.0,
            StringSimilarity::tfidfCosine('Kerkstraat Amsterdam', 'Dorpsweg Rotterdam')
        );
    }//end testTfidfIdentityAndDisjoint()

    /**
     * Partial address overlap yields a partial, bounded cosine score.
     *
     * @return void
     */
    public function testTfidfPartialOverlapIsBounded(): void
    {
        $score = StringSimilarity::tfidfCosine(
            'Bedrijfsplein 10 5678 XY Utrecht',
            'Bedrijfsplein 10 5678 Utrecht'
        );
        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }//end testTfidfPartialOverlapIsBounded()

    /**
     * Empty input never crashes and scores 0 against a non-empty document.
     *
     * @return void
     */
    public function testEmptyInputs(): void
    {
        $this->assertSame(0.0, StringSimilarity::tfidfCosine('', 'something'));
        $this->assertSame(0.0, StringSimilarity::jaro('', 'abc'));
    }//end testEmptyInputs()
}//end class
