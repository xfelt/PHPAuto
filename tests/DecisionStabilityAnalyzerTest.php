<?php

require_once __DIR__ . '/../src/DecisionStabilityAnalyzer.php';

$model = <<<'OPL'
dvar boolean x[N];
dvar boolean z[N][S];
dvar int+ q[N][S];
float sup[S][1..4];
dexpr float Original = sum(i in N) x[i];
minimize Original;
subject to {
}
execute {
    writeln("#DELIVER:");
}
OPL;

$transformed = DecisionStabilityAnalyzer::buildProbeModel(
    $model,
    [1, 0],
    [1, 0, 0, 1],
    [10, 0, 0, 20],
    2,
    'suppliers',
    10.1
);

foreach ([
    'dexpr float stabilityOriginalObjective = Original;',
    'dexpr float stabilityScore = stabilitySupplierDivergence;',
    'minimize -stabilityScore;',
    'ct_stability_objective: stabilityOriginalObjective <= 10.1;',
    '#STABILITY_ORIGINAL_OBJECTIVE:',
] as $expected) {
    if (strpos($transformed, $expected) === false) {
        throw new RuntimeException("Missing transformed model fragment: {$expected}");
    }
}

$comparison = DecisionStabilityAnalyzer::compare(
    [
        'X' => [1, 0, 1],
        'Z' => [1, 0, 0, 1],
        'Q' => [10, 0, 0, 20],
    ],
    [
        'X' => [1, 1, 0],
        'Z' => [0, 1, 0, 1],
        'Q' => [5, 5, 0, 20],
    ]
);

if (abs($comparison['buffer_jaccard_similarity'] - (1 / 3)) > 1e-9) {
    throw new RuntimeException('Buffer Jaccard similarity is incorrect');
}
if (abs($comparison['supplier_jaccard_similarity'] - (1 / 3)) > 1e-9) {
    throw new RuntimeException('Supplier Jaccard similarity is incorrect');
}
if (abs($comparison['allocation_l1_normalized'] - (10 / 30)) > 1e-9) {
    throw new RuntimeException('Normalized allocation L1 deviation is incorrect');
}

$summary = DecisionStabilityAnalyzer::summarize([
    array_merge([
        'anchor_run_id' => 'A',
        'instance_id' => 'bom_5',
        'source_experiment' => 'carbon_tax_sweep',
        'strategy' => 'EMISTAXE',
        'tax_rate' => 50,
        'cap_level' => 'none',
        'probe_status' => 'OPTIMAL',
        'objective_degradation_pct' => 0.5,
    ], $comparison),
    array_merge([
        'anchor_run_id' => 'A',
        'instance_id' => 'bom_5',
        'source_experiment' => 'carbon_tax_sweep',
        'strategy' => 'EMISTAXE',
        'tax_rate' => 50,
        'cap_level' => 'none',
        'probe_status' => 'OPTIMAL',
        'objective_degradation_pct' => 0.9,
    ], [
        'buffer_jaccard_similarity' => 0.8,
        'supplier_jaccard_similarity' => 0.7,
        'allocation_l1_normalized' => 0.2,
    ]),
]);

if (count($summary) !== 1
    || abs($summary[0]['minimum_buffer_jaccard_similarity'] - (1 / 3)) > 1e-9
    || abs($summary[0]['maximum_objective_degradation_pct'] - 0.9) > 1e-9) {
    throw new RuntimeException('Decision-stability summary is incorrect');
}

echo "Decision-stability analyzer tests passed.\n";
