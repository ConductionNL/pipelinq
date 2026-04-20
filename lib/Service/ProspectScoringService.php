<?php

/**
 * Pipelinq ProspectScoringService.
 *
 * Service for calculating ICP fit scores for prospect companies.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Calculates fit scores based on ICP criteria.
 *
 * Score breakdown (max 100):
 * - SBI code match: 30 points
 * - Employee count match: 25 points
 * - Location (province) match: 20 points
 * - Legal form match: 15 points
 * - Active registration: 10 points
 */
class ProspectScoringService
{
    /**
     * Score a single prospect against ICP criteria.
     *
     * @param array $prospect The prospect data.
     * @param array $criteria The ICP criteria.
     *
     * @return array The prospect with fitScore and fitBreakdown added.
     */
    public function score(array $prospect, array $criteria): array
    {
        $breakdown = [
            'sbiMatch'       => 0,
            'employeeMatch'  => 0,
            'locationMatch'  => 0,
            'legalFormMatch' => 0,
            'activeMatch'    => 0,
        ];

        // SBI code match (30 points).
        $breakdown['sbiMatch'] = $this->scoreSbi(
            sbiCode: $prospect['sbiCode'] ?? '',
            targetCodes: $criteria['sbiCodes'] ?? []
        );

        // Employee count match (25 points).
        $breakdown['employeeMatch'] = $this->scoreEmployeeCount(
            count: $prospect['employeeCount'] ?? null,
            min: $criteria['employeeCountMin'] ?? 0,
            max: $criteria['employeeCountMax'] ?? 0
        );

        // Location match (20 points).
        $breakdown['locationMatch'] = $this->scoreLocation(
            province: $prospect['address']['province'] ?? '',
            targetProvinces: $criteria['provinces'] ?? []
        );

        // Legal form match (15 points).
        $breakdown['legalFormMatch'] = $this->scoreLegalForm(
            legalForm: $prospect['legalForm'] ?? '',
            targetForms: $criteria['legalForms'] ?? []
        );

        // Active registration (10 points).
        $isActive = ($prospect['isActive'] ?? false) === true;

        $breakdown['activeMatch'] = 0;
        if ($isActive === true) {
            $breakdown['activeMatch'] = 10;
        }

        $prospect['fitScore']     = array_sum(array: $breakdown);
        $prospect['fitBreakdown'] = $breakdown;

        return $prospect;
    }//end score()

    /**
     * Score multiple prospects and sort by fit score descending.
     *
     * @param array $prospects The prospects to score.
     * @param array $criteria  The ICP criteria.
     *
     * @return array The scored and sorted prospects.
     */
    public function scoreAll(array $prospects, array $criteria): array
    {
        $scored = array_map(
            callback: fn(array $prospect): array => $this->score(
                prospect: $prospect,
                criteria: $criteria
            ),
            array: $prospects
        );

        usort(
            array: $scored,
            callback: fn(array $a, array $b): int => ($b['fitScore'] ?? 0) <=> ($a['fitScore'] ?? 0)
        );

        return $scored;
    }//end scoreAll()

    /**
     * Score SBI code match.
     *
     * @param string $sbiCode     The prospect's SBI code.
     * @param array  $targetCodes The target SBI codes.
     *
     * @return int The SBI score (0 or 30).
     */
    private function scoreSbi(string $sbiCode, array $targetCodes): int
    {
        if (count($targetCodes) === 0 || $sbiCode === '') {
            return 0;
        }

        foreach ($targetCodes as $target) {
            if (str_starts_with(haystack: $sbiCode, needle: (string) $target) === true) {
                return 30;
            }
        }

        return 0;
    }//end scoreSbi()

    /**
     * Score employee count match.
     *
     * @param int|null $count The prospect's employee count.
     * @param int      $min   The minimum desired count.
     * @param int      $max   The maximum desired count.
     *
     * @return int The employee score (0 or 25).
     */
    private function scoreEmployeeCount(?int $count, int $min, int $max): int
    {
        if ($count === null) {
            return 0;
        }

        if ($min === 0 && $max === 0) {
            return 0;
        }

        if ($max > 0 && $count >= $min && $count <= $max) {
            return 25;
        }

        if ($max === 0 && $count >= $min) {
            return 25;
        }

        return 0;
    }//end scoreEmployeeCount()

    /**
     * Score location match.
     *
     * @param string $province        The prospect's province.
     * @param array  $targetProvinces The target provinces.
     *
     * @return int The location score (0 or 20).
     */
    private function scoreLocation(string $province, array $targetProvinces): int
    {
        if (count($targetProvinces) === 0 || $province === '') {
            return 0;
        }

        $normalised = strtolower(string: trim(string: $province));
        foreach ($targetProvinces as $target) {
            if (strtolower(string: trim(string: (string) $target)) === $normalised) {
                return 20;
            }
        }

        return 0;
    }//end scoreLocation()

    /**
     * Score legal form match.
     *
     * @param string $legalForm   The prospect's legal form.
     * @param array  $targetForms The target legal forms.
     *
     * @return int The legal form score (0 or 15).
     */
    private function scoreLegalForm(string $legalForm, array $targetForms): int
    {
        if (count($targetForms) === 0 || $legalForm === '') {
            return 0;
        }

        $normalised = strtolower(string: trim(string: $legalForm));
        foreach ($targetForms as $target) {
            if (strtolower(string: trim(string: (string) $target)) === $normalised) {
                return 15;
            }
        }

        return 0;
    }//end scoreLegalForm()
}//end class
