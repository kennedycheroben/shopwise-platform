# Third-Party Licenses & Notices

The ShopWise Platform utilizes front-end UI templates and third-party libraries under their respective licensing terms.

---

## 1. PowerAdmin - Bootstrap Admin Template
- **Author**: BootstrapMade
- **Author URL**: https://bootstrapmade.com/
- **License**: Creative Commons Attribution 3.0 (CC BY 3.0) / BootstrapMade Free License
- **Usage**: Administrative dashboard shell, forms, UI components, and table layouts located in `public/admin/` and `app/views/admin/`.
- **Attribution Requirement**: The backlink / credit link to BootstrapMade must remain visible in the administrative footer (`app/views/admin/footer.php`):
  ```html
  <div class="credits">
    Designed by <a href="https://bootstrapmade.com/">BootstrapMade</a>
  </div>
  ```

---

## 2. ShopWise - eCommerce Storefront Template
- **Author**: BootstrapMade
- **Author URL**: https://bootstrapmade.com/
- **License**: Creative Commons Attribution 3.0 (CC BY 3.0) / BootstrapMade Free License
- **Usage**: Public storefront layouts, catalog grids, shopping cart, checkout, and customer portal views (Phase 3 & Phase 4).
- **Attribution Requirement**: The backlink / credit link to BootstrapMade must be maintained in the storefront footer.

---

## 3. Vendored PHP Libraries
- **PHPMailer** (`vendor/PHPMailer/`):
  - **License**: GNU Lesser General Public License (LGPL) version 2.1
  - **Source**: https://github.com/PHPMailer/PHPMailer
  - Used for authenticated SMTP email delivery and queue processing.

---

## 4. Front-End Vendor Libraries
- **Bootstrap 5**: MIT License (Twitter, Inc. / The Bootstrap Authors)
- **Bootstrap Icons**: MIT License (Twitter, Inc. / The Bootstrap Authors)
- **ApexCharts**: MIT License (ApexCharts.com)
- **Simple-DataTables**: LGPL v2.1 (fiduswriter)
