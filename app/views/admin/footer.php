        <!-- Footer -->
        <footer class="footer">
            <div class="footer-content">
                <div class="footer-copyright">
                    &copy; <?= e(date('Y')) ?> <a href="<?= e(url('admin/')) ?>">ShopWise Kenya</a> · Nairobi. All Rights Reserved.
                </div>
                <div class="footer-credits">
                    <div class="credits">
                        <!-- All the links in the footer should remain intact. -->
                        <!-- You can delete the links only if you purchased the pro version. -->
                        <!-- Licensing information: https://bootstrapmade.com/license/ -->
                        <!-- Purchase the pro version with working PHP/AJAX contact form: https://bootstrapmade.com/power-admin-template/ -->
                        Designed by <a href="https://bootstrapmade.com/">BootstrapMade</a>
                    </div>
                </div>
            </div>
        </footer>
    </main>

    <!-- Back to Top -->
    <a href="#" class="back-to-top">
        <i class="bi bi-arrow-up"></i>
    </a>

    <!-- Vendor JS Files -->
    <script src="<?= e(asset('admin/assets/vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= e(asset('admin/assets/vendor/apexcharts/apexcharts.min.js')) ?>"></script>
    <script src="<?= e(asset('admin/assets/vendor/chart.js/chart.umd.js')) ?>"></script>
    <script src="<?= e(asset('admin/assets/vendor/echarts/echarts.min.js')) ?>"></script>
    <script src="<?= e(asset('admin/assets/vendor/simple-datatables/simple-datatables.js')) ?>"></script>
    <script src="<?= e(asset('admin/assets/vendor/quill/quill.js')) ?>"></script>
    <script src="<?= e(asset('admin/assets/vendor/tinymce/tinymce.min.js')) ?>"></script>
    <script src="<?= e(asset('admin/assets/vendor/choices.js/choices.min.js')) ?>"></script>
    <script src="<?= e(asset('admin/assets/vendor/flatpickr/flatpickr.min.js')) ?>"></script>
    <script src="<?= e(asset('admin/assets/vendor/php-email-form/validate.js')) ?>"></script>

    <!-- Template Main JS Files -->
    <script src="<?= e(asset('admin/assets/js/theme.js')) ?>"></script>
    <script src="<?= e(asset('admin/assets/js/main.js')) ?>"></script>

    <!-- App Sidebar Toggle (for app pages with sidebars) -->
    <script src="<?= e(asset('admin/assets/js/apps-sidebar-toggle.js')) ?>"></script>

    <?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>

</html>
