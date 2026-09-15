// r162 is the final Three.js release whose WebGLRenderer supports both
// WebGL2 and WebGL1. This keeps GLB trophies interactive on browsers and
// remote sessions where Chromium cannot expose a WebGL2 context.
import * as THREE from './vendor/three-r162/build/three.module.min.js';
import { GLTFLoader } from './vendor/three-r162/examples/jsm/loaders/GLTFLoader.js';

const modelPromises = new Map();
let webGLAvailable;

function supportsWebGL() {
    if (typeof webGLAvailable === 'boolean') return webGLAvailable;

    try {
        const canvas = document.createElement('canvas');
        // Prefer WebGL2 but accept WebGL1 through the compatibility renderer.
        // Forcing a high-performance GPU can fail on remote sessions, guest
        // profiles, and systems where Chromium cannot bind the discrete GPU.
        const contextOptions = {
            alpha: true,
            antialias: true,
            stencil: true,
            failIfMajorPerformanceCaveat: false,
            powerPreference: 'default'
        };
        const context = canvas.getContext('webgl2', contextOptions)
            || canvas.getContext('webgl', contextOptions)
            || canvas.getContext('experimental-webgl', contextOptions);
        webGLAvailable = Boolean(context);
        if (context) {
            const loseContext = context.getExtension('WEBGL_lose_context');
            if (loseContext) loseContext.loseContext();
        }
    } catch (error) {
        webGLAvailable = false;
    }

    return webGLAvailable;
}

function showStaticTrophyFallback(root, stage, viewButtons, rotateButtons) {
    root.classList.add('has-static-trophy-fallback');
    if (stage) stage.classList.add('has-model-error');

    const fallback = stage && stage.querySelector('.rts-single-trophy__model-fallback');
    const fallbackUrl = fallback && (fallback.currentSrc || fallback.src);
    root.querySelectorAll('[data-rts-thumbnail-model]').forEach((element) => {
        element.classList.add('has-model-error');
        if (!fallbackUrl) return;

        const image = document.createElement('img');
        image.src = fallbackUrl;
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';
        element.replaceChildren(image);
    });

    rotateButtons.forEach((button) => {
        button.disabled = true;
        button.hidden = true;
    });
    viewButtons.forEach((button) => {
        button.disabled = true;
    });

    const interaction = root.querySelector('.rts-single-trophy__interaction');
    if (interaction) interaction.textContent = '3D view is unavailable in this browser.';
}

function normaliseAngle(angle) {
    const value = Number(angle) || 0;
    return ((value % 360) + 360) % 360;
}

function angularDistance(left, right) {
    const distance = Math.abs(normaliseAngle(left) - normaliseAngle(right));
    return Math.min(distance, 360 - distance);
}

function loadModel(url) {
    if (!modelPromises.has(url)) {
        modelPromises.set(url, new GLTFLoader().loadAsync(url));
    }
    return modelPromises.get(url);
}

function fitText(context, text, maximumWidth, startingSize, weight) {
    let size = startingSize;
    do {
        context.font = `${weight || 700} ${size}px Arial, sans-serif`;
        if (context.measureText(text).width <= maximumWidth) break;
        size -= 2;
    } while (size > 20);
}

function createPlaqueTexture(root, renderer) {
    const canvas = document.createElement('canvas');
    canvas.width = 1200;
    canvas.height = 640;
    const context = canvas.getContext('2d');
    const lines = [
        { text: root.dataset.plaqueHeading || 'MARATHON', y: 66, size: 76, weight: 800, color: '#ffd878' },
        { text: root.dataset.plaqueMilestone || 'TROPHY', y: 142, size: 72, weight: 800, color: '#ffd878' },
        { text: root.dataset.plaqueMember || '', y: 238, size: 62, weight: 800, color: '#fff0bd' },
        { text: root.dataset.plaqueRunner || '', y: 316, size: 42, weight: 700, color: '#f7d995' },
        { text: root.dataset.plaqueReferrals || '', y: 390, size: 40, weight: 700, color: '#efc36e' }
    ];

    context.clearRect(0, 0, canvas.width, canvas.height);
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.shadowColor = 'rgba(0, 0, 0, .9)';
    context.shadowBlur = 7;
    context.strokeStyle = 'rgba(0, 0, 0, .92)';
    context.lineJoin = 'round';
    lines.forEach((line) => {
        const text = String(line.text).toUpperCase();
        fitText(context, text, 1090, line.size, line.weight);
        context.lineWidth = Math.max(3, line.size * 0.055);
        context.strokeText(text, canvas.width / 2, line.y);
        context.fillStyle = line.color;
        context.fillText(text, canvas.width / 2, line.y);
    });

    const dayColumns = [
        {
            x: 355,
            label: root.dataset.plaqueSplitLabel || 'SPLIT DAYS',
            value: root.dataset.plaqueSplitDays || '0'
        },
        {
            x: 845,
            label: root.dataset.plaqueTotalLabel || 'TOTAL DAYS',
            value: root.dataset.plaqueTotalDays || '0'
        }
    ];
    context.textAlign = 'center';
    context.shadowBlur = 6;
    dayColumns.forEach((column) => {
        context.font = '700 34px Arial, sans-serif';
        context.lineWidth = 3;
        context.strokeText(String(column.label).toUpperCase(), column.x, 474);
        context.fillStyle = '#efc36e';
        context.fillText(String(column.label).toUpperCase(), column.x, 474);
        context.font = '700 68px Georgia, serif';
        context.lineWidth = 4;
        context.strokeText(String(column.value), column.x, 557);
        context.fillStyle = '#ffe7a4';
        context.fillText(String(column.value), column.x, 557);
    });
    context.save();
    context.shadowBlur = 0;
    context.strokeStyle = 'rgba(239, 195, 110, .9)';
    context.lineWidth = 3;
    context.beginPath();
    context.moveTo(canvas.width / 2, 445);
    context.lineTo(canvas.width / 2, 585);
    context.stroke();
    context.restore();

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
    texture.needsUpdate = true;
    return texture;
}

function findPlinthObject(model) {
    const names = ['plinth', 'trophy_plinth', 'base_plinth', 'pedestal'];
    let object = null;
    names.some((name) => {
        object = model.getObjectByName(name);
        return Boolean(object);
    });

    // Replacement trophies are not required to use the original node names.
    // For example, the 5K wave model is one `Trophy` node whose front panel is
    // exposed as a mesh using the `BlackPlaque` material. Prefer that real
    // surface over the small whole-model fallback used for unknown assets.
    if (!object) {
        const plaqueCandidates = [];
        model.traverse((child) => {
            if (!child.isMesh || !child.material) return;
            const materials = Array.isArray(child.material) ? child.material : [child.material];
            const materialNames = materials.map((material) => String(material && material.name || '').toLowerCase());
            const objectName = String(child.name || '').toLowerCase();
            if (materialNames.some((name) => name.includes('plaque')) || objectName.includes('plaque')) {
                plaqueCandidates.push(child);
            }
        });
        object = plaqueCandidates[0] || null;
    }

    return object;
}

function findPlinthBox(model) {
    const object = findPlinthObject(model);
    return object ? new THREE.Box3().setFromObject(object) : null;
}

function enablePlinthStencilMask(plinthObject) {
    if (!plinthObject) return false;
    let hasMesh = false;
    plinthObject.traverse((child) => {
        if (!child.isMesh || !child.material) return;
        hasMesh = true;
        const materials = Array.isArray(child.material) ? child.material : [child.material];
        const maskedMaterials = materials.map((source) => {
            const material = source.clone();
            material.stencilWrite = true;
            material.stencilRef = 1;
            material.stencilFunc = THREE.AlwaysStencilFunc;
            material.stencilFail = THREE.KeepStencilOp;
            material.stencilZFail = THREE.KeepStencilOp;
            material.stencilZPass = THREE.ReplaceStencilOp;
            return material;
        });
        child.material = Array.isArray(child.material) ? maskedMaterials : maskedMaterials[0];
        child.renderOrder = 1;
    });
    return hasMesh;
}

function addPlaqueMeshes(model, root, renderer) {
    const modelBox = new THREE.Box3().setFromObject(model);
    const modelSize = modelBox.getSize(new THREE.Vector3());
    const plinthObject = findPlinthObject(model);
    const plinthBox = plinthObject ? new THREE.Box3().setFromObject(plinthObject) : null;
    const hasPlinthMask = enablePlinthStencilMask(plinthObject);
    const box = plinthBox || modelBox;
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    const widthX = plinthBox ? size.x * 0.82 : modelSize.x * 0.48;
    const height = plinthBox ? size.y * 0.66 : modelSize.y * 0.17;
    const y = plinthBox ? center.y + size.y * 0.025 : modelBox.min.y + modelSize.y * 0.2;
    const offset = Math.max(modelSize.length() * 0.008, size.z * 0.035, 0.0005);
    const texture = createPlaqueTexture(root, renderer);
    const material = new THREE.MeshBasicMaterial({
        map: texture,
        transparent: true,
        // A detected plaque writes its visible silhouette into the stencil
        // buffer. Let that mask, rather than a nearly coplanar depth test,
        // control the inscription so lower rows do not disappear into a
        // sloped plaque when viewed straight on.
        depthTest: !hasPlinthMask,
        depthWrite: false,
        side: THREE.FrontSide,
        toneMapped: false,
        polygonOffset: true,
        polygonOffsetFactor: -2,
        polygonOffsetUnits: -2,
        stencilWrite: hasPlinthMask,
        stencilRef: 1,
        stencilFunc: THREE.EqualStencilFunc,
        stencilFail: THREE.KeepStencilOp,
        stencilZFail: THREE.KeepStencilOp,
        stencilZPass: THREE.KeepStencilOp
    });
    const plane = new THREE.Mesh(new THREE.PlaneGeometry(widthX, height), material);
    plane.name = 'rts_front_trophy_plaque';
    let localPosition = new THREE.Vector3(center.x, y, box.max.z + offset);
    let localQuaternion = new THREE.Quaternion();

    if (plinthObject) {
        model.updateWorldMatrix(true, true);
        const raycaster = new THREE.Raycaster(
            new THREE.Vector3(center.x, y, box.max.z + modelSize.length()),
            new THREE.Vector3(0, 0, -1),
            0,
            modelSize.length() * 2
        );
        const hit = raycaster.intersectObject(plinthObject, true).find((intersection) => intersection.face);
        if (hit) {
            const surfaceNormal = hit.face.normal.clone().transformDirection(hit.object.matrixWorld).normalize();
            const worldPosition = hit.point.clone().addScaledVector(surfaceNormal, offset);
            localPosition = model.worldToLocal(worldPosition);

            const worldQuaternion = new THREE.Quaternion().setFromUnitVectors(
                new THREE.Vector3(0, 0, 1),
                surfaceNormal
            );
            const inverseParentQuaternion = model.getWorldQuaternion(new THREE.Quaternion()).invert();
            localQuaternion = inverseParentQuaternion.multiply(worldQuaternion);
        }
    }

    plane.position.copy(localPosition);
    plane.quaternion.copy(localQuaternion);
    plane.renderOrder = 5;
    model.add(plane);
}

function addDisplayPlatform(scene, model, modelSize, options) {
    const plinthBox = findPlinthBox(model);
    const plinthSize = plinthBox ? plinthBox.getSize(new THREE.Vector3()) : modelSize;
    const footprint = Math.max(plinthSize.x, plinthSize.z, modelSize.x * 0.82, modelSize.z * 0.82);
    const radius = footprint * (options.interactive ? 0.9 : 0.72);
    const height = modelSize.y * 0.075;
    const topHeight = height * 0.14;
    const clearance = modelSize.y * 0.012;
    const modelBottom = -(modelSize.y / 2);
    const topSurface = modelBottom - clearance;
    const baseTop = topSurface - topHeight;
    const y = baseTop - (height / 2);
    const platform = new THREE.Group();
    platform.name = 'rts_trophy_display_platform';

    const base = new THREE.Mesh(
        new THREE.CylinderGeometry(radius * 0.94, radius, height, 96, 1, false),
        new THREE.MeshStandardMaterial({
            color: 0x07131d,
            metalness: 0.9,
            roughness: 0.16
        })
    );
    base.position.y = y;
    platform.add(base);

    const top = new THREE.Mesh(
        new THREE.CylinderGeometry(radius * 0.92, radius * 0.92, topHeight, 96),
        new THREE.MeshStandardMaterial({
            color: 0x14212b,
            emissive: 0x141006,
            emissiveIntensity: 0.3,
            metalness: 0.75,
            roughness: 0.14
        })
    );
    top.position.y = baseTop + (topHeight / 2);
    platform.add(top);

    const ringMaterial = new THREE.MeshStandardMaterial({
        color: 0xd99a2b,
        emissive: 0x6b3900,
        emissiveIntensity: 0.85,
        metalness: 1,
        roughness: 0.16
    });
    [
        { ringRadius: radius * 0.94, ringY: topSurface - (topHeight * 0.16) },
        { ringRadius: radius * 0.985, ringY: y - height * 0.42 }
    ].forEach(({ ringRadius, ringY }) => {
        const ring = new THREE.Mesh(
            new THREE.TorusGeometry(ringRadius, Math.max(height * 0.045, radius * 0.004), 10, 96),
            ringMaterial
        );
        ring.rotation.x = Math.PI / 2;
        ring.position.y = ringY;
        platform.add(ring);
    });

    scene.add(platform);
    return {
        height: height + topHeight + clearance,
        radius,
        bottomY: y - (height / 2),
        topY: topSurface
    };
}

function setCameraAngle(camera, target, angle, radius, polarAngle) {
    const spherical = new THREE.Spherical(radius, polarAngle, THREE.MathUtils.degToRad(angle));
    camera.position.copy(target).add(new THREE.Vector3().setFromSpherical(spherical));
    camera.lookAt(target);
}

async function createThreeViewer(element, root, options) {
    const renderer = new THREE.WebGLRenderer({
        antialias: true,
        alpha: true,
        stencil: true,
        failIfMajorPerformanceCaveat: false,
        powerPreference: 'default'
    });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, options.interactive ? 2 : 1.5));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.15;
    renderer.setClearColor(0x000000, 0);
    element.replaceChildren(renderer.domElement);

    const scene = new THREE.Scene();
    scene.add(new THREE.HemisphereLight(0xffe4ae, 0x07111c, 2.3));
    const keyLight = new THREE.DirectionalLight(0xffd18a, 4.2);
    keyLight.position.set(2.5, 3.5, 4);
    scene.add(keyLight);
    const rimLight = new THREE.DirectionalLight(0xd4e7ff, 2.2);
    rimLight.position.set(-3, 2, -4);
    scene.add(rimLight);

    const camera = new THREE.PerspectiveCamera(options.interactive ? 31 : 34, 1, 0.001, 100);
    const gltf = await loadModel(element.dataset.modelUrl);
    const model = gltf.scene.clone(true);
    addPlaqueMeshes(model, root, renderer);

    const bounds = new THREE.Box3().setFromObject(model);
    const center = bounds.getCenter(new THREE.Vector3());
    const size = bounds.getSize(new THREE.Vector3());
    model.position.sub(center);
    const rotatingTrophy = new THREE.Group();
    rotatingTrophy.name = 'rts_rotating_trophy';
    rotatingTrophy.add(model);
    scene.add(rotatingTrophy);
    const platform = addDisplayPlatform(scene, model, size, options);
    const target = new THREE.Vector3(0, (size.y / 2 + platform.bottomY) / 2, 0);
    const framedHeight = size.y + platform.height;
    const framedWidth = platform.radius * 2.08;
    const viewer = options.interactive ? element.closest('.rts-single-trophy__viewer') : null;
    const frameHeight = Math.max(1, viewer ? viewer.clientHeight : element.clientHeight);
    const canvasHeight = Math.max(frameHeight, element.clientHeight);
    const viewerRect = viewer ? viewer.getBoundingClientRect() : null;
    const canvasRect = element.getBoundingClientRect();
    const topOverlap = viewerRect ? Math.max(0, viewerRect.top - canvasRect.top) : 0;
    const bottomOverlap = viewerRect ? Math.max(0, canvasRect.bottom - viewerRect.bottom) : 0;
    const viewportAspect = Math.max(0.2, element.clientWidth / frameHeight);
    const verticalFov = THREE.MathUtils.degToRad(camera.fov);
    const horizontalFov = 2 * Math.atan(Math.tan(verticalFov / 2) * viewportAspect);
    const verticalDistance = framedHeight / (2 * Math.tan(verticalFov / 2));
    const horizontalDistance = framedWidth / (2 * Math.tan(horizontalFov / 2));
    const baseDistance = Math.max(verticalDistance, horizontalDistance) * (options.interactive ? 1.08 : 1.15);
    const initialDistance = baseDistance * (canvasHeight / frameHeight);
    // The main canvas extends upward over the heading. Compensate for its
    // larger drawing surface so the trophy and pedestal keep exactly the same
    // resting position until the member deliberately enlarges the trophy.
    target.y += (topOverlap - bottomOverlap) * baseDistance * Math.tan(verticalFov / 2) / frameHeight;
    const polarAngle = Math.PI / 2;
    const cameraDistance = initialDistance;
    let trophyScale = 1;
    let currentAngle = Number(options.angle) || 0;
    setCameraAngle(camera, target, 0, cameraDistance, polarAngle);
    rotatingTrophy.rotation.y = THREE.MathUtils.degToRad(currentAngle);
    element.dataset.currentModelAngle = String(normaliseAngle(currentAngle));

    const controls = new THREE.EventDispatcher();
    let angleAnimationFrame = 0;
    let angleAnimationFallback = 0;
    const render = () => renderer.render(scene, camera);
    const applyTrophyScale = (scale) => {
        trophyScale = scale;
        rotatingTrophy.scale.setScalar(trophyScale);
        // Scaling a centred model normally pushes its lower half through the
        // pedestal. Raise it by the same growth amount so the bottom remains
        // seated at its original height and the trophy grows upward.
        rotatingTrophy.position.y = (trophyScale - 1) * (size.y / 2);
        render();
    };
    const applyTrophyAngle = (angle) => {
        currentAngle = angle;
        rotatingTrophy.rotation.y = THREE.MathUtils.degToRad(currentAngle);
        element.dataset.currentModelAngle = String(normaliseAngle(currentAngle));
        render();
        controls.dispatchEvent({ type: 'change' });
    };
    const resize = () => {
        const width = Math.max(1, element.clientWidth);
        const height = Math.max(1, element.clientHeight);
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
        render();
    };
    new ResizeObserver(resize).observe(element);
    resize();

    if (options.interactive) {
        let dragging = false;
        let pointerX = 0;
        renderer.domElement.addEventListener('pointerdown', (event) => {
            window.cancelAnimationFrame(angleAnimationFrame);
            dragging = true;
            pointerX = event.clientX;
            renderer.domElement.setPointerCapture(event.pointerId);
        });
        renderer.domElement.addEventListener('pointermove', (event) => {
            if (!dragging) return;
            const deltaX = event.clientX - pointerX;
            pointerX = event.clientX;
            applyTrophyAngle(currentAngle + deltaX * 0.42);
        });
        const finishDrag = (event) => {
            dragging = false;
            if (renderer.domElement.hasPointerCapture(event.pointerId)) {
                renderer.domElement.releasePointerCapture(event.pointerId);
            }
        };
        renderer.domElement.addEventListener('pointerup', finishDrag);
        renderer.domElement.addEventListener('pointercancel', finishDrag);
        renderer.domElement.addEventListener('wheel', (event) => {
            event.preventDefault();
            applyTrophyScale(
                THREE.MathUtils.clamp(
                    trophyScale * Math.exp(event.deltaY * -0.001),
                    0.72,
                    1.38
                )
            );
        }, { passive: false });
        renderer.setAnimationLoop(render);
    }

    element.classList.add('is-three-ready');
    return {
        getAngle() {
            return currentAngle;
        },
        setAngle(angle, animate = true) {
            window.cancelAnimationFrame(angleAnimationFrame);
            window.clearTimeout(angleAnimationFallback);

            if (!options.interactive || !animate) {
                applyTrophyAngle(angle);
                return;
            }

            const startAngle = THREE.MathUtils.degToRad(currentAngle);
            const destinationAngle = THREE.MathUtils.degToRad(angle);
            const angleDelta = Math.atan2(
                Math.sin(destinationAngle - startAngle),
                Math.cos(destinationAngle - startAngle)
            );
            const startedAt = performance.now();
            const duration = 420;

            const animateAngle = (now) => {
                const progress = Math.min(1, (now - startedAt) / duration);
                const eased = 1 - Math.pow(1 - progress, 3);
                applyTrophyAngle(THREE.MathUtils.radToDeg(startAngle + angleDelta * eased));
                if (progress < 1) {
                    angleAnimationFrame = window.requestAnimationFrame(animateAngle);
                } else {
                    window.clearTimeout(angleAnimationFallback);
                    applyTrophyAngle(angle);
                }
            };
            angleAnimationFrame = window.requestAnimationFrame(animateAngle);
            angleAnimationFallback = window.setTimeout(() => applyTrophyAngle(angle), duration + 100);
        },
        controls
    };
}

function initShare(root) {
    const shareButton = root.querySelector('[data-rts-share]');
    const shareStatus = root.querySelector('[data-rts-share-status]');
    let statusTimer = 0;
    const showStatus = (message, persistent = false) => {
        if (!shareStatus) return;
        window.clearTimeout(statusTimer);
        shareStatus.textContent = message;
        if (message && !persistent) {
            statusTimer = window.setTimeout(() => { shareStatus.textContent = ''; }, 2600);
        }
    };
    if (!shareButton) return;

    const nextPaint = () => new Promise((resolve) => {
        window.requestAnimationFrame(() => window.requestAnimationFrame(resolve));
    });
    const loadImage = (url) => new Promise((resolve) => {
        if (!url) {
            resolve(null);
            return;
        }
        const image = new Image();
        let settled = false;
        const finish = (value) => {
            if (settled) return;
            settled = true;
            resolve(value);
        };
        image.crossOrigin = 'anonymous';
        image.onload = () => finish(image);
        image.onerror = () => finish(null);
        image.src = url;
        window.setTimeout(() => finish(null), 5000);
    });
    const drawImageCover = (context, image, width, height) => {
        const scale = Math.max(width / image.naturalWidth, height / image.naturalHeight);
        const drawWidth = image.naturalWidth * scale;
        const drawHeight = image.naturalHeight * scale;
        context.drawImage(image, (width - drawWidth) / 2, (height - drawHeight) / 2, drawWidth, drawHeight);
    };
    const drawImageContained = (context, image, x, y, width, height) => {
        if (!image || !image.naturalWidth || !image.naturalHeight) return;
        const scale = Math.min(width / image.naturalWidth, height / image.naturalHeight);
        const drawWidth = image.naturalWidth * scale;
        const drawHeight = image.naturalHeight * scale;
        context.drawImage(
            image,
            x + (width - drawWidth) / 2,
            y + (height - drawHeight) / 2,
            drawWidth,
            drawHeight
        );
    };
    const drawFittedText = (context, text, x, y, maximumWidth, size, options = {}) => {
        const family = options.family || 'Georgia, serif';
        const weight = options.weight || 600;
        const minimumSize = options.minimumSize || 18;
        let fontSize = size;
        context.textAlign = options.align || 'center';
        context.textBaseline = 'middle';
        context.fillStyle = options.color || '#f5c363';
        do {
            context.font = `${weight} ${fontSize}px ${family}`;
            if (context.measureText(text).width <= maximumWidth) break;
            fontSize -= 2;
        } while (fontSize > minimumSize);
        context.fillText(text, x, y);
    };
    const getTrophyBounds = (source) => {
        const sample = document.createElement('canvas');
        sample.width = 640;
        sample.height = Math.max(1, Math.round(640 * source.height / source.width));
        const context = sample.getContext('2d', { willReadFrequently: true });
        context.drawImage(source, 0, 0, sample.width, sample.height);
        let pixels;
        try {
            pixels = context.getImageData(0, 0, sample.width, sample.height).data;
        } catch (error) {
            return null;
        }
        let left = sample.width;
        let right = -1;
        let top = sample.height;
        let bottom = -1;
        for (let y = 0; y < sample.height; y += 1) {
            for (let x = 0; x < sample.width; x += 1) {
                if (pixels[(y * sample.width + x) * 4 + 3] < 10) continue;
                left = Math.min(left, x);
                right = Math.max(right, x);
                top = Math.min(top, y);
                bottom = Math.max(bottom, y);
            }
        }
        if (right < left || bottom < top) return null;
        const scaleX = source.width / sample.width;
        const scaleY = source.height / sample.height;
        const paddingX = Math.max(2, (right - left) * 0.03);
        const paddingY = Math.max(2, (bottom - top) * 0.03);
        return {
            x: Math.max(0, (left - paddingX) * scaleX),
            y: Math.max(0, (top - paddingY) * scaleY),
            width: Math.min(source.width, (right - left + paddingX * 2) * scaleX),
            height: Math.min(source.height, (bottom - top + paddingY * 2) * scaleY)
        };
    };
    const drawTrophyContained = (context, source, bounds, target) => {
        const sourceBounds = bounds || { x: 0, y: 0, width: source.width, height: source.height };
        const scale = Math.min(target.width / sourceBounds.width, target.height / sourceBounds.height);
        const width = sourceBounds.width * scale;
        const height = sourceBounds.height * scale;
        context.drawImage(
            source,
            sourceBounds.x,
            sourceBounds.y,
            sourceBounds.width,
            sourceBounds.height,
            target.x + (target.width - width) / 2,
            target.y + (target.height - height) / 2,
            width,
            height
        );
    };
    const createTrophyShareCard = async () => {
        const source = root.querySelector('[data-rts-main-model] canvas');
        if (!source || !source.width || !source.height) {
            throw new Error('The rendered trophy canvas is unavailable.');
        }
        const width = 1600;
        const height = 900;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        const backgroundValue = window.getComputedStyle(root).backgroundImage || '';
        const backgroundMatch = backgroundValue.match(/url\(["']?(.*?)["']?\)/i);
        const imageUrl = (selector) => {
            const image = root.querySelector(selector);
            return image && image.currentSrc ? image.currentSrc : (image && image.src ? image.src : '');
        };
        const [
            backgroundImage,
            titleLeftArt,
            titleRightArt,
            subheadingArt,
            detailsFrameArt,
            referralsArt,
            calendarArt
        ] = await Promise.all([
            loadImage(backgroundMatch ? backgroundMatch[1] : ''),
            loadImage(imageUrl('.rts-single-trophy__title-art.is-left img')),
            loadImage(imageUrl('.rts-single-trophy__title-art.is-right img')),
            loadImage(imageUrl('.rts-single-trophy__subheading-art')),
            loadImage(imageUrl('.rts-single-trophy__details-frame')),
            loadImage(imageUrl('.rts-single-trophy__referrals img')),
            loadImage(imageUrl('.rts-single-trophy__unlocked img'))
        ]);
        if (backgroundImage) {
            drawImageCover(context, backgroundImage, width, height);
        } else {
            const background = context.createRadialGradient(
                width * 0.65,
                height * 0.4,
                0,
                width * 0.65,
                height * 0.4,
                width * 0.72
            );
            background.addColorStop(0, '#132538');
            background.addColorStop(0.45, '#06111d');
            background.addColorStop(1, '#01060b');
            context.fillStyle = background;
            context.fillRect(0, 0, width, height);
        }
        const vignette = context.createRadialGradient(
            width * 0.63,
            height * 0.42,
            0,
            width * 0.63,
            height * 0.42,
            width * 0.78
        );
        vignette.addColorStop(0, 'rgba(0, 0, 0, 0)');
        vignette.addColorStop(0.72, 'rgba(0, 0, 0, .25)');
        vignette.addColorStop(1, 'rgba(0, 0, 0, .72)');
        context.fillStyle = vignette;
        context.fillRect(0, 0, width, height);

        await Promise.race([
            document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve(),
            new Promise((resolve) => window.setTimeout(resolve, 3000))
        ]);
        await nextPaint();

        drawTrophyContained(context, source, getTrophyBounds(source), {
            x: 190,
            y: 125,
            width: 1220,
            height: 740
        });

        context.save();
        context.globalCompositeOperation = 'screen';
        drawImageContained(context, titleLeftArt, 250, 8, 270, 56);
        drawImageContained(context, titleRightArt, 1080, 8, 270, 56);
        drawImageContained(context, subheadingArt, 650, 88, 300, 44);
        context.restore();
        context.shadowColor = 'rgba(0, 0, 0, .9)';
        context.shadowBlur = 12;
        drawFittedText(context, 'MARATHON TROPHY', 800, 37, 720, 56, { weight: 600 });
        drawFittedText(
            context,
            `${String(root.dataset.plaqueMilestone || 'TROPHY').replace(/\s+TROPHY$/i, '')} — ${root.dataset.shareMarathon || 'FOUNDING RUNNER MARATHON'}`,
            800,
            78,
            720,
            25,
            { family: 'Arial, sans-serif', color: '#e5ad4f', weight: 500 }
        );
        context.shadowBlur = 0;

        const panel = { x: 42, y: 130, width: 365, height: 705 };
        context.fillStyle = 'rgba(2, 12, 21, .92)';
        context.fillRect(panel.x, panel.y, panel.width, panel.height);
        if (detailsFrameArt) {
            context.drawImage(detailsFrameArt, panel.x, panel.y, panel.width, panel.height);
        } else {
            context.strokeStyle = '#d99a2b';
            context.lineWidth = 3;
            context.strokeRect(panel.x, panel.y, panel.width, panel.height);
            context.strokeStyle = 'rgba(217, 154, 43, .42)';
            context.lineWidth = 1;
            context.strokeRect(panel.x + 12, panel.y + 12, panel.width - 24, panel.height - 24);
        }

        const panelCenter = panel.x + panel.width / 2;
        drawFittedText(context, 'MARATHON TROPHY', panelCenter, 248, 315, 31);
        drawFittedText(context, String(root.dataset.plaqueMilestone || 'TROPHY').toUpperCase(), panelCenter, 272, 315, 26, { weight: 500 });
        drawFittedText(context, String(root.dataset.shareMarathon || 'FOUNDING RUNNER MARATHON').toUpperCase(), panelCenter, 300, 315, 17, {
            family: 'Arial, sans-serif',
            color: '#efc36e',
            weight: 600,
            minimumSize: 14
        });
        context.strokeStyle = 'rgba(217, 154, 43, .5)';
        context.beginPath();
        context.moveTo(panel.x + 30, 319);
        context.lineTo(panel.x + panel.width - 30, 319);
        context.stroke();
        drawFittedText(context, String(root.dataset.plaqueMember || '').toUpperCase(), panelCenter, 365, 315, 28, { color: '#fff0bd' });
        drawFittedText(context, root.dataset.plaqueRunner || '', panelCenter, 395, 315, 20, { color: '#f7d995', weight: 500 });
        drawImageContained(context, referralsArt, panel.x + 30, 407, 38, 38);
        drawFittedText(context, String(root.dataset.plaqueReferrals || '').toUpperCase(), panelCenter + (referralsArt ? 24 : 0), 433, referralsArt ? 250 : 315, 20, {
            family: 'Arial, sans-serif',
            color: '#efc36e',
            weight: 700
        });
        context.strokeStyle = 'rgba(217, 154, 43, .5)';
        context.beginPath();
        context.moveTo(panel.x + 30, 465);
        context.lineTo(panel.x + panel.width - 30, 465);
        context.stroke();
        drawFittedText(context, root.dataset.plaqueSplitLabel || 'SPLIT DAYS', panel.x + 100, 500, 140, 16, {
            family: 'Arial, sans-serif',
            weight: 700
        });
        drawFittedText(context, root.dataset.plaqueTotalLabel || 'TOTAL DAYS', panel.x + 265, 500, 140, 16, {
            family: 'Arial, sans-serif',
            weight: 700
        });
        drawFittedText(context, root.dataset.plaqueSplitDays || '0', panel.x + 100, 545, 140, 38);
        drawFittedText(context, root.dataset.plaqueTotalDays || '0', panel.x + 265, 545, 140, 38);
        context.strokeStyle = 'rgba(217, 154, 43, .55)';
        context.beginPath();
        context.moveTo(panelCenter, 485);
        context.lineTo(panelCenter, 562);
        context.stroke();
        context.beginPath();
        context.moveTo(panel.x + 30, 582);
        context.lineTo(panel.x + panel.width - 30, 582);
        context.stroke();
        drawFittedText(context, 'UNLOCKED', panelCenter, 616, 315, 16, {
            family: 'Arial, sans-serif',
            color: '#efc36e',
            weight: 700
        });
        drawImageContained(context, calendarArt, panel.x + 72, 605, 38, 38);
        drawFittedText(context, root.dataset.plaqueEarnedDate || '', panelCenter + (calendarArt ? 22 : 0), 646, calendarArt ? 255 : 315, 21, { color: '#ffe7a4' });
        drawFittedText(context, '“Every mile. Every achievement.”', panelCenter, 680, 315, 18, {
            color: '#efc36e',
            weight: 500,
            minimumSize: 14
        });
        drawFittedText(context, 'Every victory.', panelCenter, 709, 315, 18, {
            color: '#efc36e',
            weight: 500,
            minimumSize: 14
        });
        drawFittedText(context, 'Your voyage. Your legacy.”', panelCenter, 738, 315, 18, {
            color: '#efc36e',
            weight: 500,
            minimumSize: 14
        });
        //drawFittedText(context, 'RUN THE SEAS', panelCenter, 780, 315, 18, { color: '#f5c363', weight: 700 });

        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (blob && blob.size) resolve(blob);
                else reject(new Error('The trophy picture was empty.'));
            }, 'image/png');
        });
    };
    const downloadPicture = (blob, filename) => {
        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    };

    const dialog = document.createElement('div');
    dialog.className = 'rts-single-trophy__share-dialog';
    dialog.hidden = true;
    dialog.innerHTML = `
        <div class="rts-single-trophy__share-panel" role="dialog" aria-modal="true" aria-label="Share trophy picture">
            <button class="rts-single-trophy__share-close" type="button" data-rts-share-close aria-label="Close share options">×</button>
            <h2>Share Trophy Picture</h2>
            <p data-rts-share-message>Preparing your trophy picture…</p>
            <img data-rts-share-preview alt="Preview of the trophy picture">
            <div class="rts-single-trophy__share-choices">
                <button type="button" data-rts-share-native disabled>Share Picture</button>
                <button type="button" data-rts-share-copy disabled>Copy Picture</button>
                <button type="button" data-rts-share-download disabled>Download Picture</button>
            </div>
        </div>`;
    root.appendChild(dialog);

    const message = dialog.querySelector('[data-rts-share-message]');
    const preview = dialog.querySelector('[data-rts-share-preview]');
    const nativeButton = dialog.querySelector('[data-rts-share-native]');
    const copyButton = dialog.querySelector('[data-rts-share-copy]');
    const downloadButton = dialog.querySelector('[data-rts-share-download]');
    const closeButton = dialog.querySelector('[data-rts-share-close]');
    let preparedBlob = null;
    let preparedFile = null;
    let previewUrl = '';
    let preparing = false;

    const closeDialog = () => {
        dialog.hidden = true;
        shareButton.setAttribute('aria-expanded', 'false');
        shareButton.focus();
    };
    closeButton.addEventListener('click', closeDialog);
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeDialog();
    });
    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !dialog.hidden) closeDialog();
    });

    shareButton.setAttribute('aria-haspopup', 'dialog');
    shareButton.setAttribute('aria-expanded', 'false');
    shareButton.addEventListener('click', async () => {
        dialog.hidden = false;
        shareButton.setAttribute('aria-expanded', 'true');
        closeButton.focus();
        if (preparing) return;

        preparing = true;
        preparedBlob = null;
        preparedFile = null;
        nativeButton.disabled = true;
        copyButton.disabled = true;
        downloadButton.disabled = true;
        preview.removeAttribute('src');
        message.textContent = 'Preparing your trophy picture…';
        showStatus('Preparing trophy picture…', true);
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = '';
        }

        try {
            preparedBlob = await createTrophyShareCard();
            preparedFile = new File(
                [preparedBlob],
                shareButton.dataset.shareFilename || 'run-the-seas-trophy.png',
                { type: 'image/png' }
            );
            previewUrl = URL.createObjectURL(preparedBlob);
            preview.src = previewUrl;
            const shareData = {
                title: shareButton.dataset.shareTitle || 'Run The Seas Trophy',
                text: shareButton.dataset.shareText || 'My Run The Seas trophy',
                files: [preparedFile]
            };
            let nativeSupported = false;
            try {
                nativeSupported = Boolean(
                    navigator.share && (!navigator.canShare || navigator.canShare(shareData))
                );
            } catch (error) {
                nativeSupported = false;
            }
            const copySupported = Boolean(navigator.clipboard && window.ClipboardItem);
            nativeButton.disabled = !nativeSupported;
            copyButton.disabled = !copySupported;
            downloadButton.disabled = false;
            message.textContent = nativeSupported
                ? 'Choose how you want to share your picture.'
                : 'This browser cannot open image share options. You can copy or download the picture.';
            showStatus('Trophy picture ready.');
        } catch (error) {
            console.error('Unable to create the trophy picture.', error);
            message.textContent = 'Unable to create the trophy picture. Please close this window and try again.';
            showStatus('Unable to create the trophy picture. Please try again.');
        } finally {
            preparing = false;
        }
    });

    nativeButton.addEventListener('click', async () => {
        if (!preparedFile || !navigator.share) return;
        try {
            await navigator.share({
                title: shareButton.dataset.shareTitle || 'Run The Seas Trophy',
                text: shareButton.dataset.shareText || 'My Run The Seas trophy',
                files: [preparedFile]
            });
            message.textContent = 'Trophy picture shared.';
            showStatus('Trophy picture shared.');
        } catch (error) {
            if (error && error.name === 'AbortError') return;
            message.textContent = 'The browser could not open its share options. Try Copy Picture instead.';
        }
    });
    copyButton.addEventListener('click', async () => {
        if (!preparedBlob || !navigator.clipboard || !window.ClipboardItem) return;
        try {
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': preparedBlob })]);
            message.textContent = 'Trophy picture copied. You can paste it into a message or post.';
            showStatus('Trophy picture copied.');
        } catch (error) {
            message.textContent = 'The browser could not copy the picture. Use Download Picture instead.';
        }
    });
    downloadButton.addEventListener('click', () => {
        if (!preparedBlob) return;
        downloadPicture(preparedBlob, shareButton.dataset.shareFilename || 'run-the-seas-trophy.png');
        message.textContent = 'Trophy picture downloaded.';
        showStatus('Trophy picture downloaded.');
    });
}

async function initTrophyViewer(root) {
    const mainElement = root.querySelector('[data-rts-main-model]');
    const stage = root.querySelector('.rts-single-trophy__model-stage');
    const viewer = root.querySelector('.rts-single-trophy__viewer');
    const viewButtons = Array.from(root.querySelectorAll('[data-rts-model-angle]'));
    const rotateButtons = Array.from(root.querySelectorAll('[data-rts-rotate]'));
    const step = Math.max(15, Math.min(90, Number(viewer && viewer.dataset.rotationStep) || 45));
    let currentAngle = 0;
    let controller = null;

    const selectNearestView = (angle) => {
        let nearest = null;
        let nearestDistance = Infinity;
        viewButtons.forEach((button) => {
            const distance = angularDistance(angle, Number(button.dataset.rtsModelAngle));
            if (distance < nearestDistance) {
                nearest = button;
                nearestDistance = distance;
            }
        });
        viewButtons.forEach((button) => {
            const selected = button === nearest;
            button.classList.toggle('is-current', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
    };
    const moveTo = (angle) => {
        currentAngle = Number(angle) || 0;
        selectNearestView(currentAngle);
        if (controller) controller.setAngle(currentAngle);
    };

    if (mainElement && !supportsWebGL()) {
        showStaticTrophyFallback(root, stage, viewButtons, rotateButtons);
        initShare(root);
        return;
    }

    if (mainElement) {
        try {
            controller = await createThreeViewer(mainElement, root, { interactive: true, angle: currentAngle });
            stage.classList.add('is-model-loaded');
            controller.controls.addEventListener('change', () => {
                currentAngle = controller.getAngle();
                selectNearestView(currentAngle);
            });
        } catch (error) {
            showStaticTrophyFallback(root, stage, viewButtons, rotateButtons);
            console.warn('The 3D trophy is unavailable; displaying its static artwork instead.', error);
            initShare(root);
            return;
        }
    }
    root.querySelectorAll('[data-rts-thumbnail-model]').forEach((element) => {
        createThreeViewer(element, root, { interactive: false, angle: Number(element.dataset.modelAngle) || 0 }).catch((error) => {
            element.classList.add('has-model-error');
            console.error('Unable to load a Three.js trophy preview.', error);
        });
    });
    viewButtons.forEach((button) => button.addEventListener('click', () => moveTo(Number(button.dataset.rtsModelAngle))));
    rotateButtons.forEach((button) => button.addEventListener('click', () => moveTo(currentAngle + (button.dataset.rtsRotate === 'previous' ? -step : step))));
    root.addEventListener('keydown', (event) => {
        if (event.defaultPrevented || /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName)) return;
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            moveTo(currentAngle - step);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            moveTo(currentAngle + step);
        }
    });
    initShare(root);
}

document.querySelectorAll('[data-rts-single-trophy]').forEach((root) => {
    initTrophyViewer(root).catch((error) => console.error('Unable to initialize trophy viewer.', error));
});
