import * as THREE from 'three';
import {
  animateAvatar,
  createPlayerAvatar,
  triggerAvatarSkill,
  type AnimatedAvatar,
} from '../game/EntityMeshFactory';
import type { ClassCode, ClassMeta, SkillData } from '../game/types';

interface LobbyClassEntry {
  meta: ClassMeta;
  skills: SkillData[];
}

const panel = document.getElementById('creator-panel');
const canvas = document.getElementById('class-preview-canvas') as HTMLCanvasElement | null;
const hiddenInput = document.getElementById('selected-class-input') as HTMLInputElement | null;

if (panel && canvas && hiddenInput) {
  const classes = JSON.parse(panel.dataset.classes ?? '[]') as LobbyClassEntry[];
  const byCode = new Map<ClassCode, LobbyClassEntry>();
  classes.forEach((c) => byCode.set(c.meta.code, c));

  // Lightweight 3D turntable preview of Tank / DPS / Heal (all using Tikaf base rig + class gear!)
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(40, canvas.clientWidth / Math.max(1, canvas.clientHeight), 0.1, 50);
  camera.position.set(0, 2.3, 6.25);
  camera.lookAt(0, 1.65, 0);

  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.setSize(canvas.clientWidth || 220, canvas.clientHeight || 230, false);
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.2;

  scene.add(new THREE.AmbientLight(0xffffff, 1.1));
  scene.add(new THREE.HemisphereLight(0xeef6ff, 0x475569, 0.95));
  const keyLight = new THREE.DirectionalLight(0xfff5e6, 2.1);
  keyLight.position.set(3, 5, 4);
  scene.add(keyLight);
  const rimLight = new THREE.DirectionalLight(0x38bdf8, 0.7);
  rimLight.position.set(-3, 2, -3);
  scene.add(rimLight);

  // Basalt pedestal
  const pedestal = new THREE.Mesh(
    new THREE.CylinderGeometry(1.15, 1.35, 0.28, 8),
    new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.85, flatShading: true }),
  );
  pedestal.position.y = -0.14;
  scene.add(pedestal);

  let currentAvatar: AnimatedAvatar | null = null;

  const selectClass = (code: ClassCode) => {
    const entry = byCode.get(code);
    if (!entry) return;
    hiddenInput.value = code;

    document.querySelectorAll<HTMLButtonElement>('.class-option').forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.classCode === code);
    });

    const titleEl = document.getElementById('cls-title');
    const descEl = document.getElementById('cls-desc');
    const statsEl = document.getElementById('cls-stats');
    const skillsEl = document.getElementById('cls-skills');

    if (titleEl) {
      titleEl.textContent = `${entry.meta.label} (${entry.meta.role})`;
      titleEl.style.color = entry.meta.color;
    }
    if (descEl) descEl.textContent = entry.meta.description;

    if (statsEl) {
      const labels: [string, string][] = [
        ['vitality', 'Vitalité'],
        ['strength', 'Force'],
        ['intelligence', 'Intelligence'],
        ['defense', 'Défense'],
        ['spirit', 'Esprit'],
      ];
      statsEl.innerHTML = labels
        .map(([k, lbl]) => {
          const v = entry.meta.baseStats[k] ?? 0;
          const pct = Math.min(100, (v / 18) * 100);
          return `
            <div class="stat-row">
              <span class="lbl">${lbl}</span>
              <div class="stat-bar"><i style="width:${pct}%; background:${entry.meta.color};"></i></div>
              <span style="width:24px; text-align:right; font-weight:700;">${v}</span>
            </div>
          `;
        })
        .join('');
    }

    if (skillsEl) {
      skillsEl.innerHTML = entry.skills
        .map(
          (s) => `
        <div class="skill-chip" data-preview-skill="${s.code}" style="cursor:pointer;" title="Cliquer pour prévisualiser l'animation de cette compétence">
          <strong>${s.icon} ${s.name} <small>(Niv. ${s.unlockLevel})</small></strong>
          <small>${s.description}</small>
        </div>
      `,
        )
        .join('');
      skillsEl.querySelectorAll<HTMLElement>('[data-preview-skill]').forEach((chip) => {
        chip.addEventListener('click', () => {
          const skillCode = chip.dataset.previewSkill;
          if (skillCode && currentAvatar) {
            triggerAvatarSkill(currentAvatar, skillCode);
          }
        });
      });
    }

    if (currentAvatar) scene.remove(currentAvatar.group);
    currentAvatar = createPlayerAvatar(code);
    scene.add(currentAvatar.group);
  };

  document.querySelectorAll<HTMLButtonElement>('.class-option').forEach((btn) => {
    btn.addEventListener('click', () => {
      const code = btn.dataset.classCode as ClassCode;
      if (code) selectClass(code);
    });
  });

  selectClass('TANK');

  const clock = new THREE.Clock();
  const loop = () => {
    requestAnimationFrame(loop);
    const dt = clock.getDelta();
    const t = clock.elapsedTime;
    if (currentAvatar) {
      currentAvatar.group.rotation.y = t * 0.65;
      const lobbyDist = currentAvatar.fbxRoot !== null ? dt * 3.6 : 0;
      animateAvatar(currentAvatar, dt, currentAvatar.fbxRoot !== null, false, t, lobbyDist);
    }
    renderer.render(scene, camera);
  };
  loop();
}
