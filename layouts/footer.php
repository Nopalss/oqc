        <!-- Footer Section -->
        <footer class="mt-auto border-t border-slate-200/80 bg-white py-3 px-4 md:px-6 text-center md:text-left flex flex-col md:flex-row items-center justify-between text-[11px] text-slate-500 gap-2">
            <div>
                &copy; <?= date('Y') ?> <span class="font-bold text-slate-700"><?= APP_NAME ?></span>. All rights reserved.
            </div>
        </footer>

    </div><!-- End of Main Content Container -->
</div><!-- End of Layout Wrapper -->

<!-- Real 3D CAD WebGL Engine Scripts (WebAssembly & Three.js) -->
<script>window.APP_BASE_URL = "<?= base_url() ?>";</script>
<script src="<?= base_url('assets/js/vendor/three.r128.min.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/OrbitControls.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/occt-import-js.js') ?>"></script>
<script src="<?= base_url('assets/js/cad_viewer.js') ?>"></script>

</body>
</html>
