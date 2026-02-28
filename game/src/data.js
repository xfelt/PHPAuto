/**
 * Data and constants for DDMRP game
 */

export const FIXED_LTF = 1.0;
export const FIXED_VF = 0.35;
export const INVENTORY_EMISSIONS_FACTOR = 0.2;
export const BOM_BASE_VIEWBOX = { x: 0, y: 0, w: 920, h: 440 };
export const ZOOM_MIN = 0.7;
export const ZOOM_MAX = 2.4;
export const ZOOM_STEP = 0.2;
export const ANIM_DURATION = 700;
export const ADVISOR_DELAY = 350;

export const SUPPLIER_LIBRARY = [
  { id: "s_speed", name: "SpeedElite", unitCost: 16.0, unitEmissions: 4.0, reliability: 95, leadTime: 3, capacity: 100000 },
  { id: "s_budget", name: "BudgetBulk", unitCost: 6.5, unitEmissions: 8.0, reliability: 68, leadTime: 8, capacity: 300000 },
  { id: "s_green", name: "GreenPrime", unitCost: 19.0, unitEmissions: 1.2, reliability: 92, leadTime: 8, capacity: 70000 },
  { id: "s_bal", name: "BalancePro", unitCost: 10.5, unitEmissions: 3.5, reliability: 86, leadTime: 5, capacity: 180000 },
  { id: "s_local", name: "LocalSwift", unitCost: 12.5, unitEmissions: 3.0, reliability: 89, leadTime: 4, capacity: 120000 },
  { id: "s_eco", name: "EcoValue", unitCost: 9.0, unitEmissions: 2.5, reliability: 80, leadTime: 9, capacity: 150000 },
  { id: "s_mega", name: "MegaCorp", unitCost: 7.5, unitEmissions: 6.5, reliability: 73, leadTime: 8, capacity: 250000 },
  { id: "s_prem", name: "PremiumGreen", unitCost: 15.0, unitEmissions: 1.8, reliability: 93, leadTime: 6, capacity: 90000 }
];

export const BOM_SCENARIOS = [
  {
    name: "BOM A (Simple)",
    description: "8-node BOM. Learn decoupling points and supplier trade-offs.",
    fgId: "FG_A",
    fgDemand: 1250,
    targetCost: 165000,
    targetEmissions: 48000,
    targetInventoryEmissions: 4800,
    serviceTarget: 16,
    objective: { cost: 420, emissions: 220, inventoryEmissions: 90, reliability: 2.4, service: 2.2 },
    nodes: [
      { id: "FG_A", name: "City Bike", type: "FG", leadTime: 4, holdingRate: 1.2, internalEmission: 0.9, x: 70, y: 160 },
      { id: "SUB_A1", name: "Frame Assembly", type: "SUB", leadTime: 3, holdingRate: 0.8, internalEmission: 0.5, x: 280, y: 100 },
      { id: "SUB_A2", name: "Power Assembly", type: "SUB", leadTime: 3, holdingRate: 0.85, internalEmission: 0.45, x: 280, y: 230 },
      { id: "P_A4", name: "Aluminum Shell", type: "PURCHASED", leadTime: 5, holdingRate: 0.5, internalEmission: 0, x: 525, y: 60 },
      { id: "P_A3", name: "Battery", type: "PURCHASED", leadTime: 8, holdingRate: 0.7, internalEmission: 0, x: 525, y: 145 },
      { id: "P_A1", name: "Motor", type: "PURCHASED", leadTime: 7, holdingRate: 0.6, internalEmission: 0, x: 525, y: 210 },
      { id: "P_A2", name: "Control Board", type: "PURCHASED", leadTime: 6, holdingRate: 0.55, internalEmission: 0, x: 525, y: 280 },
      { id: "P_A5", name: "Fastener Kit", type: "PURCHASED", leadTime: 4, holdingRate: 0.35, internalEmission: 0, x: 780, y: 160 }
    ],
    edges: [
      { parent: "FG_A", child: "SUB_A1", qty: 1 },
      { parent: "FG_A", child: "SUB_A2", qty: 1 },
      { parent: "FG_A", child: "P_A3", qty: 1 },
      { parent: "FG_A", child: "P_A5", qty: 4 },
      { parent: "SUB_A1", child: "P_A4", qty: 1 },
      { parent: "SUB_A2", child: "P_A1", qty: 1 },
      { parent: "SUB_A2", child: "P_A2", qty: 1 }
    ],
    supplierIds: ["s_speed", "s_budget", "s_bal", "s_local", "s_eco"]
  },
  {
    name: "BOM B (Medium)",
    description: "14-node BOM, 2 levels deep. Position buffers where flow bottlenecks appear.",
    fgId: "FG_B",
    fgDemand: 980,
    targetCost: 220000,
    targetEmissions: 23000,
    targetInventoryEmissions: 6200,
    serviceTarget: 18,
    objective: { cost: 250, emissions: 390, inventoryEmissions: 110, reliability: 2.2, service: 2.1 },
    nodes: [
      { id: "FG_B", name: "Smart Pump", type: "FG", leadTime: 4, holdingRate: 1.3, internalEmission: 1.1, x: 60, y: 180 },
      { id: "SUB_B1", name: "Hydraulic Block", type: "SUB", leadTime: 3, holdingRate: 0.9, internalEmission: 0.7, x: 245, y: 60 },
      { id: "SUB_B2", name: "Control Block", type: "SUB", leadTime: 3, holdingRate: 0.95, internalEmission: 0.6, x: 245, y: 180 },
      { id: "SUB_B3", name: "Housing Block", type: "SUB", leadTime: 2, holdingRate: 0.85, internalEmission: 0.45, x: 245, y: 300 },
      { id: "P_B1", name: "Rotor", type: "PURCHASED", leadTime: 8, holdingRate: 0.65, internalEmission: 0, x: 470, y: 15 },
      { id: "P_B2", name: "Seal Kit", type: "PURCHASED", leadTime: 5, holdingRate: 0.4, internalEmission: 0, x: 470, y: 60 },
      { id: "P_B8", name: "Valve", type: "PURCHASED", leadTime: 6, holdingRate: 0.45, internalEmission: 0, x: 470, y: 105 },
      { id: "P_B3", name: "Sensor", type: "PURCHASED", leadTime: 6, holdingRate: 0.45, internalEmission: 0, x: 470, y: 155 },
      { id: "P_B4", name: "Microcontroller", type: "PURCHASED", leadTime: 9, holdingRate: 0.6, internalEmission: 0, x: 470, y: 200 },
      { id: "P_B5", name: "Wiring Loom", type: "PURCHASED", leadTime: 4, holdingRate: 0.35, internalEmission: 0, x: 470, y: 245 },
      { id: "P_B9", name: "Actuator", type: "PURCHASED", leadTime: 8, holdingRate: 0.55, internalEmission: 0, x: 710, y: 200 },
      { id: "P_B6", name: "Casting", type: "PURCHASED", leadTime: 7, holdingRate: 0.5, internalEmission: 0, x: 470, y: 295 },
      { id: "P_B7", name: "Cover Plate", type: "PURCHASED", leadTime: 5, holdingRate: 0.35, internalEmission: 0, x: 470, y: 340 },
      { id: "P_B10", name: "Connector Set", type: "PURCHASED", leadTime: 4, holdingRate: 0.32, internalEmission: 0, x: 710, y: 320 }
    ],
    edges: [
      { parent: "FG_B", child: "SUB_B1", qty: 1 },
      { parent: "FG_B", child: "SUB_B2", qty: 1 },
      { parent: "FG_B", child: "SUB_B3", qty: 1 },
      { parent: "SUB_B1", child: "P_B1", qty: 1 },
      { parent: "SUB_B1", child: "P_B2", qty: 2 },
      { parent: "SUB_B1", child: "P_B8", qty: 2 },
      { parent: "SUB_B2", child: "P_B3", qty: 2 },
      { parent: "SUB_B2", child: "P_B4", qty: 1 },
      { parent: "SUB_B2", child: "P_B5", qty: 1 },
      { parent: "SUB_B2", child: "P_B9", qty: 1 },
      { parent: "SUB_B3", child: "P_B6", qty: 1 },
      { parent: "SUB_B3", child: "P_B7", qty: 1 },
      { parent: "SUB_B3", child: "P_B10", qty: 3 }
    ],
    supplierIds: ["s_speed", "s_green", "s_bal", "s_local", "s_eco", "s_prem"]
  },
  {
    name: "BOM C (Max 20)",
    description: "20-node BOM, 3 levels. Keep responsiveness while controlling inventory cost.",
    fgId: "FG_C",
    fgDemand: 820,
    targetCost: 240000,
    targetEmissions: 33500,
    targetInventoryEmissions: 7600,
    serviceTarget: 22,
    objective: { cost: 330, emissions: 320, inventoryEmissions: 120, reliability: 2.3, service: 2.2 },
    nodes: [
      { id: "FG_C", name: "Autonomous Cart", type: "FG", leadTime: 5, holdingRate: 1.35, internalEmission: 1.3, x: 45, y: 185 },
      { id: "SUB_C1", name: "Drive Train", type: "SUB", leadTime: 3, holdingRate: 0.95, internalEmission: 0.8, x: 200, y: 60 },
      { id: "SUB_C2", name: "Control Core", type: "SUB", leadTime: 4, holdingRate: 1.0, internalEmission: 0.85, x: 200, y: 150 },
      { id: "SUB_C3", name: "Body Frame", type: "SUB", leadTime: 3, holdingRate: 0.9, internalEmission: 0.7, x: 200, y: 240 },
      { id: "SUB_C4", name: "Battery Pack", type: "SUB", leadTime: 3, holdingRate: 0.95, internalEmission: 0.75, x: 200, y: 330 },
      { id: "SUB_C5", name: "Motor Module", type: "SUB", leadTime: 2, holdingRate: 0.75, internalEmission: 0.45, x: 390, y: 25 },
      { id: "SUB_C6", name: "Wheel Module", type: "SUB", leadTime: 2, holdingRate: 0.7, internalEmission: 0.4, x: 390, y: 95 },
      { id: "SUB_C7", name: "PCB Module", type: "SUB", leadTime: 2, holdingRate: 0.75, internalEmission: 0.48, x: 390, y: 165 },
      { id: "SUB_C8", name: "Enclosure Module", type: "SUB", leadTime: 2, holdingRate: 0.7, internalEmission: 0.35, x: 390, y: 245 },
      { id: "P_C1", name: "Copper Coil", type: "PURCHASED", leadTime: 8, holdingRate: 0.58, internalEmission: 0, x: 590, y: 5 },
      { id: "P_C2", name: "Magnet Set", type: "PURCHASED", leadTime: 7, holdingRate: 0.54, internalEmission: 0, x: 590, y: 45 },
      { id: "P_C3", name: "Gear Kit", type: "PURCHASED", leadTime: 6, holdingRate: 0.48, internalEmission: 0, x: 590, y: 85 },
      { id: "P_C4", name: "Wheel Set", type: "PURCHASED", leadTime: 5, holdingRate: 0.45, internalEmission: 0, x: 590, y: 125 },
      { id: "P_C5", name: "Sensor PCB", type: "PURCHASED", leadTime: 8, holdingRate: 0.52, internalEmission: 0, x: 590, y: 165 },
      { id: "P_C6", name: "CPU Board", type: "PURCHASED", leadTime: 9, holdingRate: 0.66, internalEmission: 0, x: 590, y: 205 },
      { id: "P_C7", name: "Aluminum Panel", type: "PURCHASED", leadTime: 6, holdingRate: 0.43, internalEmission: 0, x: 590, y: 245 },
      { id: "P_C8", name: "Polymer Cover", type: "PURCHASED", leadTime: 5, holdingRate: 0.4, internalEmission: 0, x: 590, y: 285 },
      { id: "P_C9", name: "Cell Module", type: "PURCHASED", leadTime: 8, holdingRate: 0.62, internalEmission: 0, x: 430, y: 310 },
      { id: "P_C10", name: "BMS Chip", type: "PURCHASED", leadTime: 9, holdingRate: 0.6, internalEmission: 0, x: 430, y: 350 },
      { id: "P_C11", name: "Harness Set", type: "PURCHASED", leadTime: 4, holdingRate: 0.36, internalEmission: 0, x: 430, y: 390 }
    ],
    edges: [
      { parent: "FG_C", child: "SUB_C1", qty: 1 },
      { parent: "FG_C", child: "SUB_C2", qty: 1 },
      { parent: "FG_C", child: "SUB_C3", qty: 1 },
      { parent: "FG_C", child: "SUB_C4", qty: 1 },
      { parent: "SUB_C1", child: "SUB_C5", qty: 1 },
      { parent: "SUB_C1", child: "SUB_C6", qty: 2 },
      { parent: "SUB_C2", child: "SUB_C7", qty: 1 },
      { parent: "SUB_C3", child: "SUB_C8", qty: 1 },
      { parent: "SUB_C5", child: "P_C1", qty: 1 },
      { parent: "SUB_C5", child: "P_C2", qty: 2 },
      { parent: "SUB_C5", child: "P_C3", qty: 1 },
      { parent: "SUB_C6", child: "P_C4", qty: 2 },
      { parent: "SUB_C7", child: "P_C5", qty: 2 },
      { parent: "SUB_C7", child: "P_C6", qty: 1 },
      { parent: "SUB_C8", child: "P_C7", qty: 2 },
      { parent: "SUB_C8", child: "P_C8", qty: 2 },
      { parent: "SUB_C4", child: "P_C9", qty: 4 },
      { parent: "SUB_C4", child: "P_C10", qty: 1 },
      { parent: "SUB_C4", child: "P_C11", qty: 2 }
    ],
    supplierIds: ["s_speed", "s_budget", "s_green", "s_bal", "s_local", "s_eco", "s_mega", "s_prem"]
  }
];
