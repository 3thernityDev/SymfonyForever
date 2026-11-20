import * as THREE from 'three';
import type { WeatherCode } from './types';

export interface WeatherFrameFactors {
  rainFactor: number;
  stormFactor: number;
  sandstormFactor: number;
  lightningFlash: number;
}

export function createSoftParticleTexture(): THREE.CanvasTexture {
  const size = 32;
  const canvas = document.createElement('canvas');
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  if (ctx) {
    const half = size * 0.5;
    const grad = ctx.createRadialGradient(half, half, 0, half, half, half);
    grad.addColorStop(0.0, 'rgba(255,255,255,1)');
    grad.addColorStop(0.35, 'rgba(255,255,255,0.72)');
    grad.addColorStop(1.0, 'rgba(255,255,255,0)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, size, size);
  }
  const tex = new THREE.CanvasTexture(canvas);
  tex.needsUpdate = true;
  return tex;
}

/**
 * Dynamic player-centered 3D weather system: tropical rain, lightning storms & volcanic sandstorms.
 */
export class WeatherSystem {
  private readonly rainCount = 2400;
  private readonly rainBoxW = 92;
  private readonly rainBoxH = 56;
  private readonly rainOffsets: Float32Array;
  private readonly rainSpeeds: Float32Array;
  private readonly rainPositions: Float32Array;
  private readonly rainMat: THREE.LineBasicMaterial;
  private readonly rainLines: THREE.LineSegments;

  private readonly sandCount = 2600;
  private readonly sandBoxW = 88;
  private readonly sandBoxH = 34;
  private readonly sandOffsets: Float32Array;
  private readonly sandSpeeds: Float32Array;
  private readonly sandStreakLengths: Float32Array;
  private readonly sandLinePositions: Float32Array;
  private readonly sandLineMat: THREE.LineBasicMaterial;
  private readonly sandWindLines: THREE.LineSegments;

  private readonly microDustCount = 1200;
  private readonly microDustPositions: Float32Array;
  private readonly sandMat: THREE.PointsMaterial;
  private readonly sandPoints: THREE.Points;

  private readonly lightningLight: THREE.DirectionalLight;
  private readonly boltLine: THREE.Line;
  private readonly boltMat: THREE.LineBasicMaterial;
  private boltTimer = 4.5;
  private lightningFlash = 0;

  private rainFactor = 0;
  private stormFactor = 0;
  private sandstormFactor = 0;

  public constructor(scene: THREE.Scene, softTex?: THREE.Texture) {
    const tex = softTex ?? createSoftParticleTexture();

    // 1. Rain / Storm streaks (LineSegments wrapped around player position)
    this.rainOffsets = new Float32Array(this.rainCount * 3);
    this.rainSpeeds = new Float32Array(this.rainCount);
    this.rainPositions = new Float32Array(this.rainCount * 6);

    for (let i = 0; i < this.rainCount; i++) {
      this.rainOffsets[i * 3] = (Math.random() - 0.5) * this.rainBoxW;
      this.rainOffsets[i * 3 + 1] = Math.random() * this.rainBoxH;
      this.rainOffsets[i * 3 + 2] = (Math.random() - 0.5) * this.rainBoxW;
      this.rainSpeeds[i] = 46 + Math.random() * 26;
    }

    const rainGeo = new THREE.BufferGeometry();
    rainGeo.setAttribute('position', new THREE.BufferAttribute(this.rainPositions, 3));
    this.rainMat = new THREE.LineBasicMaterial({
      color: 0xcbe7ff,
      transparent: true,
      opacity: 0,
      depthWrite: false,
    });
    this.rainLines = new THREE.LineSegments(rainGeo, this.rainMat);
    this.rainLines.frustumCulled = false;
    this.rainLines.visible = false;
    scene.add(this.rainLines);

    // 2. Volcanic sandstorm / Piton wind & fine sand filaments (replace coarse round blobs with fine wind streaks + micro-dust)
    this.sandOffsets = new Float32Array(this.sandCount * 3);
    this.sandSpeeds = new Float32Array(this.sandCount);
    this.sandStreakLengths = new Float32Array(this.sandCount);
    this.sandLinePositions = new Float32Array(this.sandCount * 6);
    const sandLineColors = new Float32Array(this.sandCount * 6);

    const cHeadWind = new THREE.Color(0xf2cfa6);
    const cHeadSand = new THREE.Color(0xd99b5e);
    const cTail = new THREE.Color(0x7c4d25);
    const tmpHead = new THREE.Color();

    for (let i = 0; i < this.sandCount; i++) {
      this.sandOffsets[i * 3] = (Math.random() - 0.5) * this.sandBoxW;
      // Concentrate more wind & sand closer to ground while keeping upper gusts
      this.sandOffsets[i * 3 + 1] = Math.pow(Math.random(), 1.35) * this.sandBoxH;
      this.sandOffsets[i * 3 + 2] = (Math.random() - 0.5) * this.sandBoxW;

      const isLongWindFilament = i % 4 === 0;
      this.sandSpeeds[i] = isLongWindFilament ? 48 + Math.random() * 24 : 32 + Math.random() * 20;
      this.sandStreakLengths[i] = isLongWindFilament
        ? 0.052 + Math.random() * 0.035
        : 0.015 + Math.random() * 0.018;

      tmpHead.copy(isLongWindFilament ? cHeadWind : cHeadSand).offsetHSL(0, 0, (Math.random() - 0.5) * 0.08);
      const cIdx = i * 6;
      sandLineColors[cIdx] = tmpHead.r;
      sandLineColors[cIdx + 1] = tmpHead.g;
      sandLineColors[cIdx + 2] = tmpHead.b;
      sandLineColors[cIdx + 3] = cTail.r;
      sandLineColors[cIdx + 4] = cTail.g;
      sandLineColors[cIdx + 5] = cTail.b;
    }

    const sandLineGeo = new THREE.BufferGeometry();
    sandLineGeo.setAttribute('position', new THREE.BufferAttribute(this.sandLinePositions, 3));
    sandLineGeo.setAttribute('color', new THREE.BufferAttribute(sandLineColors, 3));
    this.sandLineMat = new THREE.LineBasicMaterial({
      vertexColors: true,
      transparent: true,
      opacity: 0,
      depthWrite: false,
    });
    this.sandWindLines = new THREE.LineSegments(sandLineGeo, this.sandLineMat);
    this.sandWindLines.frustumCulled = false;
    this.sandWindLines.visible = false;
    scene.add(this.sandWindLines);

    this.microDustPositions = new Float32Array(this.microDustCount * 3);
    const sandGeo = new THREE.BufferGeometry();
    sandGeo.setAttribute('position', new THREE.BufferAttribute(this.microDustPositions, 3));
    this.sandMat = new THREE.PointsMaterial({
      color: 0xe6b47e,
      map: tex,
      size: 0.18,
      transparent: true,
      opacity: 0,
      depthWrite: false,
    });
    this.sandPoints = new THREE.Points(sandGeo, this.sandMat);
    this.sandPoints.frustumCulled = false;
    this.sandPoints.visible = false;
    scene.add(this.sandPoints);

    // 3. Storm Lightning light + jagged sky bolt
    this.lightningLight = new THREE.DirectionalLight(0xdbeafe, 0);
    this.lightningLight.position.set(160, 420, -140);
    scene.add(this.lightningLight);

    const boltPts = new Array(15).fill(0).map(() => new THREE.Vector3());
    const boltGeo = new THREE.BufferGeometry().setFromPoints(boltPts);
    this.boltMat = new THREE.LineBasicMaterial({
      color: 0xe0f2fe,
      transparent: true,
      opacity: 0,
      blending: THREE.AdditiveBlending,
    });
    this.boltLine = new THREE.Line(boltGeo, this.boltMat);
    this.boltLine.frustumCulled = false;
    this.boltLine.visible = false;
    scene.add(this.boltLine);
  }

  public update(
    dt: number,
    elapsed: number,
    playerPos: THREE.Vector3,
    targetWeather: WeatherCode,
  ): WeatherFrameFactors {
    const targetRain = targetWeather === 'STORM' ? 1.0 : targetWeather === 'RAIN' ? 0.75 : 0.0;
    const targetStorm = targetWeather === 'STORM' ? 1.0 : 0.0;
    const targetSand = targetWeather === 'SANDSTORM' ? 1.0 : 0.0;

    const blend = Math.min(1, dt * 1.4);
    this.rainFactor += (targetRain - this.rainFactor) * blend;
    this.stormFactor += (targetStorm - this.stormFactor) * blend;
    this.sandstormFactor += (targetSand - this.sandstormFactor) * blend;

    // Rain / storm simulation
    if (this.rainFactor > 0.01) {
      this.rainLines.visible = true;
      this.rainMat.opacity = this.rainFactor * (0.48 + this.stormFactor * 0.2);

      const windX = 7.0 + this.stormFactor * 16.0;
      const windZ = 4.0 + this.stormFactor * 10.0;
      const streakLen = 0.042 + this.stormFactor * 0.02;
      const halfW = this.rainBoxW * 0.5;
      const baseBottomY = playerPos.y - 8;

      for (let i = 0; i < this.rainCount; i++) {
        const idx3 = i * 3;
        const vy = this.rainSpeeds[i] * (0.9 + this.stormFactor * 0.35);

        let ox = this.rainOffsets[idx3] + windX * dt;
        let oy = this.rainOffsets[idx3 + 1] - vy * dt;
        let oz = this.rainOffsets[idx3 + 2] + windZ * dt;

        if (oy < 0) oy += this.rainBoxH;
        if (ox > halfW) ox -= this.rainBoxW;
        if (ox < -halfW) ox += this.rainBoxW;
        if (oz > halfW) oz -= this.rainBoxW;
        if (oz < -halfW) oz += this.rainBoxW;

        this.rainOffsets[idx3] = ox;
        this.rainOffsets[idx3 + 1] = oy;
        this.rainOffsets[idx3 + 2] = oz;

        const wx = playerPos.x + ox;
        const wy = baseBottomY + oy;
        const wz = playerPos.z + oz;

        const idx6 = i * 6;
        this.rainPositions[idx6] = wx;
        this.rainPositions[idx6 + 1] = wy;
        this.rainPositions[idx6 + 2] = wz;
        this.rainPositions[idx6 + 3] = wx - windX * streakLen;
        this.rainPositions[idx6 + 4] = wy + vy * streakLen;
        this.rainPositions[idx6 + 5] = wz - windZ * streakLen;
      }

      (this.rainLines.geometry.attributes.position as THREE.BufferAttribute).needsUpdate = true;
    } else {
      this.rainLines.visible = false;
    }

    // Lightning in storms
    if (this.stormFactor > 0.45) {
      this.boltTimer -= dt;
      if (this.boltTimer <= 0) {
        this.boltTimer = 4.0 + Math.random() * 6.5;
        this.lightningFlash = 1.0;
        this.spawnBoltNear(playerPos);
      }
    }

    if (this.lightningFlash > 0.005) {
      this.lightningFlash = Math.max(0, this.lightningFlash - dt * 3.8);
      // Realistic secondary double-flicker near peak
      const flicker =
        this.lightningFlash > 0.55 && this.lightningFlash < 0.72
          ? this.lightningFlash * 0.4
          : this.lightningFlash;
      this.lightningLight.intensity = flicker * 4.2 * Math.max(0.2, this.stormFactor);
      this.boltLine.visible = flicker > 0.12;
      this.boltMat.opacity = Math.min(1.0, flicker * 1.35);
    } else {
      this.lightningFlash = 0;
      this.lightningLight.intensity = 0;
      this.boltLine.visible = false;
    }

    // Volcanic sandstorm simulation: fine wind streaks + tiny micro-grains
    if (this.sandstormFactor > 0.01) {
      this.sandWindLines.visible = true;
      this.sandPoints.visible = true;
      this.sandLineMat.opacity = this.sandstormFactor * 0.46;
      this.sandMat.opacity = this.sandstormFactor * 0.30;

      const halfS = this.sandBoxW * 0.5;
      const baseY = playerPos.y - 3.5;
      const gustPulse = 0.92 + Math.sin(elapsed * 1.8) * 0.22 + Math.cos(elapsed * 3.1) * 0.12;

      for (let i = 0; i < this.sandCount; i++) {
        const idx3 = i * 3;
        const sp = this.sandSpeeds[i] * gustPulse;
        const oxPrev = this.sandOffsets[idx3];
        const ozPrev = this.sandOffsets[idx3 + 2];

        const waveY = Math.sin(elapsed * 3.2 + oxPrev * 0.09 + i * 0.13) * 3.6;
        const waveZ = sp * 0.38 + Math.cos(elapsed * 2.4 + ozPrev * 0.08 + i * 0.07) * 7.5;

        let ox = oxPrev + sp * dt;
        let oy = this.sandOffsets[idx3 + 1] + waveY * dt;
        let oz = ozPrev + waveZ * dt;

        if (ox > halfS) ox -= this.sandBoxW;
        if (ox < -halfS) ox += this.sandBoxW;
        if (oz > halfS) oz -= this.sandBoxW;
        if (oz < -halfS) oz += this.sandBoxW;
        if (oy > this.sandBoxH) oy -= this.sandBoxH;
        if (oy < 0) oy += this.sandBoxH;

        this.sandOffsets[idx3] = ox;
        this.sandOffsets[idx3 + 1] = oy;
        this.sandOffsets[idx3 + 2] = oz;

        const wx = playerPos.x + ox;
        const wy = baseY + oy;
        const wz = playerPos.z + oz;

        const streakDt = this.sandStreakLengths[i];
        const idx6 = i * 6;
        this.sandLinePositions[idx6] = wx;
        this.sandLinePositions[idx6 + 1] = wy;
        this.sandLinePositions[idx6 + 2] = wz;
        this.sandLinePositions[idx6 + 3] = wx - sp * streakDt;
        this.sandLinePositions[idx6 + 4] = wy - waveY * streakDt * 0.65;
        this.sandLinePositions[idx6 + 5] = wz - waveZ * streakDt;

        if (i < this.microDustCount) {
          this.microDustPositions[idx3] = wx;
          this.microDustPositions[idx3 + 1] = wy;
          this.microDustPositions[idx3 + 2] = wz;
        }
      }

      (this.sandWindLines.geometry.attributes.position as THREE.BufferAttribute).needsUpdate = true;
      (this.sandPoints.geometry.attributes.position as THREE.BufferAttribute).needsUpdate = true;
    } else {
      this.sandWindLines.visible = false;
      this.sandPoints.visible = false;
    }

    return {
      rainFactor: this.rainFactor,
      stormFactor: this.stormFactor,
      sandstormFactor: this.sandstormFactor,
      lightningFlash: this.lightningFlash,
    };
  }

  private spawnBoltNear(playerPos: THREE.Vector3): void {
    const angle = Math.random() * Math.PI * 2;
    const dist = 95 + Math.random() * 115;
    const bx = playerPos.x + Math.cos(angle) * dist;
    const bz = playerPos.z + Math.sin(angle) * dist;
    const topY = playerPos.y + 195;
    const botY = Math.max(8, playerPos.y - 10);

    const posAttr = this.boltLine.geometry.attributes.position as THREE.BufferAttribute;
    const segs = posAttr.count - 1;
    let cx = bx;
    let cz = bz;
    for (let i = 0; i <= segs; i++) {
      const t = i / segs;
      const y = topY + (botY - topY) * t;
      if (i > 0 && i < segs) {
        cx += (Math.random() - 0.5) * 18;
        cz += (Math.random() - 0.5) * 18;
      }
      posAttr.setXYZ(i, cx, y, cz);
    }
    posAttr.needsUpdate = true;
    this.lightningLight.position.set(bx - playerPos.x, 380, bz - playerPos.z);
  }
}
