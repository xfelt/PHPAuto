<?php
/**
 * Re-run NLM Comparison experiments that failed
 * This script runs only the NLM comparison phase and updates the consolidated results
 */

$config = require __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/FileUtils.php';
require_once __DIR__ . '/CplexRunner.php';
require_once __DIR__ . '/KPICalculator.php';

// Configuration
$baseDir = realpath(__DIR__ . '/..');
$campaignDir = $baseDir . '/logs/final_campaign_20260118_134900/';
$dataDir = $baseDir . '/data/';
$modelDir = $baseDir . '/models/';
$configDir = $baseDir . '/config/';
$timeLimitSec = 1800;
$oplRunPath = $config['OPLRUN'];

// Load instance registry
$instanceRegistry = json_decode(file_get_contents($configDir . 'instance_registry.json'), true);
$kpiCalculator = new KPICalculator([]);

// NLM Comparison config
$strategies = ['EMISTAXE', 'EMISCAP'];
$instances = ['bom_5', 'bom_13', 'bom_26'];

$results = [];

echo "=== RE-RUNNING NLM COMPARISON (Fixed) ===\n";

// Helper function to find instance in registry
function findInstanceInRegistry($registry, $instanceId) {
    foreach ($registry['bom_families'] as $family => $data) {
        foreach ($data['instances'] as $inst) {
            if ($inst['id'] === $instanceId) {
                return [
                    'id' => $inst['id'],
                    'bom_file' => $inst['file'],
                    'N' => $inst['nodes'],
                    'M' => $inst['depth'] ?? null,
                    'family' => $family,
                    'topology' => $inst['topology'] ?? 'tree'
                ];
            }
        }
    }
    return null;
}

// Helper function to determine supplier files based on node count
function getSupplierFiles($dataDir, $nodeCount) {
    // Default supplier files
    $listFile = 'supp_list_10.csv';
    // Use the standard supplier details files
    $detailsFile = ($nodeCount >= 25) ? 
        'supp_details_supeco_grdCapacity.csv' : 
        'supp_details_supeco.csv';
    
    // Try to find matching list file
    $possibleListFiles = [
        "supp_list_{$nodeCount}.csv",
        "supp_list_" . (int)(ceil($nodeCount/10)*10) . ".csv",
        "supp_list_10.csv"
    ];
    
    foreach ($possibleListFiles as $f) {
        if (file_exists($dataDir . $f)) {
            $listFile = $f;
            break;
        }
    }
    
    return ['list' => $listFile, 'details' => $detailsFile];
}

// Custom output parser (since CplexRunner::parseOutput is private)
function parseOplOutput($output) {
    $solution = [];
    $normalizedOutput = str_replace(["\r\n", "\r"], "\n", $output);
    
    // Extract runtime
    if (preg_match('/Total \(root\+branch&cut\)\s*=\s*([\d.,]+)\s*sec/', $normalizedOutput, $m)) {
        $solution['CplexRunTime'] = 'Total (root+branch&cut) = ' . $m[1] . ' sec';
    } elseif (preg_match('/! Time\s*=\s*([\d.,]+)/', $normalizedOutput, $m)) {
        $solution['CplexRunTime'] = 'CP Time = ' . $m[1];
    }
    
    // Extract solution block
    if (preg_match('/xxxx(.*)xxxx/s', $normalizedOutput, $match)) {
        $block = $match[1];
        
        // Parse key-value pairs
        if (preg_match('/#Result[^:]*:\s*<([^>]+)>/', $block, $rm)) {
            $parts = explode(' ', trim($rm[1]));
            $solution['Result'] = [
                'fctObj' => isset($parts[0]) ? (float)$parts[0] : null,
                'StCosts' => isset($parts[1]) ? (float)$parts[1] : null,
                'lts' => isset($parts[2]) ? (float)$parts[2] : null,
                'emiss' => isset($parts[3]) ? (float)$parts[3] : null
            ];
        }
        
        // Extract individual values
        if (preg_match('/#TS:\s*([\d.]+)/', $block, $m)) {
            $solution['TS'] = (float)$m[1];
        }
        if (preg_match('/#CS:\s*([\d.]+)/', $block, $m)) {
            $solution['CS'] = (float)$m[1];
        }
        if (preg_match('/#E:\s*([\d.e+\-]+)/i', $block, $m)) {
            $solution['E'] = (float)$m[1];
        }
        if (preg_match('/#A:\s*\[([^\]]+)\]/', $block, $m)) {
            $solution['A'] = array_map('intval', explode(',', $m[1]));
        }
        if (preg_match('/#X:\s*\[([^\]]+)\]/', $block, $m)) {
            $solution['X'] = array_map('intval', explode(',', $m[1]));
        }
        
        // Extract DELIVER
        $solution['DELIVER'] = [];
        if (preg_match_all('/S(\d+)=>P(\d+)/', $block, $dm, PREG_SET_ORDER)) {
            foreach ($dm as $d) {
                $solution['DELIVER'][] = "S{$d[1]}=>P{$d[2]}";
            }
        }
    }
    
    $solution['_raw_output'] = $normalizedOutput;
    return $solution;
}

foreach ($instances as $instanceId) {
    $instance = findInstanceInRegistry($instanceRegistry, $instanceId);
    
    if (!$instance) {
        echo "  Instance not found: $instanceId\n";
        continue;
    }
    
    $supplierFiles = getSupplierFiles($dataDir, $instance['N']);
    
    foreach ($strategies as $strategy) {
        // NLM run
        $modelType = 'NLM';
        $runId = "COMP-{$instanceId}-{$strategy}-{$modelType}";
        echo "  Running: $runId\n";
        
        // Get model file based on strategy
        if ($strategy === 'EMISTAXE') {
            $modelFile = 'RUNS_SupEmis_CP_NLM_Tax.mod';
            $taxRate = 0.01;
            $capValue = 999999999;
        } else {
            $modelFile = 'RUNS_SupEmis_CP_NLM_Cap.mod';
            $taxRate = 0.0;
            $capValue = 2500000;
        }
        
        // Prepare run config (use forward slashes for OPL compatibility)
        $bomPath = str_replace('\\', '/', $dataDir . $instance['bom_file']);
        $suppListPath = str_replace('\\', '/', $dataDir . $supplierFiles['list']);
        $suppDetailsPath = str_replace('\\', '/', $dataDir . $supplierFiles['details']);
        
        $runConfig = [
            '_NODE_FILE_' => $bomPath,
            '_NODE_SUPP_FILE_' => $suppListPath,
            '_SUPP_DETAILS_FILE_' => $suppDetailsPath,
            '_NBSUPP_' => '10',
            '_SERVICE_T_' => '1',
            '_EMISCAP_' => (string)$capValue,
            '_EMISTAXE_' => (string)$taxRate,
            'PREFIXE' => $runId,
            'MODEL_FILE' => $modelFile,
            'MODEL_TYPE' => $modelType,
            'EXPERIMENT' => 'nlm_comparison',
            'STRATEGY' => $strategy
        ];
        
        // Prepare model file (with correct CP time limit handling)
        $modelPath = $modelDir . $modelFile;
        $content = file_get_contents($modelPath);
        
        // Update CP time limit (NLM uses cp.param.TimeLimit, not cplex.tilim)
        $content = preg_replace(
            '/cp\.param\.TimeLimit\s*=\s*\d+/',
            "cp.param.TimeLimit = {$timeLimitSec}",
            $content
        );
        
        // Apply parameter replacements
        foreach ($runConfig as $key => $value) {
            $content = str_replace($key, $value, $content);
        }
        
        // Write prepared model
        $outputFile = $dataDir . strtoupper($runId) . "_" . basename($modelPath);
        file_put_contents($outputFile, $content);
        
        // Execute OPL
        $cmdLine = '"' . $oplRunPath . '" ' . escapeshellarg($outputFile);
        echo "    Executing: $cmdLine\n";
        $rawOutput = shell_exec($cmdLine . ' 2>&1');
        
        // Parse result
        $parsed = parseOplOutput($rawOutput);
        
        // Save log
        $logFile = $campaignDir . 'logs/' . $runId . '.log';
        file_put_contents($logFile, print_r($parsed, true));
        
        // Add metadata for KPI calculation
        $parsed['_run_config'] = $runConfig;
        $parsed['_instance'] = $instance;
        
        // Calculate KPIs
        $kpis = $kpiCalculator->computeAllKPIs($parsed, $runConfig, $instanceId);
        $kpis['run_id'] = $runId;
        $kpis['experiment'] = 'nlm_comparison';
        $kpis['instance_id'] = $instanceId;
        $kpis['bom_size'] = $instance['N'] ?? null;
        $kpis['topology'] = $instance['family'] ?? 'unknown';
        $kpis['strategy'] = $strategy;
        $kpis['model_type'] = $modelType;
        
        $results[] = [
            'config' => $runConfig,
            'result' => $parsed,
            'kpis' => $kpis
        ];
        
        $status = $kpis['solver_status'] ?? 'UNKNOWN';
        $obj = $kpis['objective_value'] ?? 'N/A';
        $runtime = $kpis['runtime_seconds'] ?? 'N/A';
        echo "    Status: $status, Obj: $obj, Time: {$runtime}s\n";
    }
}

echo "\n=== UPDATING CONSOLIDATED RESULTS ===\n";

// Load existing consolidated results
$consolidatedFile = $campaignDir . 'consolidated_results.csv';
$existingData = [];
$headers = [];

if (file_exists($consolidatedFile)) {
    $fp = fopen($consolidatedFile, 'r');
    $headers = fgetcsv($fp);
    while (($row = fgetcsv($fp)) !== false) {
        $rowData = array_combine($headers, $row);
        // Skip old NLM comparison runs (we'll add fresh ones)
        if (strpos($rowData['run_id'] ?? '', 'COMP-') === 0 && 
            strpos($rowData['run_id'] ?? '', '-NLM') !== false) {
            continue;
        }
        $existingData[] = $rowData;
    }
    fclose($fp);
}

// Add new NLM results
foreach ($results as $result) {
    $flat = $kpiCalculator->flattenKPIs($result['kpis']);
    $existingData[] = $flat;
}

// Write updated consolidated results
$fp = fopen($consolidatedFile, 'w');
if (empty($headers)) {
    $headers = KPICalculator::getCSVHeaders();
}
fputcsv($fp, $headers);
foreach ($existingData as $row) {
    $csvRow = [];
    foreach ($headers as $h) {
        $csvRow[] = $row[$h] ?? '';
    }
    fputcsv($fp, $csvRow);
}
fclose($fp);

echo "Updated consolidated results: $consolidatedFile\n";

// Also update the nlm_comparison_results.csv
$nlmFile = $campaignDir . 'tables/nlm_comparison_results.csv';
$fp = fopen($nlmFile, 'w');
fputcsv($fp, KPICalculator::getCSVHeaders());
foreach ($results as $result) {
    $flat = $kpiCalculator->flattenKPIs($result['kpis']);
    fputcsv($fp, array_values($flat));
}
fclose($fp);
echo "Updated NLM comparison results: $nlmFile\n";

echo "\n=== NLM COMPARISON RE-RUN COMPLETE ===\n";
echo "Results summary:\n";
foreach ($results as $r) {
    $kpis = $r['kpis'];
    printf("  %s: Status=%s, Obj=%.2f, Emissions=%.0f, Time=%.2fs\n",
        $kpis['run_id'],
        $kpis['solver_status'] ?? 'UNKNOWN',
        $kpis['objective_value'] ?? 0,
        $kpis['total_emissions'] ?? 0,
        $kpis['runtime_seconds'] ?? 0
    );
}
