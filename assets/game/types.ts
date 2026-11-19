export type ClassCode = 'TANK' | 'DPS' | 'HEAL';

export interface ClassMeta {
  code: ClassCode;
  label: string;
  role: string;
  description: string;
  color: string;
  threatMultiplier: number;
  resourceType?: 'RAGE' | 'ENERGY' | 'MANA';
  resourceLabel?: string;
  resourceShort?: string;
  resourceColor?: string;
  baseStats: Record<string, number>;
  levelGrowth: Record<string, number>;
}

export interface SkillData {
  code: string;
  name: string;
  description: string;
  classCode: ClassCode;
  slot: number;
  unlockLevel: number;
  targetType: 'ENEMY' | 'ALLY' | 'SELF' | 'SELF_AOE' | 'ENEMY_AOE' | 'PARTY_AOE';
  manaCost: number;
  cooldown: number;
  range: number;
  aoeRadius: number;
  effectType: string | null;
  icon: string;
  vfxColor: string;
  vfxStyle: string;
}

export interface ZoneMeta {
  code: string;
  name: string;
  subtitle: string;
  minLevel: number;
  maxLevel: number;
  centerX: number;
  centerZ: number;
  respawnX: number;
  respawnZ: number;
  fogColor: string;
  skyColor: string;
  ambientColor: string;
}

export interface LandmarkMeta {
  name: string;
  x: number;
  z: number;
  type: string;
}

export interface NpcData {
  code: string;
  name: string;
  title: string;
  greeting: string;
  zoneCode: string;
  x: number;
  y: number;
  z: number;
  rotY: number;
  coatColor: string;
  accentColor: string;
}

export interface QuestDefinitionData {
  code: string;
  order: number;
  title: string;
  description: string;
  completionText: string;
  giverNpcCode: string;
  turnInNpcCode: string;
  minLevel: number;
  recommendedLevel: number;
  prerequisiteQuestCode: string | null;
  objectiveType: 'KILL_MONSTER' | 'REACH_ZONE';
  targetCode: string;
  targetLabel: string;
  targetCount: number;
  guideX: number;
  guideZ: number;
  rewardXp: number;
  rewardStatPoints: number;
}

export interface ActiveQuestState {
  code: string;
  title: string;
  status: 'ACTIVE' | 'READY';
  progress: number;
  targetCount: number;
  targetLabel: string;
  turnInNpcCode: string;
  rewardXp: number;
  rewardStatPoints: number;
}

export interface QuestGuidance {
  mode: 'TURN_IN' | 'OBJECTIVE' | 'TALK_GIVER' | 'LEVEL_UP';
  title: string;
  text: string;
  x: number;
  z: number;
  zoneCode: string;
}

export type NpcMarkerState = 'AVAILABLE' | 'TURN_IN' | 'IN_PROGRESS' | 'NONE';

export interface QuestStatePayload {
  active: ActiveQuestState[];
  availableCodes: string[];
  completedCodes: string[];
  npcMarkers: Record<string, NpcMarkerState>;
  guidance: QuestGuidance | null;
  totalQuests: number;
}

export type ItemRarity = 'COMMON' | 'UNCOMMON' | 'RARE' | 'EPIC' | 'LEGENDARY';
export type EquipmentSlot = 'WEAPON' | 'ARMOR' | 'HELM' | 'ACCESSORY';

export interface ItemDefinitionData {
  code: string;
  name: string;
  description: string;
  type: 'EQUIPMENT' | 'CONSUMABLE' | 'JUNK';
  slot: EquipmentSlot | null;
  rarity: ItemRarity;
  minLevel: number;
  icon: string;
  sellPrice: number;
  buyPrice: number;
  stackable: boolean;
  bonuses: Partial<Record<'vitality' | 'strength' | 'intelligence' | 'defense' | 'spirit', number>>;
  restoreHp: number;
  restoreMp: number;
}

export interface CharacterItemState {
  id: number;
  itemCode: string;
  quantity: number;
  equippedSlot: EquipmentSlot | null;
}

export interface InventoryStatePayload {
  gold: number;
  maxBagSlots: number;
  bagSlotsUsed: number;
  equipped: Record<EquipmentSlot, CharacterItemState | null>;
  bag: CharacterItemState[];
}

export interface WorldLootDropState {
  id: number;
  zoneCode: string;
  sourceName: string;
  x: number;
  y: number;
  z: number;
  gold: number;
  items: { itemCode: string; quantity: number }[];
  highestRarity: ItemRarity;
  canLoot: boolean;
}

export interface ManifestResponse {
  gameTitle: string;
  world: {
    mapResolution: number;
    worldSize: number;
    maxElevation: number;
    seaLevel: number;
    walkableMinY: number;
    maxWalkableSlope: number;
    heightmapUrl: string;
    biomesUrl: string;
    minimapUrl: string;
  };
  zones: Record<string, ZoneMeta>;
  landmarks: LandmarkMeta[];
  npcs: NpcData[];
  quests: Record<string, QuestDefinitionData>;
  items: Record<string, ItemDefinitionData>;
  vendorStocks: Record<string, string[]>;
  classes: Record<ClassCode, ClassMeta>;
  skills: Record<string, SkillData>;
  gcd: number;
}

export interface ActiveEffectView {
  type: string;
  skillCode: string;
  remaining: number;
}

export interface SelfState {
  id: number;
  name: string;
  classCode: ClassCode;
  level: number;
  xp: number;
  xpNext: number;
  statPoints: number;
  gold: number;
  resourceType?: 'RAGE' | 'ENERGY' | 'MANA';
  resourceLabel?: string;
  resourceShort?: string;
  resourceColor?: string;
  autoAttackCooldown?: number;
  baseAttributes?: {
    vitality: number;
    strength: number;
    intelligence: number;
    defense: number;
    spirit: number;
  };
  equipmentBonuses?: {
    vitality: number;
    strength: number;
    intelligence: number;
    defense: number;
    spirit: number;
  };
  attributes: {
    vitality: number;
    strength: number;
    intelligence: number;
    defense: number;
    spirit: number;
  };
  derived: {
    maxHp: number;
    maxMp: number;
    attackPower: number;
    spellPower: number;
    damageReduction: number;
    critChance: number;
    hpRegenPerSec: number;
    mpRegenPerSec: number;
    activeDamageReductionBuff: number;
  };
  hp: number;
  maxHp: number;
  mp: number;
  maxMp: number;
  isDead: boolean;
  zoneCode: string;
  x: number;
  y: number;
  z: number;
  rotY: number;
  gcdRemaining: number;
  cooldowns: Record<string, number>;
  effects: ActiveEffectView[];
  partyId: number | null;
  pendingPartyInviteId: number | null;
}

export interface PublicPlayer {
  id: number;
  name: string;
  classCode: ClassCode;
  level: number;
  hp: number;
  maxHp: number;
  isDead: boolean;
  isMoving: boolean;
  zoneCode: string;
  x: number;
  y: number;
  z: number;
  rotY: number;
  partyId: number | null;
}

export interface MonsterState {
  id: number;
  code: string;
  name: string;
  level: number;
  hp: number;
  maxHp: number;
  state: 'IDLE' | 'COMBAT' | 'RETURNING' | 'DEAD';
  zoneCode: string;
  x: number;
  y: number;
  z: number;
  rotY: number;
  meshKey: string;
  primaryColor: string;
  scale: number;
  isBoss: boolean;
  isWorldBoss?: boolean;
  phase?: number;
  participantCount?: number;
  carapaceActive?: boolean;
  respawnRemaining?: number;
  aggressive: boolean;
  targetCharacterId: number | null;
  effects: ActiveEffectView[];
}

export interface WorldBossSummary {
  id: number;
  code: string;
  name: string;
  level: number;
  zoneCode: string;
  x: number;
  z: number;
  hp: number;
  maxHp: number;
  state: 'IDLE' | 'COMBAT' | 'RETURNING' | 'DEAD';
  phase: number;
  activeEngaged: number;
  participantCount: number;
  carapaceActive: boolean;
  recommendedPlayers: number;
  respawnRemaining: number;
}

export interface CombatEventData {
  id: number;
  zoneCode: string;
  eventType: string;
  sourceType: 'PLAYER' | 'MONSTER';
  sourceId: number;
  sourceName: string;
  targetType: 'PLAYER' | 'MONSTER';
  targetId: number;
  targetName: string;
  skillCode: string;
  amount: number;
  isCrit: boolean;
  x: number;
  y: number;
  z: number;
  createdAt: number;
}

export interface ChatMessageData {
  id: number;
  channel: 'GLOBAL' | 'ZONE' | 'PARTY' | 'SYSTEM';
  zoneCode: string | null;
  senderName: string;
  senderClass: string;
  content: string;
  createdAt: number;
}

export interface PartyMemberState {
  id: number;
  name: string;
  classCode: ClassCode;
  level: number;
  hp: number;
  maxHp: number;
  mp: number;
  maxMp: number;
  isDead: boolean;
  zoneCode: string;
  online: boolean;
}

export interface PartyState {
  id: number;
  name: string;
  leaderId: number;
  members: PartyMemberState[];
}

export interface SyncResponse {
  serverTime: number;
  correctedPosition: boolean;
  self: SelfState;
  zone: ZoneMeta;
  players: PublicPlayer[];
  monsters: MonsterState[];
  events: CombatEventData[];
  chat: ChatMessageData[];
  party: PartyState | null;
  quests: QuestStatePayload;
  inventory: InventoryStatePayload;
  lootDrops: WorldLootDropState[];
  worldBoss?: WorldBossSummary | null;
  pendingInvite: { partyId: number; label: string } | null;
}
