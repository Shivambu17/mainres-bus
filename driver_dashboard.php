<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is a driver
if (!isLoggedIn() || !isDriver()) {
    redirect('login.php');
}

$driver_id = $_SESSION['user_id'];
$today = date('Y-m-d');

// Get driver info
$driver_query = "SELECT u.*, d.license_number, d.employment_date 
                 FROM users u
                 JOIN drivers d ON u.user_id = d.driver_id
                 WHERE u.user_id = '$driver_id'";
$driver_result = mysqli_query($conn, $driver_query);
$driver = mysqli_fetch_assoc($driver_result);

// Get today's assigned trips
$today_trips_query = "SELECT t.*, b.bus_number, b.capacity,
                      (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count
                      FROM trips t
                      JOIN buses b ON t.bus_id = b.bus_id
                      WHERE t.driver_id = '$driver_id'
                      AND t.trip_date = '$today'
                      AND t.status IN ('scheduled', 'boarding')
                      ORDER BY t.departure_time";
$today_trips_result = mysqli_query($conn, $today_trips_query);

// Get upcoming trips (next 7 days)
$upcoming_trips_query = "SELECT t.*, b.bus_number,
                        (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count
                        FROM trips t
                        JOIN buses b ON t.bus_id = b.bus_id
                        WHERE t.driver_id = '$driver_id'
                        AND t.trip_date > '$today'
                        AND t.status = 'scheduled'
                        ORDER BY t.trip_date, t.departure_time
                        LIMIT 5";
$upcoming_trips_result = mysqli_query($conn, $upcoming_trips_query);

// Get recent attendance scans
$recent_scans_query = "SELECT a.*, u.name as student_name, u.surname as student_surname, t.route
                      FROM attendance a
                      JOIN bookings b ON a.booking_id = b.booking_id
                      JOIN users u ON b.student_id = u.user_id
                      JOIN trips t ON b.trip_id = t.trip_id
                      WHERE a.scanned_by = '$driver_id'
                      ORDER BY a.scanned_time DESC
                      LIMIT 10";
$recent_scans_result = mysqli_query($conn, $recent_scans_query);

// Get today's attendance summary
$today_summary_query = "SELECT 
                        COUNT(*) as total_scans,
                        SUM(CASE WHEN a.result = 'success' THEN 1 ELSE 0 END) as successful,
                        SUM(CASE WHEN a.result = 'invalid' THEN 1 ELSE 0 END) as invalid,
                        SUM(CASE WHEN a.result = 'duplicate' THEN 1 ELSE 0 END) as duplicate
                        FROM attendance a
                        WHERE DATE(a.scanned_time) = '$today'
                        AND a.scanned_by = '$driver_id'";
$today_summary_result = mysqli_query($conn, $today_summary_query);
$today_summary = mysqli_fetch_assoc($today_summary_result);

// Get driver statistics
$driver_stats_query = "SELECT 
                       COUNT(DISTINCT t.trip_id) as total_trips,
                       COUNT(DISTINCT DATE(t.trip_date)) as days_worked,
                       SUM((SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed')) as total_passengers
                       FROM trips t
                       WHERE t.driver_id = '$driver_id'
                       AND t.trip_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$driver_stats_result = mysqli_query($conn, $driver_stats_query);
$driver_stats = mysqli_fetch_assoc($driver_stats_result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar sidebar-driver">
            <div class="sidebar-header">
                <i class="fas fa-bus"></i>
                <h3>MainRes Bus</h3>
            </div>
            <div class="user-profile">
                <div class="avatar">
                    <i class="fas fa-user-tie"></i>
                </div>
                <h4><?php echo $_SESSION['name']; ?></h4>
                <p>Bus Driver</p>
                <p class="user-info">
                    <i class="fas fa-id-card"></i> <?php echo $driver['license_number']; ?><br>
                    <i class="fas fa-calendar"></i> <?php echo date('Y', strtotime($driver['employment_date'])); ?> - Present
                </p>
            </div>
            <nav class="sidebar-nav">
                <a href="driver_dashboard.php" class="active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="driver_scan.php">
                    <i class="fas fa-qrcode"></i> Scan QR Codes
                </a>
                <a href="driver_trips.php">
                    <i class="fas fa-route"></i> My Trips
                </a>
                <a href="driver_reports.php">
                    <i class="fas fa-exclamation-circle"></i> Report Issues
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
                <h1>Driver Dashboard</h1>
                <div class="header-actions">
                    <span class="welcome">Welcome, <?php echo $_SESSION['name']; ?>!</span>
                    <span class="current-time"><?php echo date('l, F j, Y'); ?></span>
                </div>
            </header>

            <!-- Quick Stats -->
            <div class="quick-stats">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #3498db;">
                        <i class="fas fa-route"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $driver_stats['total_trips']; ?></h3>
                        <p>Trips (30 days)</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #2ecc71;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $driver_stats['total_passengers']; ?></h3>
                        <p>Passengers (30 days)</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9b59b6;">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $driver_stats['days_worked']; ?></h3>
                        <p>Days Worked (30 days)</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #e74c3c;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $today_summary['successful']; ?></h3>
                        <p>Today's Scans</p>
                    </div>
                </div>
            </div>

            <!-- Today's Trips -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-calendar-day"></i> Today's Assigned Trips</h2>
                    <a href="driver_trips.php" class="btn btn-secondary">View All Trips</a>
                </div>
                
                <?php if (mysqli_num_rows($today_trips_result) > 0): ?>
                    <div class="trips-grid">
                        <?php while ($trip = mysqli_fetch_assoc($today_trips_result)): 
                            $available_seats = $trip['capacity'] - $trip['booked_count'];
                            $departure_time = strtotime($trip['departure_time']);
                            $current_time = time();
                            $time_diff = ($departure_time - $current_time) / 60; // in minutes
                            
                            // Determine trip status
                            if ($trip['status'] == 'boarding') {
                                $status_class = 'status-boarding';
                                $status_text = 'Boarding Now';
                            } elseif ($time_diff <= 30 && $time_diff > 0) {
                                $status_class = 'status-upcoming';
                                $status_text = 'Starting Soon';
                            } elseif ($time_diff <= 0) {
                                $status_class = 'status-departed';
                                $status_text = 'Departed';
                            } else {
                                $status_class = 'status-scheduled';
                                $status_text = 'Scheduled';
                            }
                        ?>
                            <div class="trip-card">
                                <div class="trip-header">
                                    <div>
                                        <h3><?php echo date('h:i A', $departure_time); ?></h3>
                                        <p class="trip-route"><?php echo $trip['route']; ?></p>
                                    </div>
                                    <span class="trip-status <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </div>
                                <div class="trip-body">
                                    <p><i class="fas fa-bus"></i> Bus <?php echo $trip['bus_number']; ?></p>
                                    <p><i class="fas fa-chair"></i> <?php echo $trip['booked_count']; ?>/<?php echo $trip['capacity']; ?> booked</p>
                                    <p><i class="fas fa-clock"></i> 
                                        <?php 
                                        if ($time_diff > 0) {
                                            echo "Departure in " . round($time_diff) . " minutes";
                                        } else {
                                            echo "Departure time passed";
                                        }
                                        ?>
                                    </p>
                                    
                                    <div class="capacity-bar">
                                        <div class="capacity-fill" style="width: <?php echo ($trip['booked_count'] / $trip['capacity']) * 100; ?>%"></div>
                                    </div>
                                </div>
                                <div class="trip-footer">
                                    <?php if ($trip['status'] == 'boarding' || ($time_diff <= 30 && $time_diff > -30)): ?>
                                        <a href="driver_scan.php?trip_id=<?php echo $trip['trip_id']; ?>" class="btn btn-primary btn-block">
                                            <i class="fas fa-qrcode"></i> Start Scanning
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-block" disabled>
                                            <i class="fas fa-clock"></i> Not Yet Available
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times fa-3x"></i>
                        <h3>No trips assigned for today</h3>
                        <p>Check back later or contact your supervisor.</p>
                    </div>
                <?php endif; ?>
            </section>

            <div class="dashboard-grid">
                <!-- Left Column -->
                <div class="grid-column">
                    <!-- Upcoming Trips -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-calendar-alt"></i> Upcoming Trips (Next 7 Days)</h2>
                        </div>
                        
                        <?php if (mysqli_num_rows($upcoming_trips_result) > 0): ?>
                            <div class="trips-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Route</th>
                                            <th>Bus</th>
                                            <th>Booked</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($trip = mysqli_fetch_assoc($upcoming_trips_result)): ?>
                                            <tr>
                                                <td><?php echo date('d M', strtotime($trip['trip_date'])); ?></td>
                                                <td><?php echo date('h:i A', strtotime($trip['departure_time'])); ?></td>
                                                <td><?php echo $trip['route']; ?></td>
                                                <td><?php echo $trip['bus_number']; ?></td>
                                                <td><?php echo $trip['booked_count']; ?></td>
                                                <td>
                                                    <span class="status-badge status-scheduled">
                                                        Scheduled
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-plus fa-2x"></i>
                                <p>No upcoming trips scheduled</p>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- Today's Scan Summary -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-chart-pie"></i> Today's Scan Summary</h2>
                        </div>
                        
                        <div class="scan-summary">
                            <div class="summary-item">
                                <div class="summary-icon" style="background-color: #2ecc71;">
                                    <i class="fas fa-check"></i>
                                </div>
                                <div class="summary-info">
                                    <h3><?php echo $today_summary['successful']; ?></h3>
                                    <p>Successful Scans</p>
                                </div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-icon" style="background-color: #e74c3c;">
                                    <i class="fas fa-times"></i>
                                </div>
                                <div class="summary-info">
                                    <h3><?php echo $today_summary['invalid']; ?></h3>
                                    <p>Invalid Scans</p>
                                </div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-icon" style="background-color: #f39c12;">
                                    <i class="fas fa-clone"></i>
                                </div>
                                <div class="summary-info">
                                    <h3><?php echo $today_summary['duplicate']; ?></h3>
                                    <p>Duplicate Scans</p>
                                </div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-icon" style="background-color: #3498db;">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <div class="summary-info">
                                    <h3><?php echo $today_summary['total_scans']; ?></h3>
                                    <p>Total Scans</p>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Right Column -->
                <div class="grid-column">
                    <!-- Recent Scans -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-history"></i> Recent Scans</h2>
                            <a href="driver_trips.php" class="btn btn-secondary">View All</a>
                        </div>
                        
                        <?php if (mysqli_num_rows($recent_scans_result) > 0): ?>
                            <div class="scans-list">
                                <?php while ($scan = mysqli_fetch_assoc($recent_scans_result)): 
                                    $result_class = 'scan-' . $scan['result'];
                                    $result_icon = $scan['result'] == 'success' ? 'check-circle' : 
                                                  ($scan['result'] == 'duplicate' ? 'clone' : 'times-circle');
                                ?>
                                    <div class="scan-item <?php echo $result_class; ?>">
                                        <div class="scan-icon">
                                            <i class="fas fa-<?php echo $result_icon; ?>"></i>
                                        </div>
                                        <div class="scan-info">
                                            <p class="scan-student"><?php echo $scan['student_name'] . ' ' . $scan['student_surname']; ?></p>
                                            <p class="scan-details"><?php echo $scan['route']; ?></p>
                                            <p class="scan-time"><?php echo date('H:i', strtotime($scan['scanned_time'])); ?></p>
                                        </div>
                                        <div class="scan-result">
                                            <span class="result-badge"><?php echo ucfirst($scan['result']); ?></span>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-qrcode fa-2x"></i>
                                <p>No scans recorded today</p>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- Quick Actions -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                        </div>
                        
                        <div class="quick-actions">
                            <a href="driver_scan.php" class="action-btn">
                                <i class="fas fa-qrcode"></i>
                                <span>Start Scanning</span>
                            </a>
                            <a href="driver_reports.php" class="action-btn">
                                <i class="fas fa-exclamation-circle"></i>
                                <span>Report Issue</span>
                            </a>
                            <a href="driver_trips.php" class="action-btn">
                                <i class="fas fa-calendar-alt"></i>
                                <span>View Schedule</span>
                            </a>
                            <button class="action-btn" onclick="startTrip()">
                                <i class="fas fa-play"></i>
                                <span>Start Next Trip</span>
                            </button>
                        </div>
                    </section>

                    <!-- Driver Information -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-id-card"></i> Driver Information</h2>
                        </div>
                        
                        <div class="driver-info">
                            <div class="info-item">
                                <span class="info-label">License Number</span>
                                <span class="info-value"><?php echo $driver['license_number']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Employment Date</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($driver['employment_date'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Years of Service</span>
                                <span class="info-value"><?php echo date('Y') - date('Y', strtotime($driver['employment_date'])); ?> years</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Account Status</span>
                                <span class="info-value status-badge status-<?php echo $driver['status']; ?>">
                                    <?php echo ucfirst($driver['status']); ?>
                                </span>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // Start trip function
        function startTrip() {
            // Find the next available trip
            const tripCards = document.querySelectorAll('.trip-card');
            let nextTrip = null;
            
            tripCards.forEach(card => {
                const statusElement = card.querySelector('.trip-status');
                if (statusElement && statusElement.textContent.includes('Starting Soon')) {
                    nextTrip = card;
                }
            });
            
            if (nextTrip) {
                const scanLink = nextTrip.querySelector('a.btn-primary');
                if (scanLink) {
                    window.location.href = scanLink.href;
                } else {
                    alert('Trip not yet available for scanning');
                }
            } else {
                alert('No trips starting soon. Please check your schedule.');
            }
        }

        // Auto-refresh dashboard every 30 seconds
        setInterval(function() {
            // Refresh only if on dashboard
            if (window.location.pathname.includes('driver_dashboard.php')) {
                window.location.reload();
            }
        }, 30000);

        // Update trip statuses in real-time
        function updateTripStatuses() {
            const tripCards = document.querySelectorAll('.trip-card');
            const now = new Date();
            
            tripCards.forEach(card => {
                const timeText = card.querySelector('.trip-body p:nth-child(3)');
                const statusElement = card.querySelector('.trip-status');
                const actionButton = card.querySelector('.trip-footer button, .trip-footer a');
                
                if (timeText && statusElement && actionButton) {
                    const timeMatch = timeText.textContent.match(/Departure in (\d+) minutes/);
                    
                    if (timeMatch) {
                        const minutesLeft = parseInt(timeMatch[1]);
                        
                        if (minutesLeft <= 30 && minutesLeft > 0) {
                            statusElement.textContent = 'Starting Soon';
                            statusElement.className = 'trip-status status-upcoming';
                            
                            // Enable scanning button if not already enabled
                            if (actionButton.disabled) {
                                actionButton.disabled = false;
                                actionButton.className = 'btn btn-primary btn-block';
                                actionButton.innerHTML = '<i class="fas fa-qrcode"></i> Start Scanning';
                            }
                        } else if (minutesLeft <= 0) {
                            statusElement.textContent = 'Departed';
                            statusElement.className = 'trip-status status-departed';
                            actionButton.disabled = true;
                        }
                    }
                }
            });
        }

        // Update every minute
        setInterval(updateTripStatuses, 60000);
    </script>
</body>
</html>