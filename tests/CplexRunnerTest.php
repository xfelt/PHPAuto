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
#Result <fct_obj, tot_cst, tot_ldt, Emiss>: <100 90 10 500>
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

$optimal = CplexRunner::parse($optimalMip);
$limited = CplexRunner::parse($limitedCp);
$infeasible = CplexRunner::parse($infeasibleMip);

assertSameValue('OPTIMAL', $optimal['status'], 'MIP optimal status');
assertSameValue(0.0, $optimal['mip_gap'], 'MIP optimal gap');
assertSameValue('FEASIBLE', $limited['status'], 'Limited CP incumbent status');
assertSameValue(12.5, $limited['mip_gap'], 'Limited CP incumbent gap');
assertSameValue('INFEASIBLE', $infeasible['status'], 'Infeasible status');

echo "CplexRunner status tests passed.\n";
