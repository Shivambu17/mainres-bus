<?php
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
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
            <small class="text-muted">Administrator</small>
        </div>
        
        <!-- Navigation Menu -->
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : ''; ?>" href="admin_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_buses.php' ? 'active' : ''; ?>" href="admin_buses.php">
                    <i class="fas fa-bus me-2"></i> Buses
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_schedules.php' ? 'active' : ''; ?>" href="admin_schedules.php">
                    <i class="fas fa-calendar-alt me-2"></i> Schedules
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_bookings.php' ? 'active' : ''; ?>" href="admin_bookings.php">
                    <i class="fas fa-ticket-alt me-2"></i> Bookings
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_users.php' ? 'active' : ''; ?>" href="admin_users.php">
                    <i class="fas fa-users me-2"></i> Users
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_reports.php' ? 'active' : ''; ?>" href="admin_reports.php">
                    <i class="fas fa-chart-pie me-2"></i> Reports
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_settings.php' ? 'active' : ''; ?>" href="admin_settings.php">
                    <i class="fas fa-cog me-2"></i> Settings
                </a>
            </li>
        </ul>
        
        <!-- Quick Stats -->
        <div class="mt-5 p-3 border-top">
            <h6><i class="fas fa-chart-line me-1"></i> Quick Stats</h6>
            <small class="text-muted">
                <?php
                require_once 'config.php';
                $totalUsers = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
                $activeBookings = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'confirmed'")->fetch_assoc()['count'];
                ?>
                Users: <?php echo $totalUsers; ?><br>
                Active Bookings: <?php echo $activeBookings; ?>
            </small>
        </div>
    </div>
</div>

<!-- Main Content (offset for sidebar) -->
<div class="col-md-9 col-lg-10 ms-md-auto px-4 pt-3">