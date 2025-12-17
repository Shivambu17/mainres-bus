<?php
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'driver') {
    header('Location: login.php');
    exit();
}
?>
<!-- Sidebar -->
<div class="col-md-3 col-lg-2 sidebar bg-light border-end vh-100 position-fixed">
    <div class="sidebar-sticky pt-3">
        <!-- User Info -->
        <div class="text-center mb-4 p-3 border-bottom">
            <i class="fas fa-user-circle fa-3x text-primary mb-2"></i>
            <h6 class="mb-1"><?php echo htmlspecialchars($_SESSION['name']); ?></h6>
            <small class="text-muted">Driver</small>
        </div>
        
        <!-- Navigation Menu -->
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'driver_dashboard.php' ? 'active' : ''; ?>" href="driver_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'driver_scan.php' ? 'active' : ''; ?>" href="driver_scan.php">
                    <i class="fas fa-qrcode me-2"></i> Scan QR
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'driver_trips.php' ? 'active' : ''; ?>" href="driver_trips.php">
                    <i class="fas fa-route me-2"></i> My Trips
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'driver_reports.php' ? 'active' : ''; ?>" href="driver_reports.php">
                    <i class="fas fa-chart-bar me-2"></i> Reports
                </a>
            </li>
        </ul>
        
        <!-- Today's Info -->
        <div class="mt-5 p-3 border-top">
            <h6><i class="fas fa-calendar-day me-1"></i> Today</h6>
            <p class="mb-1">Trips: <span class="badge bg-primary"><?php echo date('l'); ?></span></p>
            <small class="text-muted"><?php echo date('F j, Y'); ?></small>
        </div>
    </div>
</div>

<!-- Main Content (offset for sidebar) -->
<div class="col-md-9 col-lg-10 ms-md-auto px-4 pt-3">