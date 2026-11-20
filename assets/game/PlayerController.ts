import * as THREE from 'three';
import { TerrainSampler } from './TerrainSampler';

/**
 * Third-person ZQSD / WASD controller with right-click orbit camera & terrain collision.
 * Uses KeyboardEvent.code (KeyW/KeyA/KeyS/KeyD) so AZERTY (ZQSD) and QWERTY (WASD) work identically.
 */
export class PlayerController {
  public x = -2047.5;
  public y = 12;
  public z = -332.5;
  public rotY = 0;
  public isMoving = false;
  public isSprinting = false;
  public lastDistanceMoved = 0;
  public yaw = -Math.PI * 0.25; // Camera orbit yaw
  public pitch = 0.42;
  public distance = 18;

  private vy = 0;
  private isGrounded = true;
  private readonly keys = new Set<string>();
  private draggingCamera = false;
  private lastPointerX = 0;
  private lastPointerY = 0;
  private inputBlocked = false;
  private isMounted = false;
  private mountSpeedBonus = 0;
  private mountCanFly = false;
  public isAirborne = false;
  private readonly worldHalfBound = 3540;
  private readonly maxFlyAltitude = 550;

  public constructor(
    canvas: HTMLCanvasElement,
    private readonly sampler: TerrainSampler,
    private readonly walkSpeed = 6.0,
    private readonly sprintSpeed = 11.5,
  ) {
    window.addEventListener('keydown', (e) => {
      if (this.inputBlocked) return;
      this.keys.add(e.code);
      if (e.code === 'Space') {
        e.preventDefault();
        if (!(this.isMounted && this.mountCanFly) && this.isGrounded) {
          this.vy = 13.5;
          this.isGrounded = false;
        }
      }
    });

    window.addEventListener('keyup', (e) => {
      this.keys.delete(e.code);
    });

    window.addEventListener('blur', () => {
      this.keys.clear();
      this.draggingCamera = false;
    });

    canvas.addEventListener('contextmenu', (e) => e.preventDefault());

    canvas.addEventListener('pointerdown', (e) => {
      // Right-click (2) or middle-click (1) drags camera orbit
      if (e.button === 2 || e.button === 1) {
        this.draggingCamera = true;
        this.lastPointerX = e.clientX;
        this.lastPointerY = e.clientY;
        canvas.setPointerCapture(e.pointerId);
      }
    });

    canvas.addEventListener('pointermove', (e) => {
      if (!this.draggingCamera) return;
      const dx = e.clientX - this.lastPointerX;
      const dy = e.clientY - this.lastPointerY;
      this.lastPointerX = e.clientX;
      this.lastPointerY = e.clientY;

      this.yaw -= dx * 0.0065;
      this.pitch = Math.max(0.12, Math.min(1.22, this.pitch + dy * 0.0055));
    });

    const stopDrag = (e: PointerEvent) => {
      if (this.draggingCamera) {
        this.draggingCamera = false;
        if (canvas.hasPointerCapture(e.pointerId)) {
          canvas.releasePointerCapture(e.pointerId);
        }
      }
    };
    canvas.addEventListener('pointerup', stopDrag);
    canvas.addEventListener('pointercancel', stopDrag);

    canvas.addEventListener(
      'wheel',
      (e) => {
        e.preventDefault();
        this.distance = Math.max(7.5, Math.min(44, this.distance + e.deltaY * 0.015));
      },
      { passive: false },
    );
  }

  public setMountState(mounted: boolean, speedBonus: number, canFly: boolean): void {
    const wasFlying = this.isMounted && this.mountCanFly;
    this.isMounted = mounted;
    this.mountSpeedBonus = mounted ? Math.max(0, speedBonus) : 0;
    this.mountCanFly = mounted && canFly;
    if (wasFlying && !this.mountCanFly) {
      const groundY = this.sampler.getHeight(this.x, this.z);
      if (this.y > groundY + 0.1) {
        this.isGrounded = false;
        this.vy = 0;
      }
    }
  }

  public setInputBlocked(blocked: boolean): void {
    this.inputBlocked = blocked;
    if (blocked) this.keys.clear();
  }

  public teleport(x: number, y: number, z: number): void {
    this.x = x;
    this.y = y;
    this.z = z;
    this.vy = 0;
    this.isGrounded = true;
  }

  public update(dt: number, isDead: boolean, camera: THREE.PerspectiveCamera): void {
    let moveForward = 0;
    let moveRight = 0;

    if (!isDead && !this.inputBlocked) {
      if (this.keys.has('KeyW') || this.keys.has('ArrowUp')) moveForward += 1;
      if (this.keys.has('KeyS') || this.keys.has('ArrowDown')) moveForward -= 1;
      if (this.keys.has('KeyD') || this.keys.has('ArrowRight')) moveRight += 1;
      if (this.keys.has('KeyA') || this.keys.has('ArrowLeft')) moveRight -= 1;
    }

    const hasInput = moveForward !== 0 || moveRight !== 0;
    const prevX = this.x;
    const prevZ = this.z;
    this.isMoving = false;
    this.isSprinting = !isDead && !this.inputBlocked && (this.keys.has('ShiftLeft') || this.keys.has('ShiftRight'));

    if (hasInput) {
      // Camera-relative movement direction on XZ plane
      const fwdX = -Math.sin(this.yaw);
      const fwdZ = -Math.cos(this.yaw);
      const rightX = Math.cos(this.yaw);
      const rightZ = -Math.sin(this.yaw);

      let dx = fwdX * moveForward + rightX * moveRight;
      let dz = fwdZ * moveForward + rightZ * moveRight;
      const len = Math.hypot(dx, dz);
      if (len > 0.001) {
        dx /= len;
        dz /= len;
      }

      const mountMult = this.isMounted ? 1.0 + this.mountSpeedBonus : 1.0;
      const baseSpeed = this.isSprinting ? this.sprintSpeed : this.walkSpeed;
      const activeSpeed = baseSpeed * mountMult;
      const step = activeSpeed * dt;
      const candX = this.x + dx * step;
      const candZ = this.z + dz * step;

      if (this.isMounted && this.mountCanFly) {
        // Free flight anywhere above La Réunion (within island airspace bounds)
        this.x = Math.max(-this.worldHalfBound, Math.min(this.worldHalfBound, candX));
        this.z = Math.max(-this.worldHalfBound, Math.min(this.worldHalfBound, candZ));
        this.isMoving = true;
      } else if (this.sampler.isWalkable(candX, candZ)) {
        // Slide smoothly along ocean shoreline or steep remparts
        this.x = candX;
        this.z = candZ;
        this.isMoving = true;
      } else if (this.sampler.isWalkable(candX, this.z)) {
        this.x = candX;
        this.isMoving = true;
      } else if (this.sampler.isWalkable(this.x, candZ)) {
        this.z = candZ;
        this.isMoving = true;
      }

      // Smoothly turn character toward movement direction
      const desiredRot = Math.atan2(dx, dz);
      let diff = desiredRot - this.rotY;
      while (diff > Math.PI) diff -= Math.PI * 2;
      while (diff < -Math.PI) diff += Math.PI * 2;
      this.rotY += diff * Math.min(1, dt * 14);
    }

    this.lastDistanceMoved = Math.hypot(this.x - prevX, this.z - prevZ);

    // Flight altitude or gravity & ground clamping against Réunion heightmap
    const groundY = this.sampler.getHeight(this.x, this.z);
    if (this.isMounted && this.mountCanFly && !isDead) {
      let ascend = 0;
      if (!this.inputBlocked) {
        if (this.keys.has('Space')) ascend += 1;
        if (this.keys.has('ControlLeft') || this.keys.has('ControlRight') || this.keys.has('KeyX')) ascend -= 1;
      }
      if (ascend !== 0) {
        this.y += ascend * 18.0 * dt;
        if (ascend > 0) this.isMoving = true;
      }
      const minFlyY = Math.max(groundY, 7.2);
      this.y = Math.max(minFlyY, Math.min(this.maxFlyAltitude, this.y));
      this.vy = 0;
      this.isGrounded = this.y <= minFlyY + 0.05;
      this.isAirborne = this.y > groundY + 0.45;
    } else if (!this.isGrounded) {
      this.vy -= 34.0 * dt;
      this.y += this.vy * dt;
      if (this.y <= groundY) {
        this.y = groundY;
        this.vy = 0;
        this.isGrounded = true;
      }
      this.isAirborne = !this.isGrounded;
    } else {
      this.y = groundY;
      this.isAirborne = false;
    }

    // Orbit third-person camera placement + terrain clip avoidance
    const targetY = this.y + 2.4;
    const horiz = Math.cos(this.pitch) * this.distance;
    const camX = this.x + Math.sin(this.yaw) * horiz;
    const camZ = this.z + Math.cos(this.yaw) * horiz;
    let camY = targetY + Math.sin(this.pitch) * this.distance;

    const terrainUnderCam = this.sampler.getHeight(camX, camZ) + 2.2;
    if (camY < terrainUnderCam) {
      camY = terrainUnderCam;
    }

    camera.position.lerp(new THREE.Vector3(camX, camY, camZ), Math.min(1, dt * 16));
    camera.lookAt(this.x, targetY, this.z);
  }
}
