import * as THREE from 'three';
import type { ClassCode, CombatEventData, SkillData } from './types';

interface ActiveVfx {
  mesh: THREE.Object3D;
  age: number;
  maxAge: number;
  update: (t: number, dt: number) => void;
}

export class VfxManager {
  private readonly effects: ActiveVfx[] = [];
  private readonly tmpVec = new THREE.Vector3();

  public constructor(
    private readonly scene: THREE.Scene,
    private readonly floatingLayer: HTMLElement,
  ) {}

  public spawnFromEvent(
    ev: CombatEventData,
    skills: Record<string, SkillData>,
    camera: THREE.Camera,
    sourcePos?: THREE.Vector3,
    targetPos?: THREE.Vector3,
    sourceClassCode?: ClassCode,
  ): void {
    const pos = targetPos ? targetPos.clone() : new THREE.Vector3(ev.x, ev.y, ev.z);
    const skill = skills[ev.skillCode];
    const isAutoAttack = ev.skillCode === 'auto_attack';
    const colorHex =
      skill?.vfxColor ??
      (isAutoAttack ? (sourceClassCode === 'HEAL' ? '#34d399' : '#fbbf24') : ev.eventType === 'HEAL' || ev.eventType === 'HOT' ? '#10b981' : '#f97316');

    // 1. 3D Visual Effect
    if (ev.eventType === 'LEVEL_UP') {
      this.spawnLevelUpPillar(pos);
      this.spawnFloatingText(pos.clone().add(new THREE.Vector3(0, 4.2, 0)), `NIVEAU ${ev.amount} !`, '#fde047', 22, camera);
      return;
    }

    if (ev.eventType === 'TAUNT') {
      this.spawnExpandingRing(pos, '#facc15', 6.0, 0.55);
      this.spawnFloatingText(pos.clone().add(new THREE.Vector3(0, 3.2, 0)), 'PROVOQUÉ !', '#facc15', 15, camera);
      return;
    }

    if (ev.eventType === 'BUFF') {
      const radius = skill?.aoeRadius && skill.aoeRadius > 0 ? skill.aoeRadius : 3.2;
      this.spawnExpandingRing(pos, colorHex, radius, 0.7);
      if (ev.amount > 0) {
        this.spawnFloatingText(pos.clone().add(new THREE.Vector3(0, 3.0, 0)), `ARMURE +${ev.amount}%`, '#60a5fa', 14, camera);
      }
      return;
    }

    if (ev.eventType === 'KILL') {
      this.spawnBurst(pos.clone().add(new THREE.Vector3(0, 1.4, 0)), '#facc15', 0.65);
      this.spawnFloatingText(pos.clone().add(new THREE.Vector3(0, 3.6, 0)), `+${ev.amount} XP`, '#c084fc', 16, camera);
      return;
    }

    if (ev.eventType === 'HEAL' || ev.eventType === 'HOT') {
      this.spawnExpandingRing(pos, colorHex, skill?.vfxStyle === 'LAGOON_WAVE' ? 9.0 : 2.6, 0.65);
      const label = ev.isCrit ? `+${ev.amount} CRIT!` : `+${ev.amount}`;
      this.spawnFloatingText(pos.clone().add(new THREE.Vector3(0, 3.0, 0)), label, '#34d399', ev.isCrit ? 19 : 15, camera);
      return;
    }

    if (ev.eventType === 'DAMAGE' || ev.eventType === 'DOT') {
      if (ev.skillCode === 'boss_eruption') {
        if (sourcePos) {
          this.spawnExpandingRing(sourcePos, '#ef4444', 24.0, 0.85);
          this.spawnExpandingRing(sourcePos, '#f97316', 18.0, 0.65);
        }
        this.spawnBurst(pos.clone().add(new THREE.Vector3(0, 1.6, 0)), '#ff4500', 0.48);
        this.spawnFloatingText(
          pos.clone().add(new THREE.Vector3(0, 3.0, 0)),
          `🌋 -${ev.amount}`,
          '#fca5a5',
          ev.isCrit ? 22 : 18,
          camera,
        );
        return;
      }

      if (isAutoAttack) {
        if (sourcePos && sourceClassCode === 'HEAL') {
          this.spawnProjectile(sourcePos.clone().add(new THREE.Vector3(0, 1.8, 0)), pos.clone().add(new THREE.Vector3(0, 1.4, 0)), colorHex);
        }
        this.spawnBurst(pos.clone().add(new THREE.Vector3(0, 1.5, 0)), colorHex, 0.24);
        const txtColor = ev.isCrit ? '#fde047' : '#e2e8f0';
        const label = ev.isCrit ? `-${ev.amount}!` : `-${ev.amount}`;
        this.spawnFloatingText(pos.clone().add(new THREE.Vector3(0, 2.7, 0)), label, txtColor, ev.isCrit ? 18 : 13, camera);
        return;
      }

      if (sourcePos && (skill?.vfxStyle === 'FIREBALL' || skill?.vfxStyle === 'SOLAR' || skill?.vfxStyle === 'METEOR')) {
        this.spawnProjectile(sourcePos.clone().add(new THREE.Vector3(0, 1.8, 0)), pos.clone().add(new THREE.Vector3(0, 1.4, 0)), colorHex);
      }
      if (skill?.vfxStyle === 'QUAKE' || skill?.vfxStyle === 'METEOR') {
        this.spawnExpandingRing(pos, colorHex, skill.aoeRadius || 7.5, 0.6);
      } else {
        this.spawnBurst(pos.clone().add(new THREE.Vector3(0, 1.6, 0)), colorHex, 0.35);
      }

      const txtColor = ev.targetType === 'PLAYER' ? '#f87171' : ev.isCrit ? '#fde047' : ev.eventType === 'DOT' ? '#fb923c' : '#ffffff';
      const label = ev.isCrit ? `-${ev.amount}!` : `-${ev.amount}`;
      this.spawnFloatingText(pos.clone().add(new THREE.Vector3(0, 2.8, 0)), label, txtColor, ev.isCrit ? 20 : 15, camera);
    }
  }

  public update(dt: number): void {
    for (let i = this.effects.length - 1; i >= 0; i--) {
      const fx = this.effects[i];
      fx.age += dt;
      if (fx.age >= fx.maxAge) {
        this.scene.remove(fx.mesh);
        this.effects.splice(i, 1);
      } else {
        fx.update(fx.age / fx.maxAge, dt);
      }
    }
  }

  private spawnExpandingRing(pos: THREE.Vector3, color: string, maxRadius: number, maxAge: number): void {
    const mat = new THREE.MeshBasicMaterial({ color, side: THREE.DoubleSide, transparent: true, opacity: 0.9 });
    const mesh = new THREE.Mesh(new THREE.RingGeometry(0.55, 0.95, 28), mat);
    mesh.rotation.x = -Math.PI / 2;
    mesh.position.set(pos.x, pos.y + 0.35, pos.z);
    this.scene.add(mesh);

    this.effects.push({
      mesh,
      age: 0,
      maxAge,
      update: (t) => {
        const s = 0.6 + t * maxRadius;
        mesh.scale.set(s, s, 1);
        mat.opacity = (1 - t) * 0.85;
      },
    });
  }

  private spawnBurst(pos: THREE.Vector3, color: string, maxAge: number): void {
    const mat = new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.9, wireframe: true });
    const mesh = new THREE.Mesh(new THREE.IcosahedronGeometry(0.7, 0), mat);
    mesh.position.copy(pos);
    this.scene.add(mesh);

    this.effects.push({
      mesh,
      age: 0,
      maxAge,
      update: (t, dt) => {
        mesh.scale.setScalar(0.5 + t * 2.1);
        mesh.rotation.y += dt * 8;
        mat.opacity = 1 - t;
      },
    });
  }

  private spawnProjectile(from: THREE.Vector3, to: THREE.Vector3, color: string): void {
    const mat = new THREE.MeshBasicMaterial({ color });
    const mesh = new THREE.Mesh(new THREE.SphereGeometry(0.38, 8, 8), mat);
    mesh.position.copy(from);
    this.scene.add(mesh);

    this.effects.push({
      mesh,
      age: 0,
      maxAge: 0.28,
      update: (t) => {
        mesh.position.lerpVectors(from, to, t);
        mesh.position.y += Math.sin(t * Math.PI) * 1.4;
      },
    });
  }

  private spawnLevelUpPillar(pos: THREE.Vector3): void {
    const mat = new THREE.MeshBasicMaterial({ color: 0xfde047, transparent: true, opacity: 0.65, side: THREE.DoubleSide });
    const mesh = new THREE.Mesh(new THREE.CylinderGeometry(1.4, 2.2, 28, 12, 1, true), mat);
    mesh.position.set(pos.x, pos.y + 14, pos.z);
    this.scene.add(mesh);

    this.effects.push({
      mesh,
      age: 0,
      maxAge: 1.8,
      update: (t, dt) => {
        mesh.rotation.y += dt * 3;
        mesh.scale.set(1 + t * 0.8, 1, 1 + t * 0.8);
        mat.opacity = (1 - t) * 0.65;
      },
    });
  }

  private spawnFloatingText(worldPos: THREE.Vector3, text: string, color: string, fontSize: number, camera: THREE.Camera): void {
    this.tmpVec.copy(worldPos).project(camera);
    if (this.tmpVec.z > 1) return;

    const sx = (this.tmpVec.x * 0.5 + 0.5) * window.innerWidth + (Math.random() - 0.5) * 28;
    const sy = (-this.tmpVec.y * 0.5 + 0.5) * window.innerHeight;

    const el = document.createElement('div');
    el.className = 'fct';
    el.textContent = text;
    el.style.left = `${sx}px`;
    el.style.top = `${sy}px`;
    el.style.color = color;
    el.style.fontSize = `${fontSize}px`;
    this.floatingLayer.appendChild(el);
    window.setTimeout(() => el.remove(), 1250);
  }
}
