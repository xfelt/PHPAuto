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
    '_EMISCAP_' => 1.0e30,
    '_EMISTAXE_' => 0.0,
    'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Tax.mod',
    'MODEL_TYPE' => 'PLM',
    'EXPERIMENT' => 'lexicographic_baseline_test',
], 'bom_5');

$config = $result['config'];
$kpis = $result['kpis'];
$optimalCost = (float)$config['BASELINE_COST_OPTIMUM'];
$tolerance = (float)$config['BASELINE_COST_TOLERANCE'];
$baselineCost = (float)$kpis['cost']['total_cost_without_tax'];

if (($kpis['computational']['solver_status'] ?? null) !== 'OPTIMAL') {
    throw new RuntimeException('Lexicographic emissions stage did not terminate optimally');
}
if ($baselineCost > $optimalCost + $tolerance + 1e-6) {
    throw new RuntimeException(
        "Lexicographic baseline cost {$baselineCost} exceeds {$optimalCost} + {$tolerance}"
    );
}
if (($config['BASELINE_METHOD'] ?? null) !== 'LEXICOGRAPHIC_COST_THEN_EMISSIONS') {
    throw new RuntimeException('Lexicographic baseline method metadata missing');
}

echo "Lexicographic baseline test passed.\n";
