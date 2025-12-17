<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('login.php');
}

// Get statistics
$stats = [];

// Total bookings today
$stats['today_bookings'] = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM bookings b 
     JOIN trips t ON b.trip_id = t.trip_id 
     WHERE DATE(b.booking_date) = CURDATE()"))['count'];

// Active trips today
$stats['today_trips'] = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM trips 
     WHERE trip_date = CURDATE() AND status = 'scheduled'"))['count'];

// Total active students
$stats['active_students'] = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM users 
     WHERE role = 'student' AND status = 'active'"))['count'];

// Total active drivers
$stats['active_drivers'] = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM users 
     WHERE role = 'driver' AND status = 'active'"))['count'];

// Bus utilization (average)
$stats['bus_utilization'] = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT ROUND(AVG((booked_count / capacity) * 100), 2) as avg_util 
     FROM trips t JOIN buses b ON t.bus_id = b.bus_id 
     WHERE t.trip_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"))['avg_util'];

// Waitlist count
$stats['waitlist_count'] = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM bookings 
     WHERE status = 'waitlisted'"))['count'];

// Recent bookings
$recent_bookings_query = "SELECT b.*, u.name, u.surname, t.route, t.departure_time 
                         FROM bookings b
                         JOIN users u ON b.student_id = u.user_id
                         JOIN trips t ON b.trip_id = t.trip_id
                         ORDER BY b.booking_date DESC
                         LIMIT 10";
$recent_bookings_result = mysqli_query($conn, $recent_bookings_query);

// Today's trips
$today_trips_query = "SELECT t.*, b.bus_number, u.name as driver_name,
                      (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count
                      FROM trips t
                      JOIN buses b ON t.bus_id = b.bus_id
                      LEFT JOIN users u ON t.driver_id = u.user_id
                      WHERE t.trip_date = CURDATE()
                      ORDER BY t.departure_time";
$today_trips_result = mysqli_query($conn, $today_trips_query);

// Capacity alerts (trips with > 65 bookings)
$alerts_query = "SELECT t.*, b.bus_number,
                 (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count
                 FROM trips t
                 JOIN buses b ON t.bus_id = b.bus_id
                 WHERE t.trip_date >= CURDATE()
                 HAVING booked_count >= " . BUS_CAPACITY . "
                 ORDER BY t.trip_date, t.departure_time
                 LIMIT 5";
$alerts_result = mysqli_query($conn, $alerts_query);

// Recent issue reports
$issues_query = "SELECT r.*, u.name as driver_name, t.route
                 FROM issue_reports r
                 JOIN users u ON r.driver_id = u.user_id
                 LEFT JOIN trips t ON r.trip_id = t.trip_id
                 WHERE r.status != 'resolved'
                 ORDER BY r.reported_at DESC
                 LIMIT 5";
$issues_result = mysqli_query($conn, $issues_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar sidebar-admin">
            <div class="sidebar-header">
                <i class="fas fa-bus"></i>
                <h3>MainRes Bus</h3>
            </div>
            <div class="user-profile">
                <div class="avatar">
                    <i class="fas fa-user-shield"></i>
                </div>
                <h4><?php echo $_SESSION['name']; ?></h4>
                <p>Administrator</p>
                <p class="user-info">
                    <i class="fas fa-cog"></i> System Admin
                </p>
            </div>
            <nav class="sidebar-nav">
                <a href="admin_dashboard.php" class="active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="admin_buses.php">
                    <i class="fas fa-bus"></i> Bus Management
                </a>
                <a href="admin_schedules.php">
                    <i class="fas fa-calendar-alt"></i> Trip Schedules
                </a>
                <a href="admin_bookings.php">
                    <i class="fas fa-ticket-alt"></i> Bookings
                </a>
                <a href="admin_users.php">
                    <i class="fas fa-users"></i> User Management
                </a>
                <a href="admin_reports.php">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
                <a href="admin_settings.php">
                    <i class="fas fa-cog"></i> Settings
                </a>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <header class="dashboard-header">
                <h1>Admin Dashboard</h1>
                <div class="header-actions">
                    <span class="welcome">Welcome, <?php echo $_SESSION['name']; ?>!</span>
                    <span class="current-time"><?php echo date('l, F j, Y'); ?></span>
                </div>
            </header>

            <!-- Quick Stats -->
            <div class="quick-stats">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #3498db;">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['today_bookings']; ?></h3>
                        <p>Today's Bookings</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #2ecc71;">
                        <i class="fas fa-route"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['today_trips']; ?></h3>
                        <p>Today's Trips</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9b59b6;">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['active_students']; ?></h3>
                        <p>Active Students</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #e74c3c;">
                        <i class="fas fa-bus"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['bus_utilization']; ?>%</h3>
                        <p>Bus Utilization</p>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="dashboard-grid">
                <!-- Left Column -->
                <div class="grid-column">
                    <!-- Today's Trips -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-calendar-day"></i> Today's Trips</h2>
                            <a href="admin_schedules.php" class="btn btn-secondary">Manage All</a>
                        </div>
                        <?php if (mysqli_num_rows($today_trips_result) > 0): ?>
                            <div class="trips-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Route</th>
                                            <th>Bus</th>
                                            <th>Driver</th>
                                            <th>Booked</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($trip = mysqli_fetch_assoc($today_trips_result)): 
                                            $available_seats = BUS_CAPACITY - $trip['booked_count'];
                                            $occupancy_percent = ($trip['booked_count'] / BUS_CAPACITY) * 100;
                                        ?>
                                            <tr>
                                                <td><?php echo date('h:i A', strtotime($trip['departure_time'])); ?></td>
                                                <td><?php echo $trip['route']; ?></td>
                                                <td><?php echo $trip['bus_number']; ?></td>
                                                <td><?php echo $trip['driver_name'] ?: 'Not assigned'; ?></td>
                                                <td>
                                                    <div class="capacity-info">
                                                        <span><?php echo $trip['booked_count']; ?>/<?php echo BUS_CAPACITY; ?></span>
                                                        <div class="capacity-bar">
                                                            <div class="capacity-fill" style="width: <?php echo $occupancy_percent; ?>%"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $trip['status']; ?>">
                                                        <?php echo ucfirst($trip['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="admin_schedules.php?edit=<?php echo $trip['trip_id']; ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="admin_bookings.php?trip_id=<?php echo $trip['trip_id']; ?>" class="btn btn-secondary btn-sm">
                                                        <i class="fas fa-list"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times fa-2x"></i>
                                <p>No trips scheduled for today</p>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- Recent Bookings -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-history"></i> Recent Bookings</h2>
                            <a href="admin_bookings.php" class="btn btn-secondary">View All</a>
                        </div>
                        <?php if (mysqli_num_rows($recent_bookings_result) > 0): ?>
                            <div class="bookings-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Trip</th>
                                            <th>Time</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($booking = mysqli_fetch_assoc($recent_bookings_result)): ?>
                                            <tr>
                                                <td><?php echo $booking['name'] . ' ' . $booking['surname']; ?></td>
                                                <td><?php echo $booking['route']; ?></td>
                                                <td><?php echo date('h:i A', strtotime($booking['departure_time'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $booking['status']; ?>">
                                                        <?php echo ucfirst($booking['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, H:i', strtotime($booking['booking_date'])); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-ticket-alt fa-2x"></i>
                                <p>No recent bookings</p>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>

                <!-- Right Column -->
                <div class="grid-column">
                    <!-- Capacity Alerts -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-exclamation-triangle"></i> Capacity Alerts</h2>
                            <span class="badge badge-danger"><?php echo mysqli_num_rows($alerts_result); ?></span>
                        </div>
                        <?php if (mysqli_num_rows($alerts_result) > 0): ?>
                            <div class="alerts-list">
                                <?php while ($alert = mysqli_fetch_assoc($alerts_result)): 
                                    $excess = $alert['booked_count'] - BUS_CAPACITY;
                                ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-circle"></i>
                                        <div class="alert-content">
                                            <strong><?php echo date('M j', strtotime($alert['trip_date'])); ?> at <?php echo date('h:i A', strtotime($alert['departure_time'])); ?></strong>
                                            <p><?php echo $alert['route']; ?> - Bus <?php echo $alert['bus_number']; ?></p>
                                            <p>Over capacity by <?php echo $excess; ?> passengers</p>
                                        </div>
                                        <a href="admin_schedules.php?edit=<?php echo $alert['trip_id']; ?>" class="btn btn-primary btn-sm">
                                            Action
                                        </a>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle fa-2x"></i>
                                <p>No capacity alerts</p>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- Recent Issues -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-exclamation-circle"></i> Recent Issues</h2>
                            <a href="admin_reports.php" class="btn btn-secondary">View All</a>
                        </div>
                        <?php if (mysqli_num_rows($issues_result) > 0): ?>
                            <div class="issues-list">
                                <?php while ($issue = mysqli_fetch_assoc($issues_result)): 
                                    $severity_class = 'severity-' . $issue['severity'];
                                ?>
                                    <div class="issue-item <?php echo $severity_class; ?>">
                                        <div class="issue-header">
                                            <span class="issue-type"><?php echo ucfirst($issue['issue_type']); ?></span>
                                            <span class="issue-severity"><?php echo ucfirst($issue['severity']); ?></span>
                                        </div>
                                        <div class="issue-body">
                                            <p><strong><?php echo $issue['title']; ?></strong></p>
                                            <p><?php echo substr($issue['description'], 0, 100); ?>...</p>
                                            <p class="issue-meta">
                                                By: <?php echo $issue['driver_name']; ?>
                                                <?php if ($issue['route']): ?>
                                                    | Trip: <?php echo $issue['route']; ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="issue-footer">
                                            <span><?php echo date('M j, H:i', strtotime($issue['reported_at'])); ?></span>
                                            <span class="issue-status"><?php echo ucfirst($issue['status']); ?></span>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check fa-2x"></i>
                                <p>No pending issues</p>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- Quick Actions -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                        </div>
                        <div class="quick-actions">
                            <a href="admin_schedules.php?add=1" class="action-btn">
                                <i class="fas fa-plus-circle"></i>
                                <span>Add New Trip</span>
                            </a>
                            <a href="admin_buses.php?add=1" class="action-btn">
                                <i class="fas fa-bus"></i>
                                <span>Add Bus</span>
                            </a>
                            <a href="admin_bookings.php" class="action-btn">
                                <i class="fas fa-search"></i>
                                <span>Search Bookings</span>
                            </a>
                            <a href="admin_reports.php" class="action-btn">
                                <i class="fas fa-file-export"></i>
                                <span>Generate Report</span>
                            </a>
                        </div>
                    </section>

                    <!-- System Status -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-server"></i> System Status</h2>
                        </div>
                        <div class="system-status">
                            <div class="status-item">
                                <span class="status-label">Database</span>
                                <span class="status-indicator status-online"></span>
                                <span class="status-text">Online</span>
                            </div>
                            <div class="status-item">
                                <span class="status-label">Email Service</span>
                                <span class="status-indicator status-online"></span>
                                <span class="status-text">Online</span>
                            </div>
                            <div class="status-item">
                                <span class="status-label">Active Users</span>
                                <span class="status-indicator status-warning"></span>
                                <span class="status-text"><?php echo $stats['active_students'] + $stats['active_drivers']; ?> Online</span>
                            </div>
                            <div class="status-item">
                                <span class="status-label">System Load</span>
                                <span class="status-indicator status-online"></span>
                                <span class="status-text">Normal</span>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // Auto-refresh dashboard every 60 seconds
        setTimeout(function() {
            window.location.reload();
        }, 60000);

        // Real-time updates for today's trips
        function updateTripStatus() {
            fetch('api_booking_check.php?action=dashboard')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update trip statuses
                        data.trips.forEach(trip => {
                            const row = document.querySelector(`tr[data-trip-id="${trip.trip_id}"]`);
                            if (row) {
                                const statusCell = row.querySelector('.status-badge');
                                const bookedCell = row.querySelector('.capacity-info span');
                                const capacityBar = row.querySelector('.capacity-fill');
                                
                                if (statusCell && bookedCell && capacityBar) {
                                    statusCell.textContent = trip.status.charAt(0).toUpperCase() + trip.status.slice(1);
                                    statusCell.className = `status-badge status-${trip.status}`;
                                    
                                    bookedCell.textContent = `${trip.booked_count}/${BUS_CAPACITY}`;
                                    capacityBar.style.width = `${(trip.booked_count / BUS_CAPACITY) * 100}%`;
                                }
                            }
                        });
                    }
                })
                .catch(error => console.error('Error updating trip status:', error));
        }

        // Update every 30 seconds
        setInterval(updateTripStatus, 30000);
    </script>
</body>
</html>