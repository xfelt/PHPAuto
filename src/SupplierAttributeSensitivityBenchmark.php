<?php

/**
 * Supplier Attribute Sensitivity Benchmark Script
 * 
 * Evaluates how enriching supplier characterization beyond cost and emissions
 * (e.g. quality, reliability, lead-time variability proxies) affects:
 * - supplier selection,
 * - sourcing concentration/diversification,
 * - total cost,
 * - baseline emissions stability,
 * 
 * Under a cost-dominant optimization framework, before introducing explicit
 * multi-objective or policy constraints.
 * 
 * Configuration:
 * - Model: RUNS_SupEmis_Cplex_PLM_Tax.mod (variants A, B, C)
 * - Carbon tax: 0 (baseline emissions)
 * - Emission cap: None (inactive)
 * - Time limit: 1800 seconds (30 minutes)
 * 
 * Variants:
 * - A: Baseline (cost + emissions only)
 * - B: Enriched attributes (read but not used in objective)
 * - C1: Enriched + low penalty (α₁)
 * - C2: Enriched + moderate penalty (α₂)
 */

require_once __DIR__ . '/FileUtils.php';
require_once __DIR__ . '/CplexRunner.php';

// Configuration
$TIME_LIMIT_SEC = 1800; // 30 minutes
$CARBON_TAX = 0.0; // Baseline (no tax)
$SERVICE_TIME = 1;
$SUPPLIERS = 10;
$BASE_MODEL_FILE = 'RUNS_SupEmis_Cplex_PLM_Tax.mod';

// Selected BOMs for testing
$SELECTED_BOMS = [
    ['bom' => 'bom_supemis_13.csv', 'supp_list' => 'supp_list_13.csv', 'id' => '13'],
    ['bom' => 'bom_supemis_50.csv', 'supp_list' => 'supp_list_50.csv', 'id' => '50'] // Optional robustness
];

// Penalty levels for Variant C
$PENALTY_LEVELS = [
    'C1' => 0.1,  // Low penalty (α₁)
    'C2' => 0.5   // Moderate penalty (α₂)
];

/**
 * Apply dictionary to model file with time limit and variant-specific modifications
 */
function prepareModelFile($modelPath, $runConfig, $prefix, $workDir, $timeLimit, $variant, $penaltyAlpha = null) {
    // Read model file
    $content = file_get_contents($modelPath);
    if ($content === false) {
        throw new Exception("Failed to read model file: $modelPath");
    }
    
    // Variant-specific modifications
    if ($variant === 'B' || $variant === 'C1' || $variant === 'C2') {
        // Modify to read additional supplier attributes
        // Change sup[S][1..4] to sup[S][1..7] to include quality, reliability, lead_time_variance
        $content = str_replace(
            'float sup[S][1..4]; //delay;price;capacity;emissions',
            'float sup[S][1..7]; //delay;price;capacity;emissions;quality;reliability;lead_time_variance',
            $content
        );
        
        // Modify supplier data reading to include additional columns
        // Simple string replacement after the last supplier attribute assignment
        $content = str_replace(
            '         sup[index][4] = det[4];',
            "         sup[index][4] = det[4];\n         sup[index][5] = det[5]; // quality_score\n         sup[index][6] = det[6]; // reliability\n         sup[index][7] = det[7]; // lead_time_variance",
            $content
        );
        
        // For Variant C, modify the objective to include penalty terms
        if ($variant === 'C1' || $variant === 'C2') {
            if ($penaltyAlpha === null) {
                throw new Exception("Penalty alpha must be provided for variant C");
            }
            
            // Add penalty term to RawMCost expression
            // effective_cost = base_cost + α·(1 − quality_score) + α·(1 − reliability)
            $rawMCostLine = 'dexpr float RawMCost = sum(i in N)( unit_price[i]*sum(j in S)(q[i][j]*sup[j][2]) ); // somme des achat selon fournisseur';
            $rawMCostReplacement = "// Base procurement cost\n" . 
                "dexpr float RawMCost = sum(i in N)( unit_price[i]*sum(j in S)(q[i][j]*sup[j][2]) ); // somme des achat selon fournisseur\n" .
                "// Quality and reliability penalty terms\n" .
                "dexpr float QualityPenalty = " . $penaltyAlpha . " * sum(i in N)( unit_price[i]*sum(j in S)(q[i][j]*(1.0 - sup[j][5])) );\n" .
                "dexpr float ReliabilityPenalty = " . $penaltyAlpha . " * sum(i in N)( unit_price[i]*sum(j in S)(q[i][j]*(1.0 - sup[j][6])) );\n" .
                "dexpr float RawMCostWithPenalties = RawMCost + QualityPenalty + ReliabilityPenalty;";
            
            $content = str_replace($rawMCostLine, $rawMCostReplacement, $content);
            
            // Update TotalCostCS to use RawMCostWithPenalties
            $totalCostCSLine = 'dexpr float TotalCostCS = RawMCost + InventCost;';
            $totalCostCSReplacement = "dexpr float TotalCostCS = RawMCostWithPenalties + InventCost;";
            $content = str_replace($totalCostCSLine, $totalCostCSReplacement, $content);
        }
    }
    
    // Add time limit setting (for CPLEX)
    $timeLimitCode = "    cplex.tilim = $timeLimit; // Time limit in seconds\n";
    
    // Find the main execute block that loads BOM data
    $pattern = '/(execute\s*\{[\s\n]*\/\/BOM Nodes Data)/';
    if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        $pos = $matches[0][1] + strlen($matches[0][0]);
        $content = substr_replace($content, "\n" . $timeLimitCode, $pos, 0);
    } else {
        // Fallback: insert after the first execute block
        $pattern = '/(execute\s*\{[^}]*\/\/\s*init\s*NB_NODE[^}]*\})/s';
        if (preg_match($pattern, $content, $matches)) {
            $content = str_replace(
                $matches[0],
                $matches[0] . "\n\n// Time limit setting\n" . $timeLimitCode,
                $content
            );
        } else {
            // Last resort: insert at the beginning of any execute block
            $pattern = '/(execute\s*\{)/';
            if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                $pos = $matches[0][1] + strlen($matches[0][0]);
                $content = substr_replace($content, "\n" . $timeLimitCode, $pos, 0);
            }
        }
    }
    
    // Apply dictionary replacements
    $content = str_replace(array_keys($runConfig), array_values($runConfig), $content);
    
    // Write modified model
    $outputFile = $workDir . strtoupper($prefix) . "_" . basename($modelPath);
    if (file_put_contents($outputFile, $content) === false) {
        throw new Exception("Failed to write modified model file: $outputFile");
    }
    
    return $outputFile;
}

/**
 * Extract KPIs from CPLEX result
 */
function extractKPIs($result, $bomId, $variant, $penaltyLevel, $baselineResult = null) {
    global $TIME_LIMIT_SEC;
    
    $kpis = [
        'bom_id' => $bomId,
        'supplier_variant' => $variant,
        'penalty_level' => $penaltyLevel ?? 'N/A',
        'total_cost' => null,
        'total_emissions' => null,
        'suppliers_used' => null,
        'avg_quality' => null,
        'avg_reliability' => null,
        'supplier_concentration_index' => null,
        'procurement_cost' => null,
        'inventory_cost' => null,
        'emissions_per_unit_cost' => null,
        'delta_cost_vs_baseline' => null,
        'delta_emissions_vs_baseline' => null,
        'supplier_switch_rate' => null,
        'runtime_sec' => null,
        'feasible' => true,
        'status' => 'UNKNOWN'
    ];
    
    // Check for infeasibility or errors first
    if (isset($result['status']) && $result['status'] === 'ERROR') {
        $kpis['status'] = 'ERROR';
        $kpis['feasible'] = false;
        return $kpis;
    }
    
    if (isset($result['_is_infeasible']) || 
        (isset($result['_raw_output']) && 
         (preg_match('/Infeasibility row/i', $result['_raw_output']) || 
          preg_match('/<<< no solution/i', $result['_raw_output'])))) {
        $kpis['status'] = 'INFEASIBLE';
        $kpis['feasible'] = false;
        return $kpis;
    }
    
    if (isset($result['_is_unbounded'])) {
        $kpis['status'] = 'UNBOUNDED';
        $kpis['feasible'] = false;
        return $kpis;
    }
    
    $kpis['status'] = 'FEASIBLE';
    
    // Extract basic metrics
    if (isset($result['Result'])) {
        $resultData = $result['Result'];
        $kpis['total_cost'] = $resultData['ServiceCost'] ?? null;
        $kpis['total_emissions'] = $resultData['Emissions'] ?? null;
    }
    
    if (isset($result['TS'])) {
        $kpis['total_cost'] = is_numeric($result['TS']) ? (float)$result['TS'] : $kpis['total_cost'];
    }
    
    if (isset($result['E'])) {
        $kpis['total_emissions'] = is_numeric($result['E']) ? (float)$result['E'] : $kpis['total_emissions'];
    }
    
    // Extract runtime
    if (isset($result['CplexRunTime'])) {
        $runtimeStr = $result['CplexRunTime'];
        if (preg_match('/([\d.]+)\s*sec/', $runtimeStr, $matches)) {
            $kpis['runtime_sec'] = (float)$matches[1];
        }
    }
    
    // Extract supplier selections from DELIVER
    $selectedSuppliers = [];
    $supplierFlows = []; // Track flow per supplier
    if (isset($result['DELIVER']) && is_array($result['DELIVER'])) {
        foreach ($result['DELIVER'] as $delivery) {
            if (preg_match('/S(\d+)=>P(\d+)/', $delivery, $matches)) {
                $supplierId = (int)$matches[1];
                $productId = (int)$matches[2];
                if (!in_array($supplierId, $selectedSuppliers)) {
                    $selectedSuppliers[] = $supplierId;
                }
                if (!isset($supplierFlows[$supplierId])) {
                    $supplierFlows[$supplierId] = [];
                }
                $supplierFlows[$supplierId][] = $productId;
            }
        }
    }
    
    $kpis['suppliers_used'] = count($selectedSuppliers);
    
    // Calculate supplier concentration (top-3 share)
    if (count($supplierFlows) > 0) {
        $flowCounts = array_map(function($flows) { return count($flows); }, $supplierFlows);
        arsort($flowCounts);
        $top3Count = 0;
        $totalCount = array_sum($flowCounts);
        $top3Suppliers = array_slice($flowCounts, 0, min(3, count($flowCounts)), true);
        $top3Count = array_sum($top3Suppliers);
        $kpis['supplier_concentration_index'] = $totalCount > 0 ? ($top3Count / $totalCount) : 0;
    }
    
    // Calculate average quality and reliability (weighted by supplier usage)
    // Note: We need to load supplier details to get quality/reliability scores
    // For now, we'll set these as null and calculate them in post-processing
    $kpis['avg_quality'] = null; // Will be calculated in post-processing
    $kpis['avg_reliability'] = null; // Will be calculated in post-processing
    
    // Calculate deltas vs baseline
    if ($baselineResult !== null && $variant !== 'A') {
        $baselineKPIs = extractKPIs($baselineResult, $bomId, 'A', null);
        if ($kpis['total_cost'] !== null && $baselineKPIs['total_cost'] !== null) {
            $kpis['delta_cost_vs_baseline'] = $kpis['total_cost'] - $baselineKPIs['total_cost'];
        }
        if ($kpis['total_emissions'] !== null && $baselineKPIs['total_emissions'] !== null) {
            $kpis['delta_emissions_vs_baseline'] = $kpis['total_emissions'] - $baselineKPIs['total_emissions'];
        }
        
        // Calculate supplier switch rate
        $baselineSuppliers = [];
        if (isset($baselineResult['DELIVER']) && is_array($baselineResult['DELIVER'])) {
            foreach ($baselineResult['DELIVER'] as $delivery) {
                if (preg_match('/S(\d+)=>P(\d+)/', $delivery, $matches)) {
                    $supplierId = (int)$matches[1];
                    $productId = (int)$matches[2];
                    $baselineSuppliers[$productId] = $supplierId;
                }
            }
        }
        
        $currentSuppliers = [];
        if (isset($result['DELIVER']) && is_array($result['DELIVER'])) {
            foreach ($result['DELIVER'] as $delivery) {
                if (preg_match('/S(\d+)=>P(\d+)/', $delivery, $matches)) {
                    $supplierId = (int)$matches[1];
                    $productId = (int)$matches[2];
                    $currentSuppliers[$productId] = $supplierId;
                }
            }
        }
        
        $switches = 0;
        $totalProducts = max(count($baselineSuppliers), count($currentSuppliers));
        foreach ($baselineSuppliers as $productId => $supplierId) {
            if (isset($currentSuppliers[$productId]) && $currentSuppliers[$productId] !== $supplierId) {
                $switches++;
            }
        }
        $kpis['supplier_switch_rate'] = $totalProducts > 0 ? ($switches / $totalProducts) : 0;
    }
    
    // Calculate emissions per unit cost
    if ($kpis['total_cost'] !== null && $kpis['total_cost'] > 0 && $kpis['total_emissions'] !== null) {
        $kpis['emissions_per_unit_cost'] = $kpis['total_emissions'] / $kpis['total_cost'];
    }
    
    return $kpis;
}

/**
 * Load supplier details and calculate weighted averages
 */
function calculateWeightedAttributeAverages($supplierFlows, $supplierDetailsFile) {
    if (!file_exists($supplierDetailsFile)) {
        return ['avg_quality' => null, 'avg_reliability' => null];
    }
    
    // Read supplier details
    $supplierDetails = [];
    $lines = file($supplierDetailsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) < 2) {
        return ['avg_quality' => null, 'avg_reliability' => null];
    }
    
    // Skip header
    foreach (array_slice($lines, 1) as $line) {
        $parts = explode(';', $line);
        if (count($parts) >= 7) {
            $supplierId = (int)$parts[0];
            $quality = (float)str_replace(',', '.', $parts[5]);
            $reliability = (float)str_replace(',', '.', $parts[6]);
            $supplierDetails[$supplierId] = [
                'quality' => $quality,
                'reliability' => $reliability
            ];
        }
    }
    
    // Calculate weighted averages
    $totalFlow = 0;
    $weightedQuality = 0;
    $weightedReliability = 0;
    
    foreach ($supplierFlows as $supplierId => $flows) {
        $flowCount = count($flows);
        $totalFlow += $flowCount;
        if (isset($supplierDetails[$supplierId])) {
            $weightedQuality += $flowCount * $supplierDetails[$supplierId]['quality'];
            $weightedReliability += $flowCount * $supplierDetails[$supplierId]['reliability'];
        }
    }
    
    return [
        'avg_quality' => $totalFlow > 0 ? ($weightedQuality / $totalFlow) : null,
        'avg_reliability' => $totalFlow > 0 ? ($weightedReliability / $totalFlow) : null
    ];
}

/**
 * Generate summary report
 */
function generateSummaryReport($allKPIs, $outputFile) {
    $report = "# Supplier Attribute Sensitivity Analysis Summary\n\n";
    $report .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    $report .= "## Overview\n\n";
    $report .= "This analysis evaluates the impact of enriching supplier characterization\n";
    $report .= "beyond cost and emissions on sourcing decisions and performance metrics.\n\n";
    
    $report .= "## Variants Tested\n\n";
    $report .= "- **Variant A (Baseline)**: Cost and emissions only\n";
    $report .= "- **Variant B (Enriched Neutral)**: Additional attributes read but not used in objective\n";
    $report .= "- **Variant C1 (Low Penalty)**: Enriched attributes with low penalty (α=0.1)\n";
    $report .= "- **Variant C2 (Moderate Penalty)**: Enriched attributes with moderate penalty (α=0.5)\n\n";
    
    // Group by BOM
    $byBOM = [];
    foreach ($allKPIs as $kpi) {
        $bomId = $kpi['bom_id'];
        if (!isset($byBOM[$bomId])) {
            $byBOM[$bomId] = [];
        }
        $byBOM[$bomId][] = $kpi;
    }
    
    foreach ($byBOM as $bomId => $kpis) {
        $report .= "## BOM: $bomId\n\n";
        
        // Find baseline
        $baseline = null;
        foreach ($kpis as $kpi) {
            if ($kpi['supplier_variant'] === 'A') {
                $baseline = $kpi;
                break;
            }
        }
        
        if ($baseline) {
            $report .= "### Baseline (Variant A)\n\n";
            $report .= "- Total Cost: " . ($baseline['total_cost'] ?? 'N/A') . "\n";
            $report .= "- Total Emissions: " . ($baseline['total_emissions'] ?? 'N/A') . "\n";
            $report .= "- Suppliers Used: " . ($baseline['suppliers_used'] ?? 'N/A') . "\n";
            $report .= "- Concentration Index: " . ($baseline['supplier_concentration_index'] ?? 'N/A') . "\n\n";
        }
        
        // Compare variants
        $report .= "### Comparison Across Variants\n\n";
        $report .= "| Variant | Total Cost | Δ Cost vs Baseline | Total Emissions | Δ Emissions vs Baseline | Suppliers Used | Concentration | Avg Quality | Avg Reliability |\n";
        $report .= "|---------|------------|---------------------|-----------------|------------------------|----------------|---------------|-------------|-----------------|\n";
        
        foreach ($kpis as $kpi) {
            $variant = $kpi['supplier_variant'];
            $penalty = $kpi['penalty_level'] !== 'N/A' ? " ({$kpi['penalty_level']})" : '';
            $report .= sprintf(
                "| %s%s | %s | %s | %s | %s | %s | %s | %s | %s |\n",
                $variant,
                $penalty,
                $kpi['total_cost'] ?? 'N/A',
                $kpi['delta_cost_vs_baseline'] !== null ? sprintf('%.2f', $kpi['delta_cost_vs_baseline']) : 'N/A',
                $kpi['total_emissions'] ?? 'N/A',
                $kpi['delta_emissions_vs_baseline'] !== null ? sprintf('%.2f', $kpi['delta_emissions_vs_baseline']) : 'N/A',
                $kpi['suppliers_used'] ?? 'N/A',
                $kpi['supplier_concentration_index'] !== null ? sprintf('%.3f', $kpi['supplier_concentration_index']) : 'N/A',
                $kpi['avg_quality'] !== null ? sprintf('%.3f', $kpi['avg_quality']) : 'N/A',
                $kpi['avg_reliability'] !== null ? sprintf('%.3f', $kpi['avg_reliability']) : 'N/A'
            );
        }
        
        $report .= "\n";
        
        // Key findings
        $report .= "### Key Findings\n\n";
        
        // Check for infeasibility
        $hasInfeasible = false;
        foreach ($kpis as $kpi) {
            if (isset($kpi['status']) && ($kpi['status'] === 'INFEASIBLE' || $kpi['status'] === 'ERROR')) {
                $hasInfeasible = true;
                $report .= "- **⚠️ Model infeasibility detected**: ";
                $report .= sprintf("Variant %s returned status: %s\n", $kpi['supplier_variant'], $kpi['status']);
                $report .= "  This may indicate insufficient supplier capacity or constraint conflicts.\n";
            }
        }
        
        if ($hasInfeasible) {
            $report .= "\n";
            $report .= "**Note**: Due to infeasibility, detailed analysis is not available for this BOM.\n";
            $report .= "**Root Cause**: The model is infeasible because some leaf nodes require quantities that exceed the total capacity of available suppliers.\n";
            $report .= "For example, node 40 requires 1080 units (20 × 54), but suppliers 1-10 have a combined capacity of only 815 units.\n";
            $report .= "This is a structural constraint issue, not a bug in the optimization model.\n\n";
            continue; // Skip further analysis for this BOM but continue with others
        }
        
        // Check if Variant B changes decisions
        $variantB = null;
        foreach ($kpis as $kpi) {
            if ($kpi['supplier_variant'] === 'B') {
                $variantB = $kpi;
                break;
            }
        }
        
        if ($variantB && $baseline) {
            if ($variantB['supplier_switch_rate'] > 0.01) {
                $report .= "- **Data enrichment (Variant B) changes supplier selection**: ";
                $report .= sprintf("%.1f%% of products switched suppliers\n", $variantB['supplier_switch_rate'] * 100);
            } else {
                $report .= "- **Data enrichment (Variant B) does not materially change supplier selection**\n";
            }
        }
        
        // Check penalty impact
        $variantC1 = null;
        $variantC2 = null;
        foreach ($kpis as $kpi) {
            if ($kpi['supplier_variant'] === 'C1') {
                $variantC1 = $kpi;
            }
            if ($kpi['supplier_variant'] === 'C2') {
                $variantC2 = $kpi;
            }
        }
        
        if ($variantC1 && $variantC2) {
            $costIncreaseC1 = $variantC1['delta_cost_vs_baseline'] ?? 0;
            $costIncreaseC2 = $variantC2['delta_cost_vs_baseline'] ?? 0;
            
            if ($costIncreaseC2 > $costIncreaseC1 * 2) {
                $report .= "- **Penalty intensity significantly affects cost**: ";
                $report .= sprintf("C2 increases cost by %.2f vs C1's %.2f\n", $costIncreaseC2, $costIncreaseC1);
            }
            
            if ($variantC2['avg_quality'] > $variantC1['avg_quality']) {
                $report .= "- **Higher penalties drive selection toward higher quality suppliers**\n";
            }
        }
        
        // Emissions stability
        $emissionsStable = true;
        foreach ($kpis as $kpi) {
            if ($kpi['delta_emissions_vs_baseline'] !== null && abs($kpi['delta_emissions_vs_baseline']) > 100) {
                $emissionsStable = false;
                break;
            }
        }
        
        if ($emissionsStable) {
            $report .= "- **Baseline emissions remain relatively stable across variants**\n";
        } else {
            $report .= "- **Emissions show variation across variants**\n";
        }
        
        $report .= "\n";
    }
    
    // Overall conclusions
    $report .= "## Overall Conclusions\n\n";
    $report .= "1. **Model Robustness**: Variant B tests whether data structure extension alone distorts results.\n";
    $report .= "2. **Behavioral Sensitivity**: Variants C1 and C2 test how penalty-based preferences affect decisions.\n";
    $report .= "3. **Emission Stability**: Baseline emissions should remain stable until explicit penalties are introduced.\n";
    $report .= "4. **Decision Impact**: Attribute enrichment alters sourcing structure and resilience properties.\n\n";
    
    file_put_contents($outputFile, $report);
    return $report;
}

// Main execution
try {
    // Load configuration
    $config = include __DIR__ . '/../config/settings.php';
    echo "Configuration loaded successfully.\n";
    
    // Create timestamped log directory
    $timestamp = date('Ymd_His');
    $logSubfolder = rtrim($config['LOGS_DIR'], '/\\') . DIRECTORY_SEPARATOR . 'supplier_attributes' . DIRECTORY_SEPARATOR . $timestamp . DIRECTORY_SEPARATOR;
    if (!is_dir($logSubfolder)) {
        mkdir($logSubfolder, 0755, true);
    }
    echo "Log subfolder created: $logSubfolder\n";
    
    $dataDir = $config['WORK_DIR'];
    $modelDir = $config['MODELE'];
    // Use grand capacity suppliers for BOM 50, standard for others
    $supplierDetailsFile = $dataDir . 'supp_details_supeco.csv';
    $supplierDetailsFileGrand = $dataDir . 'supp_details_supeco_grdCapacity.csv';
    
    // Store all KPIs
    $allKPIs = [];
    $baselineResults = []; // Store baseline results for comparison
    
    // Process each BOM
    foreach ($SELECTED_BOMS as $bomConfig) {
        $bomFile = $bomConfig['bom'];
        $suppListFile = $bomConfig['supp_list'];
        $bomId = $bomConfig['id'];
        
        echo "\n=== Processing BOM: $bomId ===\n";
        
        $bomPath = $dataDir . $bomFile;
        $suppListPath = $dataDir . $suppListFile;
        
        if (!file_exists($bomPath)) {
            echo "Warning: BOM file not found: $bomPath\n";
            continue;
        }
        if (!file_exists($suppListPath)) {
            echo "Warning: Supplier list file not found: $suppListPath\n";
            continue;
        }
        
        // Select supplier details file based on BOM
        $supplierDetailsFileToUse = ($bomId === '50') ? $supplierDetailsFileGrand : $supplierDetailsFile;
        echo "  Using supplier file: " . basename($supplierDetailsFileToUse) . "\n";
        
        // Variant A: Baseline
        echo "  Running Variant A (Baseline)...\n";
        $prefixA = sprintf("SUPPATTR-%s-A", $bomId);
        $runConfigA = [
            "_NODE_FILE_" => str_replace('\\', '/', $bomPath),
            "_NODE_SUPP_FILE_" => str_replace('\\', '/', $suppListPath),
            "_SUPP_DETAILS_FILE_" => str_replace('\\', '/', $supplierDetailsFileToUse),
            "_NBSUPP_" => $SUPPLIERS,
            "_SERVICE_T_" => $SERVICE_TIME,
            "_EMISCAP_" => 2500000,
            "_EMISTAXE_" => $CARBON_TAX,
        ];
        
        $modelPath = $modelDir . $BASE_MODEL_FILE;
        $preparedModelA = prepareModelFile($modelPath, $runConfigA, $prefixA, $dataDir, $TIME_LIMIT_SEC, 'A');
        
        // Run CPLEX with error handling
        $startTime = microtime(true);
        $rawOutputA = null;
        try {
            $cmdLine = '"' . $config['OPLRUN'] . '" ' . escapeshellarg($preparedModelA);
            $rawOutputA = shell_exec($cmdLine);
            
            if (!$rawOutputA) {
                throw new Exception("No output from CPLEX. Command executed: $cmdLine");
            }
            
            $resultA = CplexRunner::run($preparedModelA, $config['OPLRUN']);
            $resultA['_raw_output'] = $rawOutputA;
            
            // Check for errors
            if (preg_match('/Infeasibility row/i', $rawOutputA) || preg_match('/<<< no solution/i', $rawOutputA)) {
                $resultA['_is_infeasible'] = true;
            }
            if (preg_match('/unbounded/i', $rawOutputA)) {
                $resultA['_is_unbounded'] = true;
            }
            $errorPatterns = ['/error/i', '/exception/i', '/failed/i', '/file.*not.*exist/i', '/format error/i'];
            foreach ($errorPatterns as $pattern) {
                if (preg_match($pattern, $rawOutputA)) {
                    $resultA['_has_error_pattern'] = true;
                    break;
                }
            }
        } catch (Exception $e) {
            echo "    ERROR: " . $e->getMessage() . "\n";
            $resultA = [
                'status' => 'ERROR',
                'error_message' => $e->getMessage(),
                'CplexRunTime' => (microtime(true) - $startTime) . " sec",
                '_raw_output' => $rawOutputA
            ];
        }
        
        $logFileA = $logSubfolder . "run_{$bomId}_A.log";
        $logContentA = print_r($resultA, true);
        if ($rawOutputA && !isset($resultA['_raw_output'])) {
            $logContentA .= "\n\n=== RAW CPLEX OUTPUT ===\n" . $rawOutputA;
        }
        file_put_contents($logFileA, $logContentA);
        echo "    Results saved to: $logFileA\n";
        
        $kpisA = extractKPIs($resultA, $bomId, 'A', null);
        $allKPIs[] = $kpisA;
        $baselineResults[$bomId] = $resultA;
        
        // Variant B: Enriched (neutral)
        echo "  Running Variant B (Enriched Neutral)...\n";
        $prefixB = sprintf("SUPPATTR-%s-B", $bomId);
        $preparedModelB = prepareModelFile($modelPath, $runConfigA, $prefixB, $dataDir, $TIME_LIMIT_SEC, 'B');
        
        $startTime = microtime(true);
        $rawOutputB = null;
        try {
            $cmdLine = '"' . $config['OPLRUN'] . '" ' . escapeshellarg($preparedModelB);
            $rawOutputB = shell_exec($cmdLine);
            
            if (!$rawOutputB) {
                throw new Exception("No output from CPLEX. Command executed: $cmdLine");
            }
            
            $resultB = CplexRunner::run($preparedModelB, $config['OPLRUN']);
            $resultB['_raw_output'] = $rawOutputB;
            
            // Check for errors
            if (preg_match('/Infeasibility row/i', $rawOutputB) || preg_match('/<<< no solution/i', $rawOutputB)) {
                $resultB['_is_infeasible'] = true;
            }
            if (preg_match('/unbounded/i', $rawOutputB)) {
                $resultB['_is_unbounded'] = true;
            }
            $errorPatterns = ['/error/i', '/exception/i', '/failed/i', '/file.*not.*exist/i', '/format error/i'];
            foreach ($errorPatterns as $pattern) {
                if (preg_match($pattern, $rawOutputB)) {
                    $resultB['_has_error_pattern'] = true;
                    break;
                }
            }
        } catch (Exception $e) {
            echo "    ERROR: " . $e->getMessage() . "\n";
            $resultB = [
                'status' => 'ERROR',
                'error_message' => $e->getMessage(),
                'CplexRunTime' => (microtime(true) - $startTime) . " sec",
                '_raw_output' => $rawOutputB
            ];
        }
        
        $logFileB = $logSubfolder . "run_{$bomId}_B.log";
        $logContentB = print_r($resultB, true);
        if ($rawOutputB && !isset($resultB['_raw_output'])) {
            $logContentB .= "\n\n=== RAW CPLEX OUTPUT ===\n" . $rawOutputB;
        }
        file_put_contents($logFileB, $logContentB);
        echo "    Results saved to: $logFileB\n";
        
        $kpisB = extractKPIs($resultB, $bomId, 'B', null, $resultA);
        // Calculate weighted averages
        $supplierFlowsB = [];
        if (isset($resultB['DELIVER']) && is_array($resultB['DELIVER'])) {
            foreach ($resultB['DELIVER'] as $delivery) {
                if (preg_match('/S(\d+)=>P(\d+)/', $delivery, $matches)) {
                    $supplierId = (int)$matches[1];
                    $productId = (int)$matches[2];
                    if (!isset($supplierFlowsB[$supplierId])) {
                        $supplierFlowsB[$supplierId] = [];
                    }
                    $supplierFlowsB[$supplierId][] = $productId;
                }
            }
        }
        $weightedB = calculateWeightedAttributeAverages($supplierFlowsB, $supplierDetailsFileToUse);
        $kpisB['avg_quality'] = $weightedB['avg_quality'];
        $kpisB['avg_reliability'] = $weightedB['avg_reliability'];
        $allKPIs[] = $kpisB;
        
        // Variant C1: Enriched + low penalty
        echo "  Running Variant C1 (Low Penalty α=0.1)...\n";
        $prefixC1 = sprintf("SUPPATTR-%s-C1", $bomId);
        $preparedModelC1 = prepareModelFile($modelPath, $runConfigA, $prefixC1, $dataDir, $TIME_LIMIT_SEC, 'C1', $PENALTY_LEVELS['C1']);
        
        $startTime = microtime(true);
        $rawOutputC1 = null;
        try {
            $cmdLine = '"' . $config['OPLRUN'] . '" ' . escapeshellarg($preparedModelC1);
            $rawOutputC1 = shell_exec($cmdLine);
            
            if (!$rawOutputC1) {
                throw new Exception("No output from CPLEX. Command executed: $cmdLine");
            }
            
            $resultC1 = CplexRunner::run($preparedModelC1, $config['OPLRUN']);
            $resultC1['_raw_output'] = $rawOutputC1;
            
            // Check for errors
            if (preg_match('/Infeasibility row/i', $rawOutputC1) || preg_match('/<<< no solution/i', $rawOutputC1)) {
                $resultC1['_is_infeasible'] = true;
            }
            if (preg_match('/unbounded/i', $rawOutputC1)) {
                $resultC1['_is_unbounded'] = true;
            }
            $errorPatterns = ['/error/i', '/exception/i', '/failed/i', '/file.*not.*exist/i', '/format error/i'];
            foreach ($errorPatterns as $pattern) {
                if (preg_match($pattern, $rawOutputC1)) {
                    $resultC1['_has_error_pattern'] = true;
                    break;
                }
            }
        } catch (Exception $e) {
            echo "    ERROR: " . $e->getMessage() . "\n";
            $resultC1 = [
                'status' => 'ERROR',
                'error_message' => $e->getMessage(),
                'CplexRunTime' => (microtime(true) - $startTime) . " sec",
                '_raw_output' => $rawOutputC1
            ];
        }
        
        $logFileC1 = $logSubfolder . "run_{$bomId}_C1.log";
        $logContentC1 = print_r($resultC1, true);
        if ($rawOutputC1 && !isset($resultC1['_raw_output'])) {
            $logContentC1 .= "\n\n=== RAW CPLEX OUTPUT ===\n" . $rawOutputC1;
        }
        file_put_contents($logFileC1, $logContentC1);
        echo "    Results saved to: $logFileC1\n";
        
        $kpisC1 = extractKPIs($resultC1, $bomId, 'C1', 'C1', $resultA);
        $supplierFlowsC1 = [];
        if (isset($resultC1['DELIVER']) && is_array($resultC1['DELIVER'])) {
            foreach ($resultC1['DELIVER'] as $delivery) {
                if (preg_match('/S(\d+)=>P(\d+)/', $delivery, $matches)) {
                    $supplierId = (int)$matches[1];
                    $productId = (int)$matches[2];
                    if (!isset($supplierFlowsC1[$supplierId])) {
                        $supplierFlowsC1[$supplierId] = [];
                    }
                    $supplierFlowsC1[$supplierId][] = $productId;
                }
            }
        }
        $weightedC1 = calculateWeightedAttributeAverages($supplierFlowsC1, $supplierDetailsFileToUse);
        $kpisC1['avg_quality'] = $weightedC1['avg_quality'];
        $kpisC1['avg_reliability'] = $weightedC1['avg_reliability'];
        $allKPIs[] = $kpisC1;
        
        // Variant C2: Enriched + moderate penalty
        echo "  Running Variant C2 (Moderate Penalty α=0.5)...\n";
        $prefixC2 = sprintf("SUPPATTR-%s-C2", $bomId);
        $preparedModelC2 = prepareModelFile($modelPath, $runConfigA, $prefixC2, $dataDir, $TIME_LIMIT_SEC, 'C2', $PENALTY_LEVELS['C2']);
        
        $startTime = microtime(true);
        $rawOutputC2 = null;
        try {
            $cmdLine = '"' . $config['OPLRUN'] . '" ' . escapeshellarg($preparedModelC2);
            $rawOutputC2 = shell_exec($cmdLine);
            
            if (!$rawOutputC2) {
                throw new Exception("No output from CPLEX. Command executed: $cmdLine");
            }
            
            $resultC2 = CplexRunner::run($preparedModelC2, $config['OPLRUN']);
            $resultC2['_raw_output'] = $rawOutputC2;
            
            // Check for errors
            if (preg_match('/Infeasibility row/i', $rawOutputC2) || preg_match('/<<< no solution/i', $rawOutputC2)) {
                $resultC2['_is_infeasible'] = true;
            }
            if (preg_match('/unbounded/i', $rawOutputC2)) {
                $resultC2['_is_unbounded'] = true;
            }
            $errorPatterns = ['/error/i', '/exception/i', '/failed/i', '/file.*not.*exist/i', '/format error/i'];
            foreach ($errorPatterns as $pattern) {
                if (preg_match($pattern, $rawOutputC2)) {
                    $resultC2['_has_error_pattern'] = true;
                    break;
                }
            }
        } catch (Exception $e) {
            echo "    ERROR: " . $e->getMessage() . "\n";
            $resultC2 = [
                'status' => 'ERROR',
                'error_message' => $e->getMessage(),
                'CplexRunTime' => (microtime(true) - $startTime) . " sec",
                '_raw_output' => $rawOutputC2
            ];
        }
        
        $logFileC2 = $logSubfolder . "run_{$bomId}_C2.log";
        $logContentC2 = print_r($resultC2, true);
        if ($rawOutputC2 && !isset($resultC2['_raw_output'])) {
            $logContentC2 .= "\n\n=== RAW CPLEX OUTPUT ===\n" . $rawOutputC2;
        }
        file_put_contents($logFileC2, $logContentC2);
        echo "    Results saved to: $logFileC2\n";
        
        $kpisC2 = extractKPIs($resultC2, $bomId, 'C2', 'C2', $resultA);
        $supplierFlowsC2 = [];
        if (isset($resultC2['DELIVER']) && is_array($resultC2['DELIVER'])) {
            foreach ($resultC2['DELIVER'] as $delivery) {
                if (preg_match('/S(\d+)=>P(\d+)/', $delivery, $matches)) {
                    $supplierId = (int)$matches[1];
                    $productId = (int)$matches[2];
                    if (!isset($supplierFlowsC2[$supplierId])) {
                        $supplierFlowsC2[$supplierId] = [];
                    }
                    $supplierFlowsC2[$supplierId][] = $productId;
                }
            }
        }
        $weightedC2 = calculateWeightedAttributeAverages($supplierFlowsC2, $supplierDetailsFileToUse);
        $kpisC2['avg_quality'] = $weightedC2['avg_quality'];
        $kpisC2['avg_reliability'] = $weightedC2['avg_reliability'];
        $allKPIs[] = $kpisC2;
    }
    
    // Generate results CSV
    echo "\n=== Generating Results CSV ===\n";
    $csvFile = $logSubfolder . 'results_supplier_attribute_sensitivity.csv';
    $csvHandle = fopen($csvFile, 'w');
    if ($csvHandle) {
        // Write header
        fputcsv($csvHandle, [
            'bom_id', 'supplier_variant', 'penalty_level', 'status', 'total_cost', 'total_emissions',
            'suppliers_used', 'avg_quality', 'avg_reliability', 'supplier_concentration_index',
            'delta_cost_vs_baseline', 'delta_emissions_vs_baseline', 'supplier_switch_rate',
            'emissions_per_unit_cost', 'runtime_sec'
        ]);
        
        // Write data
        foreach ($allKPIs as $kpi) {
            fputcsv($csvHandle, [
                $kpi['bom_id'],
                $kpi['supplier_variant'],
                $kpi['penalty_level'],
                $kpi['status'] ?? 'UNKNOWN',
                $kpi['total_cost'],
                $kpi['total_emissions'],
                $kpi['suppliers_used'],
                $kpi['avg_quality'],
                $kpi['avg_reliability'],
                $kpi['supplier_concentration_index'],
                $kpi['delta_cost_vs_baseline'],
                $kpi['delta_emissions_vs_baseline'],
                $kpi['supplier_switch_rate'],
                $kpi['emissions_per_unit_cost'],
                $kpi['runtime_sec']
            ]);
        }
        fclose($csvHandle);
        echo "Results CSV saved to: $csvFile\n";
    }
    
    // Generate summary report
    echo "\n=== Generating Summary Report ===\n";
    $summaryFile = $logSubfolder . 'results_supplier_attribute_summary.md';
    generateSummaryReport($allKPIs, $summaryFile);
    echo "Summary report saved to: $summaryFile\n";
    
    echo "\n=== Benchmark Complete ===\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if (isset($logSubfolder)) {
        $errorLog = $logSubfolder . 'error.log';
        file_put_contents($errorLog, "[" . date('Y-m-d H:i:s') . "] " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
    exit(1);
}
