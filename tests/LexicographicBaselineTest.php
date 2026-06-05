<?php

require_once __DIR__ . '/../src/FinalCampaignRunner.php';

$runner = new FinalCampaignRunner();
$method = new ReflectionMethod(FinalCampaignRunner::class, 'executeLexicographicBaseline');
$method->setAccessible(true);

$result = $method->invoke($runner, [
    'PREFIXE' => 'TEST-LEX-BASELINE-5',
    '_NODE_FILE_' => 'bom_supemis_5.csv',
    '_NODE_SUPP_FILE_' => 'supp_list_5.csv',
    '_SUPP_DETAILS_FILE_' => 'supp_details_supeco.csv',
    '_NBSUPP_' => 10,
    '_SERVICE_T_' => 1,
    '_EMISCAP_' => 100000000.0,
    '_EMISTAXE_' => 0.0,
    'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Tax.mod',
    'MODEL_TYPE' => 'PLM',
    'EXPERIMENT' => 'lexicographic_baseline_test',
], 'bom_5');

$config = $result['config'];
$kpis = $result['kpis'];
$baselineCost = (float)$kpis['cost']['total_cost_without_tax'];
$baselineEmissions = $kpis['carbon']['total_emissions'] ?? null;

if (($kpis['computational']['solver_status'] ?? null) !== 'OPTIMAL') {
    throw new RuntimeException('Native lexicographic baseline did not terminate optimally');
}
if (($config['PREFIXE'] ?? null) !== 'TEST-LEX-BASELINE-5') {
    throw new RuntimeException('Native lexicographic baseline must keep the reported run prefix');
}
if (($config['BASELINE_METHOD'] ?? null) !== 'STATIC_LEX_COST_THEN_EMISSIONS') {
    throw new RuntimeException('Lexicographic baseline method metadata missing');
}
if (($config['BASELINE_LEX_OBJECTIVE'] ?? null) !== 'staticLex(TotalCostCS, Emis)') {
    throw new RuntimeException('Native lexicographic objective metadata missing');
}
if ($baselineCost <= 0.0 || $baselineEmissions === null) {
    throw new RuntimeException('Native lexicographic baseline did not return cost and emissions KPIs');
}

echo "Lexicographic baseline test passed.\n";
