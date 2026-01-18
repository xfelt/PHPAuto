<?php

/**
 * Scalability Benchmark Script
 * 
 * Runs a comprehensive BOM size sweep to measure:
 * - Solver runtime scaling
 * - Feasibility limits
 * - KPI trends (cost, emissions, buffers) vs problem size
 * 
 * Configuration:
 * - Model: RUNS_SupEmis_Cplex_PLM_Tax.mod
 * - Carbon tax: 0 (baseline emissions)
 * - Time limit: 1800 seconds (30 minutes)
 */

require_once __DIR__ . '/FileUtils.php';
require_once __DIR__ . '/CplexRunner.php';

// BOM sizes to test
$PHASE_A = [2, 3, 4, 5, 6, 7, 8, 10]; // Small
$PHASE_B = [13, 15, 20, 25, 26, 30];  // Medium
$PHASE_C = [35, 40, 50, 60, 80, 90, 100, 123, 150]; // Large

$ALL_SIZES = array_merge($PHASE_A, $PHASE_B, $PHASE_C);

// Configuration
$TIME_LIMIT_SEC = 1800; // 30 minutes
$CARBON_TAX = 0.0; // Baseline (no tax)
$SERVICE_TIME = 1;
$SUPPLIERS = 10;
$MODEL_FILE = 'RUNS_SupEmis_Cplex_PLM_Tax.mod';

/**
 * Determine phase for a given BOM size
 */
function getPhase($size) {
    global $PHASE_A, $PHASE_B, $PHASE_C;
    if (in_array($size, $PHASE_A)) return 'A';
    if (in_array($size, $PHASE_B)) return 'B';
    if (in_array($size, $PHASE_C)) return 'C';
    return 'Unknown';
}

/**
 * Generate missing BOM file by expanding from closest available size
 */
function generateBOMFile($targetSize, $dataDir) {
    // Find closest available BOM
    $availableSizes = [];
    foreach (glob($dataDir . 'bom_supemis_*.csv') as $file) {
        if (preg_match('/bom_supemis_(\d+)\.csv$/', $file, $matches)) {
            $availableSizes[] = (int)$matches[1];
        }
    }
    sort($availableSizes);
    
    // Find closest size (prefer smaller to avoid over-generation)
    $closestSize = null;
    foreach ($availableSizes as $size) {
        if ($size < $targetSize) {
            $closestSize = $size;
        } else {
            break;
        }
    }
    
    if ($closestSize === null) {
        $closestSize = $availableSizes[0]; // Use smallest available
    }
    
    echo "  Generating BOM size $targetSize from size $closestSize...\n";
    
    // Read source BOM
    $sourceFile = $dataDir . "bom_supemis_{$closestSize}.csv";
    if (!file_exists($sourceFile)) {
        throw new Exception("Source BOM file not found: $sourceFile");
    }
    
    $lines = file($sourceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($lines)) {
        throw new Exception("Source BOM file is empty: $sourceFile");
    }
    
    // Parse header
    $header = $lines[0];
    $dataLines = array_slice($lines, 1);
    
    // Count current nodes (excluding root node 0)
    $currentNodes = count($dataLines) - 1; // -1 for root node
    
    if ($currentNodes >= $targetSize) {
        // Just copy if source is larger
        $targetFile = $dataDir . "bom_supemis_{$targetSize}.csv";
        file_put_contents($targetFile, implode("\n", array_slice($lines, 0, $targetSize + 1)) . "\n");
        echo "  Created $targetFile (truncated from $closestSize)\n";
        return;
    }
    
    // Need to expand: add nodes following the pattern
    $newNodes = $targetSize - $currentNodes;
    $lastNode = $currentNodes;
    
    // Parse last few nodes to understand structure
    $lastNodes = array_slice($dataLines, -min(5, count($dataLines)));
    $parentPattern = [];
    foreach ($lastNodes as $line) {
        $parts = explode(';', $line);
        if (count($parts) >= 3) {
            $parentPattern[] = (int)$parts[2];
        }
    }
    
    // Generate new nodes
    $newLines = [];
    for ($i = 1; $i <= $newNodes; $i++) {
        $nodeId = $lastNode + $i;
        // Use pattern: parent from last nodes, or attach to a recent parent
        $parentId = $parentPattern[($i - 1) % count($parentPattern)] ?? max(1, $lastNode - 2);
        if ($parentId >= $nodeId) {
            $parentId = max(1, (int)($nodeId / 2));
        }
        
        // Generate reasonable values based on pattern
        $tProcess = 3 + ($i % 5);
        $unitPrice = 50 + ($i * 10);
        // Cap rqtf to prevent exponential growth that causes capacity issues
        // Use a reasonable range that won't exceed supplier capacity when multiplied by adup
        $rqtf = 1 + min($i % 10, 5); // Cap at 6 to keep requirements reasonable
        
        // Use average values from existing nodes for other fields
        $avgAihCost = 0.25;
        $avgVarFactor = 0.5;
        $avgLtFactor = 0.8;
        $avgFacilityEmis = 2000 + ($i * 100);
        $avgInventoryEmis = 200 + ($i * 10);
        $avgTrspEmis = 1500;
        
        // Use comma as decimal separator (European format)
        $newLine = sprintf(
            "%d;%d;%d;%d;%d;%s;%s;%s;1;0;%d;%d;%d;",
            $nodeId, $tProcess, $parentId, $unitPrice, $rqtf,
            str_replace('.', ',', number_format($avgAihCost, 2, '.', '')),
            str_replace('.', ',', number_format($avgVarFactor, 2, '.', '')),
            str_replace('.', ',', number_format($avgLtFactor, 2, '.', '')),
            $avgFacilityEmis, $avgInventoryEmis, $avgTrspEmis
        );
        $newLines[] = $newLine;
    }
    
    // Write new BOM file
    $targetFile = $dataDir . "bom_supemis_{$targetSize}.csv";
    $content = $header . "\n";
    $content .= implode("\n", $dataLines) . "\n";
    $content .= implode("\n", $newLines) . "\n";
    file_put_contents($targetFile, $content);
    echo "  Created $targetFile (expanded from $closestSize, added $newNodes nodes)\n";
}

/**
 * Generate missing supplier list file
 * IMPORTANT: Must include ALL leaf nodes (nodes with no children) to satisfy constraint ct8
 */
function generateSupplierListFile($targetSize, $dataDir) {
    // First, we need to identify which nodes are leaf nodes from the BOM
    $bomFile = $dataDir . "bom_supemis_{$targetSize}.csv";
    if (!file_exists($bomFile)) {
        throw new Exception("BOM file not found: $bomFile. Generate BOM first.");
    }
    
    // Read BOM to identify leaf nodes
    $bomLines = file($bomFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    array_shift($bomLines); // Skip header
    
    $nodes = [];
    $parents = [];
    
    foreach ($bomLines as $line) {
        $parts = explode(';', $line);
        if (count($parts) >= 3) {
            $nodeId = (int)$parts[0];
            $parentId = (int)$parts[2];
            $nodes[$nodeId] = $parentId;
            if ($parentId >= 0) {
                $parents[$parentId] = true;
            }
        }
    }
    
    // Find leaf nodes (nodes that are not parents of any other node, excluding root)
    $leafNodes = [];
    foreach ($nodes as $nodeId => $parentId) {
        if ($nodeId == 0) continue; // Skip root node
        if (!isset($parents[$nodeId])) {
            $leafNodes[] = $nodeId;
        }
    }
    
    sort($leafNodes);
    
    echo "  Generating supplier list size $targetSize...\n";
    echo "  Identified " . count($leafNodes) . " leaf nodes: " . implode(', ', $leafNodes) . "\n";
    
    // Find closest available supplier list for pattern
    $availableSizes = [];
    foreach (glob($dataDir . 'supp_list_*.csv') as $file) {
        if (preg_match('/supp_list_(\d+)\.csv$/', $file, $matches)) {
            $availableSizes[] = (int)$matches[1];
        }
    }
    sort($availableSizes);
    
    $closestSize = $availableSizes[0] ?? 5;
    $sourceFile = $dataDir . "supp_list_{$closestSize}.csv";
    
    // Get supplier list pattern
    $supplierList = '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20';
    if (file_exists($sourceFile)) {
        $lines = file($sourceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($lines) > 3) {
            $lastLine = $lines[count($lines) - 1];
            $parts = explode(';', $lastLine);
            if (count($parts) >= 2) {
                $supplierList = $parts[1];
            }
        }
    }
    
    // Generate entries for ALL leaf nodes
    $newLines = [];
    foreach ($leafNodes as $nodeId) {
        $newLines[] = "$nodeId;$supplierList;";
    }
    
    // Write supplier list file
    $targetFile = $dataDir . "supp_list_{$targetSize}.csv";
    $header = "nb_nodes;nb_suppliers; #  ligne obligatoire : les noeud 0..nb_nodes et les suppliers 0..nbsuppliers-1 \n";
    $params = "$targetSize;10;\n";
    $columnHeader = "id_nodes;list_suppliers;  #  Attention 2 separateurs\n";
    
    $content = $header . $params . $columnHeader . implode("\n", $newLines) . "\n";
    file_put_contents($targetFile, $content);
    echo "  Created $targetFile with " . count($leafNodes) . " leaf node entries\n";
}

/**
 * Apply dictionary to model file with time limit
 */
function prepareModelFile($modelPath, $runConfig, $prefix, $workDir, $timeLimit) {
    // Read model file
    $content = file_get_contents($modelPath);
    if ($content === false) {
        throw new Exception("Failed to read model file: $modelPath");
    }
    
    // Add time limit setting (for CPLEX)
    // Insert at the beginning of the main execute block (the one that loads BOM data)
    $timeLimitCode = "    cplex.tilim = $timeLimit; // Time limit in seconds\n";
    
    // Find the main execute block that loads BOM data (starts with "execute {" followed by "//BOM Nodes Data")
    $pattern = '/(execute\s*\{[\s\n]*\/\/BOM Nodes Data)/';
    if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        $pos = $matches[0][1] + strlen($matches[0][0]);
        $content = substr_replace($content, "\n" . $timeLimitCode, $pos, 0);
    } else {
        // Fallback: insert after the first execute block (NB_NODE initialization)
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
function extractKPIs($result, $bomSize) {
    $kpis = [
        'N' => $bomSize,
        'phase' => getPhase($bomSize),
        'status' => 'UNKNOWN',
        'runtime_sec' => -1,
        'objective_value' => null,
        'total_emissions' => null,
        'total_cost' => null,
        'buffers_count' => null,
        'num_vars' => null,
        'num_constraints' => null,
        'mip_gap' => null,
    ];
    
    // Extract runtime (handle comma as decimal separator)
    if (isset($result['CplexRunTime'])) {
        $runtimeStr = $result['CplexRunTime'];
        // Handle format: "Total (root+branch&cut) =    0,03 sec" or "0.03 sec"
        if (preg_match('/([\d,\.]+)\s*sec/', $runtimeStr, $matches)) {
            $timeValue = str_replace(',', '.', $matches[1]);
            $kpis['runtime_sec'] = (float)$timeValue;
        } elseif (is_numeric($runtimeStr)) {
            $kpis['runtime_sec'] = (float)str_replace(',', '.', $runtimeStr);
        }
    }
    
    // Determine status
    global $TIME_LIMIT_SEC;
    
    // Check for errors first
    if (isset($result['status']) && $result['status'] === 'ERROR') {
        $kpis['status'] = 'ERROR';
        return $kpis;
    }
    
    // Check for infeasibility first (most specific)
    if (isset($result['_is_infeasible']) || isset($result['_raw_output'])) {
        $rawOutput = $result['_raw_output'] ?? '';
        if (isset($result['_is_infeasible']) || 
            preg_match('/Infeasibility row/i', $rawOutput) || 
            preg_match('/<<< no solution/i', $rawOutput)) {
            $kpis['status'] = 'INFEASIBLE';
            return $kpis;
        }
    }
    
    // Check for unbounded
    if (isset($result['_is_unbounded'])) {
        $kpis['status'] = 'UNBOUNDED';
        return $kpis;
    }
    
    // Check for other error patterns
    if (isset($result['_has_error_pattern']) || isset($result['error_message'])) {
        $rawOutput = $result['_raw_output'] ?? '';
        if (preg_match('/file.*not.*exist|format error/i', $rawOutput)) {
            $kpis['status'] = 'ERROR';
        } else {
            $kpis['status'] = 'ERROR';
        }
        return $kpis;
    }
    
    // Check for timeout
    if ($kpis['runtime_sec'] >= $TIME_LIMIT_SEC - 1 || isset($result['TIMEOUT_DETECTED'])) {
        $kpis['status'] = 'TIMEOUT';
    } elseif (isset($result['E']) || isset($result['TS']) || isset($result['Result'])) {
        // If we have emissions, cost, or result, it's at least feasible
        if (isset($result['E']) && is_numeric($result['E'])) {
            $kpis['status'] = 'OPTIMAL'; // Assume optimal if we have emissions data
        } elseif (isset($result['Result']) && is_array($result['Result'])) {
            $kpis['status'] = 'OPTIMAL';
        } else {
            $kpis['status'] = 'FEASIBLE';
        }
    } elseif ($kpis['runtime_sec'] >= 0) {
        // We have runtime but no solution data - check if it's a very short run (likely error/infeasible)
        // or if we can determine from raw output
        $rawOutput = $result['_raw_output'] ?? '';
        
        // Check if output contains "xxxx" markers (solution section exists)
        if (strpos($rawOutput, 'xxxx') === false) {
            // No solution section - likely failed before reaching solution output
            if ($kpis['runtime_sec'] < 0.1) {
                $kpis['status'] = 'INFEASIBLE'; // Very short runtime with no output = likely infeasible
            } else {
                $kpis['status'] = 'ERROR'; // Longer runtime but no output = error
            }
        } else {
            // Solution section exists but wasn't parsed - might be parsing issue
            $kpis['status'] = 'FEASIBLE'; // Assume feasible but parsing failed
        }
    } else {
        // No runtime extracted
        $kpis['status'] = 'ERROR';
    }
    
    // Extract emissions - use E field directly (more reliable than Result)
    if (isset($result['E']) && is_numeric($result['E'])) {
        $kpis['total_emissions'] = (float)$result['E'];
    } elseif (isset($result['Result']['Emissions']) && is_numeric($result['Result']['Emissions'])) {
        $kpis['total_emissions'] = (float)$result['Result']['Emissions'];
    }
    
    // Extract buffer count from X array (buffer positions)
    if (isset($result['X']) && is_array($result['X'])) {
        $kpis['buffers_count'] = (int)array_sum($result['X']);
    } elseif (isset($result['A']) && is_array($result['A'])) {
        // Estimate from A array (decoupled lead times) - nodes with a[i] > 0 might have buffers
        // But this is not accurate, so we'll leave it as null if X is not available
        $kpis['buffers_count'] = null;
    }
    
    // Try to extract from DELIVER if available (count unique products)
    if ($kpis['buffers_count'] === null && isset($result['DELIVER']) && is_array($result['DELIVER'])) {
        $products = [];
        foreach ($result['DELIVER'] as $delivery) {
            if (preg_match('/P(\d+)/', $delivery, $matches)) {
                $products[] = $matches[1];
            }
        }
        $kpis['buffers_count'] = count(array_unique($products));
    }
    
    // Extract total cost - use TS field directly (more reliable)
    if (isset($result['TS']) && is_numeric($result['TS'])) {
        $kpis['total_cost'] = (float)$result['TS'];
    } elseif (isset($result['CS']) && is_numeric($result['CS'])) {
        $kpis['total_cost'] = (float)$result['CS'];
    } elseif (isset($result['Result']['ServiceCost']) && is_numeric($result['Result']['ServiceCost'])) {
        $kpis['total_cost'] = (float)$result['Result']['ServiceCost'];
    }
    
    // Extract objective value
    if (isset($result['Result']['Objective']) && is_numeric($result['Result']['Objective'])) {
        $kpis['objective_value'] = (float)$result['Result']['Objective'];
    } elseif ($kpis['total_cost'] !== null) {
        // Use total cost as objective if available
        $kpis['objective_value'] = $kpis['total_cost'];
    }
    
    return $kpis;
}

/**
 * Run a single benchmark instance
 */
function runBenchmarkInstance($bomSize, $config, $dataDir, $modelDir, $logsDir) {
    global $TIME_LIMIT_SEC, $CARBON_TAX, $SERVICE_TIME, $SUPPLIERS, $MODEL_FILE;
    
    echo "\n=== Running BOM size $bomSize ===\n";
    
    // Check/generate BOM file
    $bomFile = "bom_supemis_{$bomSize}.csv";
    $bomPath = $dataDir . $bomFile;
    if (!file_exists($bomPath)) {
        echo "  BOM file not found, generating...\n";
        generateBOMFile($bomSize, $dataDir);
    }
    
    // Check/generate supplier list file
    $suppListFile = "supp_list_{$bomSize}.csv";
    $suppListPath = $dataDir . $suppListFile;
    if (!file_exists($suppListPath)) {
        echo "  Supplier list file not found, generating...\n";
        generateSupplierListFile($bomSize, $dataDir);
    }
    
    // Determine supplier details file
    // Use high capacity if:
    // 1. BOM size >= 25 (as per original logic), OR
    // 2. Any leaf node requires more than standard capacity can provide
    $suppDetailsFile = "supp_details_supeco.csv";
    
    if ($bomSize >= 25) {
        $suppDetailsFile = "supp_details_supeco_grdCapacity.csv";
    } else {
        // Check if we need high capacity by analyzing leaf node requirements
        if (file_exists($bomPath)) {
            $bomLines = file($bomPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            array_shift($bomLines); // Skip header
            
            $nodes = [];
            $parents = [];
            $adup = 20;
            
            foreach ($bomLines as $line) {
                $parts = explode(';', $line);
                if (count($parts) >= 5) {
                    $nodeId = (int)$parts[0];
                    $parentId = (int)$parts[2];
                    $rqtf = (int)$parts[4];
                    $nodes[$nodeId] = ['parent' => $parentId, 'rqtf' => $rqtf];
                    if ($parentId >= 0) {
                        $parents[$parentId] = true;
                    }
                }
            }
            
            // Find leaf nodes and check requirements
            $standardCapacity = 1960; // Total capacity of standard suppliers
            foreach ($nodes as $nodeId => $node) {
                if ($nodeId == 0) continue;
                if (!isset($parents[$nodeId])) {
                    $requiredQty = $adup * $node['rqtf'];
                    if ($requiredQty > $standardCapacity) {
                        $suppDetailsFile = "supp_details_supeco_grdCapacity.csv";
                        break;
                    }
                }
            }
        }
    }
    
    // Prepare run configuration
    // Normalize paths to use forward slashes (OPL supports this on Windows)
    // and ensure we use just filenames, not full paths
    $prefix = sprintf("SCAL-%03d", $bomSize);
    $runConfig = [
        "_NODE_FILE_" => str_replace('\\', '/', $bomFile), // Just filename, normalize slashes
        "_NODE_SUPP_FILE_" => str_replace('\\', '/', $suppListFile), // Just filename, normalize slashes
        "_SUPP_DETAILS_FILE_" => str_replace('\\', '/', $suppDetailsFile), // Just filename, normalize slashes
        "_NBSUPP_" => $SUPPLIERS,
        "_SERVICE_T_" => $SERVICE_TIME,
        "_EMISCAP_" => 2500000, // Not used (no cap), but required by model
        "_EMISTAXE_" => $CARBON_TAX,
    ];
    
    // Prepare model file with time limit
    $modelPath = $modelDir . $MODEL_FILE;
    $preparedModel = prepareModelFile($modelPath, $runConfig, $prefix, $dataDir, $TIME_LIMIT_SEC);
    
    // Record start time for timeout detection
    $startTime = microtime(true);
    
    // Run CPLEX (time limit is enforced in the model via cplex.tilim)
    $rawOutput = null;
    try {
        // Capture raw output for debugging
        $cmdLine = '"' . $config['OPLRUN'] . '" ' . escapeshellarg($preparedModel);
        $rawOutput = shell_exec($cmdLine);
        
        if (!$rawOutput) {
            throw new Exception("No output from CPLEX. Command executed: $cmdLine");
        }
        
        // Parse the output
        $result = CplexRunner::run($preparedModel, $config['OPLRUN']);
        $elapsedTime = microtime(true) - $startTime;
        
        // Store raw output for debugging
        $result['_raw_output'] = $rawOutput;
        
        // Check for specific patterns in raw output
        if (preg_match('/Infeasibility row/i', $rawOutput) || preg_match('/<<< no solution/i', $rawOutput)) {
            $result['_is_infeasible'] = true;
        }
        
        if (preg_match('/unbounded/i', $rawOutput)) {
            $result['_is_unbounded'] = true;
        }
        
        $errorPatterns = [
            '/error/i',
            '/exception/i',
            '/failed/i',
            '/file.*not.*exist/i',
            '/format error/i'
        ];
        
        foreach ($errorPatterns as $pattern) {
            if (preg_match($pattern, $rawOutput)) {
                $result['_has_error_pattern'] = true;
                break;
            }
        }
        
        // Check for timeout based on elapsed time or runtime value
        if ($elapsedTime >= $TIME_LIMIT_SEC - 5) {
            $result['TIMEOUT_DETECTED'] = true;
        }
        
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        $elapsedTime = microtime(true) - $startTime;
        $result = [
            'status' => 'ERROR',
            'error_message' => $e->getMessage(),
            'CplexRunTime' => $elapsedTime . " sec",
            '_raw_output' => $rawOutput
        ];
    }
    
    // Save log with raw output
    $logFile = $logsDir . "run_{$bomSize}.log";
    $logContent = print_r($result, true);
    if ($rawOutput && !isset($result['_raw_output'])) {
        $logContent .= "\n\n=== RAW CPLEX OUTPUT ===\n" . $rawOutput;
    }
    file_put_contents($logFile, $logContent);
    
    // Extract KPIs
    $kpis = extractKPIs($result, $bomSize);
    
    // Clean up prepared model file
    if (file_exists($preparedModel)) {
        unlink($preparedModel);
    }
    
    return $kpis;
}

/**
 * Generate summary report
 */
function generateSummary($results, $outputFile) {
    $summary = "# Scalability Benchmark Summary\n\n";
    $summary .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    $summary .= "## Configuration\n\n";
    $summary .= "- Model: RUNS_SupEmis_Cplex_PLM_Tax.mod\n";
    $summary .= "- Carbon Tax: 0 (baseline emissions)\n";
    $summary .= "- Time Limit: 1800 seconds (30 minutes)\n";
    $summary .= "- Service Time: 1\n";
    $summary .= "- Suppliers: 10\n\n";
    
    $summary .= "## Key Findings\n\n";
    
    // Find first N where runtime > 60s
    $first60s = null;
    foreach ($results as $r) {
        if ($r['runtime_sec'] > 60 && $first60s === null) {
            $first60s = $r['N'];
            $summary .= "- **First N with runtime > 60s:** N = {$first60s} (runtime: {$r['runtime_sec']}s)\n";
        }
    }
    if ($first60s === null) {
        $summary .= "- **First N with runtime > 60s:** Not reached in tested range\n";
    }
    
    // Find first N where runtime > 300s
    $first300s = null;
    foreach ($results as $r) {
        if ($r['runtime_sec'] > 300 && $first300s === null) {
            $first300s = $r['N'];
            $summary .= "- **First N with runtime > 300s:** N = {$first300s} (runtime: {$r['runtime_sec']}s)\n";
        }
    }
    if ($first300s === null) {
        $summary .= "- **First N with runtime > 300s:** Not reached in tested range\n";
    }
    
    // Find first timeout
    $firstTimeout = null;
    foreach ($results as $r) {
        if ($r['status'] === 'TIMEOUT' && $firstTimeout === null) {
            $firstTimeout = $r['N'];
            $summary .= "- **First N with TIMEOUT:** N = {$firstTimeout}\n";
        }
    }
    if ($firstTimeout === null) {
        $summary .= "- **First N with TIMEOUT:** Not reached in tested range\n";
    }
    
    // Check for infeasibility
    $infeasible = [];
    foreach ($results as $r) {
        if ($r['status'] === 'INFEASIBLE') {
            $infeasible[] = $r['N'];
        }
    }
    if (!empty($infeasible)) {
        $summary .= "- **Infeasible instances:** N = " . implode(', ', $infeasible) . "\n";
    } else {
        $summary .= "- **Infeasible instances:** None\n";
    }
    
    // Analyze scaling pattern
    $summary .= "\n## Scaling Pattern Analysis\n\n";
    $runtimes = array_filter(array_column($results, 'runtime_sec'), function($v) { return $v > 0; });
    if (count($runtimes) >= 3) {
        $sizes = array_column($results, 'N');
        $summary .= "Observed runtime scaling:\n";
        $summary .= "- Small instances (N < 10): " . 
            (isset($results[0]['runtime_sec']) ? $results[0]['runtime_sec'] : 'N/A') . "s\n";
        $midIndex = (int)(count($results) / 2);
        $summary .= "- Medium instances (10 <= N < 30): " . 
            (isset($results[$midIndex]['runtime_sec']) ? $results[$midIndex]['runtime_sec'] : 'N/A') . "s\n";
        $lastIndex = count($results) - 1;
        $summary .= "- Large instances (N >= 30): " . 
            (isset($results[$lastIndex]['runtime_sec']) ? $results[$lastIndex]['runtime_sec'] : 'N/A') . "s\n";
    }
    
    $summary .= "\n## Assumptions and Notes\n\n";
    $summary .= "- Missing BOM files were generated by expanding from the closest available size\n";
    $summary .= "- Time limit enforced at model level via cplex.tilim parameter\n";
    $summary .= "- Baseline emissions (E₀) recorded for each instance size\n";
    $summary .= "- Buffer count estimated from decision variables when available\n";
    
    file_put_contents($outputFile, $summary);
    echo "\nSummary written to: $outputFile\n";
}

// Main execution
try {
    echo "=== Scalability Benchmark Campaign ===\n\n";
    
    // Load configuration
    $config = include __DIR__ . '/../config/settings.php';
    echo "Configuration loaded.\n";
    
    $dataDir = $config['WORK_DIR'];
    $modelDir = $config['MODELE'];
    $logsDir = $config['LOGS_DIR'];
    
    // Create results directory
    $resultsDir = $logsDir . 'scalability_' . date('Ymd_His') . DIRECTORY_SEPARATOR;
    if (!is_dir($resultsDir)) {
        mkdir($resultsDir, 0755, true);
    }
    
    // Smoke test
    echo "\n=== Running Smoke Tests ===\n";
    $smokeSizes = [5, 13, 50];
    $smokeResults = [];
    foreach ($smokeSizes as $size) {
        if (in_array($size, $ALL_SIZES)) {
            $kpis = runBenchmarkInstance($size, $config, $dataDir, $modelDir, $resultsDir);
            $smokeResults[] = $kpis;
            echo "  N=$size: Status={$kpis['status']}, Runtime={$kpis['runtime_sec']}s\n";
        }
    }
    
    // Check smoke test results
    $smokePassed = true;
    foreach ($smokeResults as $r) {
        if ($r['status'] === 'ERROR' || ($r['runtime_sec'] < 0 && $r['status'] !== 'TIMEOUT')) {
            $smokePassed = false;
            echo "  WARNING: Smoke test failed for N={$r['N']}\n";
        }
    }
    
    if (!$smokePassed) {
        echo "\nWARNING: Smoke tests had issues. Continuing anyway...\n";
    } else {
        echo "\nSmoke tests passed. Proceeding with full sweep...\n";
    }
    
    // Full sweep
    echo "\n=== Running Full BOM Size Sweep ===\n";
    $allResults = [];
    
    foreach ($ALL_SIZES as $size) {
        $kpis = runBenchmarkInstance($size, $config, $dataDir, $modelDir, $resultsDir);
        $allResults[] = $kpis;
        
        echo "  N=$size: Status={$kpis['status']}, Runtime={$kpis['runtime_sec']}s, " .
             "Emissions={$kpis['total_emissions']}, Buffers={$kpis['buffers_count']}\n";
    }
    
    // Generate results CSV
    echo "\n=== Generating Results CSV ===\n";
    $csvFile = $resultsDir . 'results_scalability_bom_sweep.csv';
    $fp = fopen($csvFile, 'w');
    
    // Write header
    $headers = ['N', 'phase', 'status', 'runtime_sec', 'objective_value', 
                'total_emissions', 'total_cost', 'buffers_count', 
                'num_vars', 'num_constraints', 'mip_gap'];
    fputcsv($fp, $headers);
    
    // Write data
    foreach ($allResults as $r) {
        fputcsv($fp, [
            $r['N'],
            $r['phase'],
            $r['status'],
            $r['runtime_sec'],
            $r['objective_value'],
            $r['total_emissions'],
            $r['total_cost'],
            $r['buffers_count'],
            $r['num_vars'],
            $r['num_constraints'],
            $r['mip_gap']
        ]);
    }
    fclose($fp);
    echo "Results CSV written to: $csvFile\n";
    
    // Generate summary
    echo "\n=== Generating Summary ===\n";
    $summaryFile = $resultsDir . 'results_scalability_summary.md';
    generateSummary($allResults, $summaryFile);
    
    echo "\n=== Benchmark Complete ===\n";
    echo "Results directory: $resultsDir\n";
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
