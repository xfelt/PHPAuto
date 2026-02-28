/**
 * Game state and state helpers
 */
import { BOM_SCENARIOS, SUPPLIER_LIBRARY } from "./data.js";

export const state = {
  scenarioIndex: 0,
  selectedNodeId: null,
  supplierAssignments: {},
  buffers: {},
  netFlowInputs: {},
  finalDemand: 1000,
  periodDays: 30,
  history: {
    cost: { current: [], opt: [] },
    emissions: { current: [], opt: [] }
  },
  lastTotals: null,
  lastScore: 0,
  optimized: null,
  roundActive: false,
  animFrames: {},
  modalHandlers: { primary: null, secondary: null },
  tipFlags: {
    firstBufferChange: false,
    firstSupplierChoice: false,
    flowImproved: false,
    inventorySpike: false
  },
  bomRefs: {},
  advisor: { enabled: true, timer: 0 },
  layout: { userInspectorChoice: false, inspectorCollapsed: false },
  ui: {
    nodeSearchQuery: "",
    zoomLevel: 1,
    panX: 0,
    panY: 0,
    bomDragStart: null,
    bomDidPan: false
  }
};

export const getScenario = () => BOM_SCENARIOS[state.scenarioIndex];

export const getNodesById = () => {
  const map = {};
  getScenario().nodes.forEach((n) => { map[n.id] = n; });
  return map;
};

export const getSuppliersById = () => {
  const ids = new Set(getScenario().supplierIds);
  const map = {};
  SUPPLIER_LIBRARY.forEach((s) => { if (ids.has(s.id)) map[s.id] = s; });
  return map;
};

export const getPurchasedNodes = () => getScenario().nodes.filter((n) => n.type === "PURCHASED");

export const isPurchasedSelected = () => {
  const nodes = getNodesById();
  return !!state.selectedNodeId && nodes[state.selectedNodeId]?.type === "PURCHASED";
};

export const nodeMatchesQuery = (node, query) => {
  const q = query.trim().toLowerCase();
  if (!q) return false;
  return node.name.toLowerCase().includes(q) || node.id.toLowerCase().includes(q);
};

export const graphChildren = () => {
  const children = {};
  getScenario().nodes.forEach((n) => { children[n.id] = []; });
  getScenario().edges.forEach((e) => { children[e.parent].push(e); });
  return children;
};

export const buildWorkingConfig = (override = null) => {
  if (override) return override;
  return {
    supplierAssignments: state.supplierAssignments,
    buffers: state.buffers,
    netFlowInputs: state.netFlowInputs,
    finalDemand: state.finalDemand,
    periodDays: state.periodDays
  };
};

export const cloneConfig = (config) => ({
  supplierAssignments: Object.fromEntries(Object.entries(config.supplierAssignments || {}).map(([k, v]) => [k, [...(v || [])]])),
  buffers: Object.fromEntries(Object.entries(config.buffers || {}).map(([k, v]) => [k, { ...(v || {}) }])),
  netFlowInputs: Object.fromEntries(Object.entries(config.netFlowInputs || {}).map(([k, v]) => [k, { ...(v || {}) }])),
  finalDemand: config.finalDemand ?? 1000,
  periodDays: config.periodDays ?? 30
});

export const cloneCurrentConfig = () => cloneConfig({
  supplierAssignments: state.supplierAssignments,
  buffers: state.buffers,
  netFlowInputs: state.netFlowInputs,
  finalDemand: state.finalDemand,
  periodDays: state.periodDays
});
