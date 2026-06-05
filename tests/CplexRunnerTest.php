<?php

require_once __DIR__ . '/../src/CplexRunner.php';

function assertSameValue($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true)
        );
    }
}

$optimalMip = <<<'LOG'
Total (root+branch&cut) = 0.11 sec.
OBJECTIVE: 100
xxxx
#Result <fct_obj, tot_cst, tot_ldt, Emiss>: <100 90 10 500>#X:[1,0]#Z:[1,0,0,1]#Q:[10,0,0,20]
xxxx
LOG;

$limitedCp = <<<'LOG'
! Search terminated by limit, 4 solutions found.
! Best objective : 120 (gap is 12,50%)
OBJECTIVE: 120
xxxx
#Result <fct_obj, tot_cst, tot_ldt, Emiss>: <120 110 10 500>
xxxx
LOG;

$infeasibleMip = <<<'LOG'
Root node processing
0 0 infeasible
<<< no solution
LOG;

$staticLexMip = <<<'LOG'
Multi-objective solve log . . .

Index  Priority  Blend          Objective      Nodes  Time (sec.)  DetTime (ticks)
    1         1      1   4,8640000000e+04          0         0,16            19,86
    2         0      1   2,9322000000e+06          0         0,06            26,53

OBJECTIVE: 48640; 2932200
xxxx
#Result <fct_obj, tot_cost, DIO, WIP, Emiss>: <48640 48640 31 1.057e+5 2.9322e+6>
xxxx
LOG;

$optimal = CplexRunner::parse($optimalMip);
$limited = CplexRunner::parse($limitedCp);
$infeasible = CplexRunner::parse($infeasibleMip);
$staticLex = CplexRunner::parse($staticLexMip);

assertSameValue('OPTIMAL', $optimal['status'], 'MIP optimal status');
assertSameValue(0.0, $optimal['mip_gap'], 'MIP optimal gap');
assertSameValue([1, 0, 0, 1], $optimal['Z'], 'Supplier decision vector');
assertSameValue([10, 0, 0, 20], $optimal['Q'], 'Allocation decision vector');
assertSameValue('FEASIBLE', $limited['status'], 'Limited CP incumbent status');
assertSameValue(12.5, $limited['mip_gap'], 'Limited CP incumbent gap');
assertSameValue('INFEASIBLE', $infeasible['status'], 'Infeasible status');
assertSameValue('OPTIMAL', $staticLex['status'], 'Native staticLex status');
assertSameValue(0.0, $staticLex['mip_gap'], 'Native staticLex gap');
assertSameValue('0.22 sec', $staticLex['CplexRunTime'], 'Native staticLex runtime sums priority stage times');

echo "CplexRunner status tests passed.\n";
