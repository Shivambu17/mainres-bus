<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is a student
if (!isLoggedIn() || !isStudent()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');

// Get student info
$student_query = "SELECT s.* FROM students s WHERE s.student_id = '$user_id'";
$student_result = mysqli_query($conn, $student_query);
$student = mysqli_fetch_assoc($student_result);

// Get upcoming bookings
$upcoming_query = "SELECT b.*, t.trip_date, t.departure_time, t.route, bu.bus_number 
                   FROM bookings b
                   JOIN trips t ON b.trip_id = t.trip_id
                   JOIN buses bu ON t.bus_id = bu.bus_id
                   WHERE b.student_id = '$user_id' 
                   AND b.status IN ('confirmed', 'waitlisted')
                   AND t.trip_date >= '$today'
                   ORDER BY t.trip_date, t.departure_time
                   LIMIT 5";
$upcoming_result = mysqli_query($conn, $upcoming_query);

// Get today's trips
$today_trips_query = "SELECT t.*, bu.bus_number, 
                      (SELECT COUNT(*) FROM bookings WHERE trip_id = t.trip_id AND status = 'confirmed') as booked_count
                      FROM trips t
                      JOIN buses bu ON t.bus_id = bu.bus_id
                      WHERE t.trip_date = '$today'
                      AND t.status = 'scheduled'
                      ORDER BY t.departure_time";
$today_trips_result = mysqli_query($conn, $today_trips_query);

// Get notifications
$notifications_query = "SELECT * FROM notifications 
                        WHERE user_id = '$user_id' 
                        AND is_read = FALSE
                        ORDER BY created_at DESC
                        LIMIT 5";
$notifications_result = mysqli_query($conn, $notifications_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar sidebar-student">
            <div class="sidebar-header">
                <i class="fas fa-bus"></i>
                <h3>MainRes Bus</h3>
            </div>
            <div class="user-profile">
                <div class="avatar">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <h4><?php echo $_SESSION['name']; ?></h4>
                <p>Student</p>
                <p class="user-info">
                    <i class="fas fa-graduation-cap"></i> <?php echo $student['faculty']; ?><br>
                    <i class="fas fa-home"></i> <?php echo $student['residence']; ?>
                </p>
            </div>
            <nav class="sidebar-nav">
                <a href="student_dashboard.php" class="active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="student_book.php">
                    <i class="fas fa-bus"></i> Book a Trip
                </a>
                <a href="student_mybookings.php">
                    <i class="fas fa-calendar-check"></i> My Bookings
                </a>
                <a href="student_profile.php">
                    <i class="fas fa-user-cog"></i> Profile
                </a>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>
            <div class="sidebar-footer">
                <p>Need help?<br>Contact transport@mainres.ac.za</p>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <header class="dashboard-header">
                <h1>Student Dashboard</h1>
                <div class="header-actions">
                    <a href="student_book.php" class="btn btn-primary">
                        <i class="fas fa-bus"></i> Book New Trip
                    </a>
                    <button class="btn btn-notification" id="notificationBtn">
                        <i class="fas fa-bell"></i>
                        <span class="badge"><?php echo mysqli_num_rows($notifications_result); ?></span>
                    </button>
                </div>
            </header>

            <!-- Quick Stats -->
            <div class="quick-stats">
                <?php
                // Calculate stats
                $confirmed_bookings = mysqli_fetch_assoc(mysqli_query($conn, 
                    "SELECT COUNT(*) as count FROM bookings WHERE student_id = '$user_id' AND status = 'confirmed'"))['count'];
                $waitlist_bookings = mysqli_fetch_assoc(mysqli_query($conn, 
                    "SELECT COUNT(*) as count FROM bookings WHERE student_id = '$user_id' AND status = 'waitlisted'"))['count'];
                $used_bookings = mysqli_fetch_assoc(mysqli_query($conn, 
                    "SELECT COUNT(*) as count FROM bookings b JOIN trips t ON b.trip_id = t.trip_id 
                     WHERE b.student_id = '$user_id' AND b.status = 'used'"))['count'];
                ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $confirmed_bookings; ?></h3>
                        <p>Confirmed Bookings</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $waitlist_bookings; ?></h3>
                        <p>Waitlisted</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $used_bookings; ?></h3>
                        <p>Past Trips</p>
                    </div>
                </div>
            </div>

            <!-- Today's Trips -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-calendar-day"></i> Today's Available Trips</h2>
                    <a href="student_book.php" class="btn btn-secondary">View All</a>
                </div>
                <div class="trips-grid">
                    <?php if (mysqli_num_rows($today_trips_result) > 0): ?>
                        <?php while ($trip = mysqli_fetch_assoc($today_trips_result)): 
                            $available_seats = BUS_CAPACITY - $trip['booked_count'];
                            $is_full = $available_seats <= 0;
                        ?>
                            <div class="trip-card <?php echo $is_full ? 'trip-full' : ''; ?>">
                                <div class="trip-header">
                                    <h3><?php echo date('h:i A', strtotime($trip['departure_time'])); ?></h3>
                                    <span class="trip-status <?php echo $is_full ? 'status-full' : 'status-available'; ?>">
                                        <?php echo $is_full ? 'FULL' : $available_seats . ' seats'; ?>
                                    </span>
                                </div>
                                <div class="trip-body">
                                    <p><i class="fas fa-route"></i> <?php echo $trip['route']; ?></p>
                                    <p><i class="fas fa-bus"></i> Bus <?php echo $trip['bus_number']; ?></p>
                                    <div class="capacity-bar">
                                        <div class="capacity-fill" style="width: <?php echo ($trip['booked_count'] / BUS_CAPACITY) * 100; ?>%"></div>
                                    </div>
                                </div>
                                <div class="trip-footer">
                                    <?php if (!$is_full): ?>
                                        <a href="student_book.php?trip_id=<?php echo $trip['trip_id']; ?>" class="btn btn-primary btn-sm">
                                            Book Now
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled>
                                            Join Waitlist
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times fa-3x"></i>
                            <h3>No trips scheduled for today</h3>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Upcoming Bookings -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-calendar-alt"></i> Your Upcoming Bookings</h2>
                    <a href="student_mybookings.php" class="btn btn-secondary">View All</a>
                </div>
                <div class="bookings-table">
                    <?php if (mysqli_num_rows($upcoming_result) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Route</th>
                                    <th>Bus</th>
                                    <th>Status</th>
                                    <th>QR Code</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($booking = mysqli_fetch_assoc($upcoming_result)): ?>
                                    <tr>
                                        <td>
                                            <?php echo date('d M Y', strtotime($booking['trip_date'])); ?><br>
                                            <?php echo date('h:i A', strtotime($booking['departure_time'])); ?>
                                        </td>
                                        <td><?php echo $booking['route']; ?></td>
                                        <td><?php echo $booking['bus_number']; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $booking['status']; ?>">
                                                <?php echo ucfirst($booking['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($booking['qr_code'] && $booking['status'] == 'confirmed'): ?>
                                                <a href="uploads/qr_codes/<?php echo $booking['qr_code']; ?>" download class="btn-qr">
                                                    <i class="fas fa-qrcode"></i> Download
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $trip_datetime = $booking['trip_date'] . ' ' . $booking['departure_time'];
                                            $cancel_deadline = date('Y-m-d H:i:s', strtotime('-1 hour', strtotime($trip_datetime)));
                                            $now = date('Y-m-d H:i:s');
                                            
                                            if ($booking['status'] == 'confirmed' && $now < $cancel_deadline): ?>
                                                <a href="student_book.php?cancel=<?php echo $booking['booking_id']; ?>" 
                                                   class="btn btn-danger btn-sm"
                                                   onclick="return confirm('Are you sure you want to cancel this booking?')">
                                                    Cancel
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-plus fa-3x"></i>
                            <h3>No upcoming bookings</h3>
                            <a href="student_book.php" class="btn btn-primary">Book Your First Trip</a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <script src="scripts.js"></script>
</body>
</html>