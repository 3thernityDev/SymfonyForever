import * as THREE from 'three';
import { FBXLoader } from 'three/examples/jsm/loaders/FBXLoader.js';
import * as SkeletonUtils from 'three/examples/jsm/utils/SkeletonUtils.js';

export interface LoadedCharacterRig {
  root: THREE.Group;
  mixer: THREE.AnimationMixer | null;
  walkAction: THREE.AnimationAction | null;
}

interface CachedTemplate {
  scene: THREE.Group;
  animations: THREE.AnimationClip[];
  texture: THREE.Texture | null;
  scale: number;
  offset: THREE.Vector3;
}

const TIKAF_FBX_URL = '/assets/models/tikaf/Tikaf.fbx';
const TIKAF_TEX_URL = '/assets/models/tikaf/Tikaf.webp';
const DESIRED_HEIGHT = 3.25;
// Measured from R_Foot/L_Foot ground-contact stance velocity in walk.001 scaled to DESIRED_HEIGHT (3.25)
export const TIKAF_FOOT_SPEED_AT_1X = 1.605;

let tikafPromise: Promise<CachedTemplate> | null = null;

function loadTikafTemplate(): Promise<CachedTemplate> {
  if (tikafPromise) return tikafPromise;

  tikafPromise = new Promise<CachedTemplate>((resolve, reject) => {
    const manager = new THREE.LoadingManager();
    manager.setURLModifier((url) => {
      if (url.startsWith('blob:') || url.startsWith('data:')) return url;
      if (/\.(png|jpe?g|tga|webp)$/i.test(url)) {
        return TIKAF_TEX_URL;
      }
      return url;
    });

    const texLoader = new THREE.TextureLoader(manager);
    const sharedTexture = texLoader.load(TIKAF_TEX_URL, (tex) => {
      tex.colorSpace = THREE.SRGBColorSpace;
      tex.needsUpdate = true;
    });
    sharedTexture.colorSpace = THREE.SRGBColorSpace;

    const loader = new FBXLoader(manager);
    loader.load(
      TIKAF_FBX_URL,
      (fbx) => {
        // Replace FBX Phong/tinted materials with a clean, bright, non-metallic PBR material
        // so Tripo's texture renders with faithful vibrant colors from every angle.
        const tikafMat = new THREE.MeshStandardMaterial({
          color: 0xffffff,
          map: sharedTexture,
          roughness: 0.82,
          metalness: 0.0,
          emissive: new THREE.Color(0xffffff),
          emissiveMap: sharedTexture,
          emissiveIntensity: 0.24,
        });

        fbx.traverse((child) => {
          const mesh = child as THREE.Mesh;
          if (!mesh.isMesh) return;
          mesh.castShadow = true;
          mesh.receiveShadow = true;
          mesh.material = tikafMat;
        });

        // Stand Tikaf upright (compensate +90deg X axis baked into FBX)
        fbx.rotation.set(-Math.PI / 2, 0, 0);
        fbx.updateMatrixWorld(true);

        // Pose on frame 0 before measuring exact standing bounding box
        if (fbx.animations && fbx.animations.length > 0) {
          const tempMixer = new THREE.AnimationMixer(fbx);
          const act = tempMixer.clipAction(fbx.animations[0]);
          act.play();
          tempMixer.update(0);
          fbx.updateMatrixWorld(true);
          tempMixer.stopAllAction();
        }

        const rawBox = new THREE.Box3().setFromObject(fbx);
        const rawSize = new THREE.Vector3();
        rawBox.getSize(rawSize);
        const maxDim = Math.max(rawSize.x, rawSize.y, rawSize.z) || 1;
        const scale = DESIRED_HEIGHT / maxDim;

        fbx.scale.setScalar(scale);
        fbx.updateMatrixWorld(true);

        const scaledBox = new THREE.Box3().setFromObject(fbx);
        const center = new THREE.Vector3();
        scaledBox.getCenter(center);

        const offset = new THREE.Vector3(-center.x, -scaledBox.min.y, -center.z);
        fbx.position.copy(offset);

        resolve({
          scene: fbx,
          animations: fbx.animations ?? [],
          texture: sharedTexture,
          scale,
          offset,
        });
      },
      undefined,
      (err) => reject(err),
    );
  });

  return tikafPromise;
}

export async function instantiateTikafRig(): Promise<LoadedCharacterRig> {
  const tpl = await loadTikafTemplate();
  const cloned = SkeletonUtils.clone(tpl.scene) as THREE.Group;

  // Ensure shared texture stays bound on cloned materials
  cloned.traverse((child) => {
    const mesh = child as THREE.Mesh;
    if (!mesh.isMesh) return;
    const mats = Array.isArray(mesh.material) ? mesh.material : [mesh.material];
    mats.forEach((m) => {
      const std = m as THREE.MeshPhongMaterial & THREE.MeshStandardMaterial;
      if (std && tpl.texture) {
        std.map = tpl.texture;
        std.needsUpdate = true;
      }
    });
  });

  const root = new THREE.Group();
  root.add(cloned);

  let mixer: THREE.AnimationMixer | null = null;
  let walkAction: THREE.AnimationAction | null = null;

  if (tpl.animations.length > 0) {
    mixer = new THREE.AnimationMixer(cloned);
    walkAction = mixer.clipAction(tpl.animations[0]);
    walkAction.setLoop(THREE.LoopRepeat, Infinity);
    walkAction.play();
    mixer.update(0);
  }

  return { root, mixer, walkAction };
}
