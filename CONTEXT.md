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
The reference solution obtained by first minimizing economic cost without a
carbon price or emissions cap, then minimizing emissions among solutions that
retain that optimal economic cost.
_Avoid_: Arbitrary zero-price solver incumbent, minimum-emissions solution regardless of cost

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

**Flow-protection intent**:
The DDMRP-based rationale for strategically positioning buffers to protect
material flow under sourcing and environmental constraints.
_Avoid_: Demonstrated resilience, proven disruption resistance

**Deterministic cross-scenario robustness**:
Consistent model behavior across controlled deterministic variations in BOM
size, topology, service-time targets, and carbon-policy settings.
_Avoid_: Resilience under uncertainty, stochastic robustness

**Empirically validated linearized formulation**:
The explicit mixed-integer linear formulation whose objective values match the
nonlinear formulation within solver tolerance on jointly optimal test pairs.
_Avoid_: Proven equivalent formulation, universally optimal pseudo-linearization
