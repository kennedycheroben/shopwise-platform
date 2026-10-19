<aside class="sidebar">

        <!-- Icon Strip -->
        <div class="sidebar-strip">
            <div class="sidebar-strip-logo">
                <a href="<?= e(url('admin/')) ?>">
          <img src="<?= e(asset('admin/assets/img/logo.webp')) ?>" alt="ShopWise Kenya">
        </a>
            </div>

            <nav class="sidebar-strip-nav">
                <ul class="strip-menu">
                    <li>
                        <button class="strip-item active" data-panel="dashboards" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Dashboards" type="button">
              <i class="ph ph-squares-four"></i>
            </button>
                    </li>
                    <li>
                        <button class="strip-item " data-panel="apps" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Apps" type="button">
              <i class="ph ph-rocket-launch"></i>
            </button>
                    </li>
                    <li>
                        <button class="strip-item " data-panel="users" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Users" type="button">
              <i class="ph ph-users-three"></i>
            </button>
                    </li>
                    <li>
                        <button class="strip-item " data-panel="components" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="UI Elements" type="button">
              <i class="ph ph-puzzle-piece"></i>
            </button>
                    </li>
                    <li>
                        <button class="strip-item " data-panel="forms" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Forms" type="button">
              <i class="ph ph-textbox"></i>
            </button>
                    </li>
                    <li>
                        <button class="strip-item " data-panel="tables" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Tables" type="button">
              <i class="ph ph-table"></i>
            </button>
                    </li>
                    <li>
                        <button class="strip-item " data-panel="charts" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Charts" type="button">
              <i class="ph ph-chart-line-up"></i>
            </button>
                    </li>
                    <li>
                        <button class="strip-item " data-panel="icons" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Icons" type="button">
              <i class="ph ph-diamond"></i>
            </button>
                    </li>
                    <li>
                        <button class="strip-item " data-panel="auth" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Authentication" type="button">
              <i class="ph ph-shield-check"></i>
            </button>
                    </li>
                    <li>
                        <button class="strip-item " data-panel="pages" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Pages" type="button">
              <i class="ph ph-file-text"></i>
            </button>
                    </li>
                </ul>
            </nav>

            <div class="sidebar-strip-bottom">
                <a href="#" class="strip-item" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Notifications">
          <i class="bi bi-bell"></i>
        </a>
                <a href="#" class="strip-item" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Settings">
          <i class="bi bi-gear"></i>
        </a>
                <a href="<?= e(url('admin/profile.php')) ?>" class="sidebar-strip-avatar" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Profile">
          <img src="<?= e(asset('admin/assets/img/profile-img.webp')) ?>" alt="<?= e($adminUser['first_name'] . ' ' . $adminUser['last_name']) ?>">
        </a>
            </div>
        </div>

        <!-- Nav Panel -->
        <div class="sidebar-panel">

            <!-- Panel: Dashboards -->
            <div class="sidebar-panel-section active" data-section="dashboards">
                <div class="sidebar-panel-header">
                    <h6>Dashboards</h6>
                    <button class="sidebar-panel-close" type="button"><i class="bi bi-x-lg"></i></button>
                </div>
                <ul class="panel-nav">
                    <li><a class="panel-link<?= ($pageTitle ?? '') === 'Dashboard' ? ' active' : '' ?>" href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                    <li><a class="panel-link<?= ($pageTitle ?? '') === 'My profile' ? ' active' : '' ?>" href="<?= e(url('admin/profile.php')) ?>">My profile</a></li>
                </ul>
            </div>

        </div>
    </aside>
