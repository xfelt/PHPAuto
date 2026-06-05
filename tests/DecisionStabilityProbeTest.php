<?php

require_once __DIR__ . '/../src/FinalCampaignRunner.php';

function removeDirectoryTree(string $path): void {
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$runner = new FinalCampaignRunner();
$resultsDirProperty = new ReflectionProperty(FinalCampaignRunner::class, 'resultsDir');
$resultsDirProperty->setAccessible(true);
$resultsDir = $resultsDirProperty->getValue($runner);

try {
    $execute = new ReflectionMethod(FinalCampaignRunner::class, 'executeSingleRun');
    $execute->setAccessible(true);

    $baseConfig = [
        'PREFIXE' => 'TEST-STABILITY-BASE',
        '_NODE_FILE_' => 'bom_supemis_5.csv',
        '_NODE_SUPP_FILE_' => 'supp_list_5.csv',
        '_SUPP_DETAILS_FILE_' => 'supp_details_supeco.csv',
        '_NBSUPP_' => 10,
        '_SERVICE_T_' => 1,
        '_EMISCAP_' => 2500000,
        '_EMISTAXE_' => 50.0,
        'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Tax.mod',
        'MODEL_TYPE' => 'PLM',
        'EXPERIMENT' => 'decision_stability_probe_test',
    ];

    $base = $execute->invoke($runner, $baseConfig, 'bom_5', false);
    $baseStatus = $base['kpis']['computational']['solver_status'] ?? 'UNKNOWN';
    if ($baseStatus !== 'OPTIMAL') {
        throw new RuntimeException("Base stability test run status={$baseStatus}");
    }
    foreach (['X', 'Z', 'Q'] as $key) {
        if (!isset($base['result'][$key]) || !is_array($base['result'][$key])) {
            throw new RuntimeException("Base stability test run missing {$key}");
        }
    }

    $referenceObjective = (float)$base['kpis']['cost']['objective_value'];
    $probeConfig = $baseConfig;
    $probeConfig['PREFIXE'] = 'TEST-STABILITY-BUFFERS';
    $probeConfig['STABILITY_PROBE'] = 'buffers';
    $probeConfig['STABILITY_REFERENCE_X'] = $base['result']['X'];
    $probeConfig['STABILITY_REFERENCE_Z'] = $base['result']['Z'];
    $probeConfig['STABILITY_REFERENCE_Q'] = $base['result']['Q'];
    $probeConfig['STABILITY_OBJECTIVE_LIMIT'] = $referenceObjective * 1.01 + 1.0e-6;

    $probe = $execute->invoke($runner, $probeConfig, 'bom_5', false);
    $probeStatus = $probe['kpis']['computational']['solver_status'] ?? 'UNKNOWN';
    if ($probeStatus !== 'OPTIMAL') {
        throw new RuntimeException("Decision-stability probe status={$probeStatus}");
    }

    $alternativeObjective = (float)($probe['result']['STABILITY_ORIGINAL_OBJECTIVE'] ?? INF);
    if ($alternativeObjective > $probeConfig['STABILITY_OBJECTIVE_LIMIT'] + 1.0e-6) {
        throw new RuntimeException('Decision-stability probe violated the 1% objective band');
    }

    $comparison = DecisionStabilityAnalyzer::compare($base['result'], $probe['result']);
    foreach (['buffer_jaccard_similarity', 'supplier_jaccard_similarity', 'allocation_l1_normalized'] as $metric) {
        if (!is_numeric($comparison[$metric])) {
            throw new RuntimeException("Decision-stability metric {$metric} was not computed");
        }
    }

    echo "Decision-stability probe test passed.\n";
} finally {
    $workspace = realpath(__DIR__ . '/..');
    $resolvedResultsDir = realpath($resultsDir);
    if ($workspace !== false
        && $resolvedResultsDir !== false
        && strpos($resolvedResultsDir, $workspace . DIRECTORY_SEPARATOR) === 0
        && strpos(basename($resolvedResultsDir), 'final_campaign_') === 0) {
        removeDirectoryTree($resolvedResultsDir);
    }
}
