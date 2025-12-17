<?php
require_once 'config.php';
require_once 'functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MainRes Bus System - Efficient Student Transport</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <nav class="navbar">
                <div class="logo">
                    <i class="fas fa-bus"></i>
                    <h1>MainRes Bus System</h1>
                </div>
                <ul class="nav-links">
                    <li><a href="index.php" class="active"><i class="fas fa-home"></i> Home</a></li>
                    <?php if (!isLoggedIn()): ?>
                        <li><a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                        <li><a href="register.php"><i class="fas fa-user-plus"></i> Register</a></li>
                    <?php else: ?>
                        <?php if (isStudent()): ?>
                            <li><a href="student_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <?php elseif (isDriver()): ?>
                            <li><a href="driver_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <?php elseif (isAdmin()): ?>
                            <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <?php endif; ?>
                        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </header>

        <!-- Hero Section -->
        <section class="hero">
            <div class="hero-content">
                <h2>Efficient Transport for Teaching Students</h2>
                <p>Book your bus seat online, avoid overcrowding, and arrive on campus safely and on time.</p>
                
                <?php if (isLoggedIn() && isStudent()): ?>
                    <a href="student_book.php" class="btn btn-primary">
                        <i class="fas fa-bus"></i> Book a Seat Now
                    </a>
                <?php elseif (!isLoggedIn()): ?>
                    <a href="register.php" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Register as Student
                    </a>
                    <a href="login.php" class="btn btn-secondary">
                        <i class="fas fa-sign-in-alt"></i> Login to System
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <!-- Features Section -->
        <section class="features">
            <h2>System Features</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <i class="fas fa-mobile-alt"></i>
                    <h3>Online Booking</h3>
                    <p>Book your seat from anywhere using our web platform</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-qrcode"></i>
                    <h3>QR Code Verification</h3>
                    <p>Secure boarding with unique QR codes for each trip</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-bell"></i>
                    <h3>Real-time Notifications</h3>
                    <p>Get alerts for capacity updates and waitlist promotions</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-chart-line"></i>
                    <h3>Smart Waitlist</h3>
                    <p>Automatic waitlist management when buses are full</p>
                </div>
            </div>
        </section>

        <!-- How It Works -->
        <section class="how-it-works">
            <h2>How It Works</h2>
            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <h3>Register & Login</h3>
                    <p>Teaching students from MainRes register with valid credentials</p>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <h3>View Schedules</h3>
                    <p>Check available bus trips and seat availability</p>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <h3>Book Your Seat</h3>
                    <p>Reserve your spot and receive a QR code</p>
                </div>
                <div class="step">
                    <div class="step-number">4</div>
                    <h3>Board & Scan</h3>
                    <p>Show QR code to driver for verification</p>
                </div>
            </div>
        </section>

        <!-- Stats Section -->
        <section class="stats">
            <h2>System Statistics</h2>
            <div class="stats-grid">
                <?php
                // Fetch basic stats
                $stats = [
                    'total_trips' => 0,
                    'total_bookings' => 0,
                    'active_students' => 0,
                    'today_trips' => 0
                ];
                
                if ($conn) {
                    $stats['total_trips'] = mysqli_fetch_assoc(mysqli_query($conn, 
                        "SELECT COUNT(*) as count FROM trips"))['count'];
                    $stats['total_bookings'] = mysqli_fetch_assoc(mysqli_query($conn, 
                        "SELECT COUNT(*) as count FROM bookings"))['count'];
                    $stats['active_students'] = mysqli_fetch_assoc(mysqli_query($conn, 
                        "SELECT COUNT(*) as count FROM users WHERE role='student' AND status='active'"))['count'];
                    $stats['today_trips'] = mysqli_fetch_assoc(mysqli_query($conn, 
                        "SELECT COUNT(*) as count FROM trips WHERE trip_date = CURDATE()"))['count'];
                }
                ?>
                <div class="stat-card">
                    <i class="fas fa-route"></i>
                    <h3><?php echo $stats['total_trips']; ?></h3>
                    <p>Total Trips</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-chair"></i>
                    <h3><?php echo $stats['total_bookings']; ?></h3>
                    <p>Total Bookings</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <h3><?php echo $stats['active_students']; ?></h3>
                    <p>Active Students</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-calendar-day"></i>
                    <h3><?php echo $stats['today_trips']; ?></h3>
                    <p>Today's Trips</p>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="footer">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>MainRes Bus System</h3>
                    <p>Efficient transport management for Teaching students at Main Residence</p>
                </div>
                <div class="footer-section">
                    <h3>Quick Links</h3>
                    <ul>
                        <li><a href="index.php">Home</a></li>
                        <li><a href="login.php">Login</a></li>
                        <li><a href="register.php">Register</a></li>
                        <?php if (isLoggedIn()): ?>
                            <li><a href="logout.php">Logout</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>Contact</h3>
                    <p><i class="fas fa-envelope"></i> transport@mainres.ac.za</p>
                    <p><i class="fas fa-phone"></i> +27 11 123 4567</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> MainRes Bus System. All rights reserved.</p>
            </div>
        </footer>
    </div>

    <script src="scripts.js"></script>
</body>
</html>