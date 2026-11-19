import * as THREE from 'three';
import {
  animateAvatar,
  applyAvatarEquipment,
  createLootDropMesh,
  createMonsterMesh,
  createNpcMesh,
  createPlayerAvatar,
  triggerAvatarSkill,
  type AnimatedAvatar,
  type LootVisual,
  type MonsterVisual,
  type NpcVisual,
} from './EntityMeshFactory';
import type { EquippedSlotVisual } from './ClassEquipmentBuilder';
import { Minimap } from './Minimap';
import { NetworkClient } from './NetworkClient';
import { PlayerController } from './PlayerController';
import { ReunionWorldRenderer } from './ReunionWorldRenderer';
import { TerrainSampler } from './TerrainSampler';
import type {
  ChatMessageData,
  ClassCode,
  CombatEventData,
  EquipmentSlot,
  InventoryStatePayload,
  ItemDefinitionData,
  ItemRarity,
  ManifestResponse,
  MonsterState,
  NpcData,
  PartyState,
  PublicPlayer,
  QuestStatePayload,
  SelfState,
  SkillData,
  SyncResponse,
  WorldBossSummary,
  WorldLootDropState,
} from './types';
import { VfxManager } from './VfxManager';

interface RemotePlayerEntry {
  state: PublicPlayer;
  avatar: AnimatedAvatar;
  targetPos: THREE.Vector3;
  nameplate: HTMLDivElement;
}

interface MonsterEntry {
  state: MonsterState;
  visual: MonsterVisual;
  targetPos: THREE.Vector3;
  nameplate: HTMLDivElement;
}

interface NpcEntry {
  data: NpcData;
  visual: NpcVisual;
  nameplate: HTMLDivElement;
}

interface LootEntry {
  state: WorldLootDropState;
  visual: LootVisual;
  nameplate: HTMLDivElement;
}

const RARITY_COLORS: Record<ItemRarity, string> = {
  COMMON: '#e2e8f0',
  UNCOMMON: '#22c55e',
  RARE: '#38bdf8',
  EPIC: '#c084fc',
  LEGENDARY: '#fb923c',
};

const SLOT_LABELS: Record<EquipmentSlot, string> = {
  WEAPON: '⚔️ Arme',
  ARMOR: '🛡️ Armure',
  HELM: '🪖 Coiffe',
  ACCESSORY: '💠 Talisman',
};

class SymfonyForeverGame {
  private readonly canvas: HTMLCanvasElement;
  private readonly floatingLayer: HTMLElement;
  private readonly characterId: number;
  private readonly classCode: ClassCode;
  private readonly net = new NetworkClient();

  private manifest!: ManifestResponse;
  private sampler!: TerrainSampler;
  private scene!: THREE.Scene;
  private camera!: THREE.PerspectiveCamera;
  private renderer!: THREE.WebGLRenderer;
  private worldRenderer!: ReunionWorldRenderer;
  private controller!: PlayerController;
  private vfx!: VfxManager;
  private minimap!: Minimap;

  private selfAvatar!: AnimatedAvatar;
  private selfState: SelfState | null = null;
  private partyState: PartyState | null = null;
  private questState: QuestStatePayload | null = null;
  private inventoryState: InventoryStatePayload | null = null;
  private worldBossState: WorldBossSummary | null = null;
  private classSkills: SkillData[] = [];

  private readonly remotePlayers = new Map<number, RemotePlayerEntry>();
  private readonly monsters = new Map<number, MonsterEntry>();
  private readonly npcs = new Map<string, NpcEntry>();
  private readonly lootDrops = new Map<number, LootEntry>();
  private readonly seenEventIds = new Set<number>();
  private readonly notifiedReadyQuests = new Set<string>();
  private readonly skillWasOnCd = new Map<string, boolean>();
  private readonly skillPressTimers = new Map<string | number, number>();
  private readonly skillFlashTimers = new Map<string | number, number>();
  private autoAttackInFlight = false;
  private localAaCooldownUntil = 0;
  private lastQuestSignature = '';
  private lastInventorySignature = '';

  private lastEventId = 0;
  private lastChatId = 0;
  private currentZoneCode = '';
  private targetType: 'MONSTER' | 'PLAYER' | null = null;
  private targetId: number | null = null;

  private activeChatTab: 'ZONE' | 'GLOBAL' | 'PARTY' | 'COMBAT' = 'ZONE';
  private readonly chatHistory: { channel: string; html: string }[] = [];
  private charModalOpen = false;
  private bagModalOpen = false;
  private activeNpcModalCode: string | null = null;
  private activeNpcModalTab: 'QUESTS' | 'VENDOR' = 'QUESTS';
  private nearestNpcCode: string | null = null;
  private nearestLootId: number | null = null;
  private toastTimeout: number | null = null;
  private bannerTimeout: number | null = null;

  private readonly raycaster = new THREE.Raycaster();
  private readonly pointerNdc = new THREE.Vector2();
  private readonly projVec = new THREE.Vector3();

  public constructor() {
    const canvas = document.getElementById('game-viewport') as HTMLCanvasElement | null;
    const floating = document.getElementById('floating-layer');
    if (!canvas || !floating) throw new Error('DOM jeu manquant');

    this.canvas = canvas;
    this.floatingLayer = floating;
    this.characterId = Number(canvas.dataset.characterId ?? 0);
    this.classCode = (canvas.dataset.classCode as ClassCode) || 'TANK';
  }

  public async boot(): Promise<void> {
    const statusEl = document.getElementById('loading-status');
    const setStatus = (msg: string) => {
      if (statusEl) statusEl.textContent = msg;
    };

    try {
      setStatus('Récupération du manifeste Symfony...');
      this.manifest = await this.net.fetchManifest();

      this.classSkills = Object.values(this.manifest.skills)
        .filter((s) => s.classCode === this.classCode)
        .sort((a, b) => a.slot - b.slot);

      setStatus('Chargement de la heightmap 16-bit de La Réunion...');
      this.sampler = await TerrainSampler.load(this.manifest.world);

      setStatus('Construction du relief 3D (Cirques, Piton des Neiges, Fournaise)...');
      this.initThree();
      this.worldRenderer = new ReunionWorldRenderer(this.scene, this.sampler, this.manifest.landmarks);
      this.vfx = new VfxManager(this.scene, this.floatingLayer);
      this.controller = new PlayerController(
        this.canvas,
        this.sampler,
        this.manifest.world.walkSpeed ?? 6.0,
        this.manifest.world.sprintSpeed ?? 11.5,
      );

      this.selfAvatar = createPlayerAvatar(this.classCode);
      this.scene.add(this.selfAvatar.group);
      this.spawnNpcs();

      const minimapCanvas = document.getElementById('minimap-canvas') as HTMLCanvasElement;
      this.minimap = new Minimap(
        minimapCanvas,
        this.manifest.world.worldSize,
        this.manifest.landmarks,
        this.manifest.world.minimapUrl,
      );

      this.setupHudBindings();
      this.renderSkillButtonsStructure();

      setStatus('Synchronisation HTTP avec le serveur Symfony...');
      const initialOutcome = await this.net.syncWorld({
        characterId: this.characterId,
        x: this.controller.x,
        z: this.controller.z,
        rotY: 0,
        isMoving: false,
        targetType: null,
        targetId: null,
        lastEventId: 0,
        lastChatId: 0,
      });

      if (initialOutcome.status === 'auth_expired') {
        window.location.href = '/login';
        return;
      }
      if (initialOutcome.status === 'ok') {
        this.controller.teleport(initialOutcome.data.self.x, initialOutcome.data.self.y, initialOutcome.data.self.z);
        this.applySync(initialOutcome.data, true);
      }

      document.getElementById('loading-screen')?.remove();
      this.startLoops();
    } catch (err) {
      setStatus(`Erreur au démarrage : ${err instanceof Error ? err.message : String(err)}`);
    }
  }

  private initThree(): void {
    this.scene = new THREE.Scene();
    this.camera = new THREE.PerspectiveCamera(58, window.innerWidth / window.innerHeight, 0.5, 3200);
    this.renderer = new THREE.WebGLRenderer({ canvas: this.canvas, antialias: true, powerPreference: 'high-performance' });
    this.renderer.setSize(window.innerWidth, window.innerHeight);
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
    this.renderer.toneMappingExposure = 1.18;

    window.addEventListener('resize', () => {
      this.camera.aspect = window.innerWidth / window.innerHeight;
      this.camera.updateProjectionMatrix();
      this.renderer.setSize(window.innerWidth, window.innerHeight);
    });
  }

  private startLoops(): void {
    // 1. Unified HTTP Sync heartbeat every 350ms (~3 Hz)
    window.setInterval(() => {
      void this.performSync();
    }, 350);

    // 2. Smooth 60 FPS Three.js render & interpolation loop
    const clock = new THREE.Clock();
    const tick = () => {
      requestAnimationFrame(tick);
      const dt = Math.min(0.08, clock.getDelta());
      const elapsed = clock.elapsedTime;

      const isDead = this.selfState?.isDead ?? false;
      this.controller.update(dt, isDead, this.camera);

      // Update local player avatar
      this.selfAvatar.group.position.set(this.controller.x, this.controller.y, this.controller.z);
      this.selfAvatar.group.rotation.y = this.controller.rotY;
      this.selfAvatar.targetRing.visible = this.targetType === 'PLAYER' && this.targetId === this.characterId;
      animateAvatar(this.selfAvatar, dt, this.controller.isMoving, isDead, elapsed, this.controller.lastDistanceMoved);

      // Interpolate remote players
      for (const [id, entry] of this.remotePlayers) {
        const prevRx = entry.avatar.group.position.x;
        const prevRz = entry.avatar.group.position.z;
        entry.avatar.group.position.lerp(entry.targetPos, Math.min(1, dt * 8));
        const remoteDist = Math.hypot(
          entry.avatar.group.position.x - prevRx,
          entry.avatar.group.position.z - prevRz,
        );
        entry.avatar.group.rotation.y = this.lerpAngle(entry.avatar.group.rotation.y, entry.state.rotY, Math.min(1, dt * 9));
        entry.avatar.targetRing.visible = this.targetType === 'PLAYER' && this.targetId === id;
        animateAvatar(entry.avatar, dt, entry.state.isMoving || remoteDist > 0.004, entry.state.isDead, elapsed + id, remoteDist);
        this.updateNameplate(
          entry.nameplate,
          entry.avatar.group.position,
          3.2,
          entry.state.hp,
          entry.state.maxHp,
          !entry.state.isDead,
        );
      }

      // Interpolate monsters
      for (const [id, entry] of this.monsters) {
        const alive = entry.state.state !== 'DEAD';
        entry.visual.group.visible = alive;
        if (!alive) {
          entry.nameplate.style.display = 'none';
          continue;
        }
        entry.visual.group.position.lerp(entry.targetPos, Math.min(1, dt * 6.5));
        entry.visual.group.rotation.y = this.lerpAngle(entry.visual.group.rotation.y, entry.state.rotY, Math.min(1, dt * 7));
        entry.visual.body.position.y = Math.sin(elapsed * (entry.state.state === 'COMBAT' ? 9 : 2.5) + id) * 0.12;
        if (entry.visual.orbitGroup) {
          const speedMult = (entry.state.phase ?? 1) >= 2 ? 2.4 : 1.1;
          entry.visual.orbitGroup.rotation.y += dt * speedMult;
          entry.visual.orbitGroup.rotation.z = Math.sin(elapsed * 1.4) * 0.25;
        }
        entry.visual.targetRing.visible = this.targetType === 'MONSTER' && this.targetId === id;
        const heightOffset = entry.state.isWorldBoss ? 14.5 : entry.state.isBoss ? 5.6 : 2.6 * entry.state.scale;
        this.updateNameplate(entry.nameplate, entry.visual.group.position, heightOffset, entry.state.hp, entry.state.maxHp, true);
      }

      // Animate NPCs, loot drops, markers & proximity prompts
      this.updateNpcsFrame(elapsed);
      this.updateLootDropsFrame(elapsed);

      // Update environment, VFX, minimap & local cooldown countdowns
      this.worldRenderer.update(dt, elapsed);
      this.vfx.update(dt);
      this.tickLocalCooldowns(dt);

      const playersArr = Array.from(this.remotePlayers.values()).map((r) => r.state);
      const monstersArr = Array.from(this.monsters.values()).map((m) => m.state);
      this.minimap.render(
        this.controller.x,
        this.controller.z,
        this.controller.yaw,
        this.selfState?.partyId ?? null,
        playersArr,
        monstersArr,
        this.manifest.npcs,
        this.questState,
        elapsed,
        this.worldBossState,
      );

      this.updateQuestDistanceLabel();

      const coordsEl = document.getElementById('hud-coords');
      if (coordsEl) {
        coordsEl.textContent = `${Math.round(this.controller.x)}, ${Math.round(this.controller.z)} (${Math.round(this.controller.y)}m)`;
      }

      this.renderer.render(this.scene, this.camera);
    };
    tick();
  }

  private async performSync(): Promise<void> {
    if (this.net.isSyncing()) return;

    const outcome = await this.net.syncWorld({
      characterId: this.characterId,
      x: Number(this.controller.x.toFixed(2)),
      z: Number(this.controller.z.toFixed(2)),
      rotY: Number(this.controller.rotY.toFixed(3)),
      isMoving: this.controller.isMoving,
      targetType: this.targetType,
      targetId: this.targetId,
      lastEventId: this.lastEventId,
      lastChatId: this.lastChatId,
    });

    if (outcome.status === 'busy') return;

    const netDot = document.getElementById('net-dot');
    const netPing = document.getElementById('net-ping');

    if (outcome.status === 'auth_expired') {
      if (netDot) netDot.style.background = '#ef4444';
      if (netPing) {
        netPing.innerHTML = '<a href="/login" style="color:#fca5a5; text-decoration:underline;">Session expirée (Reconnecter)</a>';
      }
      this.showToast('Session expirée — cliquez sur Reconnecter ou rechargez la page.');
      return;
    }

    if (outcome.status === 'error') {
      if (netDot) netDot.style.background = '#ef4444';
      if (netPing) netPing.textContent = 'Reconnexion...';
      return;
    }

    if (netDot) netDot.style.background = this.net.lastPingMs < 220 ? '#22c55e' : '#facc15';
    if (netPing) netPing.textContent = `HTTP ${this.net.lastPingMs} ms`;

    this.applySync(outcome.data, false);
  }

  private applySync(resp: SyncResponse, isFirst: boolean): void {
    this.selfState = resp.self;
    this.partyState = resp.party;
    this.worldBossState = resp.worldBoss ?? null;
    this.updateWorldBossHud();

    if (typeof resp.onlineCount === 'number') {
      const ocEl = document.getElementById('hud-online-count');
      if (ocEl) ocEl.textContent = `👥 ${resp.onlineCount} en ligne`;
    }

    if (resp.correctedPosition) {
      this.controller.teleport(resp.self.x, resp.self.y, resp.self.z);
    }

    // Zone transition atmosphere & banner
    if (resp.zone && resp.zone.code !== this.currentZoneCode) {
      this.currentZoneCode = resp.zone.code;
      this.worldRenderer.applyZoneAtmosphere(resp.zone);
      this.showZoneBanner(resp.zone.name, `${resp.zone.subtitle} · Niv. ${resp.zone.minLevel}–${resp.zone.maxLevel}`);
      const zName = document.getElementById('zone-name');
      const zSub = document.getElementById('zone-sub');
      if (zName) zName.textContent = resp.zone.name;
      if (zSub) zSub.textContent = `Niv. ${resp.zone.minLevel}–${resp.zone.maxLevel}`;
    }

    // Reconcile remote players
    const seenPlayers = new Set<number>();
    for (const p of resp.players) {
      seenPlayers.add(p.id);
      let entry = this.remotePlayers.get(p.id);
      if (!entry) {
        const avatar = createPlayerAvatar(p.classCode);
        avatar.group.position.set(p.x, p.y, p.z);
        avatar.group.userData = { entityType: 'PLAYER', entityId: p.id };
        avatar.group.traverse((obj) => {
          obj.userData = { entityType: 'PLAYER', entityId: p.id };
        });
        this.scene.add(avatar.group);

        const np = this.createNameplate(
          `${p.name} [${p.classCode}] N.${p.level}`,
          this.manifest.classes[p.classCode]?.color ?? '#38bdf8',
          '#22c55e',
        );
        entry = { state: p, avatar, targetPos: new THREE.Vector3(p.x, p.y, p.z), nameplate: np };
        this.remotePlayers.set(p.id, entry);
      } else {
        entry.state = p;
        entry.targetPos.set(p.x, p.y, p.z);
        const lbl = entry.nameplate.querySelector('.np-label');
        if (lbl) lbl.textContent = `${p.name} [${p.classCode}] N.${p.level}`;
      }
    }
    for (const [id, entry] of this.remotePlayers) {
      if (!seenPlayers.has(id)) {
        this.scene.remove(entry.avatar.group);
        entry.nameplate.remove();
        this.remotePlayers.delete(id);
        if (this.targetType === 'PLAYER' && this.targetId === id) this.clearTarget();
      }
    }

    // Reconcile monsters
    const seenMonsters = new Set<number>();
    for (const m of resp.monsters) {
      seenMonsters.add(m.id);
      let entry = this.monsters.get(m.id);
      if (!entry) {
        const visual = createMonsterMesh(m.meshKey, m.primaryColor, m.scale, m.isBoss);
        visual.group.position.set(m.x, m.y, m.z);
        visual.group.traverse((obj) => {
          obj.userData = { entityType: 'MONSTER', entityId: m.id };
        });
        this.scene.add(visual.group);

        const np = this.createNameplate(
          `${m.name} (N.${m.level})`,
          m.isBoss ? '#fde047' : m.aggressive ? '#fca5a5' : '#fef9c3',
          m.isBoss ? '#f97316' : '#ef4444',
        );
        entry = { state: m, visual, targetPos: new THREE.Vector3(m.x, m.y, m.z), nameplate: np };
        this.monsters.set(m.id, entry);
      } else {
        entry.state = m;
        entry.targetPos.set(m.x, m.y, m.z);
      }
    }
    for (const [id, entry] of this.monsters) {
      if (!seenMonsters.has(id)) {
        this.scene.remove(entry.visual.group);
        entry.nameplate.remove();
        this.monsters.delete(id);
        if (this.targetType === 'MONSTER' && this.targetId === id) this.clearTarget();
      }
    }

    // Process combat events (skip VFX burst on initial join, only record latest ID)
    for (const ev of resp.events) {
      if (ev.id > this.lastEventId) this.lastEventId = ev.id;
      if (isFirst) {
        this.seenEventIds.add(ev.id);
        continue;
      }
      this.handleCombatEvent(ev);
    }

    // Process chat messages
    for (const msg of resp.chat) {
      if (msg.id > this.lastChatId) this.lastChatId = msg.id;
      this.appendChatMessage(msg);
    }

    // Update HUD
    this.updateSelfHud();
    this.updateTargetHud();
    this.updatePartyHud(resp.pendingInvite);
    if (resp.quests) {
      this.applyQuestState(resp.quests, isFirst);
    }
    if (resp.inventory) {
      this.applyInventoryState(resp.inventory);
    }
    if (resp.lootDrops) {
      this.reconcileLootDrops(resp.lootDrops);
    }
  }

  private handleCombatEvent(ev: CombatEventData): void {
    if (this.seenEventIds.has(ev.id)) return;
    this.seenEventIds.add(ev.id);
    if (this.seenEventIds.size > 400) {
      const first = this.seenEventIds.values().next().value;
      if (first !== undefined) this.seenEventIds.delete(first);
    }

    const srcPos = this.resolveEntityPosition(ev.sourceType, ev.sourceId);
    const tgtPos = this.resolveEntityPosition(ev.targetType, ev.targetId);
    const sourceClassCode =
      ev.sourceType === 'PLAYER'
        ? ev.sourceId === this.characterId
          ? this.classCode
          : this.remotePlayers.get(ev.sourceId)?.state.classCode
        : undefined;

    // Trigger skill-specific procedural bone animation on remote players (skip passive DoT/HoT ticks)
    if (ev.sourceType === 'PLAYER' && ev.skillCode && ev.eventType !== 'DOT' && ev.eventType !== 'HOT' && ev.eventType !== 'KILL' && ev.eventType !== 'LEVEL_UP') {
      if (ev.sourceId !== this.characterId) {
        const rp = this.remotePlayers.get(ev.sourceId);
        if (rp) triggerAvatarSkill(rp.avatar, ev.skillCode);
      }
    }

    this.vfx.spawnFromEvent(ev, this.manifest.skills, this.camera, srcPos, tgtPos, sourceClassCode);

    // Log into Combat tab
    const skillName =
      ev.skillCode === 'auto_attack'
        ? 'Auto-attaque'
        : this.manifest.skills[ev.skillCode]?.name ?? ev.skillCode;
    let line = '';
    if (ev.eventType === 'DAMAGE' || ev.eventType === 'DOT') {
      line = `<span style="color:#fca5a5">${this.esc(ev.sourceName)}</span> inflige <b>${ev.amount}</b>${ev.isCrit ? ' (CRIT!)' : ''} à ${this.esc(ev.targetName)} (${this.esc(skillName || 'Attaque')}).`;
    } else if (ev.eventType === 'HEAL' || ev.eventType === 'HOT') {
      line = `<span style="color:#34d399">${this.esc(ev.sourceName)}</span> soigne ${this.esc(ev.targetName)} de <b>+${ev.amount} PV</b>.`;
    } else if (ev.eventType === 'TAUNT') {
      line = `<span style="color:#fde047">${this.esc(ev.sourceName)} provoque ${this.esc(ev.targetName)} !</span>`;
    } else if (ev.eventType === 'KILL') {
      line = `<span style="color:#c084fc">${this.esc(ev.targetName)} vaincu ! (+${ev.amount} XP)</span>`;
    } else if (ev.eventType === 'DEATH') {
      line = `<span style="color:#f87171">💀 ${this.esc(ev.targetName)} est tombé au combat !</span>`;
    } else if (ev.eventType === 'LEVEL_UP') {
      line = `<span style="color:#fde047">★ ${this.esc(ev.sourceName)} passe Niveau ${ev.amount} !</span>`;
      if (ev.sourceId === this.characterId) {
        const skillNote = ev.amount % 3 === 0 ? ' +1 point de compétence disponible !' : '';
        this.showToast(`Niveau ${ev.amount} atteint ! +3 pts de stats${skillNote} (touche C).`);
      }
    }

    if (line) {
      this.chatHistory.push({ channel: 'COMBAT', html: line });
      if (this.activeChatTab === 'COMBAT') this.renderChatLog();
    }
  }

  private resolveEntityPosition(type: 'PLAYER' | 'MONSTER', id: number): THREE.Vector3 | undefined {
    if (type === 'PLAYER') {
      if (id === this.characterId) return this.selfAvatar.group.position;
      return this.remotePlayers.get(id)?.avatar.group.position;
    }
    return this.monsters.get(id)?.visual.group.position;
  }

  private setupHudBindings(): void {
    // 3D click selection (left click on canvas without dragging)
    let downX = 0;
    let downY = 0;
    this.canvas.addEventListener('pointerdown', (e) => {
      if (e.button === 0) {
        downX = e.clientX;
        downY = e.clientY;
      }
    });
    this.canvas.addEventListener('pointerup', (e) => {
      if (e.button !== 0) return;
      if (this.charModalOpen || this.bagModalOpen || this.activeNpcModalCode !== null) return;
      if (Math.hypot(e.clientX - downX, e.clientY - downY) > 6) return;
      this.pickEntityAt(e.clientX, e.clientY);
    });

    // Populate Relais des Cirques travel select
    const travelSelect = document.getElementById('travel-select') as HTMLSelectElement | null;
    if (travelSelect) {
      for (const z of Object.values(this.manifest.zones)) {
        const opt = document.createElement('option');
        opt.value = z.code;
        opt.textContent = `${z.name} (N.${z.minLevel}-${z.maxLevel})`;
        travelSelect.appendChild(opt);
      }
    }
    document.getElementById('wb-minimap-pill')?.addEventListener('click', () => {
      if (travelSelect && this.worldBossState) {
        travelSelect.value = this.worldBossState.zoneCode;
      }
      if (this.worldBossState) {
        if (this.worldBossState.state === 'DEAD') {
          this.showToast(`World Boss vaincu ! Réapparition dans ${Math.ceil(this.worldBossState.respawnRemaining)}s au Cratère Dolomieu.`);
        } else {
          this.showToast(`🌋 ${this.worldBossState.name} (28 000 PV) attend au Piton de la Fournaise ! Alliez-vous jusqu'à 10 joueurs pour un Légendaire garanti.`);
        }
      }
    });

    document.getElementById('btn-travel')?.addEventListener('click', async () => {
      const code = travelSelect?.value;
      if (!code) return;
      const res = await this.net.travel(this.characterId, code);
      if (!res.ok) {
        this.showToast(res.error ?? 'Voyage impossible.');
        return;
      }
      if (res.self) {
        this.selfState = res.self;
        this.controller.teleport(res.self.x, res.self.y, res.self.z);
        this.showToast(`Arrivée : ${this.manifest.zones[code]?.name ?? code}`);
      }
      if (res.quests) {
        this.applyQuestState(res.quests, false);
      }
    });

    // Global MMO hotkeys
    const chatInput = document.getElementById('chat-input') as HTMLInputElement;
    const partyInput = document.getElementById('party-invite-input') as HTMLInputElement;

    const handleFocusPause = () => {
      const typing = document.activeElement === chatInput || document.activeElement === partyInput;
      this.controller.setInputBlocked(typing || this.charModalOpen || this.bagModalOpen || this.activeNpcModalCode !== null);
    };
    chatInput.addEventListener('focus', handleFocusPause);
    chatInput.addEventListener('blur', handleFocusPause);
    partyInput.addEventListener('focus', handleFocusPause);
    partyInput.addEventListener('blur', handleFocusPause);

    window.addEventListener('keydown', (e) => {
      const typing = document.activeElement === chatInput || document.activeElement === partyInput;
      if (e.key === 'Enter' && !typing) {
        e.preventDefault();
        chatInput.focus();
        return;
      }
      if (e.key === 'Escape') {
        if (typing) {
          (document.activeElement as HTMLElement).blur();
        } else if (this.activeNpcModalCode !== null) {
          this.closeNpcModal();
        } else if (this.bagModalOpen) {
          this.toggleBagModal(false);
        } else if (this.charModalOpen) {
          this.toggleCharModal(false);
        } else {
          this.clearTarget();
        }
        return;
      }
      if (typing) return;

      if (e.code === 'KeyI' && !e.repeat && this.activeNpcModalCode === null) {
        e.preventDefault();
        if (this.charModalOpen) this.toggleCharModal(false);
        this.toggleBagModal(!this.bagModalOpen);
        return;
      }

      if (e.code === 'KeyF' && !e.repeat && this.activeNpcModalCode === null && !this.charModalOpen && !this.bagModalOpen && !(this.selfState?.isDead ?? false)) {
        if (this.nearestLootId !== null) {
          e.preventDefault();
          void this.pickupLootById(this.nearestLootId);
          return;
        }
        if (this.nearestNpcCode) {
          e.preventDefault();
          this.openNpcModal(this.nearestNpcCode);
          return;
        }
      }

      if (this.activeNpcModalCode !== null || this.bagModalOpen) return;

      if (e.key === 'Tab') {
        e.preventDefault();
        this.cycleNearestMonster();
      } else if (e.code === 'KeyC' || e.key === 'c' || e.key === 'C') {
        this.toggleCharModal(!this.charModalOpen);
      } else if (this.resolveSkillSlotFromKey(e) !== null) {
        e.preventDefault();
        if (!e.repeat) {
          void this.castSlot(this.resolveSkillSlotFromKey(e)!);
        }
      } else if (e.key === 'F1') {
        e.preventDefault();
        this.setTarget('PLAYER', this.characterId);
      } else if (['F2', 'F3', 'F4', 'F5'].includes(e.key)) {
        e.preventDefault();
        const idx = Number(e.key.slice(1)) - 1;
        const member = this.partyState?.members[idx];
        if (member) this.setTarget('PLAYER', member.id);
      }
    });

    // Skill bar & Auto-Attack clicks
    document.getElementById('skill-slot-aa')?.addEventListener('click', () => {
      void this.performAutoAttack();
    });
    for (let slot = 1; slot <= 4; slot++) {
      document.getElementById(`skill-slot-${slot}`)?.addEventListener('click', () => {
        void this.castSlot(slot);
      });
    }

    // Character Sheet, Bag & NPC Dialogue Modals
    document.getElementById('btn-char-sheet')?.addEventListener('click', () => {
      if (this.bagModalOpen) this.toggleBagModal(false);
      this.toggleCharModal(!this.charModalOpen);
    });
    document.getElementById('btn-close-char-modal')?.addEventListener('click', () => this.toggleCharModal(false));
    document.getElementById('btn-bag-modal')?.addEventListener('click', () => {
      if (this.charModalOpen) this.toggleCharModal(false);
      this.toggleBagModal(!this.bagModalOpen);
    });
    document.getElementById('btn-close-bag-modal')?.addEventListener('click', () => this.toggleBagModal(false));
    document.getElementById('btn-close-npc-modal')?.addEventListener('click', () => this.closeNpcModal());
    document.getElementById('npc-interact-prompt')?.addEventListener('click', () => {
      if (this.nearestNpcCode) this.openNpcModal(this.nearestNpcCode);
    });
    document.getElementById('loot-pickup-prompt')?.addEventListener('click', () => {
      if (this.nearestLootId !== null) void this.pickupLootById(this.nearestLootId);
    });
    document.querySelectorAll<HTMLButtonElement>('[data-npc-tab]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const tab = (btn.dataset.npcTab as 'QUESTS' | 'VENDOR') ?? 'QUESTS';
        this.switchNpcModalTab(tab);
      });
    });

    // Respawn button
    document.getElementById('btn-respawn')?.addEventListener('click', async () => {
      const res = await this.net.respawn(this.characterId);
      if (res.ok && res.self) {
        this.selfState = res.self;
        this.controller.teleport(res.self.x, res.self.y, res.self.z);
        this.updateSelfHud();
        this.showToast('Ressuscité au camp de la zone.');
      }
    });

    // Chat tabs & form
    document.querySelectorAll<HTMLButtonElement>('.chat-tab').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.chat-tab').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        this.activeChatTab = (btn.dataset.channel as typeof this.activeChatTab) ?? 'ZONE';
        this.renderChatLog();
      });
    });

    document.getElementById('chat-form')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const text = chatInput.value.trim();
      chatInput.value = '';
      chatInput.blur();
      if (!text) return;
      const channel = this.activeChatTab === 'COMBAT' ? 'ZONE' : this.activeChatTab;
      const res = await this.net.sendChat(this.characterId, channel, text);
      if (!res.ok && res.error) this.showToast(res.error);
    });

    // Party controls
    document.getElementById('party-invite-form')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const targetName = partyInput.value.trim();
      partyInput.value = '';
      partyInput.blur();
      if (!targetName) return;
      const res = await this.net.partyAction('invite', { characterId: this.characterId, targetName });
      this.showToast(res.ok ? (res.message ?? 'Invitation envoyée.') : (res.error ?? 'Échec invitation.'));
    });
    document.getElementById('btn-party-accept')?.addEventListener('click', async () => {
      const res = await this.net.partyAction('accept', { characterId: this.characterId });
      this.showToast(res.ok ? 'Vous avez rejoint le groupe !' : (res.error ?? 'Erreur groupe.'));
    });
    document.getElementById('btn-party-decline')?.addEventListener('click', async () => {
      await this.net.partyAction('decline', { characterId: this.characterId });
    });
    document.getElementById('btn-party-leave')?.addEventListener('click', async () => {
      await this.net.partyAction('leave', { characterId: this.characterId });
      this.showToast('Vous avez quitté le groupe.');
    });
    document.getElementById('btn-target-invite')?.addEventListener('click', async () => {
      if (this.targetType !== 'PLAYER' || !this.targetId || this.targetId === this.characterId) return;
      const res = await this.net.partyAction('invite', { characterId: this.characterId, targetCharacterId: this.targetId });
      this.showToast(res.ok ? (res.message ?? 'Invitation envoyée.') : (res.error ?? 'Échec invitation.'));
    });
  }

  private resolveSkillSlotFromKey(e: KeyboardEvent): number | null {
    const codeMap: Record<string, number> = {
      Digit1: 1,
      Digit2: 2,
      Digit3: 3,
      Digit4: 4,
      Numpad1: 1,
      Numpad2: 2,
      Numpad3: 3,
      Numpad4: 4,
    };
    if (e.code in codeMap) {
      return codeMap[e.code];
    }

    const keyMap: Record<string, number> = {
      '1': 1,
      '2': 2,
      '3': 3,
      '4': 4,
      '&': 1,
      'é': 2,
      '"': 3,
      "'": 4,
    };
    return keyMap[e.key] ?? null;
  }

  private pickEntityAt(clientX: number, clientY: number): void {
    this.pointerNdc.x = (clientX / window.innerWidth) * 2 - 1;
    this.pointerNdc.y = -(clientY / window.innerHeight) * 2 + 1;
    this.raycaster.setFromCamera(this.pointerNdc, this.camera);

    const pickables: THREE.Object3D[] = [];
    for (const l of this.lootDrops.values()) {
      pickables.push(l.visual.group);
    }
    for (const n of this.npcs.values()) {
      pickables.push(n.visual.group);
    }
    for (const m of this.monsters.values()) {
      if (m.state.state !== 'DEAD') pickables.push(m.visual.group);
    }
    for (const p of this.remotePlayers.values()) {
      pickables.push(p.avatar.group);
    }

    const hits = this.raycaster.intersectObjects(pickables, true);
    if (hits.length > 0) {
      const ud = hits[0].object.userData as {
        entityType?: 'MONSTER' | 'PLAYER' | 'NPC' | 'LOOT';
        entityId?: number;
        npcCode?: string;
      };
      if (ud.entityType === 'LOOT' && ud.entityId) {
        void this.pickupLootById(ud.entityId);
        return;
      }
      if (ud.entityType === 'NPC' && ud.npcCode) {
        this.openNpcModal(ud.npcCode);
        return;
      }
      if (ud.entityType === 'MONSTER' && ud.entityId) {
        this.setTarget('MONSTER', ud.entityId);
        void this.performAutoAttack();
        return;
      }
      if (ud.entityType === 'PLAYER' && ud.entityId) {
        this.setTarget('PLAYER', ud.entityId);
        return;
      }
    }

    // Left-click on open ground / empty space: always trigger auto-attack swing (even with no mob in range)
    void this.performAutoAttack();
  }

  private cycleNearestMonster(): void {
    const alive = Array.from(this.monsters.values())
      .filter((m) => m.state.state !== 'DEAD')
      .map((m) => ({
        id: m.state.id,
        dist: Math.hypot(m.state.x - this.controller.x, m.state.z - this.controller.z),
      }))
      .filter((x) => x.dist <= 65)
      .sort((a, b) => a.dist - b.dist);

    if (alive.length === 0) {
      this.showToast('Aucun ennemi à portée (65m).');
      return;
    }

    const curIdx = this.targetType === 'MONSTER' ? alive.findIndex((a) => a.id === this.targetId) : -1;
    const next = alive[(curIdx + 1) % alive.length];
    this.setTarget('MONSTER', next.id);
  }

  private setTarget(type: 'MONSTER' | 'PLAYER', id: number): void {
    this.targetType = type;
    this.targetId = id;
    this.updateTargetHud();
  }

  private clearTarget(): void {
    this.targetType = null;
    this.targetId = null;
    this.updateTargetHud();
  }

  private flashSkillButton(slot: number | 'aa', mode: 'press' | 'cast' | 'reject' | 'ready'): void {
    const id = slot === 'aa' ? 'skill-slot-aa' : `skill-slot-${slot}`;
    const btn = document.getElementById(id);
    if (!btn) return;

    if (mode === 'press') {
      const prev = this.skillPressTimers.get(slot);
      if (prev !== undefined) window.clearTimeout(prev);
      btn.classList.add('skill-pressed');
      this.skillPressTimers.set(
        slot,
        window.setTimeout(() => {
          btn.classList.remove('skill-pressed');
          this.skillPressTimers.delete(slot);
        }, 140),
      );
      return;
    }

    if (mode === 'cast' || mode === 'reject') {
      const prevPress = this.skillPressTimers.get(slot);
      if (prevPress !== undefined) {
        window.clearTimeout(prevPress);
        this.skillPressTimers.delete(slot);
      }
      btn.classList.remove('skill-pressed');
    }

    const prevFlash = this.skillFlashTimers.get(slot);
    if (prevFlash !== undefined) window.clearTimeout(prevFlash);

    const cls = mode === 'cast' ? 'skill-cast-flash' : mode === 'reject' ? 'skill-rejected' : 'skill-ready-flash';
    btn.classList.remove('skill-cast-flash', 'skill-rejected', 'skill-ready-flash');
    void btn.offsetWidth; // force CSS animation restart on rapid presses
    btn.classList.add(cls);
    this.skillFlashTimers.set(
      slot,
      window.setTimeout(() => {
        btn.classList.remove(cls);
        this.skillFlashTimers.delete(slot);
      }, 460),
    );
  }

  private async performAutoAttack(): Promise<void> {
    if (!this.selfState || this.selfState.isDead || this.autoAttackInFlight) return;
    const nowSec = performance.now() / 1000;
    const aaRem = Math.max(this.selfState.autoAttackCooldown ?? 0, this.localAaCooldownUntil - nowSec);
    if (aaRem > 0.08) return;

    this.flashSkillButton('aa', 'press');

    const aaRange = this.classCode === 'HEAL' ? 18.0 : 8.5;
    let inRangeTargetId: number | null = null;

    if (this.targetType === 'MONSTER' && this.targetId !== null) {
      const targeted = this.monsters.get(this.targetId);
      if (targeted && targeted.state.state !== 'DEAD') {
        const dist = Math.hypot(targeted.state.x - this.controller.x, targeted.state.z - this.controller.z);
        if (dist <= aaRange) {
          inRangeTargetId = targeted.state.id;
        }
      }
    }

    if (inRangeTargetId === null) {
      let nearestId: number | null = null;
      let nearestDist = aaRange;
      for (const m of this.monsters.values()) {
        if (m.state.state === 'DEAD') continue;
        const dist = Math.hypot(m.state.x - this.controller.x, m.state.z - this.controller.z);
        if (dist <= nearestDist) {
          nearestDist = dist;
          nearestId = m.state.id;
        }
      }
      if (nearestId !== null) {
        this.setTarget('MONSTER', nearestId);
        inRangeTargetId = nearestId;
      }
    }

    // Empty-air swing when no monster is within auto-attack range (client-side animation + VFX + 1.2s timer)
    if (inRangeTargetId === null) {
      this.localAaCooldownUntil = nowSec + 1.2;
      this.selfState.autoAttackCooldown = 1.2;
      this.flashSkillButton('aa', 'cast');
      triggerAvatarSkill(this.selfAvatar, 'auto_attack');
      this.vfx.spawnAutoAttackSwing(this.selfAvatar.group.position, this.controller.rotY, this.classCode);
      return;
    }

    this.autoAttackInFlight = true;
    try {
      const res = await this.net.autoAttack(this.characterId, 'MONSTER', inRangeTargetId);
      if (!res.ok) {
        this.flashSkillButton('aa', 'reject');
        if (res.error && res.error !== 'Auto-attaque en recharge.') this.showToast(res.error);
        return;
      }

      this.localAaCooldownUntil = performance.now() / 1000 + 1.2;
      this.flashSkillButton('aa', 'cast');
      this.faceCurrentTarget();
      triggerAvatarSkill(this.selfAvatar, 'auto_attack');

      if (res.self) {
        this.selfState = res.self;
        this.updateSelfHud();
      }
      if (res.quests) {
        this.applyQuestState(res.quests, false);
      }
      if (res.events) {
        for (const ev of res.events) {
          if (ev.id > this.lastEventId) this.lastEventId = ev.id;
          this.handleCombatEvent(ev);
        }
      }
    } finally {
      this.autoAttackInFlight = false;
    }
  }

  private faceCurrentTarget(): void {
    if (!this.targetType || !this.targetId) return;
    if (this.targetType === 'PLAYER' && this.targetId === this.characterId) return;
    const pos = this.resolveEntityPosition(this.targetType, this.targetId);
    if (!pos) return;
    const dx = pos.x - this.controller.x;
    const dz = pos.z - this.controller.z;
    if (Math.hypot(dx, dz) > 0.25) {
      this.controller.rotY = Math.atan2(dx, dz);
    }
  }

  private async castSlot(slot: number): Promise<void> {
    const skill = this.classSkills.find((s) => s.slot === slot);
    if (!skill || !this.selfState) return;

    // Immediate tactile visual press feedback on key 1-4 / click
    this.flashSkillButton(slot, 'press');

    if (this.selfState.level < skill.unlockLevel) {
      this.flashSkillButton(slot, 'reject');
      this.showToast(`${skill.name} se débloque au niveau ${skill.unlockLevel}.`);
      return;
    }

    // Auto-pick nearest monster if enemy skill cast with no monster selected
    if ((skill.targetType === 'ENEMY' || skill.targetType === 'ENEMY_AOE') && this.targetType !== 'MONSTER') {
      this.cycleNearestMonster();
    }

    const res = await this.net.castSkill(this.characterId, skill.code, this.targetType, this.targetId);
    if (!res.ok) {
      this.flashSkillButton(slot, 'reject');
      if (res.error) this.showToast(res.error);
      return;
    }

    // Confirmed cast: icon burst flash + auto-face target + skill-specific procedural bone animation!
    this.flashSkillButton(slot, 'cast');
    this.faceCurrentTarget();
    triggerAvatarSkill(this.selfAvatar, skill.code);

    if (res.self) {
      this.selfState = res.self;
      this.updateSelfHud();
    }
    if (res.quests) {
      this.applyQuestState(res.quests, false);
    }
    if (res.events) {
      for (const ev of res.events) {
        if (ev.id > this.lastEventId) this.lastEventId = ev.id;
        this.handleCombatEvent(ev);
      }
    }
  }

  private renderSkillButtonsStructure(): void {
    const clsMeta = this.manifest.classes[this.classCode];
    const resShort = clsMeta?.resourceShort ?? 'PM';
    const resLabel = clsMeta?.resourceLabel ?? 'Mana';
    const resColor = clsMeta?.resourceColor ?? '#38bdf8';
    const aaGain = this.classCode === 'TANK' ? 15 : 10;

    const aaBtn = document.getElementById('skill-slot-aa');
    if (aaBtn) {
      aaBtn.style.setProperty('--skill-glow', '#fde047');
      aaBtn.title = `Auto-attaque (Clic gauche) — Coûte 0, génère +${aaGain} ${resLabel} par coup (1.2s). Tuer un ennemi de même niveau prend ~30s à l'auto-attaque (4 sorts max avec vos compétences).`;
      aaBtn.innerHTML = `
        <span class="skill-key">Clic G.</span>
        <span class="skill-mana" style="color:${resColor};">+${aaGain} ${resShort}</span>
        <span class="skill-icon">⚔️</span>
        <span class="skill-name">Auto-attaque</span>
        <div class="skill-cd-sweep"></div>
        <div class="skill-cd-overlay"></div>
      `;
    }

    for (const skill of this.classSkills) {
      const btn = document.getElementById(`skill-slot-${skill.slot}`);
      if (!btn) continue;
      btn.style.setProperty('--skill-glow', skill.vfxColor);
      btn.title = `${skill.name} (Rang 1/5 · Niv. ${skill.unlockLevel}) — ${skill.manaCost} ${resLabel}, CD ${skill.cooldown}s\n${skill.description}`;
      btn.innerHTML = `
        <span class="skill-key">${skill.slot}</span>
        <span class="skill-mana" style="color:${resColor};">${skill.manaCost} ${resShort}</span>
        <span class="skill-icon">${skill.icon}</span>
        <span class="skill-name">${this.esc(skill.name)}</span>
        <span class="skill-rank-badge">R.1</span>
        <div class="skill-cd-sweep"></div>
        <div class="skill-cd-overlay"></div>
      `;

      const upgBtn = document.getElementById(`skill-upgrade-${skill.slot}`);
      upgBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        void this.upgradeSkillCode(skill.code, skill.slot);
      });
    }
  }

  private async upgradeSkillCode(skillCode: string, slot?: number): Promise<void> {
    const res = await this.net.upgradeSkill(this.characterId, skillCode);
    if (!res.ok) {
      if (res.error) this.showToast(res.error);
      return;
    }

    if (res.self) {
      this.selfState = res.self;
      this.updateSelfHud();
    }

    const skill = this.classSkills.find((s) => s.code === skillCode);
    const resolvedSlot = slot ?? skill?.slot;
    if (resolvedSlot) {
      this.flashSkillButton(resolvedSlot, 'ready');
    }
    const newRank = res.newRank ?? 2;
    const bonusPct = (newRank - 1) * 22;
    this.showToast(`✨ ${skill?.name ?? skillCode} passe au Rang ${newRank} (+${bonusPct}% puissance) !`);
  }

  private updateSkillRankUi(): void {
    const s = this.selfState;
    if (!s) return;

    const skillPoints = s.skillPoints ?? 0;
    const resLabel = s.resourceLabel ?? this.manifest.classes[this.classCode]?.resourceLabel ?? 'Mana';

    const banner = document.getElementById('skill-upgrade-banner');
    const bannerTxt = document.getElementById('skill-upgrade-text');
    if (banner) {
      banner.classList.toggle('visible', skillPoints > 0);
      if (bannerTxt && skillPoints > 0) {
        bannerTxt.textContent = `${skillPoints} point${skillPoints > 1 ? 's' : ''} de compétence`;
      }
    }

    for (const skill of this.classSkills) {
      const rank = Math.max(1, s.skillLevels?.[skill.code] ?? 1);
      const bonusPct = (rank - 1) * 22;
      const btn = document.getElementById(`skill-slot-${skill.slot}`);
      if (btn) {
        const badge = btn.querySelector('.skill-rank-badge') as HTMLElement | null;
        if (badge) {
          badge.textContent = `R.${rank}`;
          badge.classList.toggle('upgraded', rank > 1);
        }
        const scaleNote = bonusPct > 0 ? ` · Puissance +${bonusPct}%` : '';
        btn.title = `${skill.name} (Rang ${rank}/5${scaleNote} · Niv. ${skill.unlockLevel}) — ${skill.manaCost} ${resLabel}, CD ${skill.cooldown}s\n${skill.description}`;
      }

      const upgBtn = document.getElementById(`skill-upgrade-${skill.slot}`);
      if (upgBtn) {
        const canUpgrade = skillPoints > 0 && s.level >= skill.unlockLevel && rank < 5;
        upgBtn.classList.toggle('visible', canUpgrade);
        if (canUpgrade) {
          upgBtn.title = `Améliorer ${skill.name} (Rang ${rank} → ${rank + 1}, +22% dégâts/soins)`;
        }
      }
    }
  }

  private tickLocalCooldowns(dt: number): void {
    if (!this.selfState) return;
    if (this.selfState.gcdRemaining > 0) {
      this.selfState.gcdRemaining = Math.max(0, this.selfState.gcdRemaining - dt);
    }
    if ((this.selfState.autoAttackCooldown ?? 0) > 0) {
      this.selfState.autoAttackCooldown = Math.max(0, (this.selfState.autoAttackCooldown ?? 0) - dt);
    }
    for (const k of Object.keys(this.selfState.cooldowns)) {
      this.selfState.cooldowns[k] = Math.max(0, this.selfState.cooldowns[k] - dt);
    }

    // Auto-Attack 1.2s swing timer indicator
    const aaBtn = document.getElementById('skill-slot-aa');
    if (aaBtn) {
      const aaRem = Math.max(this.selfState.autoAttackCooldown ?? 0, Math.max(0, this.localAaCooldownUntil - performance.now() / 1000));
      const aaOverlay = aaBtn.querySelector('.skill-cd-overlay') as HTMLElement | null;
      const aaSweep = aaBtn.querySelector('.skill-cd-sweep') as HTMLElement | null;
      if (aaRem > 0.05) {
        aaBtn.classList.add('on-cd');
        if (aaOverlay) aaOverlay.textContent = aaRem.toFixed(1);
        if (aaSweep) aaSweep.style.height = `${Math.min(100, (aaRem / 1.2) * 100)}%`;
        this.skillWasOnCd.set('auto_attack', true);
      } else {
        aaBtn.classList.remove('on-cd');
        if (aaOverlay) aaOverlay.textContent = '';
        if (aaSweep) aaSweep.style.height = '0%';
        if (this.skillWasOnCd.get('auto_attack')) {
          this.skillWasOnCd.set('auto_attack', false);
          this.flashSkillButton('aa', 'ready');
        }
      }
    }

    const gcdTotal = this.manifest.gcd || 1.4;
    for (const skill of this.classSkills) {
      const btn = document.getElementById(`skill-slot-${skill.slot}`);
      if (!btn) continue;
      const locked = this.selfState.level < skill.unlockLevel;
      btn.classList.toggle('locked', locked);
      const overlay = btn.querySelector('.skill-cd-overlay') as HTMLElement | null;
      const sweep = btn.querySelector('.skill-cd-sweep') as HTMLElement | null;
      if (!overlay) continue;

      if (locked) {
        btn.classList.add('on-cd');
        overlay.textContent = `N.${skill.unlockLevel}`;
        if (sweep) sweep.style.height = '0%';
      } else {
        const skillRem = this.selfState.cooldowns[skill.code] ?? 0;
        const gcdRem = this.selfState.gcdRemaining;
        const rem = Math.max(gcdRem, skillRem);
        const total = skillRem >= gcdRem ? Math.max(0.5, skill.cooldown) : gcdTotal;

        if (rem > 0.05) {
          btn.classList.add('on-cd');
          overlay.textContent = rem.toFixed(1);
          if (sweep) sweep.style.height = `${Math.min(100, (rem / total) * 100)}%`;
          this.skillWasOnCd.set(skill.code, true);
        } else {
          btn.classList.remove('on-cd');
          overlay.textContent = '';
          if (sweep) sweep.style.height = '0%';
          if (this.skillWasOnCd.get(skill.code)) {
            this.skillWasOnCd.set(skill.code, false);
            this.flashSkillButton(skill.slot, 'ready');
          }
        }
      }
    }
  }

  private updateSelfHud(): void {
    const s = this.selfState;
    if (!s) return;

    const clsColor = this.manifest.classes[s.classCode]?.color ?? '#38bdf8';
    const dot = document.getElementById('hud-class-dot');
    if (dot) dot.style.background = clsColor;

    const setText = (id: string, val: string) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };
    const setWidth = (id: string, pct: number) => {
      const el = document.getElementById(id);
      if (el) el.style.width = `${Math.max(0, Math.min(100, pct))}%`;
    };

    const clsMeta = this.manifest.classes[s.classCode];
    const resType = s.resourceType ?? clsMeta?.resourceType ?? 'MANA';
    const resLabel = s.resourceLabel ?? clsMeta?.resourceLabel ?? 'Mana';
    const mpFill = document.getElementById('hud-mp-fill');
    if (mpFill) {
      mpFill.style.background =
        resType === 'RAGE'
          ? 'linear-gradient(90deg, #b91c1c, #f97316)'
          : resType === 'ENERGY'
            ? 'linear-gradient(90deg, #ca8a04, #facc15)'
            : 'linear-gradient(90deg, #2563eb, #38bdf8)';
    }

    setText('hud-level', String(s.level));
    setText('hud-gold', `🪙 ${s.gold ?? 0}`);
    setText('hud-hp-text', `${s.hp} / ${s.maxHp}`);
    setText('hud-mp-text', `${resLabel} ${s.mp} / ${s.maxMp}`);
    setWidth('hud-hp-fill', (s.hp / Math.max(1, s.maxHp)) * 100);
    setWidth('hud-mp-fill', (s.mp / Math.max(1, s.maxMp)) * 100);

    setText('hud-xp-text', `XP ${s.xp} / ${s.xpNext}`);
    setWidth('hud-xp-fill', (s.xp / Math.max(1, s.xpNext)) * 100);

    const sheetBtn = document.getElementById('btn-char-sheet');
    const badge = document.getElementById('stat-pts-badge');
    const skillPts = s.skillPoints ?? 0;
    const totalPts = s.statPoints + skillPts;
    if (sheetBtn) sheetBtn.classList.toggle('has-points', totalPts > 0);
    if (badge) badge.textContent = totalPts > 0 ? `+${totalPts} pts` : '[C]';

    this.updateSkillRankUi();

    const buffsEl = document.getElementById('hud-buffs');
    if (buffsEl) {
      buffsEl.innerHTML = s.effects
        .map((ef) => `<span class="buff-pill">${this.esc(this.manifest.skills[ef.skillCode]?.name ?? ef.type)} ${ef.remaining}s</span>`)
        .join('');
    }

    document.getElementById('death-modal')?.classList.toggle('open', s.isDead);
    if (this.charModalOpen) this.renderCharModalContent();
  }

  private updateTargetHud(): void {
    const frame = document.getElementById('target-frame');
    const inviteBtn = document.getElementById('btn-target-invite');
    if (!frame) return;

    if (!this.targetType || !this.targetId) {
      frame.classList.remove('visible');
      if (inviteBtn) inviteBtn.style.display = 'none';
      return;
    }

    const nameEl = document.getElementById('target-name');
    const lvlEl = document.getElementById('target-lvl');
    const hpBar = document.getElementById('target-hp-bar');
    const hpFill = document.getElementById('target-hp-fill');
    const hpText = document.getElementById('target-hp-text');
    const aggroEl = document.getElementById('target-aggro');

    if (this.targetType === 'MONSTER') {
      if (inviteBtn) inviteBtn.style.display = 'none';
      const m = this.monsters.get(this.targetId)?.state;
      if (!m || m.state === 'DEAD') {
        this.clearTarget();
        return;
      }
      frame.classList.add('visible');
      if (nameEl) nameEl.textContent = m.name;
      if (lvlEl) lvlEl.textContent = `Niv. ${m.level}`;
      if (hpBar) hpBar.className = 'bar bar-hp-enemy';
      if (hpFill) hpFill.style.width = `${(m.hp / Math.max(1, m.maxHp)) * 100}%`;
      if (hpText) hpText.textContent = `${m.hp} / ${m.maxHp}`;
      if (aggroEl) {
        if (m.targetCharacterId === this.characterId) {
          aggroEl.textContent = '⚔️ Vous avez l\'aggro !';
        } else if (m.targetCharacterId !== null) {
          const holder = this.remotePlayers.get(m.targetCharacterId)?.state.name ?? `Joueur #${m.targetCharacterId}`;
          aggroEl.textContent = `🛡️ Aggro sur : ${holder}`;
        } else {
          aggroEl.textContent = m.aggressive ? '⚠️ Hostile' : 'Pacifique jusqu\'à attaque';
        }
      }
      return;
    }

    // Player target (self or remote)
    const isSelf = this.targetId === this.characterId;
    const p = isSelf ? this.selfState : this.remotePlayers.get(this.targetId)?.state;
    if (!p) {
      this.clearTarget();
      return;
    }
    frame.classList.add('visible');
    if (nameEl) nameEl.textContent = `${p.name} (${p.classCode})`;
    if (lvlEl) lvlEl.textContent = `Niv. ${p.level}`;
    if (hpBar) hpBar.className = 'bar bar-hp';
    if (hpFill) hpFill.style.width = `${(p.hp / Math.max(1, p.maxHp)) * 100}%`;
    if (hpText) hpText.textContent = `${p.hp} / ${p.maxHp}`;
    if (aggroEl) aggroEl.textContent = isSelf ? 'Allié (Vous-même)' : 'Allié — Ciblable par le Heal';
    if (inviteBtn) {
      const alreadyInParty = Boolean(this.partyState?.members.some((m) => m.id === p.id));
      inviteBtn.style.display = !isSelf && !p.isDead && !alreadyInParty ? 'block' : 'none';
    }
  }

  private updatePartyHud(pendingInvite: SyncResponse['pendingInvite']): void {
    const inviteBanner = document.getElementById('party-invite-banner');
    const inviteText = document.getElementById('party-invite-text');
    if (inviteBanner) {
      inviteBanner.style.display = pendingInvite ? 'block' : 'none';
      if (pendingInvite && inviteText) {
        inviteText.textContent = `Invitation reçue : ${pendingInvite.label}`;
      }
    }

    const leaveBtn = document.getElementById('btn-party-leave');
    const container = document.getElementById('party-members');
    if (!container) return;

    if (!this.partyState) {
      if (leaveBtn) leaveBtn.style.display = 'none';
      container.innerHTML =
        '<div style="color:var(--text-muted); font-size:11px;">En solo — invitez un joueur pour partager l\'XP (+10%/allié) et défier le Dolomieu.</div>';
      return;
    }

    if (leaveBtn) leaveBtn.style.display = 'inline-block';
    container.innerHTML = this.partyState.members
      .map((m, idx) => {
        const pct = (m.hp / Math.max(1, m.maxHp)) * 100;
        const targeted = this.targetType === 'PLAYER' && this.targetId === m.id;
        const col = this.manifest.classes[m.classCode]?.color ?? '#38bdf8';
        return `
          <div class="party-member ${targeted ? 'targeted' : ''}" data-member-id="${m.id}">
            <div style="display:flex; justify-content:space-between; font-size:11px; margin-bottom:2px;">
              <span><b style="color:${col}">[F${idx + 1}]</b> ${this.esc(m.name)} <small>(N.${m.level})</small></span>
              <span style="color:${m.isDead ? '#f87171' : '#86efac'}">${m.isDead ? 'Mort' : `${m.hp}/${m.maxHp}`}</span>
            </div>
            <div class="bar bar-hp" style="height:6px; margin:0;"><div class="bar-fill" style="width:${pct}%"></div></div>
          </div>
        `;
      })
      .join('');

    container.querySelectorAll<HTMLElement>('.party-member').forEach((el) => {
      el.addEventListener('click', () => {
        const id = Number(el.dataset.memberId);
        if (id) this.setTarget('PLAYER', id);
      });
    });
  }

  private toggleCharModal(open: boolean): void {
    this.charModalOpen = open;
    document.getElementById('char-modal')?.classList.toggle('open', open);
    this.controller.setInputBlocked(open);
    if (open) this.renderCharModalContent();
  }

  private renderCharModalContent(): void {
    const s = this.selfState;
    if (!s) return;
    const setText = (id: string, val: string) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };
    setText('modal-char-name', `${s.name} — ${this.manifest.classes[s.classCode]?.label ?? s.classCode}`);
    setText('modal-lvl', String(s.level));
    setText('modal-stat-pts', String(s.statPoints));
    setText('modal-skill-pts', String(s.skillPoints ?? 0));

    const attrs: [keyof SelfState['attributes'], string, string][] = [
      ['vitality', 'Vitalité (VIT)', '+12 PV max & régén.'],
      ['strength', 'Force (STR)', '+2.5 Puissance Physique'],
      ['intelligence', 'Intelligence (INT)', '+2.5 Puissance Magique / Soin'],
      ['defense', 'Défense (DEF)', 'Réduction des dégâts'],
      ['spirit', 'Esprit (SPI)', '+10 PM max & régén. mana'],
    ];

    const listEl = document.getElementById('modal-attributes');
    if (listEl) {
      listEl.innerHTML = attrs
        .map(([key, label, hint]) => {
          const bonus = s.equipmentBonuses?.[key] ?? 0;
          const bonusTag = bonus > 0 ? `<small style="color:#34d399; font-weight:700;"> (+${bonus})</small>` : '';
          return `
        <div class="attr-row">
          <div><strong>${label}</strong><div style="font-size:11px; color:var(--text-muted)">${hint}</div></div>
          <div style="display:flex; align-items:center; gap:8px;">
            <strong style="font-size:15px; color:#e0f2fe;">${s.attributes[key]}${bonusTag}</strong>
            ${
              s.statPoints > 0
                ? `<button type="button" class="mini-btn" data-alloc="${key}">+1</button>`
                : ''
            }
          </div>
        </div>
      `;
        })
        .join('');

      listEl.querySelectorAll<HTMLButtonElement>('[data-alloc]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const stat = btn.dataset.alloc;
          if (!stat) return;
          const res = await this.net.allocateStat(this.characterId, stat);
          if (res.ok && res.self) {
            this.selfState = res.self;
            this.updateSelfHud();
          }
        });
      });
    }

    const skillsListEl = document.getElementById('modal-skills-upgrade');
    if (skillsListEl) {
      const skillPts = s.skillPoints ?? 0;
      skillsListEl.innerHTML = this.classSkills
        .map((skill) => {
          const rank = Math.max(1, s.skillLevels?.[skill.code] ?? 1);
          const bonusPct = (rank - 1) * 22;
          const unlocked = s.level >= skill.unlockLevel;
          const isMax = rank >= 5;
          const sub = !unlocked
            ? `Débloqué au niveau ${skill.unlockLevel}`
            : `Rang ${rank}/5 · Bonus : +${bonusPct}% puissance`;
          const actionHtml = !unlocked
            ? '<small style="color:#94a3b8;">Verrouillé</small>'
            : isMax
              ? '<small style="color:#fde047; font-weight:700;">MAX</small>'
              : skillPts > 0
                ? `<button type="button" class="mini-btn" style="background:rgba(250,204,21,0.25); color:#fde047;" data-upgrade-skill="${skill.code}">▲ Rang ${rank + 1} (+22%)</button>`
                : '';

          return `
            <div class="attr-row">
              <div>
                <strong>${skill.icon} ${this.esc(skill.name)}</strong>
                <div style="font-size:11px; color:var(--text-muted)">${this.esc(sub)}</div>
              </div>
              <div>${actionHtml}</div>
            </div>
          `;
        })
        .join('');

      skillsListEl.querySelectorAll<HTMLButtonElement>('[data-upgrade-skill]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const code = btn.dataset.upgradeSkill;
          if (code) void this.upgradeSkillCode(code);
        });
      });
    }

    const derivedEl = document.getElementById('modal-derived');
    if (derivedEl) {
      derivedEl.innerHTML = `
        <div>PV Max : <strong>${s.derived.maxHp}</strong></div>
        <div>PM Max : <strong>${s.derived.maxMp}</strong></div>
        <div>Puissance Phy. : <strong>${s.derived.attackPower}</strong></div>
        <div>Puissance Mag. : <strong>${s.derived.spellPower}</strong></div>
        <div>Réduction Armure : <strong>${Math.round(s.derived.damageReduction * 100)}%</strong></div>
        <div>Chance Critique : <strong>${Math.round(s.derived.critChance * 100)}%</strong></div>
      `;
    }
  }

  private appendChatMessage(msg: ChatMessageData): void {
    const tagColor = msg.channel === 'GLOBAL' ? '#38bdf8' : msg.channel === 'PARTY' ? '#34d399' : '#fde047';
    const isInvitablePlayer =
      msg.channel !== 'SYSTEM' && msg.senderName !== this.selfState?.name && msg.senderName !== 'Système';
    const senderHtml = isInvitablePlayer
      ? `<b class="chat-sender-link" data-player-name="${this.esc(msg.senderName)}" style="cursor:pointer; text-decoration:underline dotted;" title="Cliquer pour inviter ${this.esc(msg.senderName)}">${this.esc(msg.senderName)}</b>`
      : `<b>${this.esc(msg.senderName)}</b>`;
    const html = `<span style="color:${tagColor}">[${msg.channel}]</span> ${senderHtml}: ${this.esc(msg.content)}`;
    this.chatHistory.push({ channel: msg.channel, html });
    if (this.chatHistory.length > 120) this.chatHistory.shift();
    this.renderChatLog();
  }

  private renderChatLog(): void {
    const log = document.getElementById('chat-log');
    if (!log) return;
    const filtered =
      this.activeChatTab === 'COMBAT'
        ? this.chatHistory.filter((c) => c.channel === 'COMBAT')
        : this.chatHistory.filter((c) => c.channel === this.activeChatTab || c.channel === 'SYSTEM');

    log.innerHTML = filtered.slice(-40).map((c) => `<div class="chat-line">${c.html}</div>`).join('');
    log.querySelectorAll<HTMLElement>('.chat-sender-link').forEach((el) => {
      el.addEventListener('click', () => {
        const name = el.dataset.playerName;
        const partyInput = document.getElementById('party-invite-input') as HTMLInputElement | null;
        if (name && partyInput) {
          partyInput.value = name;
          partyInput.focus();
        }
      });
    });
    log.scrollTop = log.scrollHeight;
  }

  private createNameplate(label: string, textColor: string, barColor: string): HTMLDivElement {
    const el = document.createElement('div');
    el.className = 'nameplate';
    el.innerHTML = `
      <div class="np-label" style="color:${textColor}">${this.esc(label)}</div>
      <div class="np-hp"><i style="width:100%; background:${barColor};"></i></div>
    `;
    this.floatingLayer.appendChild(el);
    return el;
  }

  private updateNameplate(
    el: HTMLDivElement,
    worldPos: THREE.Vector3,
    yOffset: number,
    hp: number,
    maxHp: number,
    visible: boolean,
  ): void {
    if (!visible) {
      el.style.display = 'none';
      return;
    }
    const dist = this.camera.position.distanceTo(worldPos);
    if (dist > 140) {
      el.style.display = 'none';
      return;
    }

    this.projVec.set(worldPos.x, worldPos.y + yOffset, worldPos.z).project(this.camera);
    if (this.projVec.z > 1) {
      el.style.display = 'none';
      return;
    }

    const sx = (this.projVec.x * 0.5 + 0.5) * window.innerWidth;
    const sy = (-this.projVec.y * 0.5 + 0.5) * window.innerHeight;
    el.style.display = 'block';
    el.style.left = `${sx}px`;
    el.style.top = `${sy}px`;
    const bar = el.querySelector('.np-hp > i') as HTMLElement | null;
    if (bar) bar.style.width = `${Math.max(0, Math.min(100, (hp / Math.max(1, maxHp)) * 100))}%`;
  }

  private spawnNpcs(): void {
    for (const npc of this.manifest.npcs) {
      const visual = createNpcMesh(npc.coatColor, npc.accentColor);
      visual.group.position.set(npc.x, npc.y, npc.z);
      visual.group.rotation.y = npc.rotY;
      visual.group.traverse((obj) => {
        obj.userData = { entityType: 'NPC', npcCode: npc.code };
      });
      this.scene.add(visual.group);

      const el = document.createElement('div');
      el.className = 'nameplate';
      el.innerHTML = `
        <div class="np-quest-badge" style="font-size:18px; font-weight:900; line-height:1; text-shadow:0 2px 5px #000;"></div>
        <div class="np-label" style="color:#fde047;">${this.esc(npc.name)}</div>
        <div style="font-size:10px; color:#5eead4; text-shadow:0 1px 3px #000;">&lt;${this.esc(npc.title)}&gt;</div>
      `;
      this.floatingLayer.appendChild(el);

      this.npcs.set(npc.code, { data: npc, visual, nameplate: el });
    }
  }

  private updateNpcsFrame(elapsed: number): void {
    let closestCode: string | null = null;
    let closestDist = 17.0;

    for (const [code, entry] of this.npcs) {
      const marker = this.questState?.npcMarkers[code] ?? 'NONE';
      const showCrystal = marker === 'AVAILABLE' || marker === 'TURN_IN';
      entry.visual.markerMesh.visible = showCrystal;
      if (showCrystal) {
        entry.visual.markerMesh.position.y = 3.7 + Math.sin(elapsed * 4.0) * 0.25;
        entry.visual.markerMesh.rotation.y = elapsed * 2.2;
        const col = marker === 'TURN_IN' ? 0x34d399 : 0xfacc15;
        entry.visual.markerMat.color.setHex(col);
        entry.visual.markerMat.emissive.setHex(col);
      }

      const d = Math.hypot(entry.data.x - this.controller.x, entry.data.z - this.controller.z);
      if (d < closestDist) {
        closestDist = d;
        closestCode = code;
      }

      // Project nameplate
      const distCam = this.camera.position.distanceTo(entry.visual.group.position);
      if (distCam > 150) {
        entry.nameplate.style.display = 'none';
        continue;
      }
      this.projVec.set(entry.data.x, entry.data.y + 4.0, entry.data.z).project(this.camera);
      if (this.projVec.z > 1) {
        entry.nameplate.style.display = 'none';
        continue;
      }
      const sx = (this.projVec.x * 0.5 + 0.5) * window.innerWidth;
      const sy = (-this.projVec.y * 0.5 + 0.5) * window.innerHeight;
      entry.nameplate.style.display = 'block';
      entry.nameplate.style.left = `${sx}px`;
      entry.nameplate.style.top = `${sy}px`;

      const badge = entry.nameplate.querySelector('.np-quest-badge') as HTMLElement | null;
      if (badge) {
        if (marker === 'TURN_IN') {
          badge.textContent = '?';
          badge.style.color = '#34d399';
        } else if (marker === 'AVAILABLE') {
          badge.textContent = '!';
          badge.style.color = '#fde047';
        } else if (marker === 'IN_PROGRESS') {
          badge.textContent = '?';
          badge.style.color = '#94a3b8';
        } else {
          badge.textContent = '';
        }
      }
    }

    this.nearestNpcCode = closestCode;
    const promptEl = document.getElementById('npc-interact-prompt');
    if (promptEl) {
      if (closestCode && this.activeNpcModalCode === null && !this.charModalOpen && !this.bagModalOpen && !(this.selfState?.isDead ?? false)) {
        const npc = this.npcs.get(closestCode)?.data;
        promptEl.textContent = this.nearestLootId !== null
          ? `💬 Cliquez pour parler à ${npc?.name ?? 'PNJ'}`
          : `💬 Appuyez sur [F] ou cliquez pour parler à ${npc?.name ?? 'PNJ'}`;
        promptEl.classList.add('visible');
      } else {
        promptEl.classList.remove('visible');
      }
    }
  }

  private applyQuestState(quests: QuestStatePayload, isFirst: boolean): void {
    const signature = JSON.stringify(quests);
    const changed = signature !== this.lastQuestSignature;
    this.lastQuestSignature = signature;
    this.questState = quests;

    for (const q of quests.active) {
      if (q.status === 'READY' && !this.notifiedReadyQuests.has(q.code)) {
        this.notifiedReadyQuests.add(q.code);
        if (!isFirst) {
          const npcName = this.npcs.get(q.turnInNpcCode)?.data.name ?? 'votre guide';
          this.showToast(`Quête prête : ${q.title} — Retournez voir ${npcName} !`);
        }
      }
    }

    if (changed) {
      this.updateQuestTracker();
      if (this.activeNpcModalCode !== null) {
        this.renderNpcModalContent(this.activeNpcModalCode);
      }
    }
  }

  private updateQuestTracker(): void {
    const qState = this.questState;
    const counterEl = document.getElementById('qt-counter');
    const bodyEl = document.getElementById('qt-body');
    if (!qState || !bodyEl) return;

    if (counterEl) {
      counterEl.textContent = `${qState.completedCodes.length}/${qState.totalQuests}`;
    }

    const parts: string[] = [];

    for (const q of qState.active) {
      const ready = q.status === 'READY';
      const turnInName = this.npcs.get(q.turnInNpcCode)?.data.name ?? q.turnInNpcCode;
      parts.push(`
        <div class="qt-item ${ready ? 'ready' : ''}">
          <div class="qt-item-title">${ready ? '✅' : '⚔️'} ${this.esc(q.title)}</div>
          <div class="qt-item-obj">
            ${ready ? `Prête ! Parlez à <b>${this.esc(turnInName)}</b>` : `${this.esc(q.targetLabel)} : <b>${q.progress}/${q.targetCount}</b>`}
          </div>
          <div class="qt-dist" id="qt-dist-primary"></div>
        </div>
      `);
    }

    if (qState.active.length === 0) {
      if (qState.guidance) {
        parts.push(`
          <div class="qt-item">
            <div class="qt-item-title">❗ ${this.esc(qState.guidance.title)}</div>
            <div class="qt-item-obj">${this.esc(qState.guidance.text)}</div>
            <div class="qt-dist" id="qt-dist-primary"></div>
          </div>
        `);
      } else {
        parts.push(
          '<div style="color:#34d399; font-size:11px;">🏆 Toutes les quêtes de La Réunion sont terminées ! Bravo !</div>',
        );
      }
    }

    bodyEl.innerHTML = parts.join('');
    this.updateQuestDistanceLabel();
  }

  private updateQuestDistanceLabel(): void {
    const distEl = document.getElementById('qt-dist-primary');
    const g = this.questState?.guidance;
    if (!distEl || !g) return;
    if (g.mode === 'LEVEL_UP') {
      distEl.textContent = 'Gagnez de l\'XP en affrontant les créatures proches';
      return;
    }
    const dist = Math.round(Math.hypot(g.x - this.controller.x, g.z - this.controller.z));
    const zoneName = this.manifest.zones[g.zoneCode]?.name ?? '';
    distEl.textContent = `🧭 ${dist} m${zoneName ? ` · ${zoneName}` : ''}`;
  }

  private openNpcModal(npcCode: string): void {
    const entry = this.npcs.get(npcCode);
    if (!entry) return;

    const dist = Math.hypot(entry.data.x - this.controller.x, entry.data.z - this.controller.z);
    if (dist > 20.0) {
      this.showToast(`Approchez-vous de ${entry.data.name} (${Math.round(dist)}m).`);
      return;
    }

    if (this.bagModalOpen) this.toggleBagModal(false);
    if (this.charModalOpen) this.toggleCharModal(false);

    this.activeNpcModalCode = npcCode;
    this.controller.setInputBlocked(true);
    document.getElementById('npc-modal')?.classList.add('open');
    this.switchNpcModalTab('QUESTS');
    this.renderNpcModalContent(npcCode);
    this.renderNpcVendorContent(npcCode);
  }

  private closeNpcModal(): void {
    this.activeNpcModalCode = null;
    document.getElementById('npc-modal')?.classList.remove('open');
    this.controller.setInputBlocked(this.charModalOpen || this.bagModalOpen);
  }

  private switchNpcModalTab(tab: 'QUESTS' | 'VENDOR'): void {
    this.activeNpcModalTab = tab;
    document.querySelectorAll<HTMLButtonElement>('[data-npc-tab]').forEach((b) => {
      b.classList.toggle('active', b.dataset.npcTab === tab);
    });
    const qEl = document.getElementById('npc-modal-quests');
    const vEl = document.getElementById('npc-modal-vendor');
    if (qEl) qEl.style.display = tab === 'QUESTS' ? 'block' : 'none';
    if (vEl) vEl.style.display = tab === 'VENDOR' ? 'block' : 'none';
    if (tab === 'VENDOR' && this.activeNpcModalCode) {
      this.renderNpcVendorContent(this.activeNpcModalCode);
    }
  }

  private renderNpcModalContent(npcCode: string): void {
    const entry = this.npcs.get(npcCode);
    if (!entry) return;

    const nameEl = document.getElementById('npc-modal-name');
    const titleEl = document.getElementById('npc-modal-title');
    const greetEl = document.getElementById('npc-modal-greeting');
    const listEl = document.getElementById('npc-modal-quests');
    if (nameEl) nameEl.textContent = entry.data.name;
    if (titleEl) titleEl.textContent = entry.data.title;
    if (greetEl) greetEl.textContent = `« ${entry.data.greeting} »`;
    if (!listEl) return;

    const qState = this.questState;
    const allQuests = Object.values(this.manifest.quests).sort((a, b) => a.order - b.order);
    const activeByCode = new Map(qState?.active.map((q) => [q.code, q]) ?? []);
    const completed = new Set(qState?.completedCodes ?? []);
    const available = new Set(qState?.availableCodes ?? []);
    const playerLvl = this.selfState?.level ?? 1;

    const cards: string[] = [];

    // 1. Quests ready to turn in to this NPC
    for (const q of allQuests) {
      const act = activeByCode.get(q.code);
      if (act && act.status === 'READY' && q.turnInNpcCode === npcCode) {
        cards.push(`
          <div class="quest-card-modal ready">
            <h4>✅ ${this.esc(q.title)} <span class="lvl-badge">Prête</span></h4>
            <p>« ${this.esc(q.completionText)} »</p>
            <div class="quest-reward-line">Récompense : +${q.rewardXp} XP${q.rewardStatPoints > 0 ? ` · +${q.rewardStatPoints} pt(s) de stat` : ''}</div>
            <button type="button" class="btn btn-lagoon" style="width:100%;" data-complete-quest="${q.code}">Rendre la quête</button>
          </div>
        `);
      }
    }

    // 2. Quests available from this NPC
    for (const q of allQuests) {
      if (available.has(q.code) && q.giverNpcCode === npcCode) {
        cards.push(`
          <div class="quest-card-modal available">
            <h4>❗ ${this.esc(q.title)} <span class="lvl-badge">Niv. ${q.recommendedLevel}</span></h4>
            <p>${this.esc(q.description)}</p>
            <p style="color:#e2e8f0; margin-bottom:6px;"><b>Objectif :</b> ${this.esc(q.targetLabel)} (${q.targetCount})</p>
            <div class="quest-reward-line">Récompense : +${q.rewardXp} XP${q.rewardStatPoints > 0 ? ` · +${q.rewardStatPoints} pt(s) de stat` : ''}</div>
            <button type="button" class="btn btn-lava" style="width:100%;" data-accept-quest="${q.code}">Accepter la quête</button>
          </div>
        `);
      }
    }

    // 3. In-progress quests for this NPC
    for (const q of allQuests) {
      const act = activeByCode.get(q.code);
      if (act && act.status === 'ACTIVE' && (q.giverNpcCode === npcCode || q.turnInNpcCode === npcCode)) {
        cards.push(`
          <div class="quest-card-modal">
            <h4>⏳ ${this.esc(q.title)} <span style="font-size:11px; color:#94a3b8;">En cours</span></h4>
            <p>${this.esc(q.targetLabel)} : <b>${act.progress} / ${act.targetCount}</b></p>
          </div>
        `);
      }
    }

    // 4. Upcoming locked quests at this NPC (level or prerequisite not met yet)
    for (const q of allQuests) {
      if (q.giverNpcCode !== npcCode || completed.has(q.code) || activeByCode.has(q.code) || available.has(q.code)) {
        continue;
      }
      const prereqDone = !q.prerequisiteQuestCode || completed.has(q.prerequisiteQuestCode);
      if (prereqDone && playerLvl < q.minLevel) {
        cards.push(`
          <div class="quest-card-modal" style="opacity:0.7;">
            <h4>🔒 ${this.esc(q.title)} <span style="font-size:11px; color:#fca5a5;">Requis : Niv. ${q.minLevel}</span></h4>
            <p>Revenez me voir une fois au niveau ${q.minLevel} !</p>
          </div>
        `);
      }
    }

    if (cards.length === 0) {
      const guideHint = qState?.guidance
        ? `<br><span style="color:#5eead4;">Conseil : ${this.esc(qState.guidance.text)}</span>`
        : '';
      cards.push(`<p style="color:var(--text-muted); font-size:0.88rem;">Je n'ai plus de tâche pour toi ici pour le moment.${guideHint}</p>`);
    }

    listEl.innerHTML = cards.join('');

    listEl.querySelectorAll<HTMLButtonElement>('[data-accept-quest]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const code = btn.dataset.acceptQuest;
        if (!code) return;
        const res = await this.net.acceptQuest(this.characterId, code);
        if (!res.ok) {
          this.showToast(res.error ?? 'Impossible d\'accepter la quête.');
          return;
        }
        if (res.self) {
          this.selfState = res.self;
          this.updateSelfHud();
        }
        if (res.quests) {
          this.applyQuestState(res.quests, false);
        }
        this.showToast(`Quête acceptée : ${this.manifest.quests[code]?.title ?? code}`);
      });
    });

    listEl.querySelectorAll<HTMLButtonElement>('[data-complete-quest]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const code = btn.dataset.completeQuest;
        if (!code) return;
        const res = await this.net.completeQuest(this.characterId, code);
        if (!res.ok) {
          this.showToast(res.error ?? 'Impossible de valider la quête.');
          return;
        }
        if (res.self) {
          this.selfState = res.self;
          this.updateSelfHud();
        }
        if (res.quests) {
          this.applyQuestState(res.quests, false);
        }
        const bonus = res.rewardStatPoints && res.rewardStatPoints > 0 ? ` et +${res.rewardStatPoints} pt(s) de stat` : '';
        this.showToast(
          res.leveledUp
            ? `Quête validée ! +${res.rewardXp} XP${bonus} — NIVEAU ${res.newLevel} !`
            : `Quête validée ! +${res.rewardXp} XP${bonus}`,
        );
      });
    });
  }

  private reconcileLootDrops(drops: WorldLootDropState[]): void {
    const seen = new Set<number>();
    for (const d of drops) {
      seen.add(d.id);
      let entry = this.lootDrops.get(d.id);
      if (!entry) {
        const visual = createLootDropMesh(d.highestRarity);
        visual.group.position.set(d.x, d.y, d.z);
        visual.group.traverse((obj) => {
          obj.userData = { entityType: 'LOOT', entityId: d.id };
        });
        this.scene.add(visual.group);

        const color = RARITY_COLORS[d.highestRarity] ?? '#e2e8f0';
        const itemPreview = d.items
          .map((it) => `${this.manifest.items[it.itemCode]?.name ?? it.itemCode}${it.quantity > 1 ? ` ×${it.quantity}` : ''}`)
          .slice(0, 2)
          .join(', ');
        const el = document.createElement('div');
        el.className = 'nameplate';
        el.innerHTML = `<div class="np-label" style="color:${color}; background:rgba(7,17,26,0.72); border:1px solid ${color}55;">💰 ${d.gold > 0 ? `${d.gold} Or` : ''}${d.gold > 0 && itemPreview ? ' · ' : ''}${this.esc(itemPreview)}</div>`;
        this.floatingLayer.appendChild(el);

        entry = { state: d, visual, nameplate: el };
        this.lootDrops.set(d.id, entry);
      } else {
        entry.state = d;
      }
    }

    for (const [id, entry] of this.lootDrops) {
      if (!seen.has(id)) {
        this.scene.remove(entry.visual.group);
        entry.nameplate.remove();
        this.lootDrops.delete(id);
      }
    }
  }

  private updateLootDropsFrame(elapsed: number): void {
    let closestId: number | null = null;
    let closestDist = 15.0;

    for (const [id, entry] of this.lootDrops) {
      entry.visual.chest.rotation.y = elapsed * 1.4 + id;
      entry.visual.chest.position.y = Math.sin(elapsed * 3.2 + id) * 0.14;

      const d = Math.hypot(entry.state.x - this.controller.x, entry.state.z - this.controller.z);
      if (entry.state.canLoot && d < closestDist) {
        closestDist = d;
        closestId = id;
      }

      const distCam = this.camera.position.distanceTo(entry.visual.group.position);
      if (distCam > 110) {
        entry.nameplate.style.display = 'none';
        continue;
      }
      this.projVec.set(entry.state.x, entry.state.y + 1.8, entry.state.z).project(this.camera);
      if (this.projVec.z > 1) {
        entry.nameplate.style.display = 'none';
        continue;
      }
      entry.nameplate.style.display = 'block';
      entry.nameplate.style.left = `${(this.projVec.x * 0.5 + 0.5) * window.innerWidth}px`;
      entry.nameplate.style.top = `${(-this.projVec.y * 0.5 + 0.5) * window.innerHeight}px`;
    }

    this.nearestLootId = closestId;
    const promptEl = document.getElementById('loot-pickup-prompt');
    if (promptEl) {
      const show = closestId !== null && this.activeNpcModalCode === null && !this.charModalOpen && !this.bagModalOpen && !(this.selfState?.isDead ?? false);
      promptEl.classList.toggle('visible', show);
      if (show && closestId !== null) {
        const drop = this.lootDrops.get(closestId)?.state;
        promptEl.textContent = `💰 Appuyez sur [F] ou cliquez pour ramasser le butin (${drop?.sourceName ?? 'Coffre'})`;
      }
    }
  }

  private async pickupLootById(lootId: number): Promise<void> {
    const res = await this.net.inventoryPost<{
      goldGained?: number;
      itemsGained?: { itemCode: string; name: string; quantity: number }[];
      remaining?: boolean;
    }>('/api/loot/pickup', {
      characterId: this.characterId,
      lootId,
    });

    if (!res.ok) {
      this.showToast(res.error ?? 'Impossible de ramasser ce butin.');
      return;
    }

    if (res.self) {
      this.selfState = res.self;
      this.updateSelfHud();
    }
    if (res.inventory) {
      this.applyInventoryState(res.inventory);
    }

    if (!res.remaining) {
      const entry = this.lootDrops.get(lootId);
      if (entry) {
        this.scene.remove(entry.visual.group);
        entry.nameplate.remove();
        this.lootDrops.delete(lootId);
      }
    }

    const parts: string[] = [];
    if (res.goldGained && res.goldGained > 0) parts.push(`+${res.goldGained} Or`);
    if (res.itemsGained) {
      for (const it of res.itemsGained) {
        parts.push(`${it.name}${it.quantity > 1 ? ` ×${it.quantity}` : ''}`);
      }
    }
    this.showToast(parts.length > 0 ? `Butin : ${parts.join(', ')}` : 'Butin ramassé !');
  }

  private applyInventoryState(inv: InventoryStatePayload): void {
    const sig = JSON.stringify(inv);
    const changed = sig !== this.lastInventorySignature;
    this.lastInventorySignature = sig;
    this.inventoryState = inv;

    const badge = document.getElementById('bag-count-badge');
    if (badge) badge.textContent = `${inv.bagSlotsUsed}/${inv.maxBagSlots}`;

    if (changed) {
      const equipVisuals: Partial<Record<EquipmentSlot, EquippedSlotVisual | null>> = {};
      for (const slot of ['WEAPON', 'ARMOR', 'HELM', 'ACCESSORY'] as EquipmentSlot[]) {
        const entry = inv.equipped[slot];
        const def = entry ? this.manifest.items[entry.itemCode] : undefined;
        equipVisuals[slot] = entry && def ? { itemCode: entry.itemCode, rarity: def.rarity } : null;
      }
      applyAvatarEquipment(this.selfAvatar, equipVisuals);

      if (this.bagModalOpen) this.renderBagModalContent();
      if (this.activeNpcModalCode !== null && this.activeNpcModalTab === 'VENDOR') {
        this.renderNpcVendorContent(this.activeNpcModalCode);
      }
    }
  }

  private toggleBagModal(open: boolean): void {
    this.bagModalOpen = open;
    document.getElementById('bag-modal')?.classList.toggle('open', open);
    this.controller.setInputBlocked(open || this.charModalOpen || this.activeNpcModalCode !== null);
    if (open) this.renderBagModalContent();
  }

  private formatItemDetails(def: ItemDefinitionData): string {
    const parts: string[] = [];
    const bonusLabels: Record<string, string> = {
      vitality: 'VIT',
      strength: 'STR',
      intelligence: 'INT',
      defense: 'DEF',
      spirit: 'SPI',
    };
    for (const [k, v] of Object.entries(def.bonuses)) {
      if (v && v > 0) parts.push(`+${v} ${bonusLabels[k] ?? k}`);
    }
    if (def.restoreHp > 0) parts.push(`+${def.restoreHp} PV`);
    if (def.restoreMp > 0) parts.push(`+${def.restoreMp} PM`);
    if (def.minLevel > 1) parts.push(`Niv. ${def.minLevel}`);
    if (def.sellPrice > 0) parts.push(`Vente: ${def.sellPrice} Or`);
    return parts.join(' · ') || def.description;
  }

  private renderBagModalContent(): void {
    const inv = this.inventoryState;
    if (!inv) return;

    const goldEl = document.getElementById('bag-modal-gold');
    const slotsEl = document.getElementById('bag-modal-slots');
    if (goldEl) goldEl.textContent = `🪙 ${inv.gold}`;
    if (slotsEl) slotsEl.textContent = `${inv.bagSlotsUsed} / ${inv.maxBagSlots}`;

    const eqEl = document.getElementById('bag-equipped-list');
    if (eqEl) {
      const slots: EquipmentSlot[] = ['WEAPON', 'ARMOR', 'HELM', 'ACCESSORY'];
      eqEl.innerHTML = slots
        .map((slot) => {
          const ci = inv.equipped[slot];
          if (!ci) {
            return `
              <div class="equip-slot-card" style="opacity:0.65;">
                <div class="equip-slot-label">${SLOT_LABELS[slot]}</div>
                <div style="font-size:12px; color:var(--text-muted);">Vide</div>
              </div>
            `;
          }
          const def = this.manifest.items[ci.itemCode];
          const col = def ? RARITY_COLORS[def.rarity] : '#e2e8f0';
          return `
            <div class="equip-slot-card" style="border-color:${col}66;">
              <div class="equip-slot-label">${SLOT_LABELS[slot]}</div>
              <div style="display:flex; justify-content:space-between; align-items:center; gap:6px;">
                <div style="min-width:0;">
                  <div style="font-weight:700; font-size:12.5px; color:${col};">${def?.icon ?? '📦'} ${this.esc(def?.name ?? ci.itemCode)}</div>
                  <div style="font-size:10.5px; color:#86efac;">${def ? this.esc(this.formatItemDetails(def)) : ''}</div>
                </div>
                <button type="button" class="mini-btn" data-unequip-slot="${slot}">Retirer</button>
              </div>
            </div>
          `;
        })
        .join('');

      eqEl.querySelectorAll<HTMLButtonElement>('[data-unequip-slot]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const slot = btn.dataset.unequipSlot;
          if (!slot) return;
          const res = await this.net.inventoryPost('/api/inventory/unequip', { characterId: this.characterId, slot });
          if (!res.ok) {
            this.showToast(res.error ?? 'Erreur déséquipement.');
            return;
          }
          if (res.self) {
            this.selfState = res.self;
            this.updateSelfHud();
          }
          if (res.inventory) this.applyInventoryState(res.inventory);
        });
      });
    }

    const bagEl = document.getElementById('bag-items-list');
    if (bagEl) {
      if (inv.bag.length === 0) {
        bagEl.innerHTML = '<p style="color:var(--text-muted); font-size:12px;">Votre sac est vide. Combattez des monstres sur l\'île pour récupérer du butin !</p>';
        return;
      }

      bagEl.innerHTML = inv.bag
        .map((ci) => {
          const def = this.manifest.items[ci.itemCode];
          if (!def) return '';
          const col = RARITY_COLORS[def.rarity];
          const primaryBtn =
            def.type === 'EQUIPMENT'
              ? `<button type="button" class="mini-btn" data-equip-id="${ci.id}">Équiper</button>`
              : def.type === 'CONSUMABLE'
                ? `<button type="button" class="mini-btn" style="background:rgba(16,185,129,0.28); color:#a7f3d0;" data-use-id="${ci.id}">Utiliser</button>`
                : '';

          return `
            <div class="bag-row" style="border-left-color:${col};">
              <div class="bag-row-main">
                <div class="bag-row-title" style="color:${col};">
                  <span>${def.icon}</span>
                  <span>${this.esc(def.name)}</span>
                  ${ci.quantity > 1 ? `<span class="lvl-badge">×${ci.quantity}</span>` : ''}
                </div>
                <div class="bag-row-sub">${this.esc(this.formatItemDetails(def))}</div>
              </div>
              <div class="bag-row-actions">
                ${primaryBtn}
                <button type="button" class="mini-btn mini-btn-red" data-drop-id="${ci.id}" title="Jeter">✕</button>
              </div>
            </div>
          `;
        })
        .join('');

      bagEl.querySelectorAll<HTMLButtonElement>('[data-equip-id]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const res = await this.net.inventoryPost('/api/inventory/equip', {
            characterId: this.characterId,
            characterItemId: Number(btn.dataset.equipId),
          });
          if (!res.ok) {
            this.showToast(res.error ?? 'Impossible d\'équiper.');
            return;
          }
          if (res.self) {
            this.selfState = res.self;
            this.updateSelfHud();
          }
          if (res.inventory) this.applyInventoryState(res.inventory);
          this.showToast('Équipement équipé ! Vos stats ont été mises à jour.');
        });
      });

      bagEl.querySelectorAll<HTMLButtonElement>('[data-use-id]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const res = await this.net.inventoryPost<{ restoredHp?: number; restoredMp?: number }>('/api/inventory/use', {
            characterId: this.characterId,
            characterItemId: Number(btn.dataset.useId),
          });
          if (!res.ok) {
            this.showToast(res.error ?? 'Utilisation impossible.');
            return;
          }
          if (res.self) {
            this.selfState = res.self;
            this.updateSelfHud();
          }
          if (res.inventory) this.applyInventoryState(res.inventory);
          const msg: string[] = [];
          if (res.restoredHp) msg.push(`+${res.restoredHp} PV`);
          if (res.restoredMp) msg.push(`+${res.restoredMp} PM`);
          this.showToast(msg.length > 0 ? `Consommé : ${msg.join(' · ')}` : 'Objet consommé.');
        });
      });

      bagEl.querySelectorAll<HTMLButtonElement>('[data-drop-id]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const res = await this.net.inventoryPost('/api/inventory/drop', {
            characterId: this.characterId,
            characterItemId: Number(btn.dataset.dropId),
          });
          if (!res.ok) {
            this.showToast(res.error ?? 'Impossible de jeter.');
            return;
          }
          if (res.inventory) this.applyInventoryState(res.inventory);
        });
      });
    }
  }

  private renderNpcVendorContent(npcCode: string): void {
    const vEl = document.getElementById('npc-modal-vendor');
    const inv = this.inventoryState;
    if (!vEl || !inv) return;

    let junkTotal = 0;
    let junkCount = 0;
    for (const ci of inv.bag) {
      const def = this.manifest.items[ci.itemCode];
      if (def && def.type === 'JUNK') {
        junkTotal += def.sellPrice * ci.quantity;
        junkCount += ci.quantity;
      }
    }

    const sellRows = inv.bag
      .map((ci) => {
        const def = this.manifest.items[ci.itemCode];
        if (!def || def.sellPrice <= 0) return '';
        const col = RARITY_COLORS[def.rarity];
        const total = def.sellPrice * ci.quantity;
        return `
          <div class="bag-row" style="border-left-color:${col};">
            <div class="bag-row-main">
              <div class="bag-row-title" style="color:${col};">${def.icon} ${this.esc(def.name)} ${ci.quantity > 1 ? `<span class="lvl-badge">×${ci.quantity}</span>` : ''}</div>
              <div class="bag-row-sub">${this.esc(this.formatItemDetails(def))}</div>
            </div>
            <button type="button" class="mini-btn" style="background:rgba(250,204,21,0.2); color:#fde047;" data-sell-id="${ci.id}">Vendre (+${total} 🪙)</button>
          </div>
        `;
      })
      .join('');

    const stockCodes = this.manifest.vendorStocks[npcCode] ?? [];
    const buyRows = stockCodes
      .map((code) => {
        const def = this.manifest.items[code];
        if (!def || def.buyPrice <= 0) return '';
        const col = RARITY_COLORS[def.rarity];
        const canAfford = inv.gold >= def.buyPrice;
        return `
          <div class="bag-row" style="border-left-color:${col};">
            <div class="bag-row-main">
              <div class="bag-row-title" style="color:${col};">${def.icon} ${this.esc(def.name)}</div>
              <div class="bag-row-sub">${this.esc(this.formatItemDetails(def))}</div>
            </div>
            <button type="button" class="mini-btn" ${canAfford ? '' : 'style="opacity:0.45;"'} data-buy-code="${def.code}">Acheter (${def.buyPrice} 🪙)</button>
          </div>
        `;
      })
      .join('');

    vEl.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <span class="gold-pill">Votre bourse : 🪙 ${inv.gold} Or (${inv.bagSlotsUsed}/${inv.maxBagSlots})</span>
        ${
          junkCount > 0
            ? `<button type="button" class="mini-btn" style="background:rgba(250,204,21,0.28); color:#fef08a;" id="btn-sell-all-junk">🪙 Vendre tout le butin (+${junkTotal} Or)</button>`
            : ''
        }
      </div>

      <div style="font-size:11px; text-transform:uppercase; color:#fde047; font-weight:700; margin-bottom:5px;">Vendre depuis votre sac</div>
      <div class="bag-list" style="max-height:175px; margin-bottom:12px;">
        ${sellRows || '<div style="color:var(--text-muted); font-size:12px;">Aucun objet à vendre dans votre sac.</div>'}
      </div>

      <div style="font-size:11px; text-transform:uppercase; color:#5eead4; font-weight:700; margin-bottom:5px;">Boutique du PNJ</div>
      <div class="bag-list" style="max-height:195px;">
        ${buyRows}
      </div>
    `;

    vEl.querySelector('#btn-sell-all-junk')?.addEventListener('click', async () => {
      const res = await this.net.inventoryPost<{ goldEarned?: number; soldCount?: number }>('/api/vendor/sell-junk', {
        characterId: this.characterId,
        npcCode,
      });
      if (!res.ok) {
        this.showToast(res.error ?? 'Vente impossible.');
        return;
      }
      if (res.self) {
        this.selfState = res.self;
        this.updateSelfHud();
      }
      if (res.inventory) this.applyInventoryState(res.inventory);
      this.showToast(`Vendu ${res.soldCount ?? 0} objet(s) de butin pour +${res.goldEarned ?? 0} Or !`);
    });

    vEl.querySelectorAll<HTMLButtonElement>('[data-sell-id]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const res = await this.net.inventoryPost<{ goldEarned?: number; itemName?: string }>('/api/vendor/sell', {
          characterId: this.characterId,
          characterItemId: Number(btn.dataset.sellId),
          npcCode,
        });
        if (!res.ok) {
          this.showToast(res.error ?? 'Vente impossible.');
          return;
        }
        if (res.self) {
          this.selfState = res.self;
          this.updateSelfHud();
        }
        if (res.inventory) this.applyInventoryState(res.inventory);
        this.showToast(`Vendu : ${res.itemName ?? 'objet'} (+${res.goldEarned ?? 0} Or)`);
      });
    });

    vEl.querySelectorAll<HTMLButtonElement>('[data-buy-code]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const res = await this.net.inventoryPost<{ itemName?: string; cost?: number }>('/api/vendor/buy', {
          characterId: this.characterId,
          itemCode: btn.dataset.buyCode,
          npcCode,
        });
        if (!res.ok) {
          this.showToast(res.error ?? 'Achat impossible.');
          return;
        }
        if (res.self) {
          this.selfState = res.self;
          this.updateSelfHud();
        }
        if (res.inventory) this.applyInventoryState(res.inventory);
        this.showToast(`Acheté : ${res.itemName ?? 'article'} (-${res.cost ?? 0} Or)`);
      });
    });
  }

  private updateWorldBossHud(): void {
    const wb = this.worldBossState;
    const hudEl = document.getElementById('world-boss-hud');
    const pillEl = document.getElementById('wb-minimap-pill');
    const pillStatus = document.getElementById('wb-minimap-status');

    if (pillEl && pillStatus) {
      if (!wb) {
        pillEl.style.display = 'none';
      } else {
        pillEl.style.display = 'flex';
        const isDead = wb.state === 'DEAD';
        pillEl.classList.toggle('dead', isDead);
        if (isDead) {
          const secs = Math.max(0, Math.ceil(wb.respawnRemaining));
          const mins = Math.floor(secs / 60);
          const rem = secs % 60;
          pillStatus.textContent = `Respawn ${mins}m${String(rem).padStart(2, '0')}s`;
        } else if (wb.state === 'COMBAT') {
          const pct = Math.max(1, Math.round((wb.hp / Math.max(1, wb.maxHp)) * 100));
          pillStatus.textContent = `⚔️ ${pct}% (${wb.participantCount}/10)`;
        } else {
          pillStatus.textContent = 'Actif · Fournaise';
        }
      }
    }

    if (!hudEl) return;
    if (!wb || wb.state === 'DEAD') {
      hudEl.classList.remove('visible');
      return;
    }

    const dist = Math.hypot(wb.x - this.controller.x, wb.z - this.controller.z);
    const inZoneOrNear = this.selfState?.zoneCode === wb.zoneCode || dist <= 220;
    hudEl.classList.toggle('visible', inZoneOrNear);
    if (!inZoneOrNear) return;

    const pct = Math.max(0, Math.min(100, (wb.hp / Math.max(1, wb.maxHp)) * 100));
    const nameEl = document.getElementById('wb-name');
    const badgeEl = document.getElementById('wb-phase-badge');
    const fillEl = document.getElementById('wb-hp-fill');
    const txtEl = document.getElementById('wb-hp-text');
    const alliesEl = document.getElementById('wb-allies');
    const capEl = document.getElementById('wb-carapace');

    if (nameEl) nameEl.textContent = wb.name;
    if (fillEl) fillEl.style.width = `${pct.toFixed(1)}%`;
    if (txtEl) txtEl.textContent = `${wb.hp.toLocaleString('fr-FR')} / ${wb.maxHp.toLocaleString('fr-FR')} (${Math.round(pct)}%)`;

    if (badgeEl) {
      if (wb.phase >= 2) {
        badgeEl.textContent = '🔥 PHASE 2 : FUREUR';
        badgeEl.classList.add('phase2');
      } else {
        badgeEl.textContent = `RAID ${wb.recommendedPlayers} JOUEURS`;
        badgeEl.classList.remove('phase2');
      }
    }

    if (alliesEl) {
      alliesEl.textContent = `👥 ${wb.participantCount} / ${wb.recommendedPlayers} Alliés`;
    }

    if (capEl) {
      if (wb.carapaceActive) {
        capEl.textContent = '🛡️ Carapace (Régén. actif · 3+ requis)';
        capEl.className = 'wb-pill warn';
      } else {
        capEl.textContent = '⚔️ Carapace Brisée !';
        capEl.className = 'wb-pill ok';
      }
    }
  }

  private showToast(msg: string): void {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    if (this.toastTimeout) window.clearTimeout(this.toastTimeout);
    this.toastTimeout = window.setTimeout(() => el.classList.remove('show'), 2800);
  }

  private showZoneBanner(title: string, sub: string): void {
    const el = document.getElementById('zone-banner');
    const t = document.getElementById('zone-banner-title');
    const s = document.getElementById('zone-banner-sub');
    if (!el || !t || !s) return;
    t.textContent = title;
    s.textContent = sub;
    el.classList.add('show');
    if (this.bannerTimeout) window.clearTimeout(this.bannerTimeout);
    this.bannerTimeout = window.setTimeout(() => el.classList.remove('show'), 3600);
  }

  private lerpAngle(a: number, b: number, t: number): number {
    let diff = b - a;
    while (diff > Math.PI) diff -= Math.PI * 2;
    while (diff < -Math.PI) diff += Math.PI * 2;
    return a + diff * t;
  }

  private esc(s: string): string {
    return s.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch] ?? ch);
  }
}

void new SymfonyForeverGame().boot();
