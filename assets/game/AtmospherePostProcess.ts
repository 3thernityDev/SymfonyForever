import * as THREE from 'three';
import { EffectComposer } from 'three/examples/jsm/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/examples/jsm/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/examples/jsm/postprocessing/UnrealBloomPass.js';
import { ShaderPass } from 'three/examples/jsm/postprocessing/ShaderPass.js';
import { OutputPass } from 'three/examples/jsm/postprocessing/OutputPass.js';

export interface PostProcessUpdateParams {
  sunWorldPos: THREE.Vector3;
  sunColor: THREE.Color;
  dayFactor: number;
  goldenFactor: number;
  nightFactor: number;
  rainFactor: number;
  stormFactor: number;
  sandstormFactor: number;
  lightningFlash: number;
}

const GodRaysAndGradingShader = {
  uniforms: {
    tDiffuse: { value: null },
    uSunScreenPos: { value: new THREE.Vector2(0.5, 0.5) },
    uSunColor: { value: new THREE.Color('#ffdca8') },
    uGodRayIntensity: { value: 0.0 },
    uDecay: { value: 0.945 },
    uDensity: { value: 0.90 },
    uWeight: { value: 0.055 },
    uVignette: { value: 0.22 },
    uGradeTint: { value: new THREE.Color('#ffffff') },
    uGradeStrength: { value: 0.0 },
    uSaturation: { value: 1.08 },
    uContrast: { value: 1.04 },
  },
  vertexShader: /* glsl */ `
    varying vec2 vUv;
    void main() {
      vUv = uv;
      gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
    }
  `,
  fragmentShader: /* glsl */ `
    uniform sampler2D tDiffuse;
    uniform vec2 uSunScreenPos;
    uniform vec3 uSunColor;
    uniform float uGodRayIntensity;
    uniform float uDecay;
    uniform float uDensity;
    uniform float uWeight;
    uniform float uVignette;
    uniform vec3 uGradeTint;
    uniform float uGradeStrength;
    uniform float uSaturation;
    uniform float uContrast;
    varying vec2 vUv;

    const int SAMPLES = 26;

    void main() {
      vec3 base = texture2D(tDiffuse, vUv).rgb;
      vec3 col = base;

      if (uGodRayIntensity > 0.001) {
        vec2 deltaTexCoord = (vUv - uSunScreenPos) * (uDensity / float(SAMPLES));
        vec2 tc = vUv;
        float illuminationDecay = 1.0;
        vec3 rays = vec3(0.0);

        for (int i = 0; i < SAMPLES; i++) {
          tc -= deltaTexCoord;
          vec2 clamped = clamp(tc, vec2(0.0), vec2(1.0));
          vec3 sampleCol = texture2D(tDiffuse, clamped).rgb;
          float lum = dot(sampleCol, vec3(0.299, 0.587, 0.114));
          float bright = max(0.0, lum - 0.52);
          rays += sampleCol * (bright / max(lum, 0.001)) * illuminationDecay * uWeight;
          illuminationDecay *= uDecay;
        }

        col += uSunColor * rays * uGodRayIntensity;
      }

      // Subtle saturation & contrast grading in linear space before ACESFilmic OutputPass
      float luma = dot(col, vec3(0.2126, 0.7152, 0.0722));
      col = mix(vec3(luma), col, uSaturation);
      col = (col - 0.18) * uContrast + 0.18;
      col = mix(col, col * uGradeTint, uGradeStrength);

      // Smooth radial vignette
      float vigDist = length((vUv - 0.5) * vec2(1.15, 1.0));
      float vig = 1.0 - smoothstep(0.45, 1.35, vigDist) * uVignette;
      col *= vig;

      gl_FragColor = vec4(max(col, vec3(0.0)), 1.0);
    }
  `,
};

/**
 * Post-processing pipeline: RenderPass -> UnrealBloomPass -> Volumetric God Rays & Color Grading -> OutputPass.
 */
export class AtmospherePostProcess {
  private readonly composer: EffectComposer;
  private readonly bloomPass: UnrealBloomPass;
  private readonly gradePass: ShaderPass;
  private readonly tmpProj = new THREE.Vector3();
  private readonly tmpCamDir = new THREE.Vector3();
  private readonly tmpSunDir = new THREE.Vector3();
  private readonly tmpTint = new THREE.Color();
  private readonly warmGoldenTint = new THREE.Color('#ffbe78');
  private readonly coolNightTint = new THREE.Color('#6488d9');
  private readonly stormTint = new THREE.Color('#7da2b8');
  private readonly sandTint = new THREE.Color('#e3a25f');

  public constructor(
    private readonly renderer: THREE.WebGLRenderer,
    scene: THREE.Scene,
    camera: THREE.Camera,
  ) {
    const width = Math.max(1, window.innerWidth);
    const height = Math.max(1, window.innerHeight);

    this.composer = new EffectComposer(renderer);
    this.composer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    this.composer.setSize(width, height);

    const renderPass = new RenderPass(scene, camera);
    this.bloomPass = new UnrealBloomPass(new THREE.Vector2(width, height), 0.42, 0.55, 0.85);
    this.gradePass = new ShaderPass(GodRaysAndGradingShader);
    const outputPass = new OutputPass();

    this.composer.addPass(renderPass);
    this.composer.addPass(this.bloomPass);
    this.composer.addPass(this.gradePass);
    this.composer.addPass(outputPass);
  }

  public update(camera: THREE.Camera, params: PostProcessUpdateParams): void {
    const {
      sunWorldPos,
      sunColor,
      dayFactor,
      goldenFactor,
      nightFactor,
      rainFactor,
      stormFactor,
      sandstormFactor,
      lightningFlash,
    } = params;

    // Project celestial light position to UV screen space & fade cleanly when behind camera
    this.tmpProj.copy(sunWorldPos).project(camera);
    const screenX = this.tmpProj.x * 0.5 + 0.5;
    const screenY = this.tmpProj.y * 0.5 + 0.5;
    (this.gradePass.uniforms['uSunScreenPos'].value as THREE.Vector2).set(screenX, screenY);

    camera.getWorldDirection(this.tmpCamDir);
    this.tmpSunDir.copy(sunWorldPos).sub(camera.position).normalize();
    const facing = Math.max(0.0, this.tmpCamDir.dot(this.tmpSunDir));
    const facingSmooth = Math.pow(facing, 1.35);

    // Edge screen attenuation so offscreen edges fade softly
    const edgeDist = Math.hypot(screenX - 0.5, screenY - 0.5);
    const onScreenWeight = THREE.MathUtils.smoothstep(1.35 - edgeDist, 0.0, 0.85);

    const occlusion = Math.max(0.12, 1.0 - stormFactor * 0.85 - rainFactor * 0.45 - sandstormFactor * 0.72);
    const baseRayPower = goldenFactor * 1.45 + dayFactor * 0.52 + nightFactor * 0.24;
    const rayIntensity = baseRayPower * facingSmooth * onScreenWeight * occlusion;

    this.gradePass.uniforms['uGodRayIntensity'].value = rayIntensity;
    (this.gradePass.uniforms['uSunColor'].value as THREE.Color).copy(sunColor);

    // Bloom ramps gently at golden hour & night so torches, lava, campfires & lit huts pop
    const desiredStrength =
      0.36 * dayFactor +
      0.56 * goldenFactor +
      0.62 * nightFactor +
      0.14 * stormFactor;
    const desiredThreshold =
      0.86 * (1.0 - nightFactor * 0.55) + 0.74 * (nightFactor * 0.55) - goldenFactor * 0.05;
    this.bloomPass.strength = THREE.MathUtils.clamp(desiredStrength, 0.34, 0.72);
    this.bloomPass.threshold = THREE.MathUtils.clamp(desiredThreshold, 0.70, 0.88);
    this.bloomPass.radius = 0.55 + goldenFactor * 0.14 + nightFactor * 0.12;

    // Tone mapping exposure (night slightly darker with torch/lightning bounce)
    const baseExposure =
      1.12 * (1.0 - nightFactor) +
      0.92 * nightFactor +
      0.10 * goldenFactor -
      0.08 * stormFactor -
      0.06 * sandstormFactor +
      lightningFlash * 0.45;
    this.renderer.toneMappingExposure = THREE.MathUtils.clamp(baseExposure, 0.82, 1.65);

    // Filmic tint blending
    this.tmpTint.setRGB(1.0, 1.0, 1.0);
    let gradeStrength = 0.0;

    if (goldenFactor > 0.01) {
      this.tmpTint.lerp(this.warmGoldenTint, goldenFactor * 0.8);
      gradeStrength = Math.max(gradeStrength, goldenFactor * 0.28);
    }
    if (nightFactor > 0.01) {
      this.tmpTint.lerp(this.coolNightTint, nightFactor * 0.85);
      gradeStrength = Math.max(gradeStrength, nightFactor * 0.30);
    }
    if (stormFactor > 0.01 || rainFactor > 0.01) {
      const wet = Math.max(stormFactor, rainFactor * 0.7);
      this.tmpTint.lerp(this.stormTint, wet * 0.65);
      gradeStrength = Math.max(gradeStrength, wet * 0.26);
    }
    if (sandstormFactor > 0.01) {
      this.tmpTint.lerp(this.sandTint, sandstormFactor * 0.8);
      gradeStrength = Math.max(gradeStrength, sandstormFactor * 0.38);
    }

    (this.gradePass.uniforms['uGradeTint'].value as THREE.Color).copy(this.tmpTint);
    this.gradePass.uniforms['uGradeStrength'].value = THREE.MathUtils.clamp(gradeStrength, 0.0, 0.45);
    this.gradePass.uniforms['uSaturation'].value = THREE.MathUtils.clamp(
      1.08 + goldenFactor * 0.08 - stormFactor * 0.14 - sandstormFactor * 0.08 - nightFactor * 0.04,
      0.85,
      1.20,
    );
    this.gradePass.uniforms['uContrast'].value = 1.04 + goldenFactor * 0.04 + nightFactor * 0.05;
    this.gradePass.uniforms['uVignette'].value = THREE.MathUtils.clamp(
      0.18 + nightFactor * 0.16 + stormFactor * 0.14 + sandstormFactor * 0.18 + goldenFactor * 0.04,
      0.16,
      0.42,
    );
  }

  public render(): void {
    this.composer.render();
  }

  public resize(width: number, height: number): void {
    const w = Math.max(1, width);
    const h = Math.max(1, height);
    this.composer.setSize(w, h);
    this.bloomPass.resolution.set(w, h);
  }
}
