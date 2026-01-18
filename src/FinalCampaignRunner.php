<?php

/**
 * Final Campaign Runner for DDMRP Buffer Positioning and Carbon Footprint Study
 * 
 * Executes comprehensive numerical test campaign:
 * - Scalability tests
 * - Topology scenarios
 * - Carbon strategy tests (Tax, Cap, Hybrid)
 * - Service time sensitivity
 * - Multi-objective optimization
 * 
 * Generates publication-ready results for Journal of Cleaner Production article.
 */

require_once __DIR__ . '/FileUtils.php';
require_once __DIR__ . '/CplexRunner.php';
require_once __DIR__ . '/KPICalculator.php';
require_once __DIR__ . '/MultiObjectiveRunner.php';

class FinalCampaignRunner {
    
    private $config;
    private $instanceRegistry;
    private $campaignConfig;
    private $kpiCalculator;
    private $resultsDir;
    private $figuresDir;
    private $tablesDir;
    private $allResults = [];
    private $baselineEmissions = [];
    private $runCounter = 0;
    
    // Solver settings
    private $timeLimitSec = 1800;
    private $dataDir;
    private $modelDir;
    private $logsDir;
    private $oplRunPath;
    
    public function __construct() {
        // Load system configuration
        $this->config = include __DIR__ . '/../config/settings.php';
        
        $this->dataDir = $this->config['WORK_DIR'];
        $this->modelDir = $this->config['MODELE'];
        $this->logsDir = $this->config['LOGS_DIR'];
        $this->oplRunPath = $this->config['OPLRUN'];
        
        // Load instance registry
        $registryFile = __DIR__ . '/../config/instance_registry.json';
        $this->instanceRegistry = json_decode(file_get_contents($registryFile), true);
        
        // Load campaign configuration
        $campaignFile = __DIR__ . '/../config/final_campaign_config.json';
        $this->campaignConfig = json_decode(file_get_contents($campaignFile), true);
        
        // Initialize KPI calculator
        $this->kpiCalculator = new KPICalculator();
        
        // Create results directories
        $timestamp = date('Ymd_His');
        $this->resultsDir = $this->logsDir . 'final_campaign_' . $timestamp . DIRECTORY_SEPARATOR;
        $this->figuresDir = $this->resultsDir . 'figures' . DIRECTORY_SEPARATOR;
        $this->tablesDir = $this->resultsDir . 'tables' . DIRECTORY_SEPARATOR;
        
        foreach ([$this->resultsDir, $this->figuresDir, $this->tablesDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        
        // Extract time limit from config
        $this->timeLimitSec = $this->campaignConfig['solver_settings']['time_limit_sec'] ?? 1800;
    }
    
    /**
     * Run the complete campaign
     */
    public function runFullCampaign(): void {
        $startTime = microtime(true);
        
        echo "========================================\n";
        echo "FINAL COMPREHENSIVE TEST CAMPAIGN\n";
        echo "Journal of Cleaner Production Article\n";
        echo "========================================\n\n";
        echo "Campaign started: " . date('Y-m-d H:i:s') . "\n";
        echo "Results directory: {$this->resultsDir}\n\n";
        
        // Save campaign metadata
        $this->saveCampaignMetadata();
        
        $experiments = $this->campaignConfig['experiments'];
        
        // 1. Run scalability benchmark
        if ($experiments['scalability']['enabled'] ?? false) {
            echo "\n=== PHASE 1: SCALABILITY BENCHMARK ===\n";
            $this->runScalabilityBenchmark($experiments['scalability']);
        }
        
        // 2. Run topology baseline
        if ($experiments['topology_baseline']['enabled'] ?? false) {
            echo "\n=== PHASE 2: TOPOLOGY BASELINE ===\n";
            $this->runTopologyBaseline($experiments['topology_baseline']);
        }
        
        // 3. Run carbon tax sweep
        if ($experiments['carbon_tax_sweep']['enabled'] ?? false) {
            echo "\n=== PHASE 3: CARBON TAX SWEEP ===\n";
            $this->runCarbonTaxSweep($experiments['carbon_tax_sweep']);
        }
        
        // 4. Run carbon cap sweep
        if ($experiments['carbon_cap_sweep']['enabled'] ?? false) {
            echo "\n=== PHASE 4: CARBON CAP SWEEP ===\n";
            $this->runCarbonCapSweep($experiments['carbon_cap_sweep']);
        }
        
        // 5. Run hybrid strategy tests
        if ($experiments['carbon_hybrid']['enabled'] ?? false) {
            echo "\n=== PHASE 5: HYBRID STRATEGY TESTS ===\n";
            $this->runHybridStrategyTests($experiments['carbon_hybrid']);
        }
        
        // 6. Run service time sensitivity
        if ($experiments['service_time_sensitivity']['enabled'] ?? false) {
            echo "\n=== PHASE 6: SERVICE TIME SENSITIVITY ===\n";
            $this->runServiceTimeSensitivity($experiments['service_time_sensitivity']);
        }
        
        // 7. Run multi-objective optimization
        if ($experiments['multi_objective']['enabled'] ?? false) {
            echo "\n=== PHASE 7: MULTI-OBJECTIVE OPTIMIZATION ===\n";
            $this->runMultiObjectiveOptimization($experiments['multi_objective']);
        }
        
        // 8. Run PLM vs NLM comparison
        if ($experiments['nlm_comparison']['enabled'] ?? false) {
            echo "\n=== PHASE 8: PLM vs NLM COMPARISON ===\n";
            $this->runNLMComparison($experiments['nlm_comparison']);
        }
        
        // Generate consolidated outputs
        echo "\n=== GENERATING OUTPUTS ===\n";
        $this->generateConsolidatedCSV();
        $this->generateSummaryStatistics();
        $this->generateChecklist();
        
        $elapsed = microtime(true) - $startTime;
        echo "\n========================================\n";
        echo "CAMPAIGN COMPLETE\n";
        echo "Total runs: {$this->runCounter}\n";
        echo "Elapsed time: " . gmdate("H:i:s", (int)$elapsed) . "\n";
        echo "Results saved to: {$this->resultsDir}\n";
        echo "========================================\n";
    }
    
    /**
     * Run scalability benchmark
     */
    private function runScalabilityBenchmark(array $expConfig): void {
        $sizes = $expConfig['instances'];
        $modelType = $expConfig['model_type'];
        $taxRate = $expConfig['tax_rate'];
        $serviceTime = $expConfig['service_time'];
        $suppliers = $expConfig['suppliers'];
        
        echo "Testing " . count($sizes) . " BOM sizes: " . implode(', ', $sizes) . "\n";
        
        foreach ($sizes as $size) {
            $instanceId = "bom_{$size}";
            $bomFile = "bom_supemis_{$size}.csv";
            $suppListFile = "supp_list_{$size}.csv";
            
            // Check if files exist
            if (!file_exists($this->dataDir . $bomFile)) {
                echo "  Skipping N=$size - BOM file not found\n";
                continue;
            }
            
            // Determine supplier details file
            $suppDetailsFile = ($size >= 25) ? 
                "supp_details_supeco_grdCapacity.csv" : 
                "supp_details_supeco.csv";
            
            $runConfig = [
                'PREFIXE' => sprintf("SCAL-%03d", $size),
                '_NODE_FILE_' => $bomFile,
                '_NODE_SUPP_FILE_' => $suppListFile,
                '_SUPP_DETAILS_FILE_' => $suppDetailsFile,
                '_NBSUPP_' => $suppliers,
                '_SERVICE_T_' => $serviceTime,
                '_EMISCAP_' => 2500000,
                '_EMISTAXE_' => $taxRate,
                'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Tax.mod',
                'MODEL_TYPE' => $modelType,
                'EXPERIMENT' => 'scalability'
            ];
            
            $result = $this->executeSingleRun($runConfig, $instanceId);
            
            // Store baseline emissions
            if ($taxRate == 0.0 && isset($result['kpis']['carbon']['total_emissions'])) {
                $this->baselineEmissions[$instanceId] = $result['kpis']['carbon']['total_emissions'];
                $this->kpiCalculator->setBaseline($instanceId, [
                    'total_emissions' => $result['kpis']['carbon']['total_emissions'],
                    'total_cost' => $result['kpis']['cost']['total_cost_without_tax'],
                    'DIO' => $result['kpis']['inventory']['DIO'],
                    'WIP' => $result['kpis']['inventory']['WIP_level']
                ]);
            }
            
            echo "  N=$size: Status={$result['kpis']['computational']['solver_status']}, " .
                 "Runtime={$result['kpis']['computational']['runtime_sec']}s, " .
                 "Emissions=" . ($result['kpis']['carbon']['total_emissions'] ?? 'N/A') . "\n";
        }
        
        // Save scalability results
        $this->saveExperimentResults('scalability', array_filter($this->allResults, function($r) {
            return ($r['config']['EXPERIMENT'] ?? '') === 'scalability';
        }));
    }
    
    /**
     * Run topology baseline tests
     */
    private function runTopologyBaseline(array $expConfig): void {
        $families = $expConfig['instance_families'];
        
        foreach ($families as $family) {
            if (!isset($this->instanceRegistry['bom_families'][$family])) {
                echo "  Unknown family: $family\n";
                continue;
            }
            
            $instances = $this->instanceRegistry['bom_families'][$family]['instances'];
            echo "Testing {$family} family: " . count($instances) . " instances\n";
            
            foreach ($instances as $instance) {
                $instanceId = $instance['id'];
                $bomFile = $instance['file'];
                
                // Determine supplier list file
                $suppListBaseName = preg_replace('/^bom_supemis_/', '', basename($bomFile, '.csv'));
                $suppListFile = "supp_list_{$suppListBaseName}.csv";
                
                if (!file_exists($this->dataDir . $bomFile) || !file_exists($this->dataDir . $suppListFile)) {
                    echo "  Skipping {$instanceId} - files not found\n";
                    continue;
                }
                
                $suppDetailsFile = ($instance['nodes'] >= 25) ? 
                    "supp_details_supeco_grdCapacity.csv" : 
                    "supp_details_supeco.csv";
                
                $runConfig = [
                    'PREFIXE' => "TOPO-{$family}-{$instanceId}",
                    '_NODE_FILE_' => $bomFile,
                    '_NODE_SUPP_FILE_' => $suppListFile,
                    '_SUPP_DETAILS_FILE_' => $suppDetailsFile,
                    '_NBSUPP_' => $expConfig['suppliers'],
                    '_SERVICE_T_' => $expConfig['service_time'],
                    '_EMISCAP_' => 2500000,
                    '_EMISTAXE_' => $expConfig['tax_rate'],
                    'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Tax.mod',
                    'MODEL_TYPE' => 'PLM',
                    'EXPERIMENT' => 'topology_baseline',
                    'TOPOLOGY' => $family
                ];
                
                $result = $this->executeSingleRun($runConfig, $instanceId);
                
                // Store baseline
                if (isset($result['kpis']['carbon']['total_emissions'])) {
                    $this->baselineEmissions[$instanceId] = $result['kpis']['carbon']['total_emissions'];
                    $this->kpiCalculator->setBaseline($instanceId, [
                        'total_emissions' => $result['kpis']['carbon']['total_emissions'],
                        'total_cost' => $result['kpis']['cost']['total_cost_without_tax'],
                        'DIO' => $result['kpis']['inventory']['DIO'],
                        'WIP' => $result['kpis']['inventory']['WIP_level']
                    ]);
                }
                
                echo "  {$instanceId}: Status={$result['kpis']['computational']['solver_status']}, " .
                     "Emissions=" . ($result['kpis']['carbon']['total_emissions'] ?? 'N/A') . "\n";
            }
        }
        
        $this->saveExperimentResults('topology_baseline', array_filter($this->allResults, function($r) {
            return ($r['config']['EXPERIMENT'] ?? '') === 'topology_baseline';
        }));
    }
    
    /**
     * Run carbon tax sweep
     */
    private function runCarbonTaxSweep(array $expConfig): void {
        $taxRates = $expConfig['tax_rates'];
        $instances = $expConfig['representative_instances'];
        
        echo "Testing tax rates: " . implode(', ', $taxRates) . "\n";
        echo "Instances: " . implode(', ', $instances) . "\n";
        
        foreach ($instances as $instanceId) {
            // Get instance details
            $instance = $this->findInstance($instanceId);
            if (!$instance) {
                echo "  Skipping unknown instance: $instanceId\n";
                continue;
            }
            
            $bomFile = $instance['file'];
            $suppListBaseName = preg_replace('/^bom_supemis_/', '', basename($bomFile, '.csv'));
            $suppListFile = "supp_list_{$suppListBaseName}.csv";
            
            if (!file_exists($this->dataDir . $bomFile)) {
                echo "  Skipping $instanceId - BOM file not found\n";
                continue;
            }
            
            $suppDetailsFile = ($instance['nodes'] >= 25) ? 
                "supp_details_supeco_grdCapacity.csv" : 
                "supp_details_supeco.csv";
            
            foreach ($taxRates as $tax) {
                $runConfig = [
                    'PREFIXE' => sprintf("TAX-%s-%.2f", $instanceId, $tax),
                    '_NODE_FILE_' => $bomFile,
                    '_NODE_SUPP_FILE_' => $suppListFile,
                    '_SUPP_DETAILS_FILE_' => $suppDetailsFile,
                    '_NBSUPP_' => $expConfig['suppliers'],
                    '_SERVICE_T_' => $expConfig['service_time'],
                    '_EMISCAP_' => 2500000,
                    '_EMISTAXE_' => $tax,
                    'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Tax.mod',
                    'MODEL_TYPE' => 'PLM',
                    'EXPERIMENT' => 'carbon_tax_sweep',
                    'TAX_RATE' => $tax
                ];
                
                $result = $this->executeSingleRun($runConfig, $instanceId);
                
                echo "  {$instanceId}, tax={$tax}: " .
                     "Cost=" . ($result['kpis']['cost']['total_cost_with_tax'] ?? 'N/A') . ", " .
                     "Emissions=" . ($result['kpis']['carbon']['total_emissions'] ?? 'N/A') . "\n";
            }
        }
        
        $this->saveExperimentResults('carbon_tax_sweep', array_filter($this->allResults, function($r) {
            return ($r['config']['EXPERIMENT'] ?? '') === 'carbon_tax_sweep';
        }));
    }
    
    /**
     * Run carbon cap sweep
     */
    private function runCarbonCapSweep(array $expConfig): void {
        $capPercentages = $expConfig['cap_percentages'];
        $instances = $expConfig['representative_instances'];
        
        echo "Testing cap percentages: " . implode(', ', array_map(function($p) { return ($p * 100) . '%'; }, $capPercentages)) . "\n";
        echo "Instances: " . implode(', ', $instances) . "\n";
        
        foreach ($instances as $instanceId) {
            $instance = $this->findInstance($instanceId);
            if (!$instance) continue;
            
            $bomFile = $instance['file'];
            $suppListBaseName = preg_replace('/^bom_supemis_/', '', basename($bomFile, '.csv'));
            $suppListFile = "supp_list_{$suppListBaseName}.csv";
            
            if (!file_exists($this->dataDir . $bomFile)) {
                echo "  Skipping $instanceId - BOM file not found\n";
                continue;
            }
            
            // Get baseline emissions for this instance
            $baselineEmis = $this->baselineEmissions[$instanceId] ?? 2500000;
            
            $suppDetailsFile = ($instance['nodes'] >= 25) ? 
                "supp_details_supeco_grdCapacity.csv" : 
                "supp_details_supeco.csv";
            
            foreach ($capPercentages as $capPct) {
                $capValue = (int)($baselineEmis * $capPct);
                
                $runConfig = [
                    'PREFIXE' => sprintf("CAP-%s-%.0f", $instanceId, $capPct * 100),
                    '_NODE_FILE_' => $bomFile,
                    '_NODE_SUPP_FILE_' => $suppListFile,
                    '_SUPP_DETAILS_FILE_' => $suppDetailsFile,
                    '_NBSUPP_' => $expConfig['suppliers'],
                    '_SERVICE_T_' => $expConfig['service_time'],
                    '_EMISCAP_' => $capValue,
                    '_EMISTAXE_' => 0.01,
                    'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Cap.mod',
                    'MODEL_TYPE' => 'PLM',
                    'EXPERIMENT' => 'carbon_cap_sweep',
                    'CAP_PERCENTAGE' => $capPct,
                    'CAP_VALUE' => $capValue
                ];
                
                $result = $this->executeSingleRun($runConfig, $instanceId);
                
                echo "  {$instanceId}, cap={$capPct}: " .
                     "Cost=" . ($result['kpis']['cost']['total_cost_without_tax'] ?? 'N/A') . ", " .
                     "Emissions=" . ($result['kpis']['carbon']['total_emissions'] ?? 'N/A') . "\n";
            }
        }
        
        $this->saveExperimentResults('carbon_cap_sweep', array_filter($this->allResults, function($r) {
            return ($r['config']['EXPERIMENT'] ?? '') === 'carbon_cap_sweep';
        }));
    }
    
    /**
     * Run hybrid strategy tests
     */
    private function runHybridStrategyTests(array $expConfig): void {
        $combinations = $expConfig['combinations'];
        $instances = $expConfig['representative_instances'];
        
        echo "Testing " . count($combinations) . " hybrid combinations\n";
        echo "Instances: " . implode(', ', $instances) . "\n";
        
        foreach ($instances as $instanceId) {
            $instance = $this->findInstance($instanceId);
            if (!$instance) continue;
            
            $bomFile = $instance['file'];
            $suppListBaseName = preg_replace('/^bom_supemis_/', '', basename($bomFile, '.csv'));
            $suppListFile = "supp_list_{$suppListBaseName}.csv";
            
            if (!file_exists($this->dataDir . $bomFile)) continue;
            
            $baselineEmis = $this->baselineEmissions[$instanceId] ?? 2500000;
            $suppDetailsFile = ($instance['nodes'] >= 25) ? 
                "supp_details_supeco_grdCapacity.csv" : 
                "supp_details_supeco.csv";
            
            foreach ($combinations as $combo) {
                $tax = $combo['tax'];
                $capPct = $combo['cap_pct'];
                $label = $combo['label'];
                $capValue = (int)($baselineEmis * $capPct);
                
                $runConfig = [
                    'PREFIXE' => "HYB-{$instanceId}-{$label}",
                    '_NODE_FILE_' => $bomFile,
                    '_NODE_SUPP_FILE_' => $suppListFile,
                    '_SUPP_DETAILS_FILE_' => $suppDetailsFile,
                    '_NBSUPP_' => $expConfig['suppliers'],
                    '_SERVICE_T_' => $expConfig['service_time'],
                    '_EMISCAP_' => $capValue,
                    '_EMISTAXE_' => $tax,
                    'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Hybrid.mod',
                    'MODEL_TYPE' => 'PLM',
                    'EXPERIMENT' => 'carbon_hybrid',
                    'HYBRID_LABEL' => $label,
                    'TAX_RATE' => $tax,
                    'CAP_PERCENTAGE' => $capPct,
                    'CAP_VALUE' => $capValue
                ];
                
                $result = $this->executeSingleRun($runConfig, $instanceId);
                
                echo "  {$instanceId}, {$label}: " .
                     "Status={$result['kpis']['computational']['solver_status']}, " .
                     "Cost=" . ($result['kpis']['cost']['total_cost_with_tax'] ?? 'N/A') . "\n";
            }
        }
        
        $this->saveExperimentResults('carbon_hybrid', array_filter($this->allResults, function($r) {
            return ($r['config']['EXPERIMENT'] ?? '') === 'carbon_hybrid';
        }));
    }
    
    /**
     * Run service time sensitivity analysis
     */
    private function runServiceTimeSensitivity(array $expConfig): void {
        $serviceTimes = $expConfig['service_times'];
        $strategies = $expConfig['strategies'];
        $instances = $expConfig['representative_instances'];
        
        echo "Testing service times: " . implode(', ', $serviceTimes) . "\n";
        echo "Strategies: " . implode(', ', $strategies) . "\n";
        
        foreach ($instances as $instanceId) {
            $instance = $this->findInstance($instanceId);
            if (!$instance) continue;
            
            $bomFile = $instance['file'];
            $suppListBaseName = preg_replace('/^bom_supemis_/', '', basename($bomFile, '.csv'));
            $suppListFile = "supp_list_{$suppListBaseName}.csv";
            
            if (!file_exists($this->dataDir . $bomFile)) continue;
            
            $suppDetailsFile = ($instance['nodes'] >= 25) ? 
                "supp_details_supeco_grdCapacity.csv" : 
                "supp_details_supeco.csv";
            $baselineEmis = $this->baselineEmissions[$instanceId] ?? 2500000;
            
            foreach ($strategies as $strategy) {
                foreach ($serviceTimes as $svt) {
                    $modelFile = ($strategy === 'EMISCAP') ? 
                        'RUNS_SupEmis_Cplex_PLM_Cap.mod' : 
                        'RUNS_SupEmis_Cplex_PLM_Tax.mod';
                    
                    $capValue = ($strategy === 'EMISCAP') ? 
                        (int)($baselineEmis * $expConfig['cap_percentage']) : 2500000;
                    $taxRate = ($strategy === 'EMISTAXE') ? 
                        $expConfig['tax_rate'] : 0.01;
                    
                    $runConfig = [
                        'PREFIXE' => "SVT-{$instanceId}-{$strategy}-SvT{$svt}",
                        '_NODE_FILE_' => $bomFile,
                        '_NODE_SUPP_FILE_' => $suppListFile,
                        '_SUPP_DETAILS_FILE_' => $suppDetailsFile,
                        '_NBSUPP_' => $expConfig['suppliers'],
                        '_SERVICE_T_' => $svt,
                        '_EMISCAP_' => $capValue,
                        '_EMISTAXE_' => $taxRate,
                        'MODEL_FILE' => $modelFile,
                        'MODEL_TYPE' => 'PLM',
                        'EXPERIMENT' => 'service_time_sensitivity',
                        'STRATEGY' => $strategy,
                        'SERVICE_TIME' => $svt
                    ];
                    
                    $result = $this->executeSingleRun($runConfig, $instanceId);
                    
                    echo "  {$instanceId}, {$strategy}, SvT={$svt}: " .
                         "Status={$result['kpis']['computational']['solver_status']}, " .
                         "Buffers={$result['kpis']['ddmrp']['buffer_count']}\n";
                }
            }
        }
        
        $this->saveExperimentResults('service_time_sensitivity', array_filter($this->allResults, function($r) {
            return ($r['config']['EXPERIMENT'] ?? '') === 'service_time_sensitivity';
        }));
    }
    
    /**
     * Run multi-objective optimization
     */
    private function runMultiObjectiveOptimization(array $expConfig): void {
        $instances = $expConfig['representative_instances'];
        $numPoints = $expConfig['num_pareto_points'];
        
        echo "Generating Pareto fronts with {$numPoints} points\n";
        echo "Instances: " . implode(', ', $instances) . "\n";
        
        foreach ($instances as $instanceId) {
            $instance = $this->findInstance($instanceId);
            if (!$instance) continue;
            
            $bomFile = $instance['file'];
            $suppListBaseName = preg_replace('/^bom_supemis_/', '', basename($bomFile, '.csv'));
            $suppListFile = "supp_list_{$suppListBaseName}.csv";
            
            if (!file_exists($this->dataDir . $bomFile)) continue;
            
            echo "  Processing {$instanceId}...\n";
            
            $suppDetailsFile = ($instance['nodes'] >= 25) ? 
                "supp_details_supeco_grdCapacity.csv" : 
                "supp_details_supeco.csv";
            
            $baseRun = [
                'PREFIXE' => "MOBJ-{$instanceId}",
                '_NODE_FILE_' => $bomFile,
                '_NODE_SUPP_FILE_' => $suppListFile,
                '_SUPP_DETAILS_FILE_' => $suppDetailsFile,
                '_NBSUPP_' => $expConfig['suppliers'],
                '_SERVICE_T_' => $expConfig['service_time'],
                '_EMISCAP_' => 2500000,
                '_EMISTAXE_' => 0.0
            ];
            
            $modelFile = $this->modelDir . 'RUNS_SupEmis_MultiObj_PLM.mod';
            
            // Find ideal and nadir points
            echo "    Finding ideal/nadir points...\n";
            $idealNadir = MultiObjectiveRunner::findIdealNadirPoints(
                $baseRun, $modelFile, $this->dataDir, $this->oplRunPath
            );
            
            // Generate Cost-Emissions Pareto front
            echo "    Generating Cost-Emissions Pareto front...\n";
            $costEmisPareto = MultiObjectiveRunner::generateCostEmissionsPareto(
                $baseRun, $modelFile, $this->dataDir, $this->oplRunPath, $idealNadir, $numPoints
            );
            
            // Generate Cost-DIO Pareto front
            echo "    Generating Cost-DIO Pareto front...\n";
            $costDIOPareto = MultiObjectiveRunner::generateCostDIOPareto(
                $baseRun, $modelFile, $this->dataDir, $this->oplRunPath, $idealNadir, $numPoints
            );
            
            // Generate Cost-WIP Pareto front
            echo "    Generating Cost-WIP Pareto front...\n";
            $costWIPPareto = MultiObjectiveRunner::generateCostWIPPareto(
                $baseRun, $modelFile, $this->dataDir, $this->oplRunPath, $idealNadir, $numPoints
            );
            
            // Save Pareto fronts
            $paretoDir = $this->resultsDir . 'pareto' . DIRECTORY_SEPARATOR;
            if (!is_dir($paretoDir)) mkdir($paretoDir, 0755, true);
            
            MultiObjectiveRunner::exportParetoToCSV(
                $costEmisPareto, $paretoDir . "{$instanceId}_cost_emissions_pareto.csv", 'Cost-Emissions'
            );
            MultiObjectiveRunner::exportParetoToCSV(
                $costDIOPareto, $paretoDir . "{$instanceId}_cost_dio_pareto.csv", 'Cost-DIO'
            );
            MultiObjectiveRunner::exportParetoToCSV(
                $costWIPPareto, $paretoDir . "{$instanceId}_cost_wip_pareto.csv", 'Cost-WIP'
            );
            
            // Save ideal/nadir
            file_put_contents(
                $paretoDir . "{$instanceId}_ideal_nadir.json",
                json_encode($idealNadir, JSON_PRETTY_PRINT)
            );
            
            echo "    Cost-Emissions: " . count($costEmisPareto) . " points\n";
            echo "    Cost-DIO: " . count($costDIOPareto) . " points\n";
            echo "    Cost-WIP: " . count($costWIPPareto) . " points\n";
        }
    }
    
    /**
     * Run PLM vs NLM comparison
     */
    private function runNLMComparison(array $expConfig): void {
        $strategies = $expConfig['strategies'];
        $instances = $expConfig['representative_instances'];
        
        echo "Comparing PLM vs NLM for selected instances\n";
        
        foreach ($instances as $instanceId) {
            $instance = $this->findInstance($instanceId);
            if (!$instance) continue;
            
            $bomFile = $instance['file'];
            $suppListBaseName = preg_replace('/^bom_supemis_/', '', basename($bomFile, '.csv'));
            $suppListFile = "supp_list_{$suppListBaseName}.csv";
            
            if (!file_exists($this->dataDir . $bomFile)) continue;
            
            $suppDetailsFile = "supp_details_supeco_grdCapacity.csv";
            $baselineEmis = $this->baselineEmissions[$instanceId] ?? 2500000;
            
            foreach ($strategies as $strategy) {
                foreach (['PLM', 'NLM'] as $modelType) {
                    if ($strategy === 'EMISCAP') {
                        $modelFile = ($modelType === 'PLM') ? 
                            'RUNS_SupEmis_Cplex_PLM_Cap.mod' : 
                            'RUNS_SupEmis_CP_NLM_Cap.mod';
                        $capValue = (int)($baselineEmis * $expConfig['cap_percentage']);
                        $taxRate = 0.01;
                    } else {
                        $modelFile = ($modelType === 'PLM') ? 
                            'RUNS_SupEmis_Cplex_PLM_Tax.mod' : 
                            'RUNS_SupEmis_CP_NLM_Tax.mod';
                        $capValue = 2500000;
                        $taxRate = $expConfig['tax_rate'];
                    }
                    
                    $runConfig = [
                        'PREFIXE' => "COMP-{$instanceId}-{$strategy}-{$modelType}",
                        '_NODE_FILE_' => $bomFile,
                        '_NODE_SUPP_FILE_' => $suppListFile,
                        '_SUPP_DETAILS_FILE_' => $suppDetailsFile,
                        '_NBSUPP_' => $expConfig['suppliers'],
                        '_SERVICE_T_' => $expConfig['service_time'],
                        '_EMISCAP_' => $capValue,
                        '_EMISTAXE_' => $taxRate,
                        'MODEL_FILE' => $modelFile,
                        'MODEL_TYPE' => $modelType,
                        'EXPERIMENT' => 'nlm_comparison',
                        'STRATEGY' => $strategy
                    ];
                    
                    $result = $this->executeSingleRun($runConfig, $instanceId);
                    
                    echo "  {$instanceId}, {$strategy}, {$modelType}: " .
                         "Runtime={$result['kpis']['computational']['runtime_sec']}s, " .
                         "Cost=" . ($result['kpis']['cost']['total_cost_without_tax'] ?? 'N/A') . "\n";
                }
            }
        }
        
        $this->saveExperimentResults('nlm_comparison', array_filter($this->allResults, function($r) {
            return ($r['config']['EXPERIMENT'] ?? '') === 'nlm_comparison';
        }));
    }
    
    /**
     * Execute a single optimization run
     */
    private function executeSingleRun(array $runConfig, string $instanceId): array {
        $this->runCounter++;
        
        $modelPath = $this->modelDir . $runConfig['MODEL_FILE'];
        $prefix = $runConfig['PREFIXE'];
        
        // Prepare model file
        $preparedModel = $this->prepareModelFile($modelPath, $runConfig, $prefix);
        
        // Execute CPLEX
        $rawOutput = null;
        $result = [];
        
        try {
            $cmdLine = '"' . $this->oplRunPath . '" ' . escapeshellarg($preparedModel);
            $rawOutput = shell_exec($cmdLine);
            
            if ($rawOutput) {
                $result = CplexRunner::run($preparedModel, $this->oplRunPath);
                $result['_raw_output'] = $rawOutput;
            } else {
                $result = ['status' => 'ERROR', 'error' => 'No output'];
            }
        } catch (Exception $e) {
            $result = ['status' => 'ERROR', 'error' => $e->getMessage()];
        }
        
        // Save log
        $logFile = $this->resultsDir . "logs" . DIRECTORY_SEPARATOR;
        if (!is_dir($logFile)) mkdir($logFile, 0755, true);
        $logFile .= "{$prefix}.log";
        file_put_contents($logFile, print_r($result, true));
        
        // Compute KPIs
        $kpis = $this->kpiCalculator->computeAllKPIs($result, $runConfig, $instanceId);
        
        // Store result
        $fullResult = [
            'config' => $runConfig,
            'instance_id' => $instanceId,
            'result' => $result,
            'kpis' => $kpis
        ];
        $this->allResults[] = $fullResult;
        
        // Clean up
        if (file_exists($preparedModel)) {
            unlink($preparedModel);
        }
        
        return $fullResult;
    }
    
    /**
     * Prepare model file with parameters
     */
    private function prepareModelFile(string $modelPath, array $runConfig, string $prefix): string {
        $content = file_get_contents($modelPath);
        
        // Detect if this is an NLM (CP Optimizer) model
        $isNLM = strpos($content, 'using CP;') !== false;
        
        if ($isNLM) {
            // For NLM models: update the existing cp.param.TimeLimit value
            $content = preg_replace(
                '/cp\.param\.TimeLimit\s*=\s*\d+/',
                "cp.param.TimeLimit = {$this->timeLimitSec}",
                $content
            );
        } else {
            // For PLM models: inject cplex.tilim after the second execute block
            $timeLimitCode = "    cplex.tilim = {$this->timeLimitSec};\n";
            $pattern = '/(execute\s*\{[\s\n]*\/\/BOM Nodes Data)/';
            if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                $pos = $matches[0][1] + strlen($matches[0][0]);
                $content = substr_replace($content, "\n" . $timeLimitCode, $pos, 0);
            }
        }
        
        // Apply replacements
        $content = str_replace(array_keys($runConfig), array_values($runConfig), $content);
        
        // Write to output
        $outputFile = $this->dataDir . strtoupper($prefix) . "_" . basename($modelPath);
        file_put_contents($outputFile, $content);
        
        return $outputFile;
    }
    
    /**
     * Find instance by ID
     */
    private function findInstance(string $instanceId): ?array {
        foreach ($this->instanceRegistry['bom_families'] as $family => $data) {
            foreach ($data['instances'] as $instance) {
                if ($instance['id'] === $instanceId) {
                    return $instance;
                }
            }
        }
        return null;
    }
    
    /**
     * Save experiment results to CSV
     */
    private function saveExperimentResults(string $experimentName, array $results): void {
        if (empty($results)) return;
        
        $csvFile = $this->tablesDir . "{$experimentName}_results.csv";
        $fp = fopen($csvFile, 'w');
        
        // Write header
        fputcsv($fp, KPICalculator::getCSVHeaders());
        
        // Write data
        foreach ($results as $result) {
            $flat = $this->kpiCalculator->flattenKPIs($result['kpis']);
            fputcsv($fp, array_values($flat));
        }
        
        fclose($fp);
        echo "  Saved {$experimentName} results to: {$csvFile}\n";
    }
    
    /**
     * Generate consolidated CSV with all results
     */
    private function generateConsolidatedCSV(): void {
        $csvFile = $this->resultsDir . 'consolidated_results.csv';
        $fp = fopen($csvFile, 'w');
        
        // Add experiment column to headers
        $headers = array_merge(['experiment'], KPICalculator::getCSVHeaders());
        fputcsv($fp, $headers);
        
        foreach ($this->allResults as $result) {
            $flat = $this->kpiCalculator->flattenKPIs($result['kpis']);
            $experiment = $result['config']['EXPERIMENT'] ?? 'unknown';
            $row = array_merge([$experiment], array_values($flat));
            fputcsv($fp, $row);
        }
        
        fclose($fp);
        echo "Consolidated results saved to: {$csvFile}\n";
    }
    
    /**
     * Generate summary statistics
     */
    private function generateSummaryStatistics(): void {
        $summary = "# Final Campaign Summary Statistics\n\n";
        $summary .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        $summary .= "## Run Statistics\n\n";
        $summary .= "- Total runs: {$this->runCounter}\n";
        
        // Count by experiment
        $byCampaign = [];
        $byStatus = [];
        foreach ($this->allResults as $result) {
            $exp = $result['config']['EXPERIMENT'] ?? 'unknown';
            $status = $result['kpis']['computational']['solver_status'] ?? 'UNKNOWN';
            
            if (!isset($byCampaign[$exp])) $byCampaign[$exp] = 0;
            $byCampaign[$exp]++;
            
            if (!isset($byStatus[$status])) $byStatus[$status] = 0;
            $byStatus[$status]++;
        }
        
        $summary .= "\n### By Experiment\n\n";
        foreach ($byCampaign as $exp => $count) {
            $summary .= "- {$exp}: {$count} runs\n";
        }
        
        $summary .= "\n### By Status\n\n";
        foreach ($byStatus as $status => $count) {
            $summary .= "- {$status}: {$count} runs\n";
        }
        
        // Compute aggregate statistics
        $summary .= "\n## Aggregate KPIs\n\n";
        
        $optimalResults = array_filter($this->allResults, function($r) {
            return ($r['kpis']['computational']['solver_status'] ?? '') === 'OPTIMAL';
        });
        
        if (!empty($optimalResults)) {
            $runtimes = array_map(function($r) { return $r['kpis']['computational']['runtime_sec']; }, $optimalResults);
            $emissions = array_filter(array_map(function($r) { return $r['kpis']['carbon']['total_emissions']; }, $optimalResults));
            $costs = array_filter(array_map(function($r) { return $r['kpis']['cost']['total_cost_without_tax']; }, $optimalResults));
            $buffers = array_filter(array_map(function($r) { return $r['kpis']['ddmrp']['buffer_count']; }, $optimalResults));
            
            $summary .= "### Runtime Statistics\n";
            $summary .= sprintf("- Min: %.3f s\n", min($runtimes));
            $summary .= sprintf("- Max: %.3f s\n", max($runtimes));
            $summary .= sprintf("- Mean: %.3f s\n", array_sum($runtimes) / count($runtimes));
            
            if (!empty($emissions)) {
                $summary .= "\n### Emissions Statistics\n";
                $summary .= sprintf("- Min: %.2f\n", min($emissions));
                $summary .= sprintf("- Max: %.2f\n", max($emissions));
                $summary .= sprintf("- Mean: %.2f\n", array_sum($emissions) / count($emissions));
            }
            
            if (!empty($buffers)) {
                $summary .= "\n### Buffer Count Statistics\n";
                $summary .= sprintf("- Min: %d\n", min($buffers));
                $summary .= sprintf("- Max: %d\n", max($buffers));
                $summary .= sprintf("- Mean: %.2f\n", array_sum($buffers) / count($buffers));
            }
        }
        
        file_put_contents($this->resultsDir . 'summary_statistics.md', $summary);
        echo "Summary statistics saved to: {$this->resultsDir}summary_statistics.md\n";
    }
    
    /**
     * Generate campaign checklist
     */
    private function generateChecklist(): void {
        $checklist = "# Final Campaign Checklist\n\n";
        $checklist .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        $experiments = $this->campaignConfig['experiments'];
        $checklist .= "## Experiments\n\n";
        
        foreach ($experiments as $name => $config) {
            $enabled = $config['enabled'] ?? false;
            $status = $enabled ? '✅ Completed' : '⏭️ Skipped';
            
            $runCount = count(array_filter($this->allResults, function($r) use ($name) {
                return ($r['config']['EXPERIMENT'] ?? '') === $name;
            }));
            
            $checklist .= "- [{$status}] **{$name}**: {$runCount} runs\n";
        }
        
        $checklist .= "\n## Data Quality\n\n";
        
        // Check for missing KPIs
        $missingEmissions = count(array_filter($this->allResults, function($r) {
            return $r['kpis']['carbon']['total_emissions'] === null;
        }));
        $missingCosts = count(array_filter($this->allResults, function($r) {
            return $r['kpis']['cost']['total_cost_without_tax'] === null;
        }));
        
        $checklist .= "- Missing emissions data: {$missingEmissions} runs\n";
        $checklist .= "- Missing cost data: {$missingCosts} runs\n";
        
        // Check for failures
        $failures = count(array_filter($this->allResults, function($r) {
            $status = $r['kpis']['computational']['solver_status'] ?? '';
            return in_array($status, ['ERROR', 'INFEASIBLE', 'TIMEOUT']);
        }));
        $checklist .= "- Solver failures: {$failures} runs\n";
        
        $checklist .= "\n## Deliverables\n\n";
        $checklist .= "- [✅] Consolidated results CSV\n";
        $checklist .= "- [✅] Summary statistics\n";
        $checklist .= "- [✅] Experiment-specific CSV files\n";
        $checklist .= "- [✅] Run logs\n";
        
        file_put_contents($this->resultsDir . 'campaign_checklist.md', $checklist);
        echo "Campaign checklist saved to: {$this->resultsDir}campaign_checklist.md\n";
    }
    
    /**
     * Save campaign metadata
     */
    private function saveCampaignMetadata(): void {
        $metadata = [
            'campaign_name' => $this->campaignConfig['campaign']['name'],
            'version' => $this->campaignConfig['campaign']['version'],
            'started_at' => date('Y-m-d H:i:s'),
            'results_directory' => $this->resultsDir,
            'oplrun_path' => $this->oplRunPath,
            'time_limit_sec' => $this->timeLimitSec,
            'instance_registry_version' => $this->instanceRegistry['metadata']['version'],
            'php_version' => PHP_VERSION,
            'os' => PHP_OS
        ];
        
        file_put_contents(
            $this->resultsDir . 'campaign_metadata.json',
            json_encode($metadata, JSON_PRETTY_PRINT)
        );
    }
}

// Main execution
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    try {
        $runner = new FinalCampaignRunner();
        $runner->runFullCampaign();
    } catch (Exception $e) {
        echo "FATAL ERROR: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
        exit(1);
    }
}
