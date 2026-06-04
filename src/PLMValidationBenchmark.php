<?php

require_once __DIR__ . '/CplexRunner.php';

$config = include __DIR__ . '/../config/settings.php';
$dataDir = rtrim($config['WORK_DIR'], "\\/") . DIRECTORY_SEPARATOR;
$modelDir = rtrim($config['MODELE'], "\\/") . DIRECTORY_SEPARATOR;
$oplrun = $config['OPLRUN'];
$timeLimit = 60;
$equivalenceSizes = [2, 3, 4, 5, 6, 7, 8];
$stressSizes = [13, 26, 50, 100, 150];
$strategies = ['TAX', 'CAP', 'HYBRID'];
$timestamp = date('Ymd_His');
$outputDir = __DIR__ . "/../logs/plm_validation_{$timestamp}/";

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

function prepareAndRun(
    string $modelPath,
    array $replacements,
    string $dataDir,
    string $oplrun,
    int $timeLimit,
    string $runId,
    string $outputDir
): array {
    $content = file_get_contents($modelPath);
    $isNlm = strpos($content, 'using CP;') !== false;

    if ($isNlm) {
        $content = preg_replace(
            '/cp\.param\.TimeLimit\s*=\s*\d+/',
            "cp.param.TimeLimit = {$timeLimit}",
            $content
        );
    } else {
        $content = preg_replace(
            '/(execute\s*\{\s*\/\/BOM Nodes Data)/',
            "execute {\n    cplex.tilim = {$timeLimit};\n//BOM Nodes Data",
            $content,
            1
        );
    }

    $content = str_replace(array_keys($replacements), array_values($replacements), $content);
    $preparedPath = $dataDir . strtoupper($runId) . '_' . basename($modelPath);
    file_put_contents($preparedPath, $content);

    $rawOutput = shell_exec('"' . $oplrun . '" ' . escapeshellarg($preparedPath));
    file_put_contents($outputDir . $runId . '.log', $rawOutput ?: '');
    @unlink($preparedPath);

    if (!$rawOutput) {
        return ['status' => 'ERROR'];
    }

    return CplexRunner::parse($rawOutput);
}

function runModel(
    string $modelName,
    int $size,
    float $tax,
    int $cap,
    string $modelDir,
    string $dataDir,
    string $oplrun,
    int $timeLimit,
    string $runId,
    string $outputDir
): array {
    return prepareAndRun(
        $modelDir . $modelName,
        [
            '_NODE_FILE_' => "bom_supemis_{$size}.csv",
            '_NODE_SUPP_FILE_' => "supp_list_{$size}.csv",
            '_SUPP_DETAILS_FILE_' => 'supp_details_supeco_grdCapacity.csv',
            '_NBSUPP_' => 10,
            '_SERVICE_T_' => 1,
            '_EMISCAP_' => $cap,
            '_EMISTAXE_' => $tax,
        ],
        $dataDir,
        $oplrun,
        $timeLimit,
        $runId,
        $outputDir
    );
}

function objective(array $result): ?float {
    return isset($result['Result']['Objective']) ? (float)$result['Result']['Objective'] : null;
}

function scalar(array $result, string $key): ?float {
    return isset($result[$key]) && is_numeric($result[$key]) ? (float)$result[$key] : null;
}

function emissions(array $result): ?float {
    if (isset($result['Result']['Emissions']) && is_numeric($result['Result']['Emissions'])) {
        return (float)$result['Result']['Emissions'];
    }
    return scalar($result, 'E');
}

function runtime(array $result): ?float {
    if (isset($result['RT']) && is_numeric($result['RT'])) {
        return (float)$result['RT'];
    }
    if (isset($result['CplexRunTime']) && preg_match('/([\d,.]+)/', (string)$result['CplexRunTime'], $matches)) {
        return (float)str_replace(',', '.', $matches[1]);
    }
    return null;
}

function sameArray(array $left, array $right, string $key): ?bool {
    if (!isset($left[$key], $right[$key]) || !is_array($left[$key]) || !is_array($right[$key])) {
        return null;
    }
    return $left[$key] === $right[$key];
}

function modelsFor(string $strategy): array {
    $suffix = ucfirst(strtolower($strategy));
    return [
        "RUNS_SupEmis_Cplex_PLM_{$suffix}.mod",
        "RUNS_SupEmis_CP_NLM_{$suffix}.mod",
    ];
}

$rows = [];
$baselines = [];
$allSizes = array_values(array_unique(array_merge($equivalenceSizes, $stressSizes)));

echo "Computing PLM baselines...\n";
foreach ($allSizes as $size) {
    $result = runModel(
        'RUNS_SupEmis_Cplex_PLM_Tax.mod',
        $size,
        0.0,
        999999999,
        $modelDir,
        $dataDir,
        $oplrun,
        $timeLimit,
        "baseline_{$size}",
        $outputDir
    );
    $baselines[$size] = emissions($result) ?? 999999999;
    echo "  N={$size}: status={$result['status']}, emissions={$baselines[$size]}\n";
}

echo "Running PLM/NLM equivalence comparisons...\n";
foreach ($equivalenceSizes as $size) {
    foreach ($strategies as $strategy) {
        [$plmModel, $nlmModel] = modelsFor($strategy);
        $tax = $strategy === 'CAP' ? 0.0 : 50.0;
        $cap = $strategy === 'TAX' ? 99999999999 : (int)ceil($baselines[$size]);
        $plm = runModel($plmModel, $size, $tax, $cap, $modelDir, $dataDir, $oplrun, $timeLimit, "eq_{$size}_{$strategy}_plm", $outputDir);
        $nlm = runModel($nlmModel, $size, $tax, $cap, $modelDir, $dataDir, $oplrun, $timeLimit, "eq_{$size}_{$strategy}_nlm", $outputDir);
        $plmObjective = objective($plm);
        $nlmObjective = objective($nlm);
        $absDifference = ($plmObjective !== null && $nlmObjective !== null) ? abs($plmObjective - $nlmObjective) : null;

        $rows[] = [
            'test_type' => 'equivalence',
            'size' => $size,
            'strategy' => $strategy,
            'plm_status' => $plm['status'] ?? 'UNKNOWN',
            'nlm_status' => $nlm['status'] ?? 'UNKNOWN',
            'plm_objective' => $plmObjective,
            'nlm_objective' => $nlmObjective,
            'abs_objective_difference' => $absDifference,
            'plm_runtime_sec' => runtime($plm),
            'nlm_runtime_sec' => runtime($nlm),
            'nlm_gap_pct' => $nlm['mip_gap'] ?? null,
            'same_buffer_vector' => sameArray($plm, $nlm, 'X'),
            'same_lead_time_vector' => sameArray($plm, $nlm, 'A'),
        ];
        echo "  N={$size} {$strategy}: PLM={$plm['status']} NLM={$nlm['status']} diff=" . ($absDifference ?? 'NA') . "\n";
    }
}

echo "Running PLM stress cases...\n";
foreach ($stressSizes as $size) {
    foreach ($strategies as $strategy) {
        [$plmModel] = modelsFor($strategy);
        $tax = $strategy === 'CAP' ? 0.0 : 50.0;
        $cap = $strategy === 'TAX' ? 99999999999 : (int)floor($baselines[$size] * 0.85);
        $plm = runModel($plmModel, $size, $tax, $cap, $modelDir, $dataDir, $oplrun, $timeLimit, "stress_{$size}_{$strategy}_plm", $outputDir);
        $rows[] = [
            'test_type' => 'stress',
            'size' => $size,
            'strategy' => $strategy,
            'plm_status' => $plm['status'] ?? 'UNKNOWN',
            'nlm_status' => null,
            'plm_objective' => objective($plm),
            'nlm_objective' => null,
            'abs_objective_difference' => null,
            'plm_runtime_sec' => runtime($plm),
            'nlm_runtime_sec' => null,
            'nlm_gap_pct' => null,
            'same_buffer_vector' => null,
            'same_lead_time_vector' => null,
        ];
        echo "  N={$size} {$strategy}: status={$plm['status']} runtime={$rows[array_key_last($rows)]['plm_runtime_sec']}s\n";
    }
}

$csvPath = $outputDir . 'plm_validation_results.csv';
$fp = fopen($csvPath, 'w');
fputcsv($fp, array_keys($rows[0]));
foreach ($rows as $row) {
    fputcsv($fp, $row);
}
fclose($fp);

$equivalenceRows = array_filter($rows, fn(array $row): bool => $row['test_type'] === 'equivalence');
$optimalPairs = array_filter($equivalenceRows, fn(array $row): bool => $row['plm_status'] === 'OPTIMAL' && $row['nlm_status'] === 'OPTIMAL');
$matchingPairs = array_filter($optimalPairs, fn(array $row): bool => $row['abs_objective_difference'] !== null && $row['abs_objective_difference'] <= 1.0);
$stressRows = array_filter($rows, fn(array $row): bool => $row['test_type'] === 'stress');
$optimalStress = array_filter($stressRows, fn(array $row): bool => $row['plm_status'] === 'OPTIMAL');
$stressRuntimes = array_filter(array_column($stressRows, 'plm_runtime_sec'), 'is_numeric');

$summary = "# PLM Validation Summary\n\n";
$summary .= "- Optimal PLM/NLM pairs: " . count($optimalPairs) . "\n";
$summary .= "- Matching objectives within solver tolerance (absolute difference <= 1): " . count($matchingPairs) . "\n";
$summary .= "- Optimal PLM stress cases: " . count($optimalStress) . '/' . count($stressRows) . "\n";
if ($stressRuntimes) {
    $summary .= "- Maximum PLM stress runtime: " . max($stressRuntimes) . " s\n";
}
$summary .= "\nThe benchmark supports empirical equivalence only for pairs in which both formulations terminate optimally.\n";
file_put_contents($outputDir . 'summary.md', $summary);

echo "\nResults: {$csvPath}\n";
echo $summary;
