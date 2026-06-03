<?php

/**
 * Pipelinq StringSimilarity.
 *
 * Pure string-similarity primitives for probabilistic record linkage: the
 * Jaro and Jaro-Winkler edit similarities (used for names) and a TF-IDF
 * cosine similarity over token bags (used for addresses / phones). Kept as a
 * stateless helper so the algorithms are exhaustively unit-tested in isolation
 * from OpenRegister.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

/**
 * Stateless string-similarity algorithms for fuzzy matching.
 */
final class StringSimilarity
{
    /**
     * The Winkler prefix scaling factor (standard value).
     *
     * @var float
     */
    private const WINKLER_SCALING = 0.1;

    /**
     * Jaro similarity between two strings, in [0, 1].
     *
     * @param string $a The first string.
     * @param string $b The second string.
     *
     * @return float The Jaro similarity.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public static function jaro(string $a, string $b): float
    {
        $a = self::normalize(value: $a);
        $b = self::normalize(value: $b);

        if ($a === $b) {
            return 1.0;
        }

        $lenA = strlen($a);
        $lenB = strlen($b);
        if ($lenA === 0 || $lenB === 0) {
            return 0.0;
        }

        $matchDistance = (int) floor(max($lenA, $lenB) / 2) - 1;
        if ($matchDistance < 0) {
            $matchDistance = 0;
        }

        $aMatches = array_fill(0, $lenA, false);
        $bMatches = array_fill(0, $lenB, false);

        $matches = 0;
        for ($i = 0; $i < $lenA; $i++) {
            $start = max(0, ($i - $matchDistance));
            $end   = min(($i + $matchDistance + 1), $lenB);
            for ($j = $start; $j < $end; $j++) {
                if ($bMatches[$j] === true || $a[$i] !== $b[$j]) {
                    continue;
                }

                $aMatches[$i] = true;
                $bMatches[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        // Count transpositions.
        $transpositions = 0;
        $k = 0;
        for ($i = 0; $i < $lenA; $i++) {
            if ($aMatches[$i] === false) {
                continue;
            }

            while ($bMatches[$k] === false) {
                $k++;
            }

            if ($a[$i] !== $b[$k]) {
                $transpositions++;
            }

            $k++;
        }

        $transpositions = ($transpositions / 2);

        return (
            ($matches / $lenA) + ($matches / $lenB) + (($matches - $transpositions) / $matches)
        ) / 3.0;
    }//end jaro()

    /**
     * Jaro-Winkler similarity (Jaro with a common-prefix bonus), in [0, 1].
     *
     * @param string $a The first string.
     * @param string $b The second string.
     *
     * @return float The Jaro-Winkler similarity.
     */
    public static function jaroWinkler(string $a, string $b): float
    {
        $jaro = self::jaro(a: $a, b: $b);

        $normA = self::normalize(value: $a);
        $normB = self::normalize(value: $b);

        $prefix    = 0;
        $maxPrefix = min(4, strlen($normA), strlen($normB));
        for ($i = 0; $i < $maxPrefix; $i++) {
            if ($normA[$i] !== $normB[$i]) {
                break;
            }

            $prefix++;
        }

        return ($jaro + ($prefix * self::WINKLER_SCALING * (1.0 - $jaro)));
    }//end jaroWinkler()

    /**
     * TF-IDF cosine similarity between two short documents.
     *
     * Each document is tokenised on non-alphanumeric boundaries. Inverse
     * document frequency is computed over the two documents (the standard
     * smoothed form), term frequency is raw count, and the cosine of the two
     * weighted vectors is returned in [0, 1].
     *
     * @param string $a The first document.
     * @param string $b The second document.
     *
     * @return float The cosine similarity.
     */
    public static function tfidfCosine(string $a, string $b): float
    {
        $tokensA = self::tokenize(value: $a);
        $tokensB = self::tokenize(value: $b);
        if (empty($tokensA) === true || empty($tokensB) === true) {
            return 0.0;
        }

        $tfA = array_count_values($tokensA);
        $tfB = array_count_values($tokensB);

        $vocabulary = array_unique(array_merge(array_keys($tfA), array_keys($tfB)));

        // Document frequency across the two documents (1 or 2).
        $idf = [];
        foreach ($vocabulary as $term) {
            $docFreq = 0;
            if (isset($tfA[$term]) === true) {
                $docFreq++;
            }

            if (isset($tfB[$term]) === true) {
                $docFreq++;
            }

            $idf[$term] = log((1 + 2) / (1 + $docFreq)) + 1.0;
        }

        $dot   = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($vocabulary as $term) {
            $weightA = ((float) ($tfA[$term] ?? 0)) * $idf[$term];
            $weightB = ((float) ($tfB[$term] ?? 0)) * $idf[$term];
            $dot    += ($weightA * $weightB);
            $normA  += ($weightA * $weightA);
            $normB  += ($weightB * $weightB);
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return ($dot / (sqrt($normA) * sqrt($normB)));
    }//end tfidfCosine()

    /**
     * Lowercase and trim a string for comparison.
     *
     * @param string $value The string.
     *
     * @return string The normalised string.
     */
    private static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }//end normalize()

    /**
     * Tokenise a document on non-alphanumeric boundaries, lowercased.
     *
     * @param string $value The document.
     *
     * @return array<int, string> The non-empty tokens.
     */
    private static function tokenize(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/', self::normalize(value: $value));
        if ($parts === false) {
            return [];
        }

        $tokens = [];
        foreach ($parts as $part) {
            if ($part !== '') {
                $tokens[] = $part;
            }
        }

        return $tokens;
    }//end tokenize()
}//end class
