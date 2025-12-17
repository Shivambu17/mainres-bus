<?php
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
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
            <small class="text-muted">Student</small>
        </div>
        
        <!-- Navigation Menu -->
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_dashboard.php' ? 'active' : ''; ?>" href="student_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_book.php' ? 'active' : ''; ?>" href="student_book.php">
                    <i class="fas fa-bus me-2"></i> Book Bus
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_mybookings.php' ? 'active' : ''; ?>" href="student_mybookings.php">
                    <i class="fas fa-calendar-check me-2"></i> My Bookings
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'student_profile.php' ? 'active' : ''; ?>" href="student_profile.php">
                    <i class="fas fa-user me-2"></i> My Profile
                </a>
            </li>
        </ul>
        
        <!-- System Info -->
        <div class="mt-5 p-3 border-top">
            <small class="text-muted">
                <i class="fas fa-info-circle me-1"></i>
                Need help? Contact transport office.
            </small>
        </div>
    </div>
</div>

<!-- Main Content (offset for sidebar) -->
<div class="col-md-9 col-lg-10 ms-md-auto px-4 pt-3">