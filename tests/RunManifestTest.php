<?php

require_once __DIR__ . '/../src/FinalCampaignRunner.php';

$runner = new FinalCampaignRunner(false);
$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpauto_manifest_' . uniqid('', true);
$resultsDir = $tmpRoot . DIRECTORY_SEPARATOR;
$tablesDir = $resultsDir . 'tables' . DIRECTORY_SEPARATOR;
$figuresDir = $resultsDir . 'figures' . DIRECTORY_SEPARATOR;
mkdir($tablesDir, 0777, true);
mkdir($figuresDir, 0777, true);

$setProperty = function(string $name, $value) use ($runner): void {
    $property = new ReflectionProperty(FinalCampaignRunner::class, $name);
    $property->setAccessible(true);
    $property->setValue($runner, $value);
};

$removeTree = function(string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full)) {
            $removeTree($full);
        } else {
            unlink($full);
        }
    }
    rmdir($path);
};

try {
    $setProperty('resultsDir', $resultsDir);
    $setProperty('tablesDir', $tablesDir);
    $setProperty('figuresDir', $figuresDir);

    $method = new ReflectionMethod(FinalCampaignRunner::class, 'saveCampaignPlan');
    $method->setAccessible(true);
    $method->invoke($runner);

    $manifestPath = $resultsDir . 'run_manifest.json';
    $planPath = $resultsDir . 'campaign_plan.json';
    if (!is_file($manifestPath) || !is_file($planPath)) {
        throw new RuntimeException('Campaign plan or run manifest was not written');
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);
    $plan = json_decode(file_get_contents($planPath), true);
    if (!is_array($manifest) || !is_array($plan)) {
        throw new RuntimeException('Campaign plan or run manifest is not valid JSON');
    }

    $consolidated = $manifest['consolidated_runs'] ?? [];
    $internal = $manifest['internal_solver_runs'] ?? [];
    $multiObj = $manifest['multi_objective_solver_runs'] ?? [];
    $conditional = $manifest['conditional_decision_stability_runs'] ?? [];

    if (count($consolidated) !== 301) {
        throw new RuntimeException('Unexpected consolidated manifest count');
    }
    if (count($internal) !== 0) {
        throw new RuntimeException('Unexpected lexicographic internal manifest count');
    }
    if (count($multiObj) !== 72) {
        throw new RuntimeException('Unexpected multi-objective manifest count');
    }
    if (count($conditional) !== 108) {
        throw new RuntimeException('Unexpected conditional decision-stability manifest count');
    }
    if (($plan['manifest_counts']['consolidated_runs'] ?? null) !== 301) {
        throw new RuntimeException('Manifest counts were not copied into campaign plan');
    }

    $ids = array_column($consolidated, 'run_id');
    foreach ([
        'SCAL-005',
        'TOPO-multi_level-bom_ml4_30',
        'TAX-bom_5-50.00',
        'CAP-bom_13-85',
        'HYB-bom_26-tax_75_cap_70',
        'SVT-bom_50-EMISCAP-SvT3',
        'COMP-bom_13-EMISCAP-NLM',
    ] as $expected) {
        if (!in_array($expected, $ids, true)) {
            throw new RuntimeException("Missing expected manifest run id: {$expected}");
        }
    }

    $conditionalIds = array_column($conditional, 'run_id');
    if (!in_array('TAX-bom_5-50.00-STAB-BUFFERS', $conditionalIds, true)) {
        throw new RuntimeException('Missing expected conditional stability probe id');
    }

    echo "Run manifest tests passed.\n";
} finally {
    $removeTree($tmpRoot);
}
