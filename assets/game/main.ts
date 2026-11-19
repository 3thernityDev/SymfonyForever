import * as THREE from 'three';
import {
  animateAvatar,
  createMonsterMesh,
  createPlayerAvatar,
  type AnimatedAvatar,
  type MonsterVisual,
} from './EntityMeshFactory';
import { Minimap } from './Minimap';
import { NetworkClient } from './NetworkClient';
import { PlayerController } from './PlayerController';
import { ReunionWorldRenderer } from './ReunionWorldRenderer';
import { TerrainSampler } from './TerrainSampler';
import type {
  ChatMessageData,
  ClassCode,
  CombatEventData,
  ManifestResponse,
  MonsterState,
  PartyState,
  PublicPlayer,
  SelfState,
  SkillData,
  SyncResponse,
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
  private classSkills: SkillData[] = [];

  private readonly remotePlayers = new Map<number, RemotePlayerEntry>();
  private readonly monsters = new Map<number, MonsterEntry>();
  private readonly seenEventIds = new Set<number>();

  private lastEventId = 0;
  private lastChatId = 0;
  private currentZoneCode = '';
  private targetType: 'MONSTER' | 'PLAYER' | null = null;
  private targetId: number | null = null;

  private activeChatTab: 'ZONE' | 'GLOBAL' | 'PARTY' | 'COMBAT' = 'ZONE';
  private readonly chatHistory: { channel: string; html: string }[] = [];
  private charModalOpen = false;
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
      this.controller = new PlayerController(this.canvas, this.sampler);

      this.selfAvatar = createPlayerAvatar(this.classCode);
      this.scene.add(this.selfAvatar.group);

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
      const initialSync = await this.net.syncWorld({
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

      if (initialSync) {
        this.controller.teleport(initialSync.self.x, initialSync.self.y, initialSync.self.z);
        this.applySync(initialSync, true);
      }

      document.getElementById('loading-screen')?.remove();
      this.startLoops();
    } catch (err) {
      setStatus(`Erreur au démarrage : ${err instanceof Error ? err.message : String(err)}`);
    }
  }

  private initThree(): void {
    this.scene = new THREE.Scene();
    this.camera = new THREE.PerspectiveCamera(58, window.innerWidth / window.innerHeight, 0.5, 1800);
    this.renderer = new THREE.WebGLRenderer({ canvas: this.canvas, antialias: true, powerPreference: 'high-performance' });
    this.renderer.setSize(window.innerWidth, window.innerHeight);
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
    this.renderer.toneMappingExposure = 1.05;

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
      animateAvatar(this.selfAvatar, dt, this.controller.isMoving, isDead, elapsed);

      // Interpolate remote players
      for (const [id, entry] of this.remotePlayers) {
        entry.avatar.group.position.lerp(entry.targetPos, Math.min(1, dt * 8));
        entry.avatar.group.rotation.y = this.lerpAngle(entry.avatar.group.rotation.y, entry.state.rotY, Math.min(1, dt * 9));
        entry.avatar.targetRing.visible = this.targetType === 'PLAYER' && this.targetId === id;
        animateAvatar(entry.avatar, dt, entry.state.isMoving, entry.state.isDead, elapsed + id);
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
        entry.visual.targetRing.visible = this.targetType === 'MONSTER' && this.targetId === id;
        const heightOffset = entry.state.isBoss ? 5.6 : 2.6 * entry.state.scale;
        this.updateNameplate(entry.nameplate, entry.visual.group.position, heightOffset, entry.state.hp, entry.state.maxHp, true);
      }

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
      );

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

    const resp = await this.net.syncWorld({
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

    const netDot = document.getElementById('net-dot');
    const netPing = document.getElementById('net-ping');
    if (!resp) {
      if (netDot) netDot.style.background = '#ef4444';
      if (netPing) netPing.textContent = 'Reconnexion...';
      return;
    }

    if (netDot) netDot.style.background = this.net.lastPingMs < 180 ? '#22c55e' : '#facc15';
    if (netPing) netPing.textContent = `HTTP ${this.net.lastPingMs} ms`;

    this.applySync(resp, false);
  }

  private applySync(resp: SyncResponse, isFirst: boolean): void {
    this.selfState = resp.self;
    this.partyState = resp.party;

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

    // Trigger remote attack animation
    if (ev.sourceType === 'PLAYER') {
      if (ev.sourceId === this.characterId) {
        this.selfAvatar.attackTimer = 0.4;
      } else {
        const rp = this.remotePlayers.get(ev.sourceId);
        if (rp) rp.avatar.attackTimer = 0.4;
      }
    }

    this.vfx.spawnFromEvent(ev, this.manifest.skills, this.camera, srcPos, tgtPos);

    // Log into Combat tab
    const skillName = this.manifest.skills[ev.skillCode]?.name ?? ev.skillCode;
    let line = '';
    if (ev.eventType === 'DAMAGE' || ev.eventType === 'DOT') {
      line = `<span style="color:#fca5a5">${this.esc(ev.sourceName)}</span> inflige <b>${ev.amount}</b>${ev.isCrit ? ' (CRIT!)' : ''} à ${this.esc(ev.targetName)} (${this.esc(skillName || 'Attaque')}).`;
    } else if (ev.eventType === 'HEAL' || ev.eventType === 'HOT') {
      line = `<span style="color:#34d399">${this.esc(ev.sourceName)}</span> soigne ${this.esc(ev.targetName)} de <b>+${ev.amount} PV</b>.`;
    } else if (ev.eventType === 'TAUNT') {
      line = `<span style="color:#fde047">${this.esc(ev.sourceName)} provoque ${this.esc(ev.targetName)} !</span>`;
    } else if (ev.eventType === 'KILL') {
      line = `<span style="color:#c084fc">${this.esc(ev.targetName)} vaincu ! (+${ev.amount} XP)</span>`;
    } else if (ev.eventType === 'LEVEL_UP') {
      line = `<span style="color:#fde047">★ ${this.esc(ev.sourceName)} passe Niveau ${ev.amount} !</span>`;
      if (ev.sourceId === this.characterId) {
        this.showToast(`Niveau ${ev.amount} atteint ! +3 points de stats disponibles (touche C).`);
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
    });

    // Global MMO hotkeys
    const chatInput = document.getElementById('chat-input') as HTMLInputElement;
    const partyInput = document.getElementById('party-invite-input') as HTMLInputElement;

    const handleFocusPause = () => {
      const typing = document.activeElement === chatInput || document.activeElement === partyInput;
      this.controller.setInputBlocked(typing || this.charModalOpen);
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
        } else if (this.charModalOpen) {
          this.toggleCharModal(false);
        } else {
          this.clearTarget();
        }
        return;
      }
      if (typing) return;

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

    // Skill bar clicks
    for (let slot = 1; slot <= 4; slot++) {
      document.getElementById(`skill-slot-${slot}`)?.addEventListener('click', () => {
        void this.castSlot(slot);
      });
    }

    // Character Sheet Modal
    document.getElementById('btn-char-sheet')?.addEventListener('click', () => this.toggleCharModal(!this.charModalOpen));
    document.getElementById('btn-close-char-modal')?.addEventListener('click', () => this.toggleCharModal(false));

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
    for (const m of this.monsters.values()) {
      if (m.state.state !== 'DEAD') pickables.push(m.visual.group);
    }
    for (const p of this.remotePlayers.values()) {
      pickables.push(p.avatar.group);
    }

    const hits = this.raycaster.intersectObjects(pickables, true);
    if (hits.length > 0) {
      const ud = hits[0].object.userData as { entityType?: 'MONSTER' | 'PLAYER'; entityId?: number };
      if (ud.entityType && ud.entityId) {
        this.setTarget(ud.entityType, ud.entityId);
        return;
      }
    }
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

  private async castSlot(slot: number): Promise<void> {
    const skill = this.classSkills.find((s) => s.slot === slot);
    if (!skill || !this.selfState) return;

    if (this.selfState.level < skill.unlockLevel) {
      this.showToast(`${skill.name} se débloque au niveau ${skill.unlockLevel}.`);
      return;
    }

    // Auto-pick nearest monster if enemy skill cast with no monster selected
    if ((skill.targetType === 'ENEMY' || skill.targetType === 'ENEMY_AOE') && this.targetType !== 'MONSTER') {
      this.cycleNearestMonster();
    }

    const res = await this.net.castSkill(this.characterId, skill.code, this.targetType, this.targetId);
    if (!res.ok) {
      if (res.error) this.showToast(res.error);
      return;
    }

    this.selfAvatar.attackTimer = 0.4;
    if (res.self) {
      this.selfState = res.self;
      this.updateSelfHud();
    }
    if (res.events) {
      for (const ev of res.events) {
        if (ev.id > this.lastEventId) this.lastEventId = ev.id;
        this.handleCombatEvent(ev);
      }
    }
  }

  private renderSkillButtonsStructure(): void {
    for (const skill of this.classSkills) {
      const btn = document.getElementById(`skill-slot-${skill.slot}`);
      if (!btn) continue;
      btn.title = `${skill.name} (Niv. ${skill.unlockLevel}) — ${skill.manaCost} PM, CD ${skill.cooldown}s\n${skill.description}`;
      btn.innerHTML = `
        <span class="skill-key">${skill.slot}</span>
        <span class="skill-mana">${skill.manaCost}</span>
        <span class="skill-icon">${skill.icon}</span>
        <span class="skill-name">${this.esc(skill.name)}</span>
        <div class="skill-cd-overlay"></div>
      `;
    }
  }

  private tickLocalCooldowns(dt: number): void {
    if (!this.selfState) return;
    if (this.selfState.gcdRemaining > 0) {
      this.selfState.gcdRemaining = Math.max(0, this.selfState.gcdRemaining - dt);
    }
    for (const k of Object.keys(this.selfState.cooldowns)) {
      this.selfState.cooldowns[k] = Math.max(0, this.selfState.cooldowns[k] - dt);
    }

    for (const skill of this.classSkills) {
      const btn = document.getElementById(`skill-slot-${skill.slot}`);
      if (!btn) continue;
      const locked = this.selfState.level < skill.unlockLevel;
      btn.classList.toggle('locked', locked);
      const overlay = btn.querySelector('.skill-cd-overlay') as HTMLElement | null;
      if (!overlay) continue;

      if (locked) {
        btn.classList.add('on-cd');
        overlay.textContent = `N.${skill.unlockLevel}`;
      } else {
        const rem = Math.max(this.selfState.gcdRemaining, this.selfState.cooldowns[skill.code] ?? 0);
        if (rem > 0.05) {
          btn.classList.add('on-cd');
          overlay.textContent = rem.toFixed(1);
        } else {
          btn.classList.remove('on-cd');
          overlay.textContent = '';
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

    setText('hud-level', String(s.level));
    setText('hud-hp-text', `${s.hp} / ${s.maxHp}`);
    setText('hud-mp-text', `${s.mp} / ${s.maxMp}`);
    setWidth('hud-hp-fill', (s.hp / Math.max(1, s.maxHp)) * 100);
    setWidth('hud-mp-fill', (s.mp / Math.max(1, s.maxMp)) * 100);

    setText('hud-xp-text', `XP ${s.xp} / ${s.xpNext}`);
    setWidth('hud-xp-fill', (s.xp / Math.max(1, s.xpNext)) * 100);

    const sheetBtn = document.getElementById('btn-char-sheet');
    const badge = document.getElementById('stat-pts-badge');
    if (sheetBtn) sheetBtn.classList.toggle('has-points', s.statPoints > 0);
    if (badge) badge.textContent = s.statPoints > 0 ? `+${s.statPoints} pts` : '[C]';

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
    if (!frame) return;

    if (!this.targetType || !this.targetId) {
      frame.classList.remove('visible');
      return;
    }

    const nameEl = document.getElementById('target-name');
    const lvlEl = document.getElementById('target-lvl');
    const hpBar = document.getElementById('target-hp-bar');
    const hpFill = document.getElementById('target-hp-fill');
    const hpText = document.getElementById('target-hp-text');
    const aggroEl = document.getElementById('target-aggro');

    if (this.targetType === 'MONSTER') {
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
        .map(
          ([key, label, hint]) => `
        <div class="attr-row">
          <div><strong>${label}</strong><div style="font-size:11px; color:var(--text-muted)">${hint}</div></div>
          <div style="display:flex; align-items:center; gap:8px;">
            <strong style="font-size:15px; color:#e0f2fe;">${s.attributes[key]}</strong>
            ${
              s.statPoints > 0
                ? `<button type="button" class="mini-btn" data-alloc="${key}">+1</button>`
                : ''
            }
          </div>
        </div>
      `,
        )
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
    const html = `<span style="color:${tagColor}">[${msg.channel}]</span> <b>${this.esc(msg.senderName)}</b>: ${this.esc(msg.content)}`;
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
