<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is a driver
if (!isLoggedIn() || !isDriver()) {
    redirect('login.php');
}

$driver_id = $_SESSION['user_id'];
$filter = isset($_GET['filter']) ? sanitize($_GET['filter']) : 'upcoming';

// Get driver information - REMOVED: assigned_bus_id reference
$driver_query = "SELECT * FROM drivers WHERE driver_id = '$driver_id'";
$driver_result = mysqli_query($conn, $driver_query);
$driver_info = mysqli_fetch_assoc($driver_result) ?? [];

// Get trips based on filter
$query = "SELECT t.*, b.bus_number, b.capacity,
          (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count,
          (SELECT COUNT(*) FROM attendance a 
           JOIN bookings bk ON a.booking_id = bk.booking_id 
           WHERE bk.trip_id = t.trip_id AND a.result = 'success') as scanned_count
          FROM trips t
          JOIN buses b ON t.bus_id = b.bus_id
          WHERE t.driver_id = '$driver_id'";

$today = date('Y-m-d');
switch ($filter) {
    case 'today':
        $query .= " AND t.trip_date = '$today'";
        break;
    case 'upcoming':
        $query .= " AND t.trip_date >= '$today' AND t.status IN ('scheduled', 'boarding')";
        break;
    case 'past':
        $query .= " AND (t.trip_date < '$today' OR t.status IN ('departed', 'arrived', 'cancelled'))";
        break;
    case 'all':
    default:
        break;
}

$query .= " ORDER BY t.trip_date DESC, t.departure_time DESC";
$trips_result = mysqli_query($conn, $query);

// Get driver's schedule for the week
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));

$weekly_schedule_query = "SELECT t.*, b.bus_number, b.capacity,
                         DAYNAME(t.trip_date) as day_name,
                         (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count
                         FROM trips t
                         JOIN buses b ON t.bus_id = b.bus_id
                         WHERE t.driver_id = '$driver_id'
                         AND t.trip_date BETWEEN '$week_start' AND '$week_end'
                         ORDER BY t.trip_date, t.departure_time";
$weekly_schedule_result = mysqli_query($conn, $weekly_schedule_query);

// Organize schedule by day
$weekly_schedule = [];
while ($row = mysqli_fetch_assoc($weekly_schedule_result)) {
    $day = $row['day_name'];
    if (!isset($weekly_schedule[$day])) {
        $weekly_schedule[$day] = [];
    }
    $weekly_schedule[$day][] = $row;
}

// Get driver statistics
$stats_query = "SELECT 
                COUNT(*) as total_trips,
                SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
                SUM((SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed')) as total_passengers,
                AVG((SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed')) as avg_passengers
                FROM trips t
                WHERE t.driver_id = '$driver_id'
                AND t.trip_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result) ?? [];

// Also fix the round() function issue
$avg_passengers = $stats['avg_passengers'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Trips - MainRes Bus System</title>
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
            <nav class="sidebar-nav">
                <a href="driver_dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="driver_scan.php">
                    <i class="fas fa-qrcode"></i> Scan QR Codes
                </a>
                <a href="driver_trips.php" class="active">
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
                <h1>My Trips</h1>
                <div class="header-actions">
                    <span class="current-week">
                        Week of <?php echo date('M j', strtotime($week_start)); ?> - <?php echo date('M j, Y', strtotime($week_end)); ?>
                    </span>
                    <span class="driver-info">
                        <?php echo htmlspecialchars($driver_info['license_number'] ?? 'Driver'); ?>
                    </span>
                </div>
            </header>

            <!-- Driver Stats -->
            <div class="quick-stats">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #3498db;">
                        <i class="fas fa-route"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_trips'] ?? 0; ?></h3>
                        <p>Total Trips (30 days)</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #2ecc71;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['completed_trips'] ?? 0; ?></h3>
                        <p>Completed Trips</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9b59b6;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_passengers'] ?? 0; ?></h3>
                        <p>Total Passengers</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #e74c3c;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo round($avg_passengers); ?></h3>
                        <p>Avg. Passengers/Trip</p>
                    </div>
                </div>
            </div>

            <!-- Weekly Schedule -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-calendar-week"></i> Weekly Schedule</h2>
                    <div class="week-navigation">
                        <a href="?filter=<?php echo $filter; ?>&week=prev" class="btn btn-secondary">
                            <i class="fas fa-chevron-left"></i> Previous Week
                        </a>
                        <span>Current Week</span>
                        <a href="?filter=<?php echo $filter; ?>&week=next" class="btn btn-secondary">
                            Next Week <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
                
                <div class="weekly-schedule">
                    <?php
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    foreach ($days as $day):
                        $day_trips = isset($weekly_schedule[$day]) ? $weekly_schedule[$day] : [];
                    ?>
                        <div class="schedule-day <?php echo (date('l') == $day) ? 'today' : ''; ?>">
                            <div class="day-header">
                                <h3><?php echo $day; ?></h3>
                                <span class="date"><?php echo date('j M', strtotime($week_start . ' +' . (array_search($day, $days)) . ' days')); ?></span>
                            </div>
                            
                            <div class="day-trips">
                                <?php if (count($day_trips) > 0): ?>
                                    <?php foreach ($day_trips as $trip): ?>
                                        <div class="trip-schedule-item">
                                            <div class="trip-time">
                                                <?php echo date('H:i', strtotime($trip['departure_time'])); ?>
                                            </div>
                                            <div class="trip-details">
                                                <p class="trip-route"><?php echo $trip['route']; ?></p>
                                                <p class="trip-bus">Bus <?php echo $trip['bus_number']; ?></p>
                                                <p class="trip-passengers"><?php echo $trip['booked_count']; ?> passengers</p>
                                            </div>
                                            <div class="trip-status">
                                                <span class="status-badge status-<?php echo $trip['status']; ?>">
                                                    <?php echo ucfirst($trip['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-trips">
                                        <i class="fas fa-coffee"></i>
                                        <p>No trips scheduled</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Trip List -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-list"></i> Trip History</h2>
                    
                    <!-- Filter Tabs -->
                    <div class="filter-tabs">
                        <a href="?filter=today" class="<?php echo ($filter == 'today') ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-day"></i> Today
                        </a>
                        <a href="?filter=upcoming" class="<?php echo ($filter == 'upcoming') ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i> Upcoming
                        </a>
                        <a href="?filter=past" class="<?php echo ($filter == 'past') ? 'active' : ''; ?>">
                            <i class="fas fa-history"></i> Past Trips
                        </a>
                        <a href="?filter=all" class="<?php echo ($filter == 'all') ? 'active' : ''; ?>">
                            <i class="fas fa-list"></i> All Trips
                        </a>
                    </div>
                </div>
                
                <?php if (mysqli_num_rows($trips_result) > 0): ?>
                    <div class="trips-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Route</th>
                                    <th>Bus</th>
                                    <th>Passengers</th>
                                    <th>Scanned</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($trip = mysqli_fetch_assoc($trips_result)): 
                                    $attendance_rate = $trip['booked_count'] > 0 ? 
                                        round(($trip['scanned_count'] / $trip['booked_count']) * 100) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <?php echo date('d M Y', strtotime($trip['trip_date'])); ?><br>
                                            <small><?php echo date('D', strtotime($trip['trip_date'])); ?></small>
                                        </td>
                                        <td><?php echo date('H:i', strtotime($trip['departure_time'])); ?></td>
                                        <td><?php echo $trip['route']; ?></td>
                                        <td><?php echo $trip['bus_number']; ?></td>
                                        <td>
                                            <div class="passenger-info">
                                                <span><?php echo $trip['booked_count']; ?> booked</span>
                                                <div class="mini-capacity-bar">
                                                    <div class="mini-fill" style="width: <?php echo min(($trip['booked_count'] / ($trip['capacity'] ?? BUS_CAPACITY)) * 100, 100); ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="attendance-info">
                                                <span><?php echo $trip['scanned_count']; ?>/<?php echo $trip['booked_count']; ?></span>
                                                <div class="attendance-bar">
                                                    <div class="attendance-fill" style="width: <?php echo $attendance_rate; ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $trip['status']; ?>">
                                                <?php echo ucfirst($trip['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($trip['status'] == 'scheduled' || $trip['status'] == 'boarding'): ?>
                                                <a href="driver_scan.php?trip_id=<?php echo $trip['trip_id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-qrcode"></i> Scan
                                                </a>
                                            <?php elseif ($trip['status'] == 'departed' || $trip['status'] == 'arrived'): ?>
                                                <a href="driver_reports.php?trip_id=<?php echo $trip['trip_id']; ?>" class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-file-alt"></i> Report
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <?php if ($filter == 'today'): ?>
                            <i class="fas fa-calendar-times fa-3x"></i>
                            <h3>No trips scheduled for today</h3>
                            <p>You don't have any trips assigned for today.</p>
                        <?php elseif ($filter == 'upcoming'): ?>
                            <i class="fas fa-calendar-plus fa-3x"></i>
                            <h3>No upcoming trips</h3>
                            <p>You don't have any upcoming trips scheduled.</p>
                        <?php elseif ($filter == 'past'): ?>
                            <i class="fas fa-history fa-3x"></i>
                            <h3>No past trips</h3>
                            <p>You haven't completed any trips yet.</p>
                        <?php else: ?>
                            <i class="fas fa-route fa-3x"></i>
                            <h3>No trips found</h3>
                            <p>You don't have any trips in the system.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Export Section -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-file-export"></i> Export Data</h2>
                </div>
                
                <div class="export-options">
                    <div class="export-card">
                        <i class="fas fa-calendar-alt fa-2x"></i>
                        <h3>Weekly Schedule</h3>
                        <p>Export your schedule for the week</p>
                        <button class="btn btn-primary" onclick="exportWeeklySchedule()">
                            <i class="fas fa-download"></i> Export PDF
                        </button>
                    </div>
                    
                    <div class="export-card">
                        <i class="fas fa-chart-bar fa-2x"></i>
                        <h3>Performance Report</h3>
                        <p>Download your monthly performance report</p>
                        <button class="btn btn-primary" onclick="exportPerformanceReport()">
                            <i class="fas fa-download"></i> Export Excel
                        </button>
                    </div>
                    
                    <div class="export-card">
                        <i class="fas fa-users fa-2x"></i>
                        <h3>Passenger Lists</h3>
                        <p>Download passenger lists for your trips</p>
                        <button class="btn btn-primary" onclick="exportPassengerLists()">
                            <i class="fas fa-download"></i> Export CSV
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // Export functions
        function exportWeeklySchedule() {
            const data = {
                week: '<?php echo $week_start . " to " . $week_end; ?>',
                schedule: <?php echo json_encode($weekly_schedule); ?>,
                driver: '<?php echo $_SESSION["name"]; ?>'
            };
            
            // Create PDF download
            alert('Weekly schedule export feature coming soon!');
            console.log('Exporting schedule:', data);
        }

        function exportPerformanceReport() {
            const data = {
                stats: <?php echo json_encode($stats); ?>,
                period: 'Last 30 days',
                driver: '<?php echo $_SESSION["name"]; ?>',
                export_date: new Date().toISOString()
            };
            
            // Create Excel download
            const csv = convertToCSV([data.stats]);
            downloadCSV(csv, 'driver_performance_' + new Date().toISOString().split('T')[0] + '.csv');
        }

        function exportPassengerLists() {
            // Get all trips data
            const trips = <?php 
                // Reset pointer to beginning
                mysqli_data_seek($trips_result, 0);
                $all_trips = [];
                while ($trip = mysqli_fetch_assoc($trips_result)) {
                    $all_trips[] = $trip;
                }
                echo json_encode($all_trips); 
            ?>;
            
            // Create passenger list CSV
            let csv = 'Date,Time,Route,Bus,Capacity,Booked Count,Scanned Count,Status\n';
            
            trips.forEach(trip => {
                csv += `"${trip.trip_date}","${trip.departure_time}","${trip.route}","${trip.bus_number}",${trip.capacity},${trip.booked_count},${trip.scanned_count},"${trip.status}"\n`;
            });
            
            downloadCSV(csv, 'passenger_lists_' + new Date().toISOString().split('T')[0] + '.csv');
        }

        function convertToCSV(objArray) {
            const array = typeof objArray != 'object' ? JSON.parse(objArray) : objArray;
            let str = '';
            let row = '';

            for (let index in objArray[0]) {
                row += index + ',';
            }
            row = row.slice(0, -1);
            str += row + '\r\n';

            for (let i = 0; i < array.length; i++) {
                let line = '';
                for (let index in array[i]) {
                    if (line != '') line += ',';
                    line += array[i][index];
                }
                str += line + '\r\n';
            }
            return str;
        }

        function downloadCSV(csv, filename) {
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // Auto-refresh upcoming trips
        <?php if ($filter == 'today' || $filter == 'upcoming'): ?>
            setInterval(() => {
                window.location.reload();
            }, 60000); // Refresh every minute
        <?php endif; ?>

        // Color code rows based on time
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('tbody tr');
            const now = new Date();
            
            rows.forEach(row => {
                const dateCell = row.cells[0].textContent.trim();
                const timeCell = row.cells[1].textContent.trim();
                
                // Parse date and time
                const [day, month, year] = dateCell.split(' ');
                const tripDate = new Date(`${month} ${day}, ${year} ${timeCell}`);
                
                // Color code based on time difference
                const timeDiff = (tripDate - now) / (1000 * 60); // in minutes
                
                if (timeDiff <= 30 && timeDiff > 0) {
                    row.classList.add('trip-soon');
                } else if (timeDiff <= 0 && timeDiff > -60) {
                    row.classList.add('trip-active');
                }
            });
        });
    </script>
</body>
</html>