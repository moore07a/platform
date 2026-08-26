<?php require_once(__DIR__ . '/init.php'); ?>
<?php
// navbar.php - Main Navigation (permission-aware)
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
}

if (!isLoggedIn()) {
    return;
}

require_once __DIR__ . '/includes/functions.php';

$subscriptionNotice = null;
if (!isPlatformOwner() && hasRole('farm_admin')) {
    $farm = currentFarm();
    if (!empty($farm['subscription_ends_at'])) {
        $daysLeft = (int) floor((strtotime($farm['subscription_ends_at']) - strtotime(date('Y-m-d'))) / 86400);
        if ($daysLeft >= 0 && $daysLeft <= 14) {
            $subscriptionNotice = 'Subscription ends in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . '. Please contact the platform owner to renew.';
        }
    }
}
?>

<?php if ($subscriptionNotice): ?><div class="alert alert-warning rounded-0 mb-0 text-center no-print"><?php echo htmlspecialchars($subscriptionNotice); ?></div><?php endif; ?>
<nav id="appNavbar" class="navbar navbar-expand-lg navbar-dark bg-success shadow-sm no-print">
  <div class="container-fluid">
    <a class="navbar-brand farm-brand" href="<?php echo BASE_URL; ?>/dashboard.php">
      <span class="brand-logo-badge">
        <img src="<?php echo htmlspecialchars(farmLogoUrl(), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(farmBrandName(), ENT_QUOTES, 'UTF-8'); ?> logo" height="30" class="brand-logo-img">
      </span>
      <span class="brand-text"><?php echo htmlspecialchars(farmBrandName(), ENT_QUOTES, 'UTF-8'); ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMenu">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarMenu">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0 main-nav-spacing">

        <li class="nav-item">
          <a class="nav-link" href="<?php echo BASE_URL; ?>/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        </li>

        <?php if (hasPermission($_SESSION['user_type'], 'inventory')): ?>
        <li class="nav-item">
          <a class="nav-link" href="<?php echo BASE_URL; ?>/inventory.php">
            <i class="bi bi-box-seam"></i> Inventory
          </a>
        </li>
        <?php endif; ?>

        <?php if (hasPermission($_SESSION['user_type'], 'poultry_overview')): ?>
        <!-- Poultry Dropdown -->
        <li class="nav-item dropdown">
          <button class="nav-link dropdown-toggle btn btn-link" type="button" id="poultryMenu" data-nav-dropdown-toggle="dropdown" aria-expanded="false"> <i class="bi bi-egg"></i> Poultry</button>
          <ul class="dropdown-menu">
            <?php if (hasPermission($_SESSION['user_type'], 'poultry_daily_layer')): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/poultry/layers_daily_record.php"><i class="bi bi-journal-check menu-icon me-2"></i> Layer Daily Record</a></li>
            <?php endif; ?>
            <?php if (hasPermission($_SESSION['user_type'], 'poultry_daily_broiler')): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/poultry/broiler_daily_record.php"><i class="bi bi-journal-text menu-icon me-2"></i> Broiler Daily Record</a></li>
            <?php endif; ?>
            <?php if (hasPermission($_SESSION['user_type'], 'poultry_feeds')): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/poultry/layer_feeds.php"><i class="bi bi-basket menu-icon me-2"></i> Layer Feeds</a></li>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/poultry/broiler_feeds.php"><i class="bi bi-basket2 menu-icon me-2"></i> Broiler Feeds</a></li>
            <?php endif; ?>
            <?php if (hasPermission($_SESSION['user_type'], 'poultry_expenses')): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/poultry/layer_expenses.php"><i class="bi bi-cash menu-icon me-2"></i> Layer Expenses</a></li>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/poultry/broiler_expenses.php"><i class="bi bi-cash-stack menu-icon me-2"></i> Broiler Expenses</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (hasPermission($_SESSION['user_type'], 'ruminant_overview')): ?>
        <!-- Ruminant Dropdown -->
        <li class="nav-item dropdown">
          <button class="nav-link dropdown-toggle btn btn-link" type="button" id="ruminantMenu" data-nav-dropdown-toggle="dropdown" aria-expanded="false"> <i class="bi bi-cow"></i> Ruminants</button>
          <ul class="dropdown-menu">
            <?php if (hasPermission($_SESSION['user_type'], 'ruminant_daily')): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/ruminant/ruminant_daily_record.php"><i class="bi bi-journal-richtext menu-icon me-2"></i> Daily Record</a></li>
            <?php endif; ?>
            <?php if (hasPermission($_SESSION['user_type'], 'ruminant_feeds')): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/ruminant/ruminant_feeds_record.php"><i class="bi bi-basket3 menu-icon me-2"></i> Ruminant Feeds</a></li>
            <?php endif; ?>
            <?php if (hasPermission($_SESSION['user_type'], 'ruminant_expenses')): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/ruminant/ruminant_expenses.php"><i class="bi bi-receipt menu-icon me-2"></i> Expenses</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (hasPermission($_SESSION['user_type'], 'management') || canViewBusinessReports()): ?>
        <!-- Management Dropdown -->
        <li class="nav-item dropdown">
          <button class="nav-link dropdown-toggle btn btn-link" type="button" id="manageMenu" data-nav-dropdown-toggle="dropdown" aria-expanded="false"> <i class="bi bi-briefcase"></i> Management</button>
          <ul class="dropdown-menu">
            <li><h6 class="dropdown-header">Reports <span class="dropdown-section-badge">Live</span></h6></li>
            <?php if (canViewBusinessReports()): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/management/sales_records.php"><i class="bi bi-graph-up menu-icon me-2"></i> Sales Report</a></li>
            <?php endif; ?>
            <?php if (canViewBusinessReports()): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/management/expenses.php"><i class="bi bi-cash-stack menu-icon me-2"></i> Expense Report</a></li>
            <?php endif; ?>
            <?php if (canViewBusinessReports()): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/management/poultry_ruminant_report.php"><i class="bi bi-clipboard-data menu-icon me-2"></i> Poultry & Ruminant Report</a></li>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/management/reports.php"><i class="bi bi-bar-chart-line menu-icon me-2"></i> Analytics Dashboard</a></li>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/management/production_cycles.php"><i class="bi bi-arrow-repeat menu-icon me-2"></i> Production Cycles</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><h6 class="dropdown-header">Administration <span class="dropdown-section-badge">Secure</span></h6></li>
            <?php if (!isPlatformOwner() && hasPermission($_SESSION['user_type'], 'users')): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/management/users.php"><i class="bi bi-people menu-icon me-2"></i> Users</a></li>
            <?php endif; ?>
            <?php if (isPlatformOwner()): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/management/farms.php"><i class="bi bi-buildings menu-icon me-2"></i> Platform Farms</a></li>
            <?php endif; ?>
            <?php if (hasPermission($_SESSION['user_type'], 'permissions')): ?>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/admin/permissions.php"><i class="bi bi-shield-lock menu-icon me-2"></i> Permissions</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

      </ul>

      <ul class="navbar-nav ms-auto">
        <li class="nav-item dropdown">
          <button class="nav-link dropdown-toggle btn btn-link" type="button" data-nav-dropdown-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-circle"></i> Account</button>
          <ul class="dropdown-menu dropdown-menu-end">
            <!-- <li><a class="dropdown-item" href="#"><i class="bi bi-person"></i> Profile</a></li> -->
            <!-- <li><a class="dropdown-item" href="#"><i class="bi bi-gear"></i> Settings</a></li> -->
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/logout.php"><i class="bi bi-box-arrow-right menu-icon me-2"></i> Logout</a></li>
          </ul>
        </li>
      </ul>

    </div>
  </div>
</nav>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const navDebugEnabled = (() => {
    const urlDebug = new URLSearchParams(window.location.search).get('nav_debug');
    if (urlDebug === '1') localStorage.setItem('nav_debug', '1');
    if (urlDebug === '0') localStorage.removeItem('nav_debug');
    return localStorage.getItem('nav_debug') === '1';
  })();

  const navDebug = (...args) => {
    if (!navDebugEnabled) return;
    console.log('[NavbarDebug]', ...args);
  };

  const toggles = document.querySelectorAll('.navbar [data-nav-dropdown-toggle="dropdown"]');
  navDebug('DOMContentLoaded', {
    page: window.location.pathname,
    toggleCount: toggles.length,
    bootstrapPresent: !!window.bootstrap,
    dropdownPluginPresent: !!(window.bootstrap && window.bootstrap.Dropdown),
    popperPresent: !!window.Popper,
    popperCreatePopperPresent: !!(window.Popper && typeof window.Popper.createPopper === 'function')
  });

  if (!toggles.length) return;

  // Always use a local navbar dropdown controller so Popper/Bootstrap mismatch cannot break navigation.
  navDebug('Using local navbar dropdown controller');

  const closeAll = () => {
    navDebug('Fallback closeAll');
    toggles.forEach((toggle) => {
      toggle.setAttribute('aria-expanded', 'false');
      const menu = toggle.parentElement?.querySelector('.dropdown-menu');
      if (menu) menu.classList.remove('show');
    });
  };

  toggles.forEach((toggle) => {
    toggle.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      const menu = this.parentElement?.querySelector('.dropdown-menu');
      if (!menu) return;

      const isOpen = menu.classList.contains('show');
      navDebug('Fallback toggle click', {
        id: this.id || '(no-id)',
        wasOpen: isOpen
      });
      closeAll();
      if (!isOpen) {
        menu.classList.add('show');
        this.setAttribute('aria-expanded', 'true');
        navDebug('Fallback menu opened', {
          id: this.id || '(no-id)'
        });
      }
    });
  });

  document.addEventListener('click', (event) => {
    navDebug('Document click closes menus', {
      target: event.target?.tagName || '(unknown)'
    });
    closeAll();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeAll();
    }
  });

  toggles.forEach((toggle) => {
    const menu = toggle.parentElement?.querySelector('.dropdown-menu');
    if (!menu) return;
    const items = () => Array.from(menu.querySelectorAll('.dropdown-item'));

    toggle.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        closeAll();
        menu.classList.add('show');
        toggle.setAttribute('aria-expanded', 'true');
        items()[0]?.focus();
      }
    });

    menu.addEventListener('keydown', (event) => {
      const list = items();
      if (!list.length) return;
      const currentIndex = list.indexOf(document.activeElement);

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        const nextIndex = currentIndex < 0 ? 0 : (currentIndex + 1) % list.length;
        list[nextIndex].focus();
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        const prevIndex = currentIndex <= 0 ? list.length - 1 : currentIndex - 1;
        list[prevIndex].focus();
      } else if (event.key === 'Home') {
        event.preventDefault();
        list[0].focus();
      } else if (event.key === 'End') {
        event.preventDefault();
        list[list.length - 1].focus();
      } else if (event.key === 'Escape') {
        event.preventDefault();
        closeAll();
        toggle.focus();
      }
    });
  });

  const currentPath = window.location.pathname.replace(/\/+$/, '');
  const allLinks = document.querySelectorAll('.navbar .nav-link[href], .navbar .dropdown-item[href]');
  allLinks.forEach((link) => {
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#')) return;
    const linkPath = new URL(href, window.location.origin).pathname.replace(/\/+$/, '');
    if (linkPath && linkPath === currentPath) {
      link.classList.add('active');
      const parentDropdown = link.closest('.dropdown');
      if (parentDropdown) {
        const toggle = parentDropdown.querySelector('.nav-link.dropdown-toggle');
        if (toggle) toggle.classList.add('active');
      }
    }
  });

  const navbar = document.getElementById('appNavbar');
  if (navbar) {
    const COMPACT_ENTER_Y = 52;
    const COMPACT_EXIT_Y = 20;

    const setCompactState = () => {
      const canCompact = window.innerWidth >= 992;
      if (!canCompact) {
        navbar.classList.remove('is-compact');
        return;
      }

      const currentlyCompact = navbar.classList.contains('is-compact');
      if (!currentlyCompact && window.scrollY > COMPACT_ENTER_Y) {
        navbar.classList.add('is-compact');
      } else if (currentlyCompact && window.scrollY < COMPACT_EXIT_Y) {
        navbar.classList.remove('is-compact');
      }
    };

    setCompactState();
    window.addEventListener('scroll', setCompactState, { passive: true });
    window.addEventListener('resize', setCompactState);
  }
});
</script>
