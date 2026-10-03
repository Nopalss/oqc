/**
 * OQC Real 3D CAD (.STP/.STEP) Viewer Engine
 * Powered by OpenCASCADE WebAssembly (occt-import-js) and Three.js
 */
(function (global) {
    "use strict";

    var THEMES = {
        light: {
            key: 'light',
            label: 'Light Gray',
            clearColor: 0xf1f5f9,
            modelColor: 0x94a3b8, // Authentic Neutral CAD Gray (Engineering Plastic / Matte Aluminum)
            edgeColor: 0x334155,  // Dark slate high-contrast feature edges
            btnBg: 'rgba(255, 255, 255, 0.92)',
            btnColor: '#1e293b',
            btnBorder: '#cbd5e1'
        },
        slate: {
            key: 'slate',
            label: 'Dark Slate',
            clearColor: 0x0f172a,
            modelColor: 0x94a3b8, // Clean industrial gray
            edgeColor: 0x64748b,  // Subtle technical contour line on dark background
            btnBg: 'rgba(15, 23, 42, 0.85)',
            btnColor: '#ffffff',
            btnBorder: '#334155'
        },
        white: {
            key: 'white',
            label: 'Clean White',
            clearColor: 0xffffff,
            modelColor: 0x8998a9, // Slightly deeper contrast gray for pure white canvas
            edgeColor: 0x1e293b,  // Sharp solid dark technical lines
            btnBg: 'rgba(248, 250, 252, 0.95)',
            btnColor: '#0f172a',
            btnBorder: '#cbd5e1'
        }
    };

    var themeKeys = ['light', 'slate', 'white'];
    var currentThemeKey = localStorage.getItem('oqc_3d_bg_theme') || 'light';
    if (!THEMES[currentThemeKey]) currentThemeKey = 'light';

    // In-memory cache for parsed CAD meshes: { [url]: result }
    var parsedCadCache = {};
    var occtModulePromise = null;

    function getOcctModule() {
        if (occtModulePromise) return occtModulePromise;

        var wasmPath = 'assets/js/vendor/occt-import-js.wasm';
        if (typeof global.APP_BASE_URL === 'string' && global.APP_BASE_URL) {
            wasmPath = global.APP_BASE_URL.replace(/\/$/, '') + '/' + wasmPath;
        }

        occtModulePromise = new Promise(function (resolve, reject) {
            if (typeof occtimportjs !== 'function') {
                return reject(new Error('occt-import-js library is not loaded.'));
            }
            occtimportjs({
                locateFile: function (name) {
                    if (name && name.indexOf('.wasm') !== -1) {
                        return wasmPath;
                    }
                    return name;
                }
            }).then(resolve).catch(reject);
        });

        return occtModulePromise;
    }

    /**
     * Active viewer sessions by containerId: { [containerId]: viewerContext }
     */
    var activeViewers = {};

    function renderCadStep(containerId, stpUrl, options) {
        options = options || {};
        var container = typeof containerId === 'string' ? document.getElementById(containerId) : containerId;
        if (!container) return;

        // Clean up previous viewer in this container if exists
        var cId = container.id || ('cad_vp_' + Math.random().toString(36).substr(2, 6));
        if (activeViewers[cId]) {
            try {
                activeViewers[cId].destroy();
            } catch (e) {
                console.warn('Destroy viewer error:', e);
            }
            delete activeViewers[cId];
        }

        container.innerHTML = '';
        container.style.position = 'relative';
        container.style.overflow = 'hidden';

        var activeTheme = THEMES[currentThemeKey] || THEMES.light;

        // Sync parent viewport background if present
        var vp3d = document.getElementById('viewport-3d');
        if (vp3d) {
            vp3d.style.backgroundColor = '#' + activeTheme.clearColor.toString(16).padStart(6, '0');
        }

        // Loading Overlay
        var loadingOverlay = document.createElement('div');
        loadingOverlay.style.position = 'absolute';
        loadingOverlay.style.inset = '0';
        loadingOverlay.style.display = 'flex';
        loadingOverlay.style.flexDirection = 'column';
        loadingOverlay.style.alignItems = 'center';
        loadingOverlay.style.justifyContent = 'center';
        loadingOverlay.style.backgroundColor = 'rgba(15, 23, 42, 0.85)';
        loadingOverlay.style.zIndex = '50';
        loadingOverlay.style.color = '#ffffff';
        loadingOverlay.style.backdropFilter = 'blur(4px)';

        loadingOverlay.innerHTML = [
            '<div style="display:flex; flex-direction:column; align-items:center; gap:12px;">',
            '  <svg class="animate-spin" style="width:36px; height:36px; color:#38bdf8;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">',
            '    <circle style="opacity:0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>',
            '    <path style="opacity:0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>',
            '  </svg>',
            '  <div id="cad-loading-text" style="font-size:12px; font-weight:600; color:#e2e8f0; font-family:sans-serif; text-align:center;">',
            '    Mengunduh file 3D (.STP)...',
            '  </div>',
            '</div>'
        ].join('');
        container.appendChild(loadingOverlay);

        function updateLoadingText(txt) {
            var el = container.querySelector('#cad-loading-text');
            if (el) el.textContent = txt;
        }

        // Three.js Scene Setup
        var width = container.clientWidth || 600;
        var height = container.clientHeight || 450;

        var scene = new THREE.Scene();
        var camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 2000);
        camera.position.set(100, 100, 100);

        var renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        renderer.setSize(width, height);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setClearColor(activeTheme.clearColor, 1);
        container.appendChild(renderer.domElement);

        // OrbitControls
        var controls = null;
        if (typeof THREE.OrbitControls === 'function') {
            controls = new THREE.OrbitControls(camera, renderer.domElement);
            controls.enableDamping = true;
            controls.dampingFactor = 0.08;
            controls.rotateSpeed = 0.8;
            controls.zoomSpeed = 1.0;
        }

        // Lighting (Industrial Multi-directional Studio setup)
        var ambientLight = new THREE.AmbientLight(0xffffff, 0.65);
        scene.add(ambientLight);

        var dirLight1 = new THREE.DirectionalLight(0xffffff, 0.7);
        dirLight1.position.set(1, 1.5, 1);
        scene.add(dirLight1);

        var dirLight2 = new THREE.DirectionalLight(0xffffff, 0.4);
        dirLight2.position.set(-1, -1, -1);
        scene.add(dirLight2);

        var modelGroup = new THREE.Group();
        scene.add(modelGroup);

        var isWireframe = false;
        var meshObjects = [];
        var edgeObjects = [];
        var defaultCameraPos = new THREE.Vector3(100, 100, 100);
        var defaultTarget = new THREE.Vector3(0, 0, 0);

        // UI Top-Right Toolbar
        var ui = document.createElement('div');
        ui.style.position = 'absolute';
        ui.style.top = '12px';
        ui.style.right = '12px';
        ui.style.display = 'flex';
        ui.style.gap = '6px';
        ui.style.zIndex = '10';

        var btnReset = document.createElement('button');
        btnReset.className = 'btn-secondary py-1 px-2.5 text-xs font-semibold rounded-md shadow-sm transition-all';
        btnReset.innerHTML = 'Reset View';

        var btnWire = document.createElement('button');
        btnWire.className = 'btn-secondary py-1 px-2.5 text-xs font-semibold rounded-md shadow-sm transition-all';
        btnWire.innerHTML = 'Wireframe';

        var btnTheme = document.createElement('button');
        btnTheme.className = 'btn-secondary py-1 px-2.5 text-xs font-semibold rounded-md shadow-sm transition-all';

        function updateToolbarTheme() {
            var th = THEMES[currentThemeKey] || THEMES.light;
            [btnReset, btnWire, btnTheme].forEach(function (btn) {
                btn.style.backgroundColor = th.btnBg;
                btn.style.color = th.btnColor;
                btn.style.borderColor = th.btnBorder;
                btn.style.cursor = 'pointer';
            });
            btnTheme.innerHTML = 'BG: ' + th.label;

            renderer.setClearColor(th.clearColor, 1);

            meshObjects.forEach(function (m) {
                if (m.material) {
                    if (m.userData && m.userData.hasNativeColor && m.userData.nativeColor) {
                        m.material.color.copy(m.userData.nativeColor);
                    } else {
                        m.material.color.setHex(th.modelColor);
                    }
                }
            });
            edgeObjects.forEach(function (e) {
                if (e.material) {
                    e.material.color.setHex(th.edgeColor);
                }
            });

            var vp = document.getElementById('viewport-3d');
            if (vp) {
                vp.style.backgroundColor = '#' + th.clearColor.toString(16).padStart(6, '0');
            }
        }

        btnReset.onclick = function () {
            camera.position.copy(defaultCameraPos);
            camera.lookAt(defaultTarget);
            if (controls) {
                controls.target.copy(defaultTarget);
                controls.update();
            }
        };

        btnWire.onclick = function () {
            isWireframe = !isWireframe;
            meshObjects.forEach(function (m) {
                if (m.material) m.material.wireframe = isWireframe;
            });
            edgeObjects.forEach(function (e) {
                e.visible = !isWireframe;
            });
            btnWire.style.fontWeight = isWireframe ? 'bold' : 'normal';
        };

        btnTheme.onclick = function () {
            var idx = themeKeys.indexOf(currentThemeKey);
            currentThemeKey = themeKeys[(idx + 1) % themeKeys.length];
            localStorage.setItem('oqc_3d_bg_theme', currentThemeKey);
            updateToolbarTheme();
        };

        updateToolbarTheme();
        ui.appendChild(btnTheme);
        ui.appendChild(btnReset);
        ui.appendChild(btnWire);
        container.appendChild(ui);

        // Render Loop
        var animId = null;
        var isDestroyed = false;

        function animate() {
            if (isDestroyed) return;
            animId = requestAnimationFrame(animate);
            if (controls) controls.update();
            renderer.render(scene, camera);
        }
        animate();

        // Handle Resize
        function onResize() {
            if (!container || isDestroyed) return;
            var w = container.clientWidth;
            var h = container.clientHeight;
            if (w === 0 || h === 0) return;
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
            renderer.setSize(w, h);
        }
        window.addEventListener('resize', onResize);

        // Fetch & Parse STEP file
        function loadStepGeometry() {
            if (parsedCadCache[stpUrl]) {
                buildMeshesFromData(parsedCadCache[stpUrl]);
                return;
            }

            updateLoadingText('Mengunduh file 3D (.STP)...');
            fetch(stpUrl)
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status + ' gagal mengunduh file .STP');
                    return res.arrayBuffer();
                })
                .then(function (buffer) {
                    updateLoadingText('Mengurai geometri CAD WebAssembly...');
                    return getOcctModule().then(function (occt) {
                        var fileBuffer = new Uint8Array(buffer);
                        var result = occt.ReadStepFile(fileBuffer, null);
                        if (!result || !result.success || !result.meshes || result.meshes.length === 0) {
                            throw new Error('Gagal mem-parsing geometri STEP. Format CAD tidak dikenali.');
                        }
                        parsedCadCache[stpUrl] = result;
                        buildMeshesFromData(result);
                    });
                })
                .catch(function (err) {
                    console.error('CAD Loading Error:', err);
                    showError(err.message || 'Gagal memuat model 3D CAD.');
                });
        }

        function buildMeshesFromData(result) {
            var th = THEMES[currentThemeKey] || THEMES.light;

            result.meshes.forEach(function (meshData) {
                var geometry = new THREE.BufferGeometry();
                if (meshData.attributes && meshData.attributes.position && meshData.attributes.position.array) {
                    geometry.setAttribute('position', new THREE.BufferAttribute(new Float32Array(meshData.attributes.position.array), 3));
                }

                if (meshData.attributes && meshData.attributes.normal && meshData.attributes.normal.array) {
                    geometry.setAttribute('normal', new THREE.BufferAttribute(new Float32Array(meshData.attributes.normal.array), 3));
                } else {
                    geometry.computeVertexNormals();
                }

                if (meshData.index && meshData.index.array) {
                    geometry.setIndex(new THREE.BufferAttribute(new Uint32Array(meshData.index.array), 1));
                } else if (meshData.attributes && meshData.attributes.index && meshData.attributes.index.array) {
                    geometry.setIndex(new THREE.BufferAttribute(new Uint32Array(meshData.attributes.index.array), 1));
                }

                // Check if file has native component/face color
                var hasNativeColor = Boolean(meshData.color && Array.isArray(meshData.color) && meshData.color.length >= 3);
                var meshColor = hasNativeColor
                    ? new THREE.Color(meshData.color[0], meshData.color[1], meshData.color[2])
                    : new THREE.Color(th.modelColor);

                var mat = new THREE.MeshPhongMaterial({
                    color: meshColor,
                    specular: 0x222d3d,
                    shininess: 32,
                    side: THREE.DoubleSide
                });

                var mesh = new THREE.Mesh(geometry, mat);
                mesh.userData.hasNativeColor = hasNativeColor;
                mesh.userData.nativeColor = hasNativeColor ? meshColor.clone() : null;
                modelGroup.add(mesh);
                meshObjects.push(mesh);

                // Feature edges for crisp CAD mechanical line definition
                try {
                    var edgesGeom = new THREE.EdgesGeometry(geometry, 28);
                    var edgeMat = new THREE.LineBasicMaterial({ color: th.edgeColor, linewidth: 1 });
                    var edgeLine = new THREE.LineSegments(edgesGeom, edgeMat);
                    mesh.add(edgeLine);
                    edgeObjects.push(edgeLine);
                } catch (e) {
                    // Ignore edges if geometry unsupported
                }
            });

            // Center & Auto-fit camera to bounding box
            var bbox = new THREE.Box3().setFromObject(modelGroup);
            var center = bbox.getCenter(new THREE.Vector3());
            var size = bbox.getSize(new THREE.Vector3());

            modelGroup.position.x -= center.x;
            modelGroup.position.y -= center.y;
            modelGroup.position.z -= center.z;

            var maxDim = Math.max(size.x, size.y, size.z);
            if (maxDim <= 0.001) maxDim = 50;

            var fovRad = camera.fov * (Math.PI / 180);
            var cameraDist = Math.abs(maxDim / 2 / Math.tan(fovRad / 2)) * 1.6;

            defaultCameraPos.set(cameraDist * 0.75, cameraDist * 0.75, cameraDist * 0.9);
            defaultTarget.set(0, 0, 0);

            camera.position.copy(defaultCameraPos);
            camera.near = maxDim / 100;
            camera.far = maxDim * 50;
            camera.updateProjectionMatrix();

            if (controls) {
                controls.maxDistance = maxDim * 20;
                controls.minDistance = maxDim / 20;
                controls.target.copy(defaultTarget);
                controls.update();
            }

            // Remove loading overlay
            if (loadingOverlay && loadingOverlay.parentNode) {
                loadingOverlay.parentNode.removeChild(loadingOverlay);
            }
        }

        function showError(msg) {
            if (loadingOverlay) {
                loadingOverlay.innerHTML = [
                    '<div style="display:flex; flex-direction:column; align-items:center; gap:8px; padding:20px; text-align:center;">',
                    '  <svg style="width:36px; height:36px; color:#f87171;" fill="none" stroke="currentColor" viewBox="0 0 24 24">',
                    '    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>',
                    '  </svg>',
                    '  <div style="font-size:12px; font-weight:bold; color:#f87171; font-family:sans-serif;">' + msg + '</div>',
                    '  <button id="cad-retry-btn" class="btn-secondary py-1 px-3 text-xs mt-2" style="background:#334155; color:#fff; border:none; cursor:pointer; border-radius:6px;">Ulangi</button>',
                    '</div>'
                ].join('');

                var retryBtn = loadingOverlay.querySelector('#cad-retry-btn');
                if (retryBtn) {
                    retryBtn.onclick = function () {
                        loadingOverlay.innerHTML = '';
                        renderCadStep(containerId, stpUrl, options);
                    };
                }
            }
        }

        // Start loading
        loadStepGeometry();

        // Save context
        var viewerContext = {
            destroy: function () {
                isDestroyed = true;
                if (animId) cancelAnimationFrame(animId);
                window.removeEventListener('resize', onResize);
                if (controls) controls.dispose();
                if (renderer) renderer.dispose();
                container.innerHTML = '';
            }
        };

        activeViewers[cId] = viewerContext;
        return viewerContext;
    }

    // Expose Global APIs
    global.renderCadStep = renderCadStep;

    // Backward-compatibility wrapper for legacy OQC3DViewer calls
    global.OQC3DViewer = function (containerId, options) {
        options = options || {};
        var container = typeof containerId === 'string' ? document.getElementById(containerId) : containerId;
        if (!container) return;

        var stpUrl = options.stpUrl || container.getAttribute('data-stp-url') || global.currentDrawing3dUrl;
        if (!stpUrl) {
            console.warn('OQC3DViewer: No STP URL provided or found in data-stp-url');
            return;
        }

        return renderCadStep(containerId, stpUrl, options);
    };

})(window);
