<?php

require_once __DIR__ . '/../src/KPICalculator.php';

function assertNear(float $expected, ?float $actual, string $message): void {
    if ($actual === null || abs($expected - $actual) > 1e-9) {
        throw new RuntimeException(
            $message . ': expected ' . $expected . ', got ' . var_export($actual, true)
        );
    }
}

$calculator = new KPICalculator();
$calculator->setBaseline('test-instance', ['total_emissions' => 3500000]);
$kpis = $calculator->computeAllKPIs(
    ['E' => 2500000],
    [
        'PREFIXE' => 'carbon-unit-test',
        'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Tax.mod',
        '_EMISTAXE_' => 50.0,
        'CAP_LEVEL' => 'none',
    ],
    'test-instance'
);

assertNear(125.0, $kpis['cost']['carbon_cost'], 'Carbon cost converts gCO2 to tCO2');
if ($kpis['cap_level'] !== 'none') {
    throw new RuntimeException('Semantic no-cap level was not preserved');
}

$sroi = $calculator->calculateSROI($kpis, [
    'carbon_price_per_ton' => 50.0,
    'implementation_cost' => 100.0,
]);
assertNear(50.0, $sroi['environmental_returns'], 'S-ROI converts gCO2 reduction to tCO2');
assertNear(0.5, $sroi['sroi_ratio'], 'S-ROI uses corrected environmental return');

$optimal = $calculator->computeAllKPIs(['status' => 'OPTIMAL'], [], 'test-instance');
$smallGap = $calculator->computeAllKPIs(
    ['status' => 'FEASIBLE', 'mip_gap' => 0.8],
    [],
    'test-instance'
);
$thresholdGap = $calculator->computeAllKPIs(
    ['status' => 'FEASIBLE', 'mip_gap' => 1.0],
    [],
    'test-instance'
);
$largeGap = $calculator->computeAllKPIs(
    ['status' => 'FEASIBLE', 'mip_gap' => 1.2],
    [],
    'test-instance'
);
$missingGap = $calculator->computeAllKPIs(['status' => 'FEASIBLE'], [], 'test-instance');
$infeasible = $calculator->computeAllKPIs(['status' => 'INFEASIBLE'], [], 'test-instance');

if (!$optimal['computational']['comparison_admissible']
    || !$smallGap['computational']['comparison_admissible']
    || !$thresholdGap['computational']['comparison_admissible']) {
    throw new RuntimeException('Optimal and feasible solutions at or below the 1% gap must be admissible');
}
if ($largeGap['computational']['comparison_admissible']
    || $missingGap['computational']['comparison_admissible']
    || $infeasible['computational']['comparison_admissible']) {
    throw new RuntimeException('Large-gap, missing-gap, and infeasible runs must be excluded from comparisons');
}
if ($largeGap['computational']['comparison_exclusion_reason'] !== 'GAP_ABOVE_THRESHOLD') {
    throw new RuntimeException('Large-gap exclusion reason was not exported');
}

$flat = $calculator->flattenKPIs($largeGap);
if ($flat['comparison_admissible'] !== 0) {
    throw new RuntimeException('Comparison admissibility was not flattened for CSV export');
}
if (array_keys($flat) !== KPICalculator::getCSVHeaders()) {
    throw new RuntimeException('Flattened KPI fields and CSV headers are out of sync');
}

$strictCalculator = new KPICalculator(0.5);
$strictResult = $strictCalculator->computeAllKPIs(
    ['status' => 'FEASIBLE', 'mip_gap' => 0.8],
    [],
    'test-instance'
);
if ($strictResult['computational']['comparison_admissible']) {
    throw new RuntimeException('Configured comparison-gap threshold was not applied');
}

echo "KPI carbon-price and comparison-admissibility tests passed.\n";
