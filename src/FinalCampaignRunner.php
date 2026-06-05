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
require_once __DIR__ . '/DecisionStabilityAnalyzer.php';

class FinalCampaignRunner {
    private const ADUP = 20;
    private const NON_BINDING_BOUND_SAFETY = 4.0;
    // Ceiling for instance-scaled "non-binding" bounds. The bound stays instance-scaled
    // (estimate * safety) and this ceiling only clips pathological estimates; it must stay
    // comfortably above the largest quantity any such bound is allowed to deactivate. The
    // emissions bound is reused as the no-cap hybrid emissions ceiling, and the largest
    // baseline emissions reach roughly 25 billion gCO2 (about 25 kt) for the biggest BOM.
    // A 100 million ceiling silently turned that "non-binding" emissions cap into a binding
    // one for instances above about 100 t (e.g. bom_50 at 194.6 t), corrupting the no-cap
    // hybrid optimum. Keep it far above the achievable range yet well below the extreme
    // fixed magnitudes (orders of ten to the thirtieth) that previously triggered CPLEX
    // presolve numerical issues.
    private const NUMERICALLY_SAFE_BOUND_MAX = 1000000000000.0;
    
    private $config;
    private $instanceRegistry;
    private $campaignConfig;
    private $kpiCalculator;
    private $resultsDir;
    private $figuresDir;
    private $tablesDir;
    private $allResults = [];
    private $baselineEmissions = [];
    private $decisionStabilityRows = [];
    private $executedRunIds = [];
    private $nonBindingBoundsCache = [];
    private $runCounter = 0;
    
    // Solver settings
    private $timeLimitSec = 1800;
    private $dataDir;
    private $modelDir;
    private $logsDir;
    private $oplRunPath;

    public static function runDeploymentPreflight(): void {
        $preflight = __DIR__ . '/../tests/DeploymentPreflightTest.php';
        if (!is_file($preflight)) {
            throw new RuntimeException("Deployment preflight test is missing: {$preflight}");
        }

        $php = PHP_BINARY ?: 'php';
        $command = escapeshellarg($php) . ' ' . escapeshellarg($preflight) . ' 2>&1';
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Deployment preflight failed. Re-run php tests/DeploymentPreflightTest.php for details.\n"
                . implode("\n", $output)
            );
        }

        echo implode("\n", $output) . "\n";
    }
    
    public function __construct(bool $createOutputDirs = true) {
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
        
        // Initialize KPI calculator with the reporting threshold, expressed in percentage points.
        $comparisonGapThresholdPct =
            $this->campaignConfig['analysis_settings']['comparison_gap_threshold_pct'] ?? 1.0;
        $this->kpiCalculator = new KPICalculator((float)$comparisonGapThresholdPct);
        
        // Create results directories
        $timestamp = date('Ymd_His');
        $this->resultsDir = $this->logsDir . 'final_campaign_' . $timestamp . DIRECTORY_SEPARATOR;
        $this->figuresDir = $this->resultsDir . 'figures' . DIRECTORY_SEPARATOR;
        $this->tablesDir = $this->resultsDir . 'tables' . DIRECTORY_SEPARATOR;
        
        if ($createOutputDirs) {
            foreach ([$this->resultsDir, $this->figuresDir, $this->tablesDir] as $dir) {
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException("Unable to create output directory: {$dir}");
                }
            }
        }
        
        // Extract time limit from config
        $this->timeLimitSec = $this->campaignConfig['solver_settings']['time_limit_sec'] ?? 1800;
    }

    public function printDryRunSummary(): void {
        $summary = $this->buildDryRunSummary();
        echo "========================================\n";
        echo "FINAL CAMPAIGN DRY RUN\n";
        echo "No solver runs will be executed.\n";
        echo "========================================\n\n";
        echo "Campaign: " . ($this->campaignConfig['campaign']['name'] ?? 'unknown') . "\n";
        echo "Solver time limit: {$this->timeLimitSec}s per run\n";
        $logsDisplay = realpath($this->logsDir) ?: $this->logsDir;
        echo "Planned results directory pattern: "
            . rtrim($logsDisplay, "\\/")
            . DIRECTORY_SEPARATOR
            . "final_campaign_YYYYMMDD_HHMMSS\n\n";
        echo $this->formatCampaignPlanMarkdown($summary, false);

        echo "\nDry run complete.\n";
    }

    private function buildDryRunSummary(): array {
        $experiments = $this->campaignConfig['experiments'];
        $rows = [];
        $warnings = [];
        $totals = [
            'reported_rows' => 0,
            'runner_solver_calls' => 0,
            'multi_objective_solver_calls' => 0,
            'decision_probe_solver_calls_max' => 0,
            'solver_calls_max' => 0
        ];

        $add = function(
            string $name,
            bool $enabled,
            int $reportedRows,
            int $solverCalls,
            string $note = '',
            bool $inConsolidated = true,
            bool $countedByRunner = true
        ) use (&$rows, &$totals): void {
            $rows[] = [
                'name' => $name,
                'enabled' => $enabled,
                'reported_rows' => $enabled ? $reportedRows : 0,
                'solver_calls' => $enabled ? $solverCalls : 0,
                'note' => $note
            ];
            if (!$enabled) {
                return;
            }
            if ($inConsolidated) {
                $totals['reported_rows'] += $reportedRows;
            }
            if ($countedByRunner) {
                $totals['runner_solver_calls'] += $solverCalls;
            }
        };

        $scalability = $experiments['scalability'] ?? [];
        $scalabilityCount = count($scalability['instances'] ?? []);
        $add(
            'scalability',
            $scalability['enabled'] ?? false,
            $scalabilityCount,
            $scalabilityCount,
            'native staticLex cost/emissions baseline'
        );

        $topology = $experiments['topology_baseline'] ?? [];
        $topologyCount = $this->countFamilyInstances($topology['instance_families'] ?? [], $warnings);
        $add(
            'topology_baseline',
            $topology['enabled'] ?? false,
            $topologyCount,
            $topologyCount,
            'native staticLex cost/emissions baseline'
        );

        $tax = $experiments['carbon_tax_sweep'] ?? [];
        $taxCount = count($tax['representative_instances'] ?? []) * count($tax['tax_rates'] ?? []);
        $add('carbon_tax_sweep', $tax['enabled'] ?? false, $taxCount, $taxCount);

        $cap = $experiments['carbon_cap_sweep'] ?? [];
        $capCount = count($cap['representative_instances'] ?? []) * count($cap['cap_percentages'] ?? []);
        $add('carbon_cap_sweep', $cap['enabled'] ?? false, $capCount, $capCount);

        $hybrid = $experiments['carbon_hybrid'] ?? [];
        $hybridCount = count($hybrid['representative_instances'] ?? [])
            * count($hybrid['tax_rates'] ?? [])
            * count($hybrid['cap_levels'] ?? []);
        $add('carbon_hybrid', $hybrid['enabled'] ?? false, $hybridCount, $hybridCount);

        $service = $experiments['service_time_sensitivity'] ?? [];
        $serviceCount = count($service['representative_instances'] ?? [])
            * count($service['strategies'] ?? [])
            * count($service['service_times'] ?? []);
        $add('service_time_sensitivity', $service['enabled'] ?? false, $serviceCount, $serviceCount);

        $multiObj = $experiments['multi_objective'] ?? [];
        $multiObjInstances = count($multiObj['representative_instances'] ?? []);
        $multiObjPoints = (int)($multiObj['num_pareto_points'] ?? 0);
        $multiObjSolverCalls = $multiObjInstances * (4 + 2 * $multiObjPoints);
        $add(
            'multi_objective',
            $multiObj['enabled'] ?? false,
            $multiObjInstances * 2 * $multiObjPoints,
            $multiObjSolverCalls,
            'Pareto CSV points; WIP front skipped',
            false,
            false
        );
        if ($multiObj['enabled'] ?? false) {
            $totals['multi_objective_solver_calls'] = $multiObjSolverCalls;
        }

        $nlm = $experiments['nlm_comparison'] ?? [];
        $nlmCount = count($nlm['representative_instances'] ?? [])
            * count($nlm['strategies'] ?? [])
            * 2;
        $add('nlm_comparison', $nlm['enabled'] ?? false, $nlmCount, $nlmCount);

        $stability = $experiments['decision_stability'] ?? [];
        $stabilityAnchors = $this->countDecisionStabilityAnchorCandidates($stability);
        $stabilityProbeCalls = $stabilityAnchors * count($stability['probes'] ?? []);
        $add(
            'decision_stability',
            $stability['enabled'] ?? false,
            $stabilityProbeCalls,
            $stabilityProbeCalls,
            'maximum; only proven-optimal anchors are probed',
            false,
            true
        );
        if ($stability['enabled'] ?? false) {
            $totals['decision_probe_solver_calls_max'] = $stabilityProbeCalls;
        }

        $baselineCoverage = $this->plannedBaselineCoverage($experiments);
        $baselineRequired = $this->plannedBaselineRequirements($experiments);
        $missingBaselines = array_values(array_diff($baselineRequired, $baselineCoverage));
        if (!empty($missingBaselines)) {
            $warnings[] = 'Some cap-based scenarios require baselines that are not produced earlier in the configured campaign.';
        }

        $totals['solver_calls_max'] =
            $totals['runner_solver_calls'] + $totals['multi_objective_solver_calls'];

        return [
            'experiments' => $rows,
            'totals' => $totals,
            'baseline' => [
                'available' => $baselineCoverage,
                'required' => $baselineRequired,
                'missing' => $missingBaselines
            ],
            'warnings' => $warnings
        ];
    }

    private function countFamilyInstances(array $families, array &$warnings): int {
        $count = 0;
        foreach ($families as $family) {
            if (!isset($this->instanceRegistry['bom_families'][$family]['instances'])) {
                $warnings[] = "Unknown topology family in dry run: {$family}";
                continue;
            }
            $count += count($this->instanceRegistry['bom_families'][$family]['instances']);
        }
        return $count;
    }

    private function plannedBaselineCoverage(array $experiments): array {
        $coverage = [];

        $scalability = $experiments['scalability'] ?? [];
        if ($scalability['enabled'] ?? false) {
            foreach ($scalability['instances'] ?? [] as $size) {
                $coverage[] = "bom_{$size}";
            }
        }

        $topology = $experiments['topology_baseline'] ?? [];
        if ($topology['enabled'] ?? false) {
            foreach ($topology['instance_families'] ?? [] as $family) {
                foreach ($this->instanceRegistry['bom_families'][$family]['instances'] ?? [] as $instance) {
                    $coverage[] = $instance['id'];
                }
            }
        }

        return $this->uniqueSorted($coverage);
    }

    private function plannedBaselineRequirements(array $experiments): array {
        $required = [];

        $cap = $experiments['carbon_cap_sweep'] ?? [];
        if ($cap['enabled'] ?? false) {
            $required = array_merge($required, $cap['representative_instances'] ?? []);
        }

        $hybrid = $experiments['carbon_hybrid'] ?? [];
        $hasHybridCap = !empty(array_filter(
            $hybrid['cap_levels'] ?? [],
            function($capLevel): bool {
                return $capLevel !== 'none';
            }
        ));
        if (($hybrid['enabled'] ?? false) && $hasHybridCap) {
            $required = array_merge($required, $hybrid['representative_instances'] ?? []);
        }

        $service = $experiments['service_time_sensitivity'] ?? [];
        if (($service['enabled'] ?? false)
            && in_array('EMISCAP', $service['strategies'] ?? [], true)) {
            $required = array_merge($required, $service['representative_instances'] ?? []);
        }

        $nlm = $experiments['nlm_comparison'] ?? [];
        if (($nlm['enabled'] ?? false)
            && in_array('EMISCAP', $nlm['strategies'] ?? [], true)) {
            $required = array_merge($required, $nlm['representative_instances'] ?? []);
        }

        return $this->uniqueSorted($required);
    }

    private function countDecisionStabilityAnchorCandidates(array $stabilityConfig): int {
        if (!($stabilityConfig['enabled'] ?? false)) {
            return 0;
        }

        $instances = count($stabilityConfig['representative_instances'] ?? []);
        $taxAnchors = $instances * count($stabilityConfig['tax_rates'] ?? []);
        $capAnchors = $instances * count($stabilityConfig['cap_percentages'] ?? []);
        $hybridAnchors = $instances * count($stabilityConfig['hybrid_combinations'] ?? []);

        return $taxAnchors + $capAnchors + $hybridAnchors;
    }

    private function uniqueSorted(array $values): array {
        $values = array_values(array_unique($values));
        sort($values, SORT_NATURAL);
        return $values;
    }

    private function getNonBindingBounds(string $bomFile, string $suppDetailsFile, int $supplierCount): array {
        $cacheKey = implode('|', [$bomFile, $suppDetailsFile, $supplierCount]);
        if (isset($this->nonBindingBoundsCache[$cacheKey])) {
            return $this->nonBindingBoundsCache[$cacheKey];
        }

        $nodes = $this->readSemicolonCsv($this->dataDir . $bomFile);
        $suppliers = array_slice(
            $this->readSemicolonCsv($this->dataDir . $suppDetailsFile),
            0,
            $supplierCount
        );

        if (empty($nodes) || empty($suppliers)) {
            throw new RuntimeException(
                "Cannot estimate non-binding bounds for {$bomFile} / {$suppDetailsFile}"
            );
        }

        $sumProcess = 0.0;
        $totalRqtf = 0.0;
        $costAccumulator = 0.0;
        $wipAccumulator = 0.0;
        $emissionAccumulator = 0.0;
        $rawCostAccumulator = 0.0;

        foreach ($nodes as $node) {
            $sumProcess += $this->csvFloat($node['t_process'] ?? 0);
            $totalRqtf += $this->csvFloat($node['rqtf'] ?? 0);
        }

        $maxDelay = $this->maxCsvColumn($suppliers, 'delay');
        $maxSupplierPrice = $this->maxCsvColumn($suppliers, 'price');
        $maxSupplierEmissions = $this->maxCsvColumn($suppliers, 'emissions');
        $bigM = max(1.0, $sumProcess + $maxDelay);
        $supplierMultiplier = 1.0 + max(1, $supplierCount) * max(1.0, $maxSupplierPrice);

        foreach ($nodes as $node) {
            $unitPrice = $this->csvFloat($node['unit_price'] ?? 0);
            $rqtf = $this->csvFloat($node['rqtf'] ?? 0);
            $aihCost = $this->csvFloat($node['aih_cost'] ?? 0);
            $varFactor = $this->csvFloat($node['var_factor'] ?? 0);
            $ltFactor = $this->csvFloat($node['lt_factor'] ?? 0);
            $facilityEmis = max(0.0, $this->csvFloat($node['facility_emis'] ?? 0));
            $inventoryEmis = max(0.0, $this->csvFloat($node['inventory_emis'] ?? 0));
            $transportEmis = max(0.0, $this->csvFloat($node['trsp_emis'] ?? 0));
            $activityFactor = max(0.0, 1.5 + $varFactor) * max(0.0, $ltFactor)
                * max(0.0, $rqtf) * self::ADUP;

            $rawCostAccumulator += $unitPrice * $rqtf * self::ADUP * max(1.0, $maxSupplierPrice);
            $wipAccumulator += $unitPrice * $rqtf * self::ADUP * $bigM * $supplierMultiplier;
            $costAccumulator += $aihCost * max(0.0, 1.5 + $varFactor) * max(0.0, $ltFactor)
                * $unitPrice * $rqtf * self::ADUP * $bigM * $supplierMultiplier;
            $emissionAccumulator += (
                $facilityEmis
                + ($inventoryEmis + $transportEmis) * $bigM
                + $transportEmis * $bigM
            ) * $activityFactor;
        }

        $supplierEmissionUpper = self::ADUP * $totalRqtf * max(1.0, $maxSupplierEmissions);
        $bounds = [
            'cost' => $this->conditionedNonBindingBound($rawCostAccumulator + $costAccumulator),
            'dio' => $this->conditionedNonBindingBound(count($nodes) * $bigM),
            'wip' => $this->conditionedNonBindingBound($wipAccumulator),
            'emissions' => $this->conditionedNonBindingBound($supplierEmissionUpper + $emissionAccumulator),
        ];

        $this->nonBindingBoundsCache[$cacheKey] = $bounds;
        return $bounds;
    }

    private function conditionedNonBindingBound(float $estimate): float {
        $scaled = max(1.0, $estimate) * self::NON_BINDING_BOUND_SAFETY;
        return min(self::NUMERICALLY_SAFE_BOUND_MAX, max(1000.0, ceil($scaled)));
    }

    private function readSemicolonCsv(string $path): array {
        if (!is_file($path)) {
            throw new RuntimeException("CSV file not found: {$path}");
        }

        $fp = fopen($path, 'r');
        if ($fp === false) {
            throw new RuntimeException("Cannot open CSV file: {$path}");
        }

        $header = fgetcsv($fp, 0, ';');
        if ($header === false) {
            fclose($fp);
            return [];
        }
        $header = array_map(function($name) {
            return trim((string)$name);
        }, $header);

        $rows = [];
        while (($row = fgetcsv($fp, 0, ';')) !== false) {
            if (count($row) === 1 && trim((string)$row[0]) === '') {
                continue;
            }
            $assoc = [];
            foreach ($header as $idx => $name) {
                if ($name === '') {
                    continue;
                }
                $assoc[$name] = $row[$idx] ?? null;
            }
            $rows[] = $assoc;
        }
        fclose($fp);

        return $rows;
    }

    private function maxCsvColumn(array $rows, string $column): float {
        $max = 0.0;
        foreach ($rows as $row) {
            $max = max($max, $this->csvFloat($row[$column] ?? 0));
        }
        return $max;
    }

    private function csvFloat($value): float {
        if ($value === null) {
            return 0.0;
        }
        return (float)str_replace(',', '.', trim((string)$value));
    }

    private function saveCampaignPlan(): void {
        $summary = $this->buildDryRunSummary();
        $summary['generated_at'] = date('Y-m-d H:i:s');
        $summary['campaign'] = $this->campaignConfig['campaign'] ?? [];
        $summary['solver_settings'] = $this->campaignConfig['solver_settings'] ?? [];
        $manifest = $this->buildRunManifest();
        $summary['manifest_counts'] = [
            'consolidated_runs' => count($manifest['consolidated_runs']),
            'internal_solver_runs' => count($manifest['internal_solver_runs']),
            'multi_objective_solver_runs' => count($manifest['multi_objective_solver_runs']),
            'pareto_files' => count($manifest['pareto_files']),
            'conditional_decision_stability_runs_max' =>
                count($manifest['conditional_decision_stability_runs'])
        ];

        file_put_contents(
            $this->resultsDir . 'campaign_plan.json',
            json_encode($summary, JSON_PRETTY_PRINT)
        );
        file_put_contents(
            $this->resultsDir . 'campaign_plan.md',
            $this->formatCampaignPlanMarkdown($summary, true)
        );
        file_put_contents(
            $this->resultsDir . 'run_manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );

        echo "Campaign plan saved to: {$this->resultsDir}campaign_plan.md\n";
    }

    private function formatCampaignPlanMarkdown(array $summary, bool $includeHeader): string {
        $out = '';
        if ($includeHeader) {
            $campaignName = $this->campaignConfig['campaign']['name'] ?? 'Final campaign';
            $out .= "# {$campaignName} Plan\n\n";
            $out .= "- Generated at: " . ($summary['generated_at'] ?? date('Y-m-d H:i:s')) . "\n";
            $out .= "- Solver time limit: {$this->timeLimitSec}s per run\n";
            $out .= "- This plan is generated before solver execution.\n\n";
        }

        $out .= "## Planned Counts\n\n";
        foreach ($summary['experiments'] as $row) {
            $enabled = $row['enabled'] ? 'enabled' : 'disabled';
            $out .= sprintf(
                "- %s (%s): %s reported rows, %s solver calls",
                $row['name'],
                $enabled,
                $row['reported_rows'],
                $row['solver_calls']
            );
            if ($row['note'] !== '') {
                $out .= " ({$row['note']})";
            }
            $out .= "\n";
        }

        $out .= "\n## Totals\n\n";
        $out .= "- Consolidated reported rows: {$summary['totals']['reported_rows']}\n";
        $out .= "- Solver calls counted by campaign runner: {$summary['totals']['runner_solver_calls']}\n";
        $out .= "- Additional multi-objective solver calls: {$summary['totals']['multi_objective_solver_calls']}\n";
        $out .= "- Maximum conditional decision-degeneracy probe calls: {$summary['totals']['decision_probe_solver_calls_max']}\n";
        $out .= "- Maximum total solver calls: {$summary['totals']['solver_calls_max']}\n";

        $out .= "\n## Baseline Coverage\n\n";
        $out .= "- Baseline-producing instances planned: "
            . implode(', ', $summary['baseline']['available']) . "\n";
        if (empty($summary['baseline']['missing'])) {
            $out .= "- Baseline prerequisites: satisfied by planned scalability/topology phases\n";
        } else {
            $out .= "- Missing baseline prerequisites: "
                . implode(', ', $summary['baseline']['missing']) . "\n";
        }

        if (!empty($summary['warnings'])) {
            $out .= "\n## Warnings\n\n";
            foreach ($summary['warnings'] as $warning) {
                $out .= "- {$warning}\n";
            }
        }

        return $out;
    }

    private function buildRunManifest(): array {
        $experiments = $this->campaignConfig['experiments'];
        $manifest = [
            'generated_at' => date('Y-m-d H:i:s'),
            'consolidated_runs' => [],
            'internal_solver_runs' => [],
            'multi_objective_solver_runs' => [],
            'pareto_files' => [],
            'conditional_decision_stability_runs' => []
        ];

        $addRun = function(string $bucket, string $runId, string $experiment, string $instanceId, array $attrs = []) use (&$manifest): void {
            $manifest[$bucket][] = array_merge([
                'run_id' => $runId,
                'experiment' => $experiment,
                'instance_id' => $instanceId
            ], $attrs);
        };

        $scalability = $experiments['scalability'] ?? [];
        if ($scalability['enabled'] ?? false) {
            foreach ($scalability['instances'] ?? [] as $size) {
                $instanceId = "bom_{$size}";
                $prefix = sprintf("SCAL-%03d", $size);
                $addRun('consolidated_runs', $prefix, 'scalability', $instanceId);
            }
        }

        $topology = $experiments['topology_baseline'] ?? [];
        if ($topology['enabled'] ?? false) {
            foreach ($topology['instance_families'] ?? [] as $family) {
                foreach ($this->instanceRegistry['bom_families'][$family]['instances'] ?? [] as $instance) {
                    $instanceId = $instance['id'];
                    $prefix = "TOPO-{$family}-{$instanceId}";
                    $addRun('consolidated_runs', $prefix, 'topology_baseline', $instanceId, ['family' => $family]);
                }
            }
        }

        $tax = $experiments['carbon_tax_sweep'] ?? [];
        if ($tax['enabled'] ?? false) {
            foreach ($tax['representative_instances'] ?? [] as $instanceId) {
                foreach ($tax['tax_rates'] ?? [] as $taxRate) {
                    $addRun(
                        'consolidated_runs',
                        sprintf("TAX-%s-%.2f", $instanceId, $taxRate),
                        'carbon_tax_sweep',
                        $instanceId,
                        ['tax_rate' => $taxRate, 'cap_level' => 'none']
                    );
                }
            }
        }

        $cap = $experiments['carbon_cap_sweep'] ?? [];
        if ($cap['enabled'] ?? false) {
            foreach ($cap['representative_instances'] ?? [] as $instanceId) {
                foreach ($cap['cap_percentages'] ?? [] as $capPct) {
                    $addRun(
                        'consolidated_runs',
                        sprintf("CAP-%s-%.0f", $instanceId, $capPct * 100),
                        'carbon_cap_sweep',
                        $instanceId,
                        ['cap_percentage' => $capPct]
                    );
                }
            }
        }

        $hybrid = $experiments['carbon_hybrid'] ?? [];
        if ($hybrid['enabled'] ?? false) {
            foreach ($hybrid['representative_instances'] ?? [] as $instanceId) {
                foreach ($hybrid['tax_rates'] ?? [] as $taxRate) {
                    foreach ($hybrid['cap_levels'] ?? [] as $capLevel) {
                        $capLabel = $capLevel === 'none'
                            ? 'none'
                            : sprintf("%g", ((float)$capLevel) * 100);
                        $label = sprintf("tax_%g_cap_%s", $taxRate, $capLabel);
                        $addRun(
                            'consolidated_runs',
                            "HYB-{$instanceId}-{$label}",
                            'carbon_hybrid',
                            $instanceId,
                            ['tax_rate' => $taxRate, 'cap_level' => $capLevel]
                        );
                    }
                }
            }
        }

        $service = $experiments['service_time_sensitivity'] ?? [];
        if ($service['enabled'] ?? false) {
            foreach ($service['representative_instances'] ?? [] as $instanceId) {
                foreach ($service['strategies'] ?? [] as $strategy) {
                    foreach ($service['service_times'] ?? [] as $svt) {
                        $addRun(
                            'consolidated_runs',
                            "SVT-{$instanceId}-{$strategy}-SvT{$svt}",
                            'service_time_sensitivity',
                            $instanceId,
                            ['strategy' => $strategy, 'service_time' => $svt]
                        );
                    }
                }
            }
        }

        $multiObj = $experiments['multi_objective'] ?? [];
        if ($multiObj['enabled'] ?? false) {
            $numPoints = (int)($multiObj['num_pareto_points'] ?? 0);
            foreach ($multiObj['representative_instances'] ?? [] as $instanceId) {
                $prefix = "MOBJ-{$instanceId}";
                for ($obj = 1; $obj <= 4; $obj++) {
                    $addRun(
                        'multi_objective_solver_runs',
                        "{$prefix}_IDEAL_OBJ{$obj}",
                        'multi_objective_ideal_nadir',
                        $instanceId,
                        ['objective' => $obj]
                    );
                }
                foreach (['DIO' => 'CDIO', 'EMIS' => 'CEMIS'] as $key => $suffix) {
                    for ($i = 0; $i < $numPoints; $i++) {
                        $addRun(
                            'multi_objective_solver_runs',
                            "{$prefix}_{$suffix}_{$i}",
                            "multi_objective_cost_{$key}",
                            $instanceId,
                            ['point' => $i]
                        );
                    }
                }
                $manifest['pareto_files'][] = "pareto/{$instanceId}_cost_emissions_pareto.csv";
                $manifest['pareto_files'][] = "pareto/{$instanceId}_cost_dio_pareto.csv";
                $manifest['pareto_files'][] = "pareto/{$instanceId}_ideal_nadir.json";
            }
        }

        $nlm = $experiments['nlm_comparison'] ?? [];
        if ($nlm['enabled'] ?? false) {
            foreach ($nlm['representative_instances'] ?? [] as $instanceId) {
                foreach ($nlm['strategies'] ?? [] as $strategy) {
                    foreach (['PLM', 'NLM'] as $modelType) {
                        $addRun(
                            'consolidated_runs',
                            "COMP-{$instanceId}-{$strategy}-{$modelType}",
                            'nlm_comparison',
                            $instanceId,
                            ['strategy' => $strategy, 'model_type' => $modelType]
                        );
                    }
                }
            }
        }

        $stability = $experiments['decision_stability'] ?? [];
        if ($stability['enabled'] ?? false) {
            foreach ($this->decisionStabilityAnchorCandidates($stability) as $anchor) {
                foreach ($stability['probes'] ?? [] as $probe) {
                    $addRun(
                        'conditional_decision_stability_runs',
                        $anchor['run_id'] . '-STAB-' . strtoupper($probe),
                        'decision_stability',
                        $anchor['instance_id'],
                        [
                            'anchor_run_id' => $anchor['run_id'],
                            'source_experiment' => $anchor['experiment'],
                            'probe' => $probe
                        ]
                    );
                }
            }
        }

        foreach (['consolidated_runs', 'internal_solver_runs', 'multi_objective_solver_runs', 'conditional_decision_stability_runs'] as $bucket) {
            usort($manifest[$bucket], function($a, $b): int {
                return strcmp($a['run_id'], $b['run_id']);
            });
        }
        sort($manifest['pareto_files'], SORT_NATURAL);

        return $manifest;
    }

    private function decisionStabilityAnchorCandidates(array $stabilityConfig): array {
        $anchors = [];
        $instances = $stabilityConfig['representative_instances'] ?? [];

        foreach ($instances as $instanceId) {
            foreach ($stabilityConfig['tax_rates'] ?? [] as $taxRate) {
                $anchors[] = [
                    'run_id' => sprintf("TAX-%s-%.2f", $instanceId, $taxRate),
                    'experiment' => 'carbon_tax_sweep',
                    'instance_id' => $instanceId
                ];
            }
            foreach ($stabilityConfig['cap_percentages'] ?? [] as $capPct) {
                $anchors[] = [
                    'run_id' => sprintf("CAP-%s-%.0f", $instanceId, $capPct * 100),
                    'experiment' => 'carbon_cap_sweep',
                    'instance_id' => $instanceId
                ];
            }
            foreach ($stabilityConfig['hybrid_combinations'] ?? [] as $combination) {
                $taxRate = $combination['tax'];
                $capLevel = $combination['cap_level'];
                $capLabel = $capLevel === 'none'
                    ? 'none'
                    : sprintf("%g", ((float)$capLevel) * 100);
                $label = sprintf("tax_%g_cap_%s", $taxRate, $capLabel);
                $anchors[] = [
                    'run_id' => "HYB-{$instanceId}-{$label}",
                    'experiment' => 'carbon_hybrid',
                    'instance_id' => $instanceId
                ];
            }
        }

        return $anchors;
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
        $this->saveCampaignPlan();
        
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

        // 9. Probe decision stability within 1% of proven-optimal policy solutions
        if ($experiments['decision_stability']['enabled'] ?? false) {
            echo "\n=== PHASE 9: NEAR-OPTIMAL DECISION STABILITY ===\n";
            $this->runDecisionStability($experiments['decision_stability']);
        }
        
        // Generate consolidated outputs
        echo "\n=== GENERATING OUTPUTS ===\n";
        $this->generateConsolidatedCSV();
        $this->generateSummaryStatistics();
        $this->generateChecklist();
        $this->validatePostRunAgainstPlan();
        
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
            
            $result = $this->executeLexicographicBaseline($runConfig, $instanceId);
            
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
                
                $result = $this->executeLexicographicBaseline($runConfig, $instanceId);
                
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
                    'TAX_RATE' => $tax,
                    'CAP_LEVEL' => 'none'
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
     * Exploratory diagnostic: identify the EmisTax level at which the price-only
     * operating point first changes relative to the no-price tax-policy solution.
     */
    public function runCarbonPriceThresholdDiagnostic(): void {
        $taxConfig = $this->campaignConfig['experiments']['carbon_tax_sweep'] ?? [];
        $expConfig = $this->campaignConfig['experiments']['carbon_price_switching_threshold'] ?? [];
        if (!($expConfig['enabled'] ?? false)) {
            echo "Carbon-price switching-threshold diagnostic is disabled.\n";
            return;
        }

        $instances = $expConfig['representative_instances']
            ?? $taxConfig['representative_instances']
            ?? [];
        if (empty($instances)) {
            throw new RuntimeException('No representative instances configured for price-threshold diagnostic');
        }

        echo "=== CARBON-PRICE SWITCHING-THRESHOLD DIAGNOSTIC ===\n";
        echo "Instances: " . implode(', ', $instances) . "\n";
        echo "Observed policy max: " . ($expConfig['observed_policy_max'] ?? 100.0) . " EUR/tCO2\n";

        $rows = [];
        foreach ($instances as $instanceId) {
            $row = $this->findCarbonPriceSwitchingThreshold($instanceId, $expConfig);
            $rows[] = $row;
            $threshold = $row['switched_within_max']
                ? sprintf(
                    '[%s, %s] EUR/tCO2',
                    $this->formatThresholdRate($row['threshold_lower_eur_per_tco2']),
                    $this->formatThresholdRate($row['threshold_upper_eur_per_tco2'])
                )
                : '>' . $this->formatThresholdRate($row['max_probe_rate']) . ' EUR/tCO2';
            echo "  {$instanceId}: {$threshold}; components={$row['changed_components']}\n";
        }

        $this->writeCarbonPriceThresholdResults($rows);
        echo "Carbon-price threshold diagnostic saved to: {$this->resultsDir}\n";
    }

    private function findCarbonPriceSwitchingThreshold(string $instanceId, array $expConfig): array {
        $observedMax = (float)($expConfig['observed_policy_max'] ?? 100.0);
        $initialProbe = (float)($expConfig['initial_probe_rate'] ?? 250.0);
        $maxProbe = (float)($expConfig['max_probe_rate'] ?? 1000000.0);
        $growthFactor = (float)($expConfig['growth_factor'] ?? 2.0);
        $iterations = (int)($expConfig['bisection_iterations'] ?? 8);

        if ($growthFactor <= 1.0) {
            throw new RuntimeException('Price-threshold growth_factor must be greater than 1');
        }
        if ($observedMax < 0.0 || $initialProbe <= 0.0 || $maxProbe <= 0.0) {
            throw new RuntimeException('Price-threshold rates must be non-negative and bounded');
        }

        $baseline = $this->executeTaxThresholdRun($instanceId, 0.0, 'BASE', $expConfig);
        $this->requireOptimalThresholdRun($baseline, $instanceId, 0.0);
        $reference = $this->operatingPointSignature($baseline);

        $lowRate = 0.0;
        $highRate = null;
        $highResult = null;
        $changedComponents = [];

        if ($observedMax > 0.0) {
            $observed = $this->executeTaxThresholdRun($instanceId, $observedMax, 'OBSERVEDMAX', $expConfig);
            $this->requireOptimalThresholdRun($observed, $instanceId, $observedMax);
            $observedChanges = $this->changedOperatingPointComponents(
                $reference,
                $this->operatingPointSignature($observed)
            );
            if (!empty($observedChanges)) {
                $highRate = $observedMax;
                $highResult = $observed;
                $changedComponents = $observedChanges;
            } else {
                $lowRate = $observedMax;
            }
        }

        $probeRate = max($initialProbe, $observedMax * $growthFactor);
        while ($highRate === null && $probeRate <= $maxProbe + 1.0e-9) {
            $probe = $this->executeTaxThresholdRun($instanceId, $probeRate, 'PROBE', $expConfig);
            $this->requireOptimalThresholdRun($probe, $instanceId, $probeRate);
            $probeChanges = $this->changedOperatingPointComponents(
                $reference,
                $this->operatingPointSignature($probe)
            );
            if (!empty($probeChanges)) {
                $highRate = $probeRate;
                $highResult = $probe;
                $changedComponents = $probeChanges;
                break;
            }
            $lowRate = $probeRate;
            $probeRate *= $growthFactor;
        }

        if ($highRate !== null) {
            for ($i = 0; $i < $iterations; $i++) {
                $midRate = ($lowRate + $highRate) / 2.0;
                if ($midRate <= $lowRate + 1.0e-9 || $midRate >= $highRate - 1.0e-9) {
                    break;
                }
                $mid = $this->executeTaxThresholdRun($instanceId, $midRate, 'BISECT', $expConfig);
                $this->requireOptimalThresholdRun($mid, $instanceId, $midRate);
                $midChanges = $this->changedOperatingPointComponents(
                    $reference,
                    $this->operatingPointSignature($mid)
                );
                if (!empty($midChanges)) {
                    $highRate = $midRate;
                    $highResult = $mid;
                    $changedComponents = $midChanges;
                } else {
                    $lowRate = $midRate;
                }
            }
        }

        $baselineSignature = $reference;
        $switchSignature = $highResult !== null
            ? $this->operatingPointSignature($highResult)
            : null;

        return [
            'instance_id' => $instanceId,
            'observed_policy_max' => $observedMax,
            'max_probe_rate' => $maxProbe,
            'switched_within_max' => $highRate !== null ? 1 : 0,
            'threshold_lower_eur_per_tco2' => $lowRate,
            'threshold_upper_eur_per_tco2' => $highRate,
            'changed_components' => !empty($changedComponents) ? implode('|', $changedComponents) : 'none',
            'baseline_cost_without_tax' => $baselineSignature['cost_without_tax'],
            'baseline_emissions_gco2' => $baselineSignature['emissions_gco2'],
            'switch_cost_without_tax' => $switchSignature['cost_without_tax'] ?? null,
            'switch_emissions_gco2' => $switchSignature['emissions_gco2'] ?? null,
            'delta_cost_without_tax' => $switchSignature !== null
                ? $switchSignature['cost_without_tax'] - $baselineSignature['cost_without_tax']
                : null,
            'delta_emissions_gco2' => $switchSignature !== null
                ? $switchSignature['emissions_gco2'] - $baselineSignature['emissions_gco2']
                : null,
            'baseline_run_status' => $baseline['kpis']['computational']['solver_status'] ?? 'UNKNOWN',
            'switch_run_status' => $highResult['kpis']['computational']['solver_status'] ?? null,
        ];
    }

    private function executeTaxThresholdRun(
        string $instanceId,
        float $taxRate,
        string $stage,
        array $expConfig
    ): array {
        $runConfig = $this->buildTaxRunConfig(
            $instanceId,
            $taxRate,
            sprintf(
                'THR-%s-%s-%s',
                $instanceId,
                strtoupper($stage),
                $this->slugTaxRate($taxRate)
            ),
            'carbon_price_switching_threshold',
            $expConfig
        );

        return $this->executeSingleRun($runConfig, $instanceId, false);
    }

    private function buildTaxRunConfig(
        string $instanceId,
        float $taxRate,
        string $prefix,
        string $experiment,
        array $expConfig
    ): array {
        $instance = $this->findInstance($instanceId);
        if (!$instance) {
            throw new RuntimeException("Unknown instance for tax run: {$instanceId}");
        }

        $bomFile = $instance['file'];
        $suppListBaseName = preg_replace('/^bom_supemis_/', '', basename($bomFile, '.csv'));
        $suppListFile = "supp_list_{$suppListBaseName}.csv";
        if (!file_exists($this->dataDir . $bomFile) || !file_exists($this->dataDir . $suppListFile)) {
            throw new RuntimeException("Missing BOM or supplier-list file for {$instanceId}");
        }

        $suppDetailsFile = ($instance['nodes'] >= 25)
            ? 'supp_details_supeco_grdCapacity.csv'
            : 'supp_details_supeco.csv';

        return [
            'PREFIXE' => $prefix,
            '_NODE_FILE_' => $bomFile,
            '_NODE_SUPP_FILE_' => $suppListFile,
            '_SUPP_DETAILS_FILE_' => $suppDetailsFile,
            '_NBSUPP_' => $expConfig['suppliers'],
            '_SERVICE_T_' => $expConfig['service_time'],
            '_EMISCAP_' => $this->getNonBindingBounds(
                $bomFile,
                $suppDetailsFile,
                (int)$expConfig['suppliers']
            )['emissions'],
            '_EMISTAXE_' => $taxRate,
            'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Tax.mod',
            'MODEL_TYPE' => $expConfig['model_type'] ?? 'PLM',
            'EXPERIMENT' => $experiment,
            'TAX_RATE' => $taxRate,
            'CAP_LEVEL' => 'none',
        ];
    }

    private function requireOptimalThresholdRun(array $result, string $instanceId, float $taxRate): void {
        $status = $result['kpis']['computational']['solver_status'] ?? 'UNKNOWN';
        if ($status !== 'OPTIMAL') {
            throw new RuntimeException(
                "Price-threshold run for {$instanceId} at EmisTax={$taxRate} did not prove optimal; status={$status}"
            );
        }
    }

    private function operatingPointSignature(array $result): array {
        return [
            'X' => $this->numericVector($result['result']['X'] ?? []),
            'Z' => $this->numericVector($result['result']['Z'] ?? []),
            'Q' => $this->numericVector($result['result']['Q'] ?? []),
            'cost_without_tax' => (float)($result['kpis']['cost']['total_cost_without_tax'] ?? 0.0),
            'emissions_gco2' => (float)($result['kpis']['carbon']['total_emissions'] ?? 0.0),
        ];
    }

    private function changedOperatingPointComponents(array $reference, array $candidate): array {
        $components = [];
        if (!$this->vectorsEqual($reference['X'], $candidate['X'])) {
            $components[] = 'buffers';
        }
        if (!$this->vectorsEqual($reference['Z'], $candidate['Z'])) {
            $components[] = 'suppliers';
        }
        if (!$this->vectorsEqual($reference['Q'], $candidate['Q'])) {
            $components[] = 'allocation';
        }
        if (abs($reference['cost_without_tax'] - $candidate['cost_without_tax']) > 1.0e-3) {
            $components[] = 'tax_free_cost';
        }
        if (abs($reference['emissions_gco2'] - $candidate['emissions_gco2']) > 1.0e-3) {
            $components[] = 'emissions';
        }
        return $components;
    }

    private function numericVector($values): array {
        if (!is_array($values)) {
            return [];
        }
        return array_map(static function($value): float {
            return (float)$value;
        }, array_values($values));
    }

    private function vectorsEqual(array $left, array $right, float $tolerance = 1.0e-6): bool {
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $index => $value) {
            if (abs((float)$value - (float)$right[$index]) > $tolerance) {
                return false;
            }
        }
        return true;
    }

    private function writeCarbonPriceThresholdResults(array $rows): void {
        if (empty($rows)) {
            return;
        }

        $csvFile = $this->tablesDir . 'carbon_price_threshold_results.csv';
        $fp = fopen($csvFile, 'w');
        fputcsv($fp, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($fp, array_values($row));
        }
        fclose($fp);

        $tablesTexDir = $this->resultsDir . 'tables_tex' . DIRECTORY_SEPARATOR;
        if (!is_dir($tablesTexDir) && !@mkdir($tablesTexDir, 0755, true) && !is_dir($tablesTexDir)) {
            throw new RuntimeException("Unable to create threshold table directory: {$tablesTexDir}");
        }
        file_put_contents(
            $tablesTexDir . 'tab_price_threshold.tex',
            $this->renderCarbonPriceThresholdLatexTable($rows)
        );
    }

    private function renderCarbonPriceThresholdLatexTable(array $rows): string {
        $bodyRows = [];
        foreach ($rows as $row) {
            $instance = str_replace('_', '\\_', $row['instance_id']);
            $threshold = $row['switched_within_max']
                ? sprintf(
                    '%s--%s',
                    $this->formatThresholdRate($row['threshold_lower_eur_per_tco2']),
                    $this->formatThresholdRate($row['threshold_upper_eur_per_tco2'])
                )
                : '$>' . $this->formatThresholdRate($row['max_probe_rate']) . '$';
            $deltaCost = $row['delta_cost_without_tax'] !== null
                ? $this->formatThresholdNumber((float)$row['delta_cost_without_tax'], 0)
                : '--';
            $emissionReduction = $row['delta_emissions_gco2'] !== null
                ? $this->formatThresholdNumber(-(float)$row['delta_emissions_gco2'] / 1000000.0, 2)
                : '--';
            $components = str_replace(
                ['tax_free_cost', '|', '_'],
                ['tax-free cost', ', ', '\\_'],
                $row['changed_components']
            );
            $bodyRows[] = "{$instance} & {$threshold} & {$components} & {$deltaCost} & {$emissionReduction} \\\\";
        }

        return "\\begin{table*}[!htbp]\\centering\\small\n"
            . "\\caption{Exploratory carbon-price switching-threshold diagnostic. The interval reports "
            . 'the first $EmisTax$ range, in EUR/tCO$_2$, where the price-only operating point differs '
            . "from the no-price solution; values above observed policy levels are stress-test diagnostics, "
            . "not proposed statutory taxes.}\\label{tab:pricethreshold}\n"
            . "\\begin{tabular}{lcccc}\n\\toprule\n"
            . 'Instance & Switching interval & Changed components & $\Delta$ cost & Emission reduction (t\,CO$_2$)\\\\' . "\n"
            . "\\midrule\n"
            . implode("\n", $bodyRows)
            . "\n\\bottomrule\n\\end{tabular}\n\\end{table*}\n";
    }

    private function slugTaxRate(float $taxRate): string {
        $formatted = abs($taxRate - round($taxRate)) < 1.0e-6
            ? sprintf('%.0f', $taxRate)
            : sprintf('%.2f', $taxRate);
        return str_replace(['.', '-'], ['p', 'm'], $formatted);
    }

    private function formatThresholdRate($value): string {
        if ($value === null || $value === '') {
            return '--';
        }
        $number = (float)$value;
        if (abs($number - round($number)) < 0.05) {
            return number_format($number, 0, '.', '\\,');
        }
        return number_format($number, 1, '.', '\\,');
    }

    private function formatThresholdNumber(float $value, int $decimals): string {
        return number_format($value, $decimals, '.', '\\,');
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
            $baselineEmis = $this->requireBaselineEmissions($instanceId);
            
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
                    '_EMISTAXE_' => 0.0,
                    'MODEL_FILE' => 'RUNS_SupEmis_Cplex_PLM_Cap.mod',
                    'MODEL_TYPE' => 'PLM',
                    'EXPERIMENT' => 'carbon_cap_sweep',
                    'CAP_PERCENTAGE' => $capPct,
                    'CAP_LEVEL' => sprintf('%g%%', $capPct * 100),
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
        $taxRates = $expConfig['tax_rates'];
        $capLevels = $expConfig['cap_levels'];
        $instances = $expConfig['representative_instances'];
        
        echo "Testing " . (count($taxRates) * count($capLevels)) . " full factorial combined scenarios\n";
        echo "Instances: " . implode(', ', $instances) . "\n";
        
        foreach ($instances as $instanceId) {
            $instance = $this->findInstance($instanceId);
            if (!$instance) continue;
            
            $bomFile = $instance['file'];
            $suppListBaseName = preg_replace('/^bom_supemis_/', '', basename($bomFile, '.csv'));
            $suppListFile = "supp_list_{$suppListBaseName}.csv";
            
            if (!file_exists($this->dataDir . $bomFile)) continue;
            
            $baselineEmis = $this->requireBaselineEmissions($instanceId);
            $suppDetailsFile = ($instance['nodes'] >= 25) ? 
                "supp_details_supeco_grdCapacity.csv" : 
                "supp_details_supeco.csv";
            $nonBindingBounds = $this->getNonBindingBounds(
                $bomFile,
                $suppDetailsFile,
                (int)$expConfig['suppliers']
            );
            
            foreach ($taxRates as $tax) {
                foreach ($capLevels as $capLevel) {
                    $hasCap = $capLevel !== 'none';
                    $capPct = $hasCap ? (float)$capLevel : null;
                    $capLabel = $hasCap ? sprintf("%g", $capPct * 100) : 'none';
                    $label = sprintf("tax_%g_cap_%s", $tax, $capLabel);
                    $capValue = $hasCap
                        ? (float)($baselineEmis * $capPct)
                        : $nonBindingBounds['emissions'];
                
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
                        'CAP_LEVEL' => $hasCap ? $capLabel . '%' : 'none',
                        'CAP_VALUE' => $capValue
                    ];
                
                    $result = $this->executeSingleRun($runConfig, $instanceId);
                
                    echo "  {$instanceId}, {$label}: " .
                         "Status={$result['kpis']['computational']['solver_status']}, " .
                         "Cost=" . ($result['kpis']['cost']['total_cost_with_tax'] ?? 'N/A') . "\n";
                }
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
            $baselineEmis = $this->requireBaselineEmissions($instanceId);
            
            foreach ($strategies as $strategy) {
                foreach ($serviceTimes as $svt) {
                    $modelFile = ($strategy === 'EMISCAP') ? 
                        'RUNS_SupEmis_Cplex_PLM_Cap.mod' : 
                        'RUNS_SupEmis_Cplex_PLM_Tax.mod';
                    
                    $capValue = ($strategy === 'EMISCAP') ? 
                        (int)($baselineEmis * $expConfig['cap_percentage']) : 2500000;
                    $taxRate = ($strategy === 'EMISTAXE') ? 
                        $expConfig['tax_rate'] : 0.0;
                    
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
                        'SERVICE_TIME' => $svt,
                        'CAP_LEVEL' => $strategy === 'EMISCAP'
                            ? sprintf('%g%%', $expConfig['cap_percentage'] * 100)
                            : 'none'
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
            $nonBindingBounds = $this->getNonBindingBounds(
                $bomFile,
                $suppDetailsFile,
                (int)$expConfig['suppliers']
            );
            
            $baseRun = [
                'PREFIXE' => "MOBJ-{$instanceId}",
                '_NODE_FILE_' => $bomFile,
                '_NODE_SUPP_FILE_' => $suppListFile,
                '_SUPP_DETAILS_FILE_' => $suppDetailsFile,
                '_NBSUPP_' => $expConfig['suppliers'],
                '_SERVICE_T_' => $expConfig['service_time'],
                // Keep inactive caps/epsilons finite and instance-scaled. Very large sentinels
                // with many-order sentinel values made the epsilon grid numerically non-monotone even with
                // cplex.reduce=0.
                '_EMISCAP_' => $nonBindingBounds['emissions'],
                '_EMISTAXE_' => 0.0,
                '_NONBINDING_COST_' => $nonBindingBounds['cost'],
                '_NONBINDING_DIO_' => $nonBindingBounds['dio'],
                '_NONBINDING_WIP_' => $nonBindingBounds['wip'],
                '_NONBINDING_EMIS_' => $nonBindingBounds['emissions']
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

            // Cost-WIP front is skipped: the WIP epsilon-constraint is non-convex (bilinear z*y)
            // and disabled in the model, so varying epsilon_WIP would yield a degenerate front.
            // WIP is still reported as an output column on the Cost-Emissions/Cost-DIO points.
            $costWIPPareto = [];

            // Save Pareto fronts
            $paretoDir = $this->resultsDir . 'pareto' . DIRECTORY_SEPARATOR;
            if (!is_dir($paretoDir)) mkdir($paretoDir, 0755, true);

            MultiObjectiveRunner::exportParetoToCSV(
                $costEmisPareto, $paretoDir . "{$instanceId}_cost_emissions_pareto.csv", 'Cost-Emissions'
            );
            MultiObjectiveRunner::exportParetoToCSV(
                $costDIOPareto, $paretoDir . "{$instanceId}_cost_dio_pareto.csv", 'Cost-DIO'
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
            $baselineEmis = $this->requireBaselineEmissions($instanceId);
            
            foreach ($strategies as $strategy) {
                foreach (['PLM', 'NLM'] as $modelType) {
                    if ($strategy === 'EMISCAP') {
                        $modelFile = ($modelType === 'PLM') ? 
                            'RUNS_SupEmis_Cplex_PLM_Cap.mod' : 
                            'RUNS_SupEmis_CP_NLM_Cap.mod';
                        $capValue = (int)($baselineEmis * $expConfig['cap_percentage']);
                        $taxRate = 0.0;
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
     * Find extremal alternative decisions within a percentage of each proven optimum.
     */
    private function runDecisionStability(array $expConfig): void {
        $tolerancePct = (float)($expConfig['objective_tolerance_pct'] ?? 1.0);
        $probes = $expConfig['probes'] ?? DecisionStabilityAnalyzer::PROBES;
        $anchors = array_filter($this->allResults, function($result) use ($expConfig) {
            return $this->isDecisionStabilityAnchor($result, $expConfig);
        });

        echo "Proven-optimal anchors selected: " . count($anchors) . "\n";
        echo "Objective tolerance: {$tolerancePct}%\n";

        foreach ($anchors as $anchor) {
            $referenceResult = $anchor['result'];
            $referenceObjective = $anchor['kpis']['cost']['objective_value'] ?? null;
            if ($referenceObjective === null || $referenceObjective <= 0) {
                echo "  Skipping {$anchor['config']['PREFIXE']} - missing positive objective\n";
                continue;
            }

            $objectiveLimit = (float)$referenceObjective * (1.0 + $tolerancePct / 100.0) + 1.0e-6;
            foreach ($probes as $probe) {
                $probeConfig = $anchor['config'];
                $probeConfig['PREFIXE'] = $anchor['config']['PREFIXE'] . '-STAB-' . strtoupper($probe);
                $probeConfig['EXPERIMENT'] = 'decision_stability';
                $probeConfig['SOURCE_EXPERIMENT'] = $anchor['config']['EXPERIMENT'] ?? '';
                $probeConfig['STABILITY_PROBE'] = $probe;
                $probeConfig['STABILITY_REFERENCE_X'] = $referenceResult['X'];
                $probeConfig['STABILITY_REFERENCE_Z'] = $referenceResult['Z'];
                $probeConfig['STABILITY_REFERENCE_Q'] = $referenceResult['Q'];
                $probeConfig['STABILITY_OBJECTIVE_LIMIT'] = $objectiveLimit;

                $alternative = $this->executeSingleRun(
                    $probeConfig,
                    $anchor['instance_id'],
                    false
                );
                $probeStatus = $alternative['kpis']['computational']['solver_status'] ?? 'UNKNOWN';
                $alternativeObjective =
                    $alternative['result']['STABILITY_ORIGINAL_OBJECTIVE'] ?? null;

                $row = [
                    'anchor_run_id' => $anchor['config']['PREFIXE'],
                    'instance_id' => $anchor['instance_id'],
                    'source_experiment' => $anchor['config']['EXPERIMENT'] ?? '',
                    'strategy' => $anchor['kpis']['strategy'] ?? '',
                    'tax_rate' => $anchor['kpis']['tax_rate'] ?? '',
                    'cap_level' => $anchor['kpis']['cap_level'] ?? '',
                    'probe' => $probe,
                    'objective_optimum' => $referenceObjective,
                    'objective_limit' => $objectiveLimit,
                    'alternative_original_objective' => $alternativeObjective,
                    'objective_degradation_pct' => null,
                    'probe_status' => $probeStatus,
                    'probe_mip_gap' => $alternative['kpis']['computational']['mip_gap'] ?? null,
                    'buffer_jaccard_similarity' => null,
                    'buffer_changed_count' => null,
                    'supplier_jaccard_similarity' => null,
                    'supplier_changed_count' => null,
                    'allocation_l1_absolute' => null,
                    'allocation_l1_normalized' => null,
                    'allocation_changed_pairs' => null,
                ];

                if ($alternativeObjective !== null && $referenceObjective > 0) {
                    $row['objective_degradation_pct'] =
                        (((float)$alternativeObjective - (float)$referenceObjective)
                            / (float)$referenceObjective) * 100.0;
                }

                if (isset($alternative['result']['X'], $alternative['result']['Z'], $alternative['result']['Q'])) {
                    $row = array_merge(
                        $row,
                        DecisionStabilityAnalyzer::compare($referenceResult, $alternative['result'])
                    );
                }

                $this->decisionStabilityRows[] = $row;
                echo "  {$row['anchor_run_id']}, {$probe}: status={$probeStatus}, " .
                    "buffer J=" . ($row['buffer_jaccard_similarity'] ?? 'N/A') . ", " .
                    "supplier J=" . ($row['supplier_jaccard_similarity'] ?? 'N/A') . ", " .
                    "allocation L1=" . ($row['allocation_l1_normalized'] ?? 'N/A') . "\n";
            }
        }

        $this->saveDecisionStabilityResults();
    }

    private function isDecisionStabilityAnchor(array $result, array $expConfig): bool {
        if (($result['kpis']['computational']['solver_status'] ?? '') !== 'OPTIMAL') {
            return false;
        }
        if (($result['config']['MODEL_TYPE'] ?? '') !== 'PLM') {
            return false;
        }
        if (!isset($result['result']['X'], $result['result']['Z'], $result['result']['Q'])) {
            return false;
        }
        if (!in_array($result['instance_id'], $expConfig['representative_instances'] ?? [], true)) {
            return false;
        }

        $experiment = $result['config']['EXPERIMENT'] ?? '';
        if ($experiment === 'carbon_tax_sweep') {
            return $this->numericInList(
                $result['config']['TAX_RATE'] ?? null,
                $expConfig['tax_rates'] ?? []
            );
        }
        if ($experiment === 'carbon_cap_sweep') {
            return $this->numericInList(
                $result['config']['CAP_PERCENTAGE'] ?? null,
                $expConfig['cap_percentages'] ?? []
            );
        }
        if ($experiment === 'carbon_hybrid') {
            foreach ($expConfig['hybrid_combinations'] ?? [] as $combination) {
                $taxMatches = abs(
                    (float)($result['config']['TAX_RATE'] ?? -1)
                    - (float)($combination['tax'] ?? -2)
                ) <= 1e-9;
                $configuredCap = $combination['cap_level'] ?? null;
                $resultCap = $result['config']['CAP_PERCENTAGE'] ?? null;
                $capMatches = $configuredCap === 'none'
                    ? $resultCap === null
                    : ($resultCap !== null
                        && abs((float)$resultCap - (float)$configuredCap) <= 1e-9);
                if ($taxMatches && $capMatches) {
                    return true;
                }
            }
        }

        return false;
    }

    private function numericInList($value, array $values): bool {
        if ($value === null) {
            return false;
        }
        foreach ($values as $candidate) {
            if (abs((float)$value - (float)$candidate) <= 1e-9) {
                return true;
            }
        }
        return false;
    }

    private function saveDecisionStabilityResults(): void {
        if (empty($this->decisionStabilityRows)) {
            return;
        }

        $detailFile = $this->tablesDir . 'decision_stability_results.csv';
        $this->writeAssociativeCSV($detailFile, $this->decisionStabilityRows);

        $summary = DecisionStabilityAnalyzer::summarize($this->decisionStabilityRows);
        if (!empty($summary)) {
            $this->writeAssociativeCSV(
                $this->tablesDir . 'decision_stability_summary.csv',
                $summary
            );
        }
    }

    private function writeAssociativeCSV(string $filename, array $rows): void {
        $fp = fopen($filename, 'w');
        fputcsv($fp, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($fp, array_values($row));
        }
        fclose($fp);
    }
    
    /**
     * Establish a reproducible baseline with CPLEX's native lexicographic objective.
     */
    private function executeLexicographicBaseline(array $runConfig, string $instanceId): array {
        $originalPrefix = $runConfig['PREFIXE'];
        $originalExperiment = $runConfig['EXPERIMENT'] ?? 'baseline';

        $nonBindingBounds = $this->getNonBindingBounds(
            $runConfig['_NODE_FILE_'],
            $runConfig['_SUPP_DETAILS_FILE_'],
            (int)$runConfig['_NBSUPP_']
        );

        $lexRun = $runConfig;
        $lexRun['PREFIXE'] = $originalPrefix;
        $lexRun['MODEL_FILE'] = 'RUNS_SupEmis_MultiObj_PLM.mod';
        $lexRun['MODEL_TYPE'] = 'PLM';
        $lexRun['_EMISCAP_'] = $nonBindingBounds['emissions'];
        $lexRun['_EMISTAXE_'] = 0.0;
        $lexRun['_OBJ_PRIMARY_'] = 1;
        $lexRun['_EPSILON_COST_'] = $nonBindingBounds['cost'];
        $lexRun['_EPSILON_DIO_'] = $nonBindingBounds['dio'];
        $lexRun['_EPSILON_WIP_'] = $nonBindingBounds['wip'];
        $lexRun['_EPSILON_EMIS_'] = $nonBindingBounds['emissions'];
        $lexRun['_NONBINDING_COST_'] = $nonBindingBounds['cost'];
        $lexRun['_NONBINDING_DIO_'] = $nonBindingBounds['dio'];
        $lexRun['_NONBINDING_WIP_'] = $nonBindingBounds['wip'];
        $lexRun['_NONBINDING_EMIS_'] = $nonBindingBounds['emissions'];
        $lexRun['EXPERIMENT'] = $originalExperiment;
        $lexRun['CAP_LEVEL'] = 'none';
        $lexRun['STATIC_LEX_BASELINE'] = true;
        $lexRun['BASELINE_METHOD'] = 'STATIC_LEX_COST_THEN_EMISSIONS';
        $lexRun['BASELINE_LEX_OBJECTIVE'] = 'staticLex(TotalCostCS, Emis)';

        $baselineResult = $this->executeSingleRun($lexRun, $instanceId);
        $baselineStatus = $baselineResult['kpis']['computational']['solver_status'] ?? 'UNKNOWN';
        if ($baselineStatus !== 'OPTIMAL') {
            throw new RuntimeException(
                "Cannot establish lexicographic baseline for {$instanceId}: " .
                "native staticLex status={$baselineStatus}"
            );
        }

        return $baselineResult;
    }

    /**
     * Return the established lexicographic emissions baseline or stop the campaign.
     */
    private function requireBaselineEmissions(string $instanceId): float {
        if (!isset($this->baselineEmissions[$instanceId])) {
            throw new RuntimeException(
                "Missing lexicographic emissions baseline for {$instanceId}; " .
                "cap-based scenarios cannot be generated."
            );
        }

        return (float)$this->baselineEmissions[$instanceId];
    }

    /**
     * Execute a single optimization run
     */
    private function executeSingleRun(array $runConfig, string $instanceId, bool $storeResult = true): array {
        $this->runCounter++;
        
        $modelPath = $this->modelDir . $runConfig['MODEL_FILE'];
        $prefix = $runConfig['PREFIXE'];
        $this->executedRunIds[] = $prefix;
        
        // Prepare model file
        $preparedModel = $this->prepareModelFile($modelPath, $runConfig, $prefix);
        
        // Execute CPLEX
        $rawOutput = null;
        $result = [];
        
        try {
            $cmdLine = '"' . $this->oplRunPath . '" ' . escapeshellarg($preparedModel);
            $rawOutput = shell_exec($cmdLine);

            if ($rawOutput) {
                // Parse the already-captured output instead of re-running oplrun (which doubled
                // the campaign runtime by executing every instance twice).
                $result = CplexRunner::parse($rawOutput);
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
        if ($storeResult) {
            $this->allResults[] = $fullResult;
        }
        
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

        if (isset($runConfig['STABILITY_PROBE'])) {
            $content = DecisionStabilityAnalyzer::buildProbeModel(
                $content,
                $runConfig['STABILITY_REFERENCE_X'],
                $runConfig['STABILITY_REFERENCE_Z'],
                $runConfig['STABILITY_REFERENCE_Q'],
                (int)$runConfig['_NBSUPP_'],
                (string)$runConfig['STABILITY_PROBE'],
                (float)$runConfig['STABILITY_OBJECTIVE_LIMIT']
            );
        }

        if (!empty($runConfig['STATIC_LEX_BASELINE'])) {
            $content = $this->applyStaticLexBaselineModel($content);
        }
        
        // Apply replacements
        $scalarConfig = array_filter($runConfig, function($value) {
            return is_scalar($value) || $value === null;
        });
        $content = str_replace(array_keys($scalarConfig), array_values($scalarConfig), $content);
        
        // Write to output
        $outputFile = $this->dataDir . strtoupper($prefix) . "_" . basename($modelPath);
        file_put_contents($outputFile, $content);
        
        return $outputFile;
    }

    private function applyStaticLexBaselineModel(string $content): string {
        $updated = preg_replace(
            '/minimize\s+PrimaryObj\s*;/',
            'minimize staticLex(TotalCostCS, Emis);',
            $content,
            1,
            $objectiveCount
        );

        if ($updated === null || $objectiveCount !== 1) {
            throw new RuntimeException(
                'Unable to prepare native lexicographic baseline: PrimaryObj objective not found'
            );
        }

        $constraintPatterns = [
            '/^\s*ct9\s*:\s*Emis\s*<=\s*EmisCap\s*;\s*$/m',
            '/^\s*ct_epsilon_Cost\s*:\s*TotalCostCS\s*<=\s*epsilon_Cost\s*;.*$/m',
            '/^\s*ct_epsilon_DIO\s*:\s*DIO\s*<=\s*epsilon_DIO\s*;.*$/m',
            '/^\s*ct_epsilon_WIP\s*:\s*WIP\s*<=\s*epsilon_WIP\s*;.*$/m',
            '/^\s*ct_epsilon_Emis\s*:\s*Emis\s*<=\s*epsilon_Emis\s*;.*$/m',
        ];

        foreach ($constraintPatterns as $pattern) {
            $updated = preg_replace_callback(
                $pattern,
                static function(array $matches): string {
                    return '//' . ltrim($matches[0]);
                },
                $updated,
                1,
                $constraintCount
            );
            if ($updated === null || $constraintCount !== 1) {
                throw new RuntimeException(
                    "Unable to prepare native lexicographic baseline: expected constraint pattern missing ({$pattern})"
                );
            }
        }

        return $updated;
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

        $comparisonAdmissible = count(array_filter($this->allResults, function($r) {
            return ($r['kpis']['computational']['comparison_admissible'] ?? false) === true;
        }));
        $summary .= "- Comparison-admissible: {$comparisonAdmissible} runs\n";
        
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
            
            $runCount = $name === 'decision_stability'
                ? count($this->decisionStabilityRows)
                : count(array_filter($this->allResults, function($r) use ($name) {
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
        
        // Infeasible policy combinations are meaningful outcomes, not execution failures.
        $infeasibleScenarios = count(array_filter($this->allResults, function($r) {
            return ($r['kpis']['computational']['solver_status'] ?? '') === 'INFEASIBLE';
        }));
        $failures = count(array_filter($this->allResults, function($r) {
            $status = $r['kpis']['computational']['solver_status'] ?? '';
            return in_array($status, ['ERROR', 'TIMEOUT']);
        }));
        $checklist .= "- Infeasible policy scenarios: {$infeasibleScenarios} runs\n";
        $checklist .= "- Solver execution failures: {$failures} runs\n";
        $comparisonExcluded = count(array_filter($this->allResults, function($r) {
            return !($r['kpis']['computational']['comparison_admissible'] ?? false);
        }));
        $checklist .= "- Retained but excluded from behavioral comparisons: {$comparisonExcluded} runs\n";
        
        $checklist .= "\n## Deliverables\n\n";
        $checklist .= "- [✅] Consolidated results CSV\n";
        $checklist .= "- [✅] Summary statistics\n";
        $checklist .= "- [✅] Experiment-specific CSV files\n";
        $checklist .= "- [✅] Run logs\n";
        
        file_put_contents($this->resultsDir . 'campaign_checklist.md', $checklist);
        echo "Campaign checklist saved to: {$this->resultsDir}campaign_checklist.md\n";
    }

    private function validatePostRunAgainstPlan(): void {
        $planFile = $this->resultsDir . 'campaign_plan.json';
        $checks = [];
        $failures = [];
        $warnings = [];

        $addCheck = function(string $name, bool $passed, string $detail) use (&$checks, &$failures): void {
            $checks[] = [
                'name' => $name,
                'status' => $passed ? 'PASS' : 'FAIL',
                'detail' => $detail
            ];
            if (!$passed) {
                $failures[] = "{$name}: {$detail}";
            }
        };

        if (!is_file($planFile)) {
            $addCheck('campaign_plan_exists', false, 'campaign_plan.json is missing');
            $this->writePostRunValidationReport($checks, $warnings, $failures);
            throw new RuntimeException('Post-run validation failed: campaign_plan.json is missing');
        }

        $plan = json_decode(file_get_contents($planFile), true);
        if (!is_array($plan)) {
            $addCheck('campaign_plan_json', false, 'campaign_plan.json is not valid JSON');
            $this->writePostRunValidationReport($checks, $warnings, $failures);
            throw new RuntimeException('Post-run validation failed: campaign_plan.json is not valid JSON');
        }

        $manifestFile = $this->resultsDir . 'run_manifest.json';
        $manifest = null;
        if (!is_file($manifestFile)) {
            $addCheck('run_manifest_exists', false, 'run_manifest.json is missing');
        } else {
            $manifest = json_decode(file_get_contents($manifestFile), true);
            $addCheck(
                'run_manifest_json',
                is_array($manifest),
                is_array($manifest) ? 'run_manifest.json is valid JSON' : 'run_manifest.json is not valid JSON'
            );
        }

        foreach ([
            'campaign_metadata.json',
            'campaign_plan.md',
            'run_manifest.json',
            'consolidated_results.csv',
            'summary_statistics.md',
            'campaign_checklist.md',
        ] as $requiredFile) {
            $addCheck(
                "file_{$requiredFile}",
                is_file($this->resultsDir . $requiredFile),
                is_file($this->resultsDir . $requiredFile)
                    ? "{$requiredFile} exists"
                    : "{$requiredFile} is missing"
            );
        }

        $plannedRows = (int)($plan['totals']['reported_rows'] ?? -1);
        $consolidatedRows = $this->countCsvDataRows($this->resultsDir . 'consolidated_results.csv');
        $addCheck(
            'consolidated_row_count',
            $consolidatedRows === $plannedRows,
            "planned={$plannedRows}, realized={$consolidatedRows}"
        );

        $allResultsCount = count($this->allResults);
        $addCheck(
            'in_memory_result_count',
            $allResultsCount === $plannedRows,
            "planned={$plannedRows}, realized={$allResultsCount}"
        );

        $plannedProbeMax = (int)($plan['totals']['decision_probe_solver_calls_max'] ?? 0);
        $plannedRunnerCalls = (int)($plan['totals']['runner_solver_calls'] ?? -1);
        $actualProbeRows = count($this->decisionStabilityRows);
        $expectedRunnerCalls = $plannedRunnerCalls - $plannedProbeMax + $actualProbeRows;
        $addCheck(
            'runner_solver_call_count',
            $this->runCounter === $expectedRunnerCalls,
            "expected={$expectedRunnerCalls}, realized={$this->runCounter}"
        );

        if (is_array($manifest)) {
            $expectedConsolidatedIds = $this->manifestRunIds($manifest['consolidated_runs'] ?? []);
            $actualConsolidatedIds = $this->actualConsolidatedRunIds();
            $missingConsolidated = array_values(array_diff($expectedConsolidatedIds, $actualConsolidatedIds));
            $unexpectedConsolidated = array_values(array_diff($actualConsolidatedIds, $expectedConsolidatedIds));
            $duplicateConsolidated = $this->duplicateValues($actualConsolidatedIds);
            $addCheck(
                'consolidated_run_manifest_ids',
                empty($missingConsolidated) && empty($unexpectedConsolidated) && empty($duplicateConsolidated),
                'missing=' . count($missingConsolidated)
                    . ', unexpected=' . count($unexpectedConsolidated)
                    . ', duplicates=' . count($duplicateConsolidated)
            );

            // executedRunIds only records solves issued through executeSingleRun, i.e. the
            // consolidated runs plus internal sub-stages and the conditional decision-degeneracy
            // probes. Multi-objective epsilon-constraint solves are driven separately by
            // MultiObjectiveRunner and never enter executedRunIds, so they are intentionally
            // excluded here: their execution is verified independently by the Pareto output
            // checks below (multi_objective_pareto_rows and the per-instance Pareto/ideal-nadir
            // file checks). Including them here would compare manifest ids against a list that
            // can never contain them and fail unconditionally.
            $expectedInternalIds = array_merge(
                $this->manifestRunIds($manifest['consolidated_runs'] ?? []),
                $this->manifestRunIds($manifest['internal_solver_runs'] ?? [])
            );
            $actualWithoutConditional = array_values(array_diff(
                $this->executedRunIds,
                $this->actualDecisionStabilityRunIds()
            ));
            $missingInternal = array_values(array_diff($expectedInternalIds, $actualWithoutConditional));
            $unexpectedInternal = array_values(array_diff($actualWithoutConditional, $expectedInternalIds));
            $duplicateExecuted = $this->duplicateValues($this->executedRunIds);
            $addCheck(
                'executed_run_manifest_ids',
                empty($missingInternal) && empty($unexpectedInternal) && empty($duplicateExecuted),
                'missing=' . count($missingInternal)
                    . ', unexpected=' . count($unexpectedInternal)
                    . ', duplicates=' . count($duplicateExecuted)
            );

            $conditionalIds = $this->manifestRunIds($manifest['conditional_decision_stability_runs'] ?? []);
            $actualConditional = $this->actualDecisionStabilityRunIds();
            $unexpectedConditional = array_values(array_diff($actualConditional, $conditionalIds));
            $addCheck(
                'conditional_decision_stability_manifest_ids',
                empty($unexpectedConditional),
                'realized=' . count($actualConditional)
                    . ', allowed_max=' . count($conditionalIds)
                    . ', unexpected=' . count($unexpectedConditional)
            );

            foreach ($manifest['pareto_files'] ?? [] as $relativeFile) {
                $path = $this->resultsDir . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
                $addCheck(
                    "manifest_file_{$relativeFile}",
                    is_file($path),
                    is_file($path) ? "{$relativeFile} exists" : "{$relativeFile} is missing"
                );
            }
        }

        $experiments = [];
        foreach ($plan['experiments'] ?? [] as $row) {
            $experiments[$row['name']] = $row;
        }

        foreach ($experiments as $name => $row) {
            if (!($row['enabled'] ?? false)) {
                continue;
            }
            if (in_array($name, ['multi_objective', 'decision_stability'], true)) {
                continue;
            }
            $expectedRows = (int)($row['reported_rows'] ?? 0);
            $file = $this->tablesDir . "{$name}_results.csv";
            $actualRows = $this->countCsvDataRows($file);
            $addCheck(
                "table_{$name}_row_count",
                $actualRows === $expectedRows,
                "planned={$expectedRows}, realized={$actualRows}, file=" . basename($file)
            );
        }

        if (($experiments['multi_objective']['enabled'] ?? false) === true) {
            foreach ($this->campaignConfig['experiments']['multi_objective']['representative_instances'] ?? [] as $instanceId) {
                foreach ([
                    "{$instanceId}_cost_emissions_pareto.csv",
                    "{$instanceId}_cost_dio_pareto.csv",
                    "{$instanceId}_ideal_nadir.json",
                ] as $paretoFile) {
                    $path = $this->resultsDir . 'pareto' . DIRECTORY_SEPARATOR . $paretoFile;
                    $addCheck(
                        "multi_objective_file_{$paretoFile}",
                        is_file($path),
                        is_file($path) ? "{$paretoFile} exists" : "{$paretoFile} is missing"
                    );
                }
            }

            $expectedParetoRows = (int)($experiments['multi_objective']['reported_rows'] ?? 0);
            $actualParetoRows = $this->countParetoRows();
            $addCheck(
                'multi_objective_pareto_rows',
                $actualParetoRows === $expectedParetoRows,
                "planned={$expectedParetoRows}, realized={$actualParetoRows}"
            );
        }

        if (($experiments['decision_stability']['enabled'] ?? false) === true) {
            $detailRows = $this->countCsvDataRows($this->tablesDir . 'decision_stability_results.csv');
            $summaryRows = $this->countCsvDataRows($this->tablesDir . 'decision_stability_summary.csv');
            if ($actualProbeRows === 0) {
                $warnings[] = 'Decision-degeneracy diagnostic produced no probe rows; no proven-optimal anchors may have been available.';
                $addCheck(
                    'decision_stability_rows',
                    $detailRows === 0 && $summaryRows === 0,
                    "in_memory=0, detail={$detailRows}, summary={$summaryRows}"
                );
            } else {
                $addCheck(
                    'decision_stability_detail_rows',
                    $detailRows === $actualProbeRows,
                    "in_memory={$actualProbeRows}, detail={$detailRows}"
                );
                $addCheck(
                    'decision_stability_summary_exists',
                    $summaryRows > 0,
                    "summary_rows={$summaryRows}"
                );
            }
        }

        $this->writePostRunValidationReport($checks, $warnings, $failures);
        if (!empty($failures)) {
            throw new RuntimeException(
                "Post-run validation failed:\n- " . implode("\n- ", $failures)
            );
        }

        echo "Post-run validation passed: {$this->resultsDir}post_run_validation.md\n";
    }

    private function countCsvDataRows(string $file): int {
        if (!is_file($file)) {
            return 0;
        }

        $fp = fopen($file, 'r');
        if ($fp === false) {
            return 0;
        }

        $rows = -1; // Header row.
        while (fgetcsv($fp) !== false) {
            $rows++;
        }
        fclose($fp);

        return max(0, $rows);
    }

    private function countParetoRows(): int {
        $paretoDir = $this->resultsDir . 'pareto' . DIRECTORY_SEPARATOR;
        if (!is_dir($paretoDir)) {
            return 0;
        }

        $rows = 0;
        foreach (glob($paretoDir . '*_cost_emissions_pareto.csv') ?: [] as $file) {
            $rows += $this->countCsvDataRows($file);
        }
        foreach (glob($paretoDir . '*_cost_dio_pareto.csv') ?: [] as $file) {
            $rows += $this->countCsvDataRows($file);
        }

        return $rows;
    }

    private function manifestRunIds(array $entries): array {
        $ids = array_map(function($entry) {
            return (string)($entry['run_id'] ?? '');
        }, $entries);
        $ids = array_values(array_filter($ids, function(string $id): bool {
            return $id !== '';
        }));
        sort($ids, SORT_NATURAL);
        return $ids;
    }

    private function actualConsolidatedRunIds(): array {
        $ids = array_map(function($result) {
            return (string)($result['config']['PREFIXE'] ?? '');
        }, $this->allResults);
        $ids = array_values(array_filter($ids, function(string $id): bool {
            return $id !== '';
        }));
        sort($ids, SORT_NATURAL);
        return $ids;
    }

    private function actualDecisionStabilityRunIds(): array {
        $ids = array_map(function($row) {
            return (string)($row['anchor_run_id'] ?? '')
                . '-STAB-'
                . strtoupper((string)($row['probe'] ?? ''));
        }, $this->decisionStabilityRows);
        $ids = array_values(array_filter($ids, function(string $id): bool {
            return $id !== '-STAB-';
        }));
        sort($ids, SORT_NATURAL);
        return $ids;
    }

    private function duplicateValues(array $values): array {
        $counts = array_count_values($values);
        $duplicates = [];
        foreach ($counts as $value => $count) {
            if ($count > 1) {
                $duplicates[] = (string)$value;
            }
        }
        sort($duplicates, SORT_NATURAL);
        return $duplicates;
    }

    private function writePostRunValidationReport(array $checks, array $warnings, array $failures): void {
        $status = empty($failures) ? 'PASS' : 'FAIL';
        $report = "# Post-Run Validation\n\n";
        $report .= "- Generated: " . date('Y-m-d H:i:s') . "\n";
        $report .= "- Status: {$status}\n\n";

        $report .= "## Checks\n\n";
        foreach ($checks as $check) {
            $report .= sprintf(
                "- [%s] %s: %s\n",
                $check['status'],
                $check['name'],
                $check['detail']
            );
        }

        if (!empty($warnings)) {
            $report .= "\n## Warnings\n\n";
            foreach ($warnings as $warning) {
                $report .= "- {$warning}\n";
            }
        }

        if (!empty($failures)) {
            $report .= "\n## Failures\n\n";
            foreach ($failures as $failure) {
                $report .= "- {$failure}\n";
            }
        }

        file_put_contents($this->resultsDir . 'post_run_validation.md', $report);
        file_put_contents(
            $this->resultsDir . 'post_run_validation.json',
            json_encode([
                'status' => $status,
                'generated_at' => date('Y-m-d H:i:s'),
                'checks' => $checks,
                'warnings' => $warnings,
                'failures' => $failures
            ], JSON_PRETTY_PRINT)
        );
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
            'comparison_gap_threshold_pct' =>
                $this->campaignConfig['analysis_settings']['comparison_gap_threshold_pct'] ?? 1.0,
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
        $dryRun = in_array('--dry-run', $argv ?? [], true);
        $priceThreshold = in_array('--price-threshold', $argv ?? [], true);
        $skipPreflight = in_array('--skip-preflight', $argv ?? [], true)
            || getenv('PHPAUTO_SKIP_PREFLIGHT') === '1';

        if ($skipPreflight) {
            echo "WARNING: Deployment preflight skipped by explicit override.\n";
        } else {
            echo "Running deployment preflight...\n";
            FinalCampaignRunner::runDeploymentPreflight();
        }

        $runner = new FinalCampaignRunner(!$dryRun);
        if ($dryRun) {
            $runner->printDryRunSummary();
            exit(0);
        }
        if ($priceThreshold) {
            $runner->runCarbonPriceThresholdDiagnostic();
            exit(0);
        }

        $runner->runFullCampaign();
    } catch (Exception $e) {
        echo "FATAL ERROR: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
        exit(1);
    }
}
