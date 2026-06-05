<?php

function repoPath(string $relativePath): string {
    return __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function readRequired(string $relativePath): string {
    $path = repoPath($relativePath);
    if (!is_file($path)) {
        throw new RuntimeException("Required file is missing: {$relativePath}");
    }
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("Unable to read required file: {$relativePath}");
    }
    return $content;
}

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSameList(array $expected, array $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . json_encode($expected) .
            ', got ' . json_encode($actual)
        );
    }
}

$config = json_decode(readRequired('config/final_campaign_config.json'), true);
if (!is_array($config)) {
    throw new RuntimeException('final_campaign_config.json is not valid JSON');
}

$context = readRequired('CONTEXT.md');
$article = readRequired('article/elsarticle-template-harv.tex');
$runner = readRequired('src/FinalCampaignRunner.php');
$kpi = readRequired('src/KPICalculator.php');
$tables = readRequired('src/generate_article_tables.py');
$graphs = readRequired('src/GraphGenerator.py');
$synthesis = readRequired('src/SynthesisGenerator.php');

// Carbon-price units and scenarios.
assertTrue(
    ($config['analysis_settings']['comparison_gap_threshold_pct'] ?? null) === 1.0,
    'Comparison-admissible threshold must remain 1%'
);
assertTrue(
    strpos($config['analysis_settings']['scenario_interpretation'] ?? '', 'controlled deterministic optimization scenarios') !== false,
    'Simulation scenarios must be documented as deterministic optimization scenarios'
);
assertSameList(
    [0.0, 15.0, 50.0, 75.0, 100.0],
    $config['experiments']['carbon_tax_sweep']['tax_rates'] ?? [],
    'Carbon-tax sweep must use the agreed EmisTax scenario set'
);
assertSameList(
    [0.0, 15.0, 50.0, 75.0, 100.0],
    $config['experiments']['carbon_hybrid']['tax_rates'] ?? [],
    'Hybrid sweep must use the agreed EmisTax scenario set'
);
assertTrue(
    ($config['experiments']['carbon_price_switching_threshold']['enabled'] ?? false) === true
        && ($config['experiments']['carbon_price_switching_threshold']['observed_policy_max'] ?? null) === 100.0
        && ($config['experiments']['carbon_price_switching_threshold']['max_probe_rate'] ?? null) >= 1000000.0,
    'Carbon-price switching-threshold diagnostic must remain configured as an exploratory stress test'
);
assertSameList(
    ['none', 1.0, 0.95, 0.90, 0.85, 0.80, 0.75, 0.70],
    $config['experiments']['carbon_hybrid']['cap_levels'] ?? [],
    'Hybrid sweep must include no-cap plus all cap levels from 100% to 70%'
);
assertTrue(
    strpos($kpi, '$taxRate * ($emissions / 1000000.0)') !== false,
    'KPICalculator must convert gCO2 to tCO2 before applying EmisTax'
);

foreach ([
    'models/RUNS_SupEmis_Cplex_PLM_Tax.mod',
    'models/RUNS_SupEmis_Cplex_PLM_Cap.mod',
    'models/RUNS_SupEmis_Cplex_PLM_Hybrid.mod',
] as $modelFile) {
    $model = readRequired($modelFile);
    assertTrue(
        strpos($model, 'dexpr float EmisTonnes = Emis / 1000000.0') !== false,
        "{$modelFile} must convert modeled gCO2 emissions to tonnes"
    );
    assertTrue(
        strpos($model, 'EmisCost = EmisTax * EmisTonnes') !== false,
        "{$modelFile} must apply EmisTax to tonnes, not grams"
    );
    assertTrue(
        preg_match('/EmisTax\s*\*\s*Emis(?!Tonnes)/', $model) !== 1,
        "{$modelFile} must not multiply EmisTax directly by gram emissions"
    );
    assertTrue(
        strpos($model, 'write("#Z:[') !== false && strpos($model, 'write("#Q:[') !== false,
        "{$modelFile} must export Z and Q for decision-degeneracy probes"
    );
}

foreach ([
    'src/FinalCampaignRunner.php' => $runner,
    'src/MultiObjectiveRunner.php' => readRequired('src/MultiObjectiveRunner.php'),
    'models/RUNS_SupEmis_MultiObj_PLM.mod' => readRequired('models/RUNS_SupEmis_MultiObj_PLM.mod'),
] as $file => $content) {
    assertTrue(
        preg_match('/\b1(?:\.0)?e(?:10|30)\b/i', $content) !== 1,
        "{$file} must not use large numeric sentinels for inactive bounds"
    );
}
assertTrue(
    strpos($runner, 'getNonBindingBounds') !== false
        && strpos($runner, 'NUMERICALLY_SAFE_BOUND_MAX') !== false
        && strpos($runner, '_NONBINDING_EMIS_') !== false,
    'FinalCampaignRunner must use instance-scaled finite non-binding bounds'
);
assertTrue(
    strpos($runner, 'STATIC_LEX_COST_THEN_EMISSIONS') !== false
        && strpos($runner, 'STATIC_LEX_BASELINE') !== false
        && strpos($runner, 'staticLex(TotalCostCS, Emis)') !== false,
    'Lexicographic baselines must use CPLEX native staticLex instead of a tight cost epsilon band'
);
assertTrue(
    strpos(json_encode($config['baseline_references'] ?? []), 'economic_cost_tolerance') === false,
    'Baseline reference config must not retain obsolete tight-band tolerance settings'
);

// Full-factorial hybrid and infeasible-scenario reporting.
assertTrue(
    strpos($runner, 'count($taxRates) * count($capLevels)') !== false
        && preg_match('/foreach\s*\(\$taxRates\s+as\s+\$[A-Za-z_][A-Za-z0-9_]*\)/', $runner) === 1
        && preg_match('/foreach\s*\(\$capLevels\s+as\s+\$[A-Za-z_][A-Za-z0-9_]*\)/', $runner) === 1,
    'FinalCampaignRunner must keep the hybrid design full factorial'
);
assertTrue(
    strpos($runner, 'runDeploymentPreflight') !== false
        && strpos($runner, '--skip-preflight') !== false
        && strpos($runner, '--dry-run') !== false
        && strpos($runner, '--price-threshold') !== false
        && strpos($runner, 'campaign_plan.md') !== false
        && strpos($runner, 'campaign_plan.json') !== false
        && strpos($runner, 'run_manifest.json') !== false
        && strpos($runner, 'post_run_validation.md') !== false
        && strpos($runner, 'post_run_validation.json') !== false
        && strpos($runner, 'PHPAUTO_SKIP_PREFLIGHT') !== false,
    'FinalCampaignRunner CLI must run preflight, support dry run, persist the campaign plan, and validate post-run outputs'
);
assertTrue(
    strpos($article, 'Infeasible combinations are retained and reported') !== false,
    'Article must state that infeasible combined policy scenarios are retained and reported'
);
assertTrue(
    strpos($article, 'price inertia') !== false
        && strpos($article, 'switching-threshold diagnostic') !== false
        && strpos($article, '\\input{tables/tab_price_threshold.tex}') !== false
        && is_file(repoPath('article/tables/tab_price_threshold.tex')),
    'Article must foreground carbon-price inertia and include the switching-threshold table'
);
assertTrue(
    strpos($article, '5.55 seconds') !== false
        && strpos($article, '1.58 seconds') !== false
        && strpos($article, '21 small PLM/NLM pairs') !== false
        && strpos($article, 'Time-limited NLM incumbents') !== false,
    'Article must distinguish lexicographic baseline runtime from PLM stress runtime and show PLM/NLM denominator'
);
assertTrue(
    stripos($article, 'jointly reshape') === false
        && stripos($article, 'ecological') === false,
    'Article must not retain the old overclaim or loose ecological terminology'
);

// Comparison-admissible filtering and reporting.
foreach ([
    'src/KPICalculator.php' => $kpi,
    'src/FinalCampaignRunner.php' => $runner,
    'src/generate_article_tables.py' => $tables,
    'src/GraphGenerator.py' => $graphs,
    'src/SynthesisGenerator.php' => $synthesis,
] as $file => $content) {
    assertTrue(
        strpos($content, 'comparison_admissible') !== false,
        "{$file} must preserve comparison-admissible filtering/reporting"
    );
}

assertTrue(
    is_file(repoPath('tests/RunManifestTest.php')),
    'Run manifest regression test must exist'
);

// Decision-degeneracy diagnostic scope.
$stability = $config['experiments']['decision_stability'] ?? [];
assertTrue(($stability['enabled'] ?? false) === true, 'Decision-degeneracy diagnostic must be enabled');
assertSameList(
    ['buffers', 'suppliers', 'allocation'],
    $stability['probes'] ?? [],
    'Decision-degeneracy diagnostic must probe buffers, suppliers, and allocation'
);
assertTrue(
    strpos($stability['scope'] ?? '', 'Representative proven-optimal policy anchors only') !== false,
    'Decision-degeneracy diagnostic must remain scoped to representative anchors'
);
assertTrue(
    strpos($article, 'not interpreted as stochastic robustness tests') !== false,
    'Article must not frame decision-degeneracy probes as stochastic robustness tests'
);

// Terminology guardrails from CONTEXT.md.
assertTrue(
    strpos($context, '**Supplier-side emissions**') !== false,
    'CONTEXT.md must define supplier-side emissions'
);
assertTrue(
    strpos($config['kpi_definitions']['carbon']['supplier_emissions'] ?? '', 'Supplier-side emissions') !== false,
    'Config must define supplier emissions as supplier-side emissions'
);
assertTrue(
    stripos($article, 'embodied emissions') === false
        && stripos(json_encode($config), 'embodied emissions') === false,
    'Article/config must not use embodied-emissions terminology for supplier-side emissions'
);
assertTrue(
    strpos($context, '**Simulation scenarios**') !== false
        && strpos($article, 'simulation scenarios denote controlled deterministic optimization scenarios') !== false,
    'Simulation scenarios must be defined as deterministic scenario analysis'
);
assertTrue(
    strpos($context, '**Carbon-price switching threshold**') !== false,
    'CONTEXT.md must define the carbon-price switching-threshold diagnostic'
);

echo "Deployment preflight checks passed.\n";
