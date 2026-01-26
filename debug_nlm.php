<?php
require_once 'src/KPICalculator.php';

// Load the log content directly
$logFile = 'logs/final_campaign_20260118_134900/logs/COMP-bom_5-EMISTAXE-NLM.log';
$logContent = file_get_contents($logFile);

// The log is a print_r'd array, we need to parse it
// For now, let's just check what we have in the actual parsed data

echo "=== DEBUG NLM KPI EXTRACTION ===\n\n";

// Simulate the parsed result based on what we see in the log
$parsed = [
    'CplexRunTime' => 'CP Time = 0,16',
    'Result' => [
        'fctObj' => 74119,
        'StCosts' => 74092,
        'lts' => 27,
        'emiss' => 2253700
    ],
    'TS' => 74092,
    'E' => 2253700,
    'A' => [0, 2, 5, 10, 4, 6],
    'X' => [0, 1, 1, 1, 0, 1],
    'DELIVER' => ['S10=>P2', 'S3=>P4', 'S4=>P4', 'S10=>P4', 'S4=>P5']
];

echo "Parsed data:\n";
echo "  Has Result: " . (isset($parsed['Result']) ? 'YES' : 'NO') . "\n";
echo "  Has E: " . (isset($parsed['E']) ? 'YES - ' . $parsed['E'] : 'NO') . "\n";
echo "  Has TS: " . (isset($parsed['TS']) ? 'YES - ' . $parsed['TS'] : 'NO') . "\n";
echo "  CplexRunTime: " . ($parsed['CplexRunTime'] ?? 'N/A') . "\n";

// Test runtime extraction
$runtimeStr = $parsed['CplexRunTime'];
echo "\nRuntime extraction test:\n";
echo "  Input: '$runtimeStr'\n";

// CPLEX format
if (preg_match('/([\d,\.]+)\s*sec/', $runtimeStr, $matches)) {
    echo "  Matched CPLEX format: " . $matches[1] . "\n";
}
// CP Optimizer format
elseif (preg_match('/CP Time\s*=\s*([\d,\.]+)/', $runtimeStr, $matches)) {
    echo "  Matched CP format: " . $matches[1] . "\n";
    $runtime = (float)str_replace(',', '.', $matches[1]);
    echo "  Converted runtime: $runtime sec\n";
}
else {
    echo "  No match found!\n";
}

// Test KPI calculation
echo "\n=== KPI Calculation ===\n";
$kpi = new KPICalculator([]);
$runConfig = ['_EMISTAXE_' => '0.01', '_SERVICE_T_' => '1'];
$result = $kpi->computeAllKPIs($parsed, $runConfig, 'bom_5');

echo "Results:\n";
echo "  Solver Status: " . ($result['computational']['solver_status'] ?? 'N/A') . "\n";
echo "  Runtime: " . ($result['computational']['runtime_sec'] ?? 'N/A') . " sec\n";
echo "  Objective: " . ($result['cost']['objective_value'] ?? 'N/A') . "\n";
echo "  Total Cost TS: " . ($result['cost']['total_cost_with_tax'] ?? 'N/A') . "\n";
echo "  Emissions: " . ($result['carbon']['total_emissions'] ?? 'N/A') . "\n";
echo "  Buffer Count: " . ($result['ddmrp']['buffer_count'] ?? 'N/A') . "\n";
