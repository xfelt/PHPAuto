# Sustainable DDMRP Decision Framework

This context defines the language used to describe the article's integrated
optimization framework for sustainable DDMRP decisions.

## Language

**Integrated sustainable DDMRP decision framework**:
An optimization framework in which carbon policies jointly reshape DDMRP
buffer positioning and supplier allocation.
_Avoid_: Ecological approach, green ecological approach

**Supplier selection and allocation**:
Choosing one or more suppliers for each externally sourced component and
allocating its required quantity among them.
_Avoid_: Supplier selection, Suppliers selection

**Decision-relevant operational carbon emissions**:
Emissions within the modeled boundary of sourcing, inbound supplier
transportation, internal transportation, and warehousing.
_Avoid_: Carbon footprint, total supply-chain emissions

**Supplier-side emissions**:
Modeled supplier-attributable emissions per allocated unit for externally
sourced materials, including inbound transportation to the unloading dock within
the study boundary.
_Avoid_: Embodied emissions, full lifecycle supplier footprint

**DDMRP strategic buffer positioning**:
Selecting decoupling points and deriving their inventory implications without
modeling DDMRP planning and execution over time.
_Avoid_: DDMRP implementation, complete DDMRP model

**Combined carbon-price-and-cap scenario**:
A policy-inspired decision scenario applying both a per-unit emissions price
and a hard emissions ceiling.
_Avoid_: Cap-and-trade system, hybrid regulatory regime

**Carbon price (`EmisTax`)**:
The monetary charge applied per tonne of modeled CO2 emissions.
Model emissions are quantified in grams and converted to tonnes before the
price is added to the cost objective. The price and every other objective-cost
parameter must use the same currency.
_Avoid_: Price per gram, universal international carbon-tax rate

**Carbon-price comparison scenarios**:
The no-price baseline and four study scenarios applying `EmisTax` values of
0, 15, 50, 75, and 100 constant 2023 EUR/tCO2. The positive values are
internationally informed comparison levels, not statutory rates or a universal
international norm.
_Avoid_: International standard tax rates, direct reproduction of one jurisdiction

**Full factorial combined scenario**:
The combined carbon-price-and-cap experiment in which every carbon-price
comparison level is tested against every emissions-cap level, including an
explicit no-cap level. The 0 EUR/tCO2 level is retained as the cap-only
reference within the same design. A 100% cap is a hard ceiling equal to
baseline emissions and is distinct from no cap.
_Avoid_: Selected representative price-cap combinations

**Lexicographic emissions baseline**:
The reproducible no-tax/no-cap emissions reference used for carbon-cap
percentages. It is solved as a single CPLEX native lexicographic objective,
`staticLex(TotalCostCS, Emis)`, so economic cost is optimized first and
supplier-side emissions act as the internal tie-break objective.
_Avoid_: Arbitrary zero-price solver incumbent, minimum-emissions solution
regardless of cost, or emulating the tie-break with a separate emissions solve
constrained by a tight `TotalCostCS <= optimum + epsilon` cost band

**Infeasible policy scenario**:
A tested carbon-price-and-cap combination for which no solution satisfies all
model constraints. It is a reported experimental outcome that identifies the
achievable emissions frontier.
_Avoid_: Failed experiment, omitted scenario, automatically relaxed cap

**Comparison-admissible solution**:
A proven-optimal solution or a time-limited feasible incumbent with a final
optimality gap no greater than 1%. It may support behavioral comparisons;
larger-gap incumbents remain reported but do not support conclusions.
_Avoid_: Treating every feasible incumbent as conclusive, omitting larger-gap incumbents

**Near-optimal decision stability / degeneracy diagnostic**:
The extent to which buffer positions, supplier selections, and allocation
quantities remain similar across alternative solutions whose objective value is
no more than 1% above a proven optimum. Reported through continuous similarity
and deviation metrics, not through stable/unstable classes. Run on
representative policy anchors as a decision-degeneracy diagnostic, not as an
exhaustive second scenario sweep or a robustness test.
_Avoid_: Inferring identical decisions from a small objective gap alone; adding
binary stability labels without a defended industrial threshold; implying every
policy scenario was stability-probed when only representative anchors were used;
calling this a robustness test

**Flow-protection intent**:
The DDMRP-based rationale for strategically positioning buffers to protect
material flow under sourcing and environmental constraints.
_Avoid_: Demonstrated resilience, proven disruption resistance

**Deterministic cross-scenario robustness**:
Consistent model behavior across controlled deterministic variations in BOM
size, topology, service-time targets, and carbon-policy settings.
_Avoid_: Resilience under uncertainty, stochastic robustness

**Simulation scenarios**:
Controlled deterministic optimization scenarios used to compare policy settings,
BOM structures, service-time targets, and carbon-price/cap values.
_Avoid_: Monte Carlo simulation, dynamic execution simulation, stochastic demand
or disruption experiments

**Empirically validated linearized formulation**:
The explicit mixed-integer linear formulation whose objective values match the
nonlinear formulation within solver tolerance on jointly optimal test pairs.
_Avoid_: Proven equivalent formulation, universally optimal pseudo-linearization

**Deployment preflight gate**:
The lightweight validation run before launching the full campaign:
`php tests/DeploymentPreflightTest.php`. It checks that the implementation,
configuration, article language, and reporting scripts still respect the
context-critical decisions on carbon-price units, scenario sets, full-factorial
hybrid design, decision-degeneracy probes, comparison-admissible filtering, and
terminology. The final campaign CLI runs it automatically; bypass it only with
the explicit debugging override `--skip-preflight` or `PHPAUTO_SKIP_PREFLIGHT=1`.
_Avoid_: Launching the full campaign after editing models or reporting code
without rerunning the preflight; silently bypassing the preflight in normal runs

**Campaign dry run**:
The non-solver deployment check `php src/FinalCampaignRunner.php --dry-run`.
It runs the preflight, prints planned reported rows, estimated solver calls,
baseline prerequisites, and maximum conditional decision-degeneracy probes,
then exits before creating result directories or calling CPLEX.
_Avoid_: Discovering scenario-count explosions only after launching the solver
campaign

**Campaign plan artifact**:
The saved planned-run design written before solver execution as
`campaign_plan.md`, `campaign_plan.json`, and `run_manifest.json` in the final
campaign results directory. It preserves planned counts, expected run IDs,
maximum solver-call estimates, baseline coverage, and warnings alongside the
realized results.
_Avoid_: Relying on console dry-run output as the only record of the launched
scenario design

**Post-run validation gate**:
The results-folder validation written after output generation as
`post_run_validation.md` and `post_run_validation.json`. It compares realized
consolidated rows, experiment table rows, Pareto rows, runner solver-call
counts, realized run IDs, and decision-degeneracy diagnostic files against
`campaign_plan.json` and `run_manifest.json`.
_Avoid_: Treating a completed solver process as publication-ready without
checking that expected output files and row counts were actually produced

**Numerically conditioned non-binding bounds**:
Inactive emissions caps and epsilon-constraint bounds must be finite,
instance-scaled values derived from BOM and supplier data. The multi-objective
PLM keeps `cplex.reduce=0`, but this is not sufficient by itself: huge sentinel
right-hand sides can still create non-monotone feasibility artifacts. These
bounds are not a substitute for the native static-lex baseline; they condition
inactive policy and Pareto constraints outside that baseline tie-break.
_Avoid_: Using many-order sentinels such as 1e30 for inactive caps or epsilons
