<?php

/**
 * Builds near-optimal extremal probes and compares their decisions with a
 * proven-optimal reference solution.
 */
class DecisionStabilityAnalyzer {

    public const PROBES = ['buffers', 'suppliers', 'allocation'];

    public static function buildProbeModel(
        string $content,
        array $referenceX,
        array $referenceZ,
        array $referenceQ,
        int $supplierCount,
        string $probe,
        float $objectiveLimit
    ): string {
        if (!in_array($probe, self::PROBES, true)) {
            throw new InvalidArgumentException("Unknown decision-stability probe: {$probe}");
        }
        if ($supplierCount <= 0 || count($referenceX) === 0) {
            throw new InvalidArgumentException('Reference decision dimensions must be positive');
        }

        $nodeCount = count($referenceX);
        $expectedMatrixSize = $nodeCount * $supplierCount;
        if (count($referenceZ) !== $expectedMatrixSize || count($referenceQ) !== $expectedMatrixSize) {
            throw new InvalidArgumentException(
                "Reference Z/Q vectors must each contain {$expectedMatrixSize} values"
            );
        }

        if (!preg_match('/^[ \t]*minimize\s+([^;\r\n]+);/m', $content, $objectiveMatch, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('Could not locate the original PLM objective');
        }
        $originalObjective = trim($objectiveMatch[1][0]);
        $objectiveLine = $objectiveMatch[0][0];
        $objectiveOffset = $objectiveMatch[0][1];

        $referenceXLiteral = self::toOplVector($referenceX);
        $referenceZLiteral = self::toOplMatrix($referenceZ, $nodeCount, $supplierCount);
        $referenceQLiteral = self::toOplMatrix($referenceQ, $nodeCount, $supplierCount);
        $scoreExpression = [
            'buffers' => 'stabilityBufferDivergence',
            'suppliers' => 'stabilitySupplierDivergence',
            'allocation' => 'stabilityAllocationDivergence',
        ][$probe];

        $declarations =
            " int stabilityRefX[N] = {$referenceXLiteral};\n" .
            " int stabilityRefZ[N][S] = {$referenceZLiteral};\n" .
            " int stabilityRefQ[N][S] = {$referenceQLiteral};\n" .
            " dvar int+ stabilityQDiff[N][S];\n" .
            " dvar boolean stabilityQSign[N][S];\n" .
            " dexpr float stabilityOriginalObjective = {$originalObjective};\n" .
            " dexpr float stabilityBufferDivergence = sum(i in N)" .
                "(stabilityRefX[i] == 1 ? 1-x[i] : x[i]);\n" .
            " dexpr float stabilitySupplierDivergence = sum(i in N, j in S)" .
                "(stabilityRefZ[i][j] == 1 ? 1-z[i][j] : z[i][j]);\n" .
            " dexpr float stabilityAllocationDivergence = sum(i in N, j in S) stabilityQDiff[i][j];\n" .
            " dexpr float stabilityScore = {$scoreExpression};\n" .
            " minimize -stabilityScore;";

        $content = substr_replace(
            $content,
            $declarations,
            $objectiveOffset,
            strlen($objectiveLine)
        );

        $limit = self::formatNumber($objectiveLimit);
        $constraints =
            "\n \tct_stability_objective: stabilityOriginalObjective <= {$limit};\n" .
            "\tforall (i in N, j in S) {\n" .
            "\t\tct_stability_qdiff_pos: stabilityQDiff[i][j] >= q[i][j] - stabilityRefQ[i][j];\n" .
            "\t\tct_stability_qdiff_neg: stabilityQDiff[i][j] >= stabilityRefQ[i][j] - q[i][j];\n" .
            "\t\tct_stability_qdiff_sign_pos: stabilityQDiff[i][j] <= q[i][j] - stabilityRefQ[i][j]" .
                " + 2*sup[j][3]*(1-stabilityQSign[i][j]);\n" .
            "\t\tct_stability_qdiff_sign_neg: stabilityQDiff[i][j] <= stabilityRefQ[i][j] - q[i][j]" .
                " + 2*sup[j][3]*stabilityQSign[i][j];\n" .
            "\t}\n";

        $content = preg_replace(
            '/subject\s+to\s*\{/',
            "subject to {{$constraints}",
            $content,
            1,
            $constraintReplacements
        );
        if ($constraintReplacements !== 1) {
            throw new RuntimeException('Could not locate the PLM constraint block');
        }

        $output =
            "write(\"#STABILITY_ORIGINAL_OBJECTIVE:\",stabilityOriginalObjective);\n\t" .
            "write(\"#STABILITY_SCORE:\",stabilityScore);\n\t";
        $content = preg_replace(
            '/writeln\("#DELIVER:"\);/',
            $output . 'writeln("#DELIVER:");',
            $content,
            1,
            $outputReplacements
        );
        if ($outputReplacements !== 1) {
            throw new RuntimeException('Could not locate the PLM result-output block');
        }

        return $content;
    }

    public static function compare(array $referenceResult, array $alternativeResult): array {
        $referenceX = self::numericVector($referenceResult['X'] ?? []);
        $alternativeX = self::numericVector($alternativeResult['X'] ?? []);
        $referenceZ = self::numericVector($referenceResult['Z'] ?? []);
        $alternativeZ = self::numericVector($alternativeResult['Z'] ?? []);
        $referenceQ = self::numericVector($referenceResult['Q'] ?? []);
        $alternativeQ = self::numericVector($alternativeResult['Q'] ?? []);

        self::assertSameLength($referenceX, $alternativeX, 'X');
        self::assertSameLength($referenceZ, $alternativeZ, 'Z');
        self::assertSameLength($referenceQ, $alternativeQ, 'Q');

        $bufferChanged = self::changedCount($referenceX, $alternativeX);
        $supplierChanged = self::changedCount($referenceZ, $alternativeZ);
        $allocationChanged = self::changedCount($referenceQ, $alternativeQ);
        $allocationL1 = 0.0;
        foreach ($referenceQ as $index => $value) {
            $allocationL1 += abs($value - $alternativeQ[$index]);
        }
        $referenceQuantity = array_sum($referenceQ);

        return [
            'buffer_jaccard_similarity' => self::binaryJaccard($referenceX, $alternativeX),
            'buffer_changed_count' => $bufferChanged,
            'supplier_jaccard_similarity' => self::binaryJaccard($referenceZ, $alternativeZ),
            'supplier_changed_count' => $supplierChanged,
            'allocation_l1_absolute' => $allocationL1,
            'allocation_l1_normalized' => $referenceQuantity > 0
                ? $allocationL1 / $referenceQuantity
                : 0.0,
            'allocation_changed_pairs' => $allocationChanged,
        ];
    }

    public static function summarize(array $rows): array {
        $byAnchor = [];
        foreach ($rows as $row) {
            $anchor = (string)($row['anchor_run_id'] ?? '');
            if ($anchor === '' || ($row['probe_status'] ?? '') !== 'OPTIMAL') {
                continue;
            }
            $byAnchor[$anchor][] = $row;
        }

        $summary = [];
        foreach ($byAnchor as $anchor => $anchorRows) {
            $first = $anchorRows[0];
            $summary[] = [
                'anchor_run_id' => $anchor,
                'instance_id' => $first['instance_id'] ?? '',
                'source_experiment' => $first['source_experiment'] ?? '',
                'strategy' => $first['strategy'] ?? '',
                'tax_rate' => $first['tax_rate'] ?? '',
                'cap_level' => $first['cap_level'] ?? '',
                'probes_completed' => count($anchorRows),
                'minimum_buffer_jaccard_similarity' => min(array_column($anchorRows, 'buffer_jaccard_similarity')),
                'minimum_supplier_jaccard_similarity' => min(array_column($anchorRows, 'supplier_jaccard_similarity')),
                'maximum_allocation_l1_normalized' => max(array_column($anchorRows, 'allocation_l1_normalized')),
                'maximum_objective_degradation_pct' => max(array_column($anchorRows, 'objective_degradation_pct')),
            ];
        }

        return $summary;
    }

    private static function toOplVector(array $values): string {
        return '[' . implode(',', array_map(function($value) {
            return (string)(int)round((float)$value);
        }, $values)) . ']';
    }

    private static function toOplMatrix(array $values, int $rows, int $columns): string {
        $matrix = [];
        for ($row = 0; $row < $rows; $row++) {
            $matrix[] = self::toOplVector(array_slice($values, $row * $columns, $columns));
        }
        return '[' . implode(',', $matrix) . ']';
    }

    private static function formatNumber(float $value): string {
        return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
    }

    private static function numericVector(array $values): array {
        return array_map(function($value) {
            return (float)$value;
        }, array_values($values));
    }

    private static function assertSameLength(array $reference, array $alternative, string $name): void {
        if (count($reference) === 0 || count($reference) !== count($alternative)) {
            throw new InvalidArgumentException(
                "{$name} decision vectors must be non-empty and have matching dimensions"
            );
        }
    }

    private static function changedCount(array $reference, array $alternative): int {
        $changed = 0;
        foreach ($reference as $index => $value) {
            if (abs($value - $alternative[$index]) > 1e-9) {
                $changed++;
            }
        }
        return $changed;
    }

    private static function binaryJaccard(array $reference, array $alternative): float {
        $intersection = 0;
        $union = 0;
        foreach ($reference as $index => $value) {
            $referenceOn = (int)round($value) === 1;
            $alternativeOn = (int)round($alternative[$index]) === 1;
            if ($referenceOn || $alternativeOn) {
                $union++;
            }
            if ($referenceOn && $alternativeOn) {
                $intersection++;
            }
        }
        return $union === 0 ? 1.0 : $intersection / $union;
    }
}
