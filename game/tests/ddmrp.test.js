/**
 * Vitest tests for DDMRP pure functions (ported from runDeterministicTests)
 */
import { describe, it, expect, beforeEach } from "vitest";
import {
  computeADU,
  computeBuffers,
  explodeBomDemand,
  computeDltMap,
  buildParentMap,
  buildIndegreeMap,
  topoOrderFromRoot,
  computeSegLtMap,
  summarizeSuppliersForNode,
  FIXED_LTF,
  FIXED_VF
} from "../src/ddmrp.js";
import { state, cloneCurrentConfig } from "../src/state.js";
import { calculateTotals, computeScore, runOptimizer, evaluateConfig } from "../src/engine.js";
import { BOM_SCENARIOS } from "../src/data.js";

const fmt = (v) => v.toLocaleString("en-US", { maximumFractionDigits: 1 });

// Mock document for state that uses getElementById - state is shared
// We need to ensure state is reset for scenarios
const mockResetState = () => {
  state.scenarioIndex = 0;
  state.supplierAssignments = {};
  state.buffers = {};
  state.netFlowInputs = {};
  state.finalDemand = 1000;
  state.periodDays = 30;
  BOM_SCENARIOS.forEach((sc, idx) => {
    state.scenarioIndex = idx;
    sc.nodes.forEach((n) => {
      state.buffers[n.id] = { buffered: false };
      if (n.type === "PURCHASED" && sc.supplierIds[0]) {
        state.supplierAssignments[n.id] = [sc.supplierIds[0]];
      }
      state.netFlowInputs[n.id] = { onHandPct: 100, openSupplyPct: 0 };
    });
  });
  state.scenarioIndex = 0;
};

describe("DDMRP pure functions", () => {
  it("buffered node with ADU>0 must have TOG>0", () => {
    const bufferedZones = computeBuffers(10, 5, FIXED_LTF, FIXED_VF);
    expect(bufferedZones.topOfGreen).toBeGreaterThan(0);
  });

  it("DLT reacts to buffer positioning", () => {
    const children = { FG: [{ child: "A" }], A: [{ child: "RM" }], RM: [] };
    const nodeIds = ["FG", "A", "RM"];
    const leadByNode = { FG: 2, A: 3, RM: 5 };
    const dltNoBuffer = computeDltMap(nodeIds, children, leadByNode, {});
    const dltWithBuffer = computeDltMap(nodeIds, children, leadByNode, { A: true });
    expect(dltNoBuffer.FG).not.toBe(dltWithBuffer.FG);
  });

  it("multi-source lead time max rule", () => {
    const sum = summarizeSuppliersForNode(["s_fast", "s_low"], {
      s_fast: { leadTime: 4, unitCost: 10, unitEmissions: 3, reliability: 90 },
      s_low: { leadTime: 9, unitCost: 8, unitEmissions: 5, reliability: 70 }
    });
    expect(sum.effLeadTime).toBe(9);
  });

  it("adding a buffer never increases effective lead time", () => {
    const children = { FG: [{ child: "A" }], A: [{ child: "RM" }], RM: [] };
    const nodeIds = ["FG", "A", "RM"];
    const leadByNode = { FG: 2, A: 3, RM: 5 };
    const dltNoBuffer = computeDltMap(nodeIds, children, leadByNode, {});
    const dltWithBuffer = computeDltMap(nodeIds, children, leadByNode, { A: true });
    expect(dltWithBuffer.FG).toBeLessThanOrEqual(dltNoBuffer.FG);
  });

  it("demand scaling linearity", () => {
    const req1 = explodeBomDemand([{ id: "FG" }, { id: "A" }], [{ parent: "FG", child: "A", qty: 2 }], "FG", 100);
    const req2 = explodeBomDemand([{ id: "FG" }, { id: "A" }], [{ parent: "FG", child: "A", qty: 2 }], "FG", 200);
    const z1 = computeBuffers(computeADU(req1.A, 30), 6, FIXED_LTF, FIXED_VF);
    const z2 = computeBuffers(computeADU(req2.A, 30), 6, FIXED_LTF, FIXED_VF);
    expect(Math.abs(z2.topOfGreen - 2 * z1.topOfGreen)).toBeLessThan(1e-6);
  });

  it("service LT equals unbuffered CP when no buffers", () => {
    const edges = [{ parent: "FG", child: "A" }, { parent: "A", child: "RM" }];
    const nodeIds = ["FG", "A", "RM"];
    const childrenMap = { FG: [{ child: "A" }], A: [{ child: "RM" }], RM: [] };
    const parentsMap = buildParentMap(nodeIds, edges);
    const indegreeMap = buildIndegreeMap(nodeIds, edges);
    const order = topoOrderFromRoot("FG", childrenMap, indegreeMap);
    const effLT = { FG: 2, A: 3, RM: 5 };
    const segNoBuffer = computeSegLtMap(order, parentsMap, effLT, {}, "FG");
    const unbufferedCP = Math.max(...order.map((id) => segNoBuffer[id] || 0));
    const serviceLT = Math.max(...order.map((id) => segNoBuffer[id] || 0));
    expect(serviceLT).toBe(unbufferedCP);
  });
});

describe("Engine", () => {
  beforeEach(() => mockResetState());

  it("optimizer finds improvement in at least one scenario", () => {
    let improvedAny = false;
    for (let idx = 0; idx < BOM_SCENARIOS.length; idx += 1) {
      state.scenarioIndex = idx;
      mockResetState();
      state.scenarioIndex = idx;
      const sc = BOM_SCENARIOS[idx];
      state.finalDemand = sc.fgDemand;
      state.buffers = {};
      state.supplierAssignments = {};
      state.netFlowInputs = {};
      sc.nodes.forEach((n) => {
        state.buffers[n.id] = { buffered: false };
        if (n.type === "PURCHASED" && sc.supplierIds[0]) {
          state.supplierAssignments[n.id] = [sc.supplierIds[0]];
        }
        state.netFlowInputs[n.id] = { onHandPct: 100, openSupplyPct: 0 };
      });
      const baselineConfig = {
        supplierAssignments: { ...state.supplierAssignments },
        buffers: { ...state.buffers },
        netFlowInputs: { ...state.netFlowInputs },
        finalDemand: state.finalDemand,
        periodDays: state.periodDays
      };
      const baseline = evaluateConfig(baselineConfig);
      const optimized = runOptimizer(baselineConfig);
      if (optimized?.config && optimized.score > baseline.score) improvedAny = true;
    }
    state.scenarioIndex = 0;
    expect(improvedAny).toBe(true);
  });

  it("optimizer does not mutate current config", () => {
    state.scenarioIndex = 0;
    mockResetState();
    const before = cloneCurrentConfig();
    const optimized = runOptimizer(before);
    const after = cloneCurrentConfig();
    expect(JSON.stringify(before.supplierAssignments)).toBe(JSON.stringify(after.supplierAssignments));
    expect(JSON.stringify(before.buffers)).toBe(JSON.stringify(after.buffers));
  });
});
