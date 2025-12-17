<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('login.php');
}

// Date ranges for reports
$start_date = isset($_GET['start_date']) ? sanitize($_GET['start_date']) : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? sanitize($_GET['end_date']) : date('Y-m-d');
$report_type = isset($_GET['report_type']) ? sanitize($_GET['report_type']) : 'usage';

// Handle report generation
if (isset($_GET['generate_pdf'])) {
    // Redirect to PDF generation script
    header("Location: api_report_pdf.php?start_date=$start_date&end_date=$end_date&report_type=$report_type");
    exit;
}

if (isset($_GET['generate_excel'])) {
    // Redirect to Excel generation script
    header("Location: api_report_excel.php?start_date=$start_date&end_date=$end_date&report_type=$report_type");
    exit;
}

// Get report statistics - FIXED: Changed alias 'b' for buses to 'bu'
$stats_query = "SELECT 
                COUNT(DISTINCT b.booking_id) as total_bookings,
                COUNT(DISTINCT CASE WHEN b.status = 'confirmed' THEN b.booking_id END) as confirmed_bookings,
                COUNT(DISTINCT CASE WHEN b.status = 'used' THEN b.booking_id END) as used_bookings,
                COUNT(DISTINCT CASE WHEN b.status = 'cancelled' THEN b.booking_id END) as cancelled_bookings,
                COUNT(DISTINCT b.student_id) as unique_students,
                COUNT(DISTINCT t.trip_id) as total_trips,
                ROUND(AVG((t.booked_count / bu.capacity) * 100), 2) as avg_utilization
                FROM bookings b
                JOIN trips t ON b.trip_id = t.trip_id
                JOIN buses bu ON t.bus_id = bu.bus_id
                WHERE t.trip_date BETWEEN '$start_date' AND '$end_date'";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get daily booking trends
$daily_trends_query = "SELECT 
                       t.trip_date as date,
                       COUNT(b.booking_id) as bookings,
                       SUM(CASE WHEN b.status = 'used' THEN 1 ELSE 0 END) as attended,
                       SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                       FROM trips t
                       LEFT JOIN bookings b ON t.trip_id = b.trip_id
                       WHERE t.trip_date BETWEEN '$start_date' AND '$end_date'
                       GROUP BY t.trip_date
                       ORDER BY t.trip_date";
$daily_trends_result = mysqli_query($conn, $daily_trends_query);

// Get bus utilization
$bus_util_query = "SELECT 
                   bu.bus_number,
                   COUNT(t.trip_id) as total_trips,
                   SUM(t.booked_count) as total_passengers,
                   ROUND(AVG((t.booked_count / bu.capacity) * 100), 2) as avg_utilization,
                   MAX((t.booked_count / bu.capacity) * 100) as max_utilization
                   FROM trips t
                   JOIN buses bu ON t.bus_id = bu.bus_id
                   WHERE t.trip_date BETWEEN '$start_date' AND '$end_date'
                   GROUP BY bu.bus_id
                   ORDER BY avg_utilization DESC";
$bus_util_result = mysqli_query($conn, $bus_util_query);

// Get popular routes
$popular_routes_query = "SELECT 
                         t.route,
                         COUNT(DISTINCT t.trip_id) as total_trips,
                         SUM(t.booked_count) as total_passengers,
                         ROUND(AVG(t.booked_count), 2) as avg_passengers
                         FROM trips t
                         WHERE t.trip_date BETWEEN '$start_date' AND '$end_date'
                         GROUP BY t.route
                         ORDER BY total_passengers DESC";
$popular_routes_result = mysqli_query($conn, $popular_routes_query);

// Get top students
$top_students_query = "SELECT 
                       u.name, u.surname, u.email,
                       COUNT(b.booking_id) as total_bookings,
                       SUM(CASE WHEN b.status = 'used' THEN 1 ELSE 0 END) as trips_attended,
                       SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as trips_cancelled
                       FROM bookings b
                       JOIN users u ON b.student_id = u.user_id
                       JOIN trips t ON b.trip_id = t.trip_id
                       WHERE t.trip_date BETWEEN '$start_date' AND '$end_date'
                       GROUP BY b.student_id
                       ORDER BY total_bookings DESC
                       LIMIT 10";
$top_students_result = mysqli_query($conn, $top_students_query);

// Get cancellation reasons
$cancellation_reasons_query = "SELECT 
                               b.cancellation_reason,
                               COUNT(*) as count
                               FROM bookings b
                               JOIN trips t ON b.trip_id = t.trip_id
                               WHERE b.status = 'cancelled'
                               AND b.cancellation_reason IS NOT NULL
                               AND t.trip_date BETWEEN '$start_date' AND '$end_date'
                               GROUP BY b.cancellation_reason
                               ORDER BY count DESC
                               LIMIT 10";
$cancellation_reasons_result = mysqli_query($conn, $cancellation_reasons_query);

// Get attendance rate by time slot
$attendance_by_time_query = "SELECT 
                             HOUR(t.departure_time) as hour,
                             COUNT(b.booking_id) as total_bookings,
                             SUM(CASE WHEN b.status = 'used' THEN 1 ELSE 0 END) as attended,
                             ROUND((SUM(CASE WHEN b.status = 'used' THEN 1 ELSE 0 END) / COUNT(b.booking_id)) * 100, 2) as attendance_rate
                             FROM trips t
                             JOIN bookings b ON t.trip_id = b.trip_id
                             WHERE t.trip_date BETWEEN '$start_date' AND '$end_date'
                             AND b.status IN ('confirmed', 'used')
                             GROUP BY HOUR(t.departure_time)
                             ORDER BY hour";
$attendance_by_time_result = mysqli_query($conn, $attendance_by_time_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar sidebar-admin">
            <div class="sidebar-header">
                <i class="fas fa-bus"></i>
                <h3>MainRes Bus</h3>
            </div>
            <nav class="sidebar-nav">
                <a href="admin_dashboard.php">
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
                <a href="admin_reports.php" class="active">
                    <i class="fas fa-chart-bar"></i> Reports
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
                <h1>Reports & Analytics</h1>
                <div class="header-actions">
                    <span class="report-period">
                        <?php echo date('d M Y', strtotime($start_date)); ?> - <?php echo date('d M Y', strtotime($end_date)); ?>
                    </span>
                </div>
            </header>

            <!-- Report Filters -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-filter"></i> Report Filters</h2>
                </div>
                
                <form method="GET" action="" class="report-filters">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_date"><i class="fas fa-calendar"></i> Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date"><i class="fas fa-calendar"></i> End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="report_type"><i class="fas fa-chart-bar"></i> Report Type</label>
                            <select id="report_type" name="report_type">
                                <option value="usage" <?php echo ($report_type == 'usage') ? 'selected' : ''; ?>>Usage Statistics</option>
                                <option value="financial" <?php echo ($report_type == 'financial') ? 'selected' : ''; ?>>Financial Report</option>
                                <option value="attendance" <?php echo ($report_type == 'attendance') ? 'selected' : ''; ?>>Attendance Analysis</option>
                                <option value="operational" <?php echo ($report_type == 'operational') ? 'selected' : ''; ?>>Operational Report</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="admin_reports.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </section>

            <!-- Quick Stats -->
            <div class="quick-stats">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #3498db;">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_bookings'] ?? 0; ?></h3>
                        <p>Total Bookings</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #2ecc71;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['used_bookings'] ?? 0; ?></h3>
                        <p>Trips Attended</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #e74c3c;">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['cancelled_bookings'] ?? 0; ?></h3>
                        <p>Cancellations</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9b59b6;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['avg_utilization'] ?? 0; ?>%</h3>
                        <p>Avg Utilization</p>
                    </div>
                </div>
            </div>

            <!-- Export Options -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-file-export"></i> Export Reports</h2>
                </div>
                
                <div class="export-options">
                    <div class="export-card">
                        <i class="fas fa-file-pdf fa-2x"></i>
                        <h3>PDF Report</h3>
                        <p>Generate detailed PDF report</p>
                        <a href="?<?php echo http_build_query(['start_date' => $start_date, 'end_date' => $end_date, 'report_type' => $report_type, 'generate_pdf' => 1]); ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                    </div>
                    
                    <div class="export-card">
                        <i class="fas fa-file-excel fa-2x"></i>
                        <h3>Excel Report</h3>
                        <p>Export data to Excel format</p>
                        <a href="?<?php echo http_build_query(['start_date' => $start_date, 'end_date' => $end_date, 'report_type' => $report_type, 'generate_excel' => 1]); ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Download Excel
                        </a>
                    </div>
                    
                    <div class="export-card">
                        <i class="fas fa-chart-bar fa-2x"></i>
                        <h3>Custom Report</h3>
                        <p>Create custom report</p>
                        <button class="btn btn-primary" onclick="showCustomReportModal()">
                            <i class="fas fa-cog"></i> Configure
                        </button>
                    </div>
                </div>
            </section>

            <!-- Charts Section -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-chart-line"></i> Analytics Dashboard</h2>
                </div>
                
                <div class="charts-grid">
                    <!-- Booking Trends Chart -->
                    <div class="chart-container">
                        <h3>Daily Booking Trends</h3>
                        <canvas id="bookingTrendsChart"></canvas>
                    </div>
                    
                    <!-- Bus Utilization Chart -->
                    <div class="chart-container">
                        <h3>Bus Utilization</h3>
                        <canvas id="busUtilizationChart"></canvas>
                    </div>
                    
                    <!-- Attendance Rate by Time -->
                    <div class="chart-container">
                        <h3>Attendance by Time Slot</h3>
                        <canvas id="attendanceTimeChart"></canvas>
                    </div>
                    
                    <!-- Cancellation Reasons -->
                    <div class="chart-container">
                        <h3>Cancellation Reasons</h3>
                        <canvas id="cancellationChart"></canvas>
                    </div>
                </div>
            </section>

            <!-- Detailed Reports -->
            <div class="reports-grid">
                <!-- Left Column -->
                <div class="reports-column">
                    <!-- Popular Routes -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-route"></i> Popular Routes</h2>
                        </div>
                        
                        <div class="routes-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Route</th>
                                        <th>Trips</th>
                                        <th>Passengers</th>
                                        <th>Avg/Trip</th>
                                        <th>Utilization</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($route = mysqli_fetch_assoc($popular_routes_result)): 
                                        $utilization = min(($route['avg_passengers'] / BUS_CAPACITY) * 100, 100);
                                    ?>
                                        <tr>
                                            <td><?php echo $route['route']; ?></td>
                                            <td><?php echo $route['total_trips']; ?></td>
                                            <td><?php echo $route['total_passengers']; ?></td>
                                            <td><?php echo round($route['avg_passengers']); ?></td>
                                            <td>
                                                <div class="utilization-bar">
                                                    <div class="utilization-fill" style="width: <?php echo $utilization; ?>%">
                                                        <?php echo round($utilization); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Top Students -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-user-graduate"></i> Top Students</h2>
                        </div>
                        
                        <div class="students-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Bookings</th>
                                        <th>Attended</th>
                                        <th>Cancelled</th>
                                        <th>Attendance Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($student = mysqli_fetch_assoc($top_students_result)): 
                                        $attendance_rate = $student['total_bookings'] > 0 ? 
                                            round(($student['trips_attended'] / $student['total_bookings']) * 100) : 0;
                                    ?>
                                        <tr>
                                            <td><?php echo $student['name'] . ' ' . $student['surname']; ?></td>
                                            <td><?php echo $student['total_bookings']; ?></td>
                                            <td><?php echo $student['trips_attended']; ?></td>
                                            <td><?php echo $student['trips_cancelled']; ?></td>
                                            <td>
                                                <div class="attendance-bar">
                                                    <div class="attendance-fill" style="width: <?php echo $attendance_rate; ?>%">
                                                        <?php echo $attendance_rate; ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <!-- Right Column -->
                <div class="reports-column">
                    <!-- Bus Performance -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-bus"></i> Bus Performance</h2>
                        </div>
                        
                        <div class="bus-performance-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Bus</th>
                                        <th>Trips</th>
                                        <th>Passengers</th>
                                        <th>Avg Utilization</th>
                                        <th>Max Utilization</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($bus = mysqli_fetch_assoc($bus_util_result)): ?>
                                        <tr>
                                            <td><?php echo $bus['bus_number']; ?></td>
                                            <td><?php echo $bus['total_trips']; ?></td>
                                            <td><?php echo $bus['total_passengers']; ?></td>
                                            <td><?php echo $bus['avg_utilization']; ?>%</td>
                                            <td><?php echo $bus['max_utilization']; ?>%</td>
                                            <td>
                                                <?php if ($bus['avg_utilization'] >= 80): ?>
                                                    <span class="status-badge status-success">Excellent</span>
                                                <?php elseif ($bus['avg_utilization'] >= 60): ?>
                                                    <span class="status-badge status-warning">Good</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-error">Low</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Key Metrics -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-chart-pie"></i> Key Metrics</h2>
                        </div>
                        
                        <div class="key-metrics">
                            <div class="metric-item">
                                <div class="metric-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="metric-info">
                                    <h3><?php echo $stats['unique_students'] ?? 0; ?></h3>
                                    <p>Active Students</p>
                                </div>
                            </div>
                            
                            <div class="metric-item">
                                <div class="metric-icon">
                                    <i class="fas fa-percentage"></i>
                                </div>
                                <div class="metric-info">
                                    <h3>
                                        <?php 
                                        $confirmed = $stats['confirmed_bookings'] ?? 0;
                                        $used = $stats['used_bookings'] ?? 0;
                                        $attendance_rate = $confirmed > 0 ? round(($used / $confirmed) * 100) : 0;
                                        echo $attendance_rate;
                                        ?>%
                                    </h3>
                                    <p>Attendance Rate</p>
                                </div>
                            </div>
                            
                            <div class="metric-item">
                                <div class="metric-icon">
                                    <i class="fas fa-calendar-times"></i>
                                </div>
                                <div class="metric-info">
                                    <h3>
                                        <?php 
                                        $total = $stats['total_bookings'] ?? 0;
                                        $cancelled = $stats['cancelled_bookings'] ?? 0;
                                        $cancellation_rate = $total > 0 ? round(($cancelled / $total) * 100) : 0;
                                        echo $cancellation_rate;
                                        ?>%
                                    </h3>
                                    <p>Cancellation Rate</p>
                                </div>
                            </div>
                            
                            <div class="metric-item">
                                <div class="metric-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="metric-info">
                                    <h3><?php echo $stats['total_trips'] ?? 0; ?></h3>
                                    <p>Total Trips</p>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Report Modal -->
    <div id="customReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Custom Report Configuration</h3>
                <button class="modal-close" onclick="closeCustomReportModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="api_custom_report.php" target="_blank" id="customReportForm">
                    <div class="form-group">
                        <label for="report_name"><i class="fas fa-file-alt"></i> Report Name</label>
                        <input type="text" id="report_name" name="report_name" required 
                               placeholder="e.g., Monthly Performance Report">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="custom_start_date"><i class="fas fa-calendar"></i> Start Date</label>
                            <input type="date" id="custom_start_date" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="custom_end_date"><i class="fas fa-calendar"></i> End Date</label>
                            <input type="date" id="custom_end_date" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-filter"></i> Include Data</label>
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" name="include_bookings" checked> Booking Statistics
                            </label>
                            <label>
                                <input type="checkbox" name="include_attendance" checked> Attendance Data
                            </label>
                            <label>
                                <input type="checkbox" name="include_buses" checked> Bus Performance
                            </label>
                            <label>
                                <input type="checkbox" name="include_students" checked> Student Activity
                            </label>
                            <label>
                                <input type="checkbox" name="include_financial"> Financial Summary
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="output_format"><i class="fas fa-file-export"></i> Output Format</label>
                        <select id="output_format" name="output_format" required>
                            <option value="pdf">PDF Document</option>
                            <option value="excel">Excel Spreadsheet</option>
                            <option value="csv">CSV File</option>
                            <option value="html">HTML Report</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="email_report"><i class="fas fa-envelope"></i> Email Report</label>
                        <input type="email" id="email_report" name="email_report" 
                               placeholder="Enter email to receive report (optional)">
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeCustomReportModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Generate Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("#start_date", {
            dateFormat: "Y-m-d",
        });
        
        flatpickr("#end_date", {
            dateFormat: "Y-m-d",
        });
        
        flatpickr("#custom_start_date", {
            dateFormat: "Y-m-d",
        });
        
        flatpickr("#custom_end_date", {
            dateFormat: "Y-m-d",
        });

        // Show custom report modal
        function showCustomReportModal() {
            document.getElementById('customReportModal').style.display = 'block';
        }

        // Close custom report modal
        function closeCustomReportModal() {
            document.getElementById('customReportModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('customReportModal');
            if (event.target == modal) {
                closeCustomReportModal();
            }
        }

        // Prepare chart data
        const bookingTrendsData = {
            labels: [],
            datasets: [{
                label: 'Bookings',
                data: [],
                borderColor: '#3498db',
                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                fill: true
            }, {
                label: 'Attended',
                data: [],
                borderColor: '#2ecc71',
                backgroundColor: 'rgba(46, 204, 113, 0.1)',
                fill: true
            }]
        };

        const busUtilizationData = {
            labels: [],
            datasets: [{
                label: 'Average Utilization',
                data: [],
                backgroundColor: '#3498db'
            }]
        };

        const attendanceTimeData = {
            labels: [],
            datasets: [{
                label: 'Attendance Rate',
                data: [],
                borderColor: '#9b59b6',
                backgroundColor: 'rgba(155, 89, 182, 0.1)',
                fill: true
            }]
        };

        const cancellationData = {
            labels: [],
            datasets: [{
                label: 'Cancellations',
                data: [],
                backgroundColor: [
                    '#e74c3c', '#f39c12', '#3498db', '#2ecc71', 
                    '#9b59b6', '#1abc9c', '#d35400', '#c0392b'
                ]
            }]
        };

        // Initialize charts when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Fetch data from server or use inline data
            initializeCharts();
        });

        function initializeCharts() {
            // Booking Trends Chart
            const bookingTrendsCtx = document.getElementById('bookingTrendsChart').getContext('2d');
            new Chart(bookingTrendsCtx, {
                type: 'line',
                data: bookingTrendsData,
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: true,
                            text: 'Daily Booking Trends'
                        }
                    }
                }
            });

            // Bus Utilization Chart
            const busUtilizationCtx = document.getElementById('busUtilizationChart').getContext('2d');
            new Chart(busUtilizationCtx, {
                type: 'bar',
                data: busUtilizationData,
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Bus Utilization Rates'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Utilization %'
                            }
                        }
                    }
                }
            });

            // Attendance by Time Chart
            const attendanceTimeCtx = document.getElementById('attendanceTimeChart').getContext('2d');
            new Chart(attendanceTimeCtx, {
                type: 'line',
                data: attendanceTimeData,
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: true,
                            text: 'Attendance Rate by Hour'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Attendance %'
                            }
                        }
                    }
                }
            });

            // Cancellation Reasons Chart
            const cancellationCtx = document.getElementById('cancellationChart').getContext('2d');
            new Chart(cancellationCtx, {
                type: 'pie',
                data: cancellationData,
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'right',
                        },
                        title: {
                            display: true,
                            text: 'Cancellation Reasons'
                        }
                    }
                }
            });

            // Load actual data via AJAX
            loadChartData();
        }

        function loadChartData() {
            const params = new URLSearchParams({
                start_date: '<?php echo $start_date; ?>',
                end_date: '<?php echo $end_date; ?>'
            });

            fetch(`api_chart_data.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update booking trends
                        bookingTrendsData.labels = data.booking_trends.labels;
                        bookingTrendsData.datasets[0].data = data.booking_trends.bookings;
                        bookingTrendsData.datasets[1].data = data.booking_trends.attended;

                        // Update bus utilization
                        busUtilizationData.labels = data.bus_utilization.labels;
                        busUtilizationData.datasets[0].data = data.bus_utilization.utilization;

                        // Update attendance by time
                        attendanceTimeData.labels = data.attendance_time.labels;
                        attendanceTimeData.datasets[0].data = data.attendance_time.rates;

                        // Update cancellation reasons
                        cancellationData.labels = data.cancellation_reasons.labels;
                        cancellationData.datasets[0].data = data.cancellation_reasons.counts;

                        // Update charts
                        Chart.getChart('bookingTrendsChart').update();
                        Chart.getChart('busUtilizationChart').update();
                        Chart.getChart('attendanceTimeChart').update();
                        Chart.getChart('cancellationChart').update();
                    }
                })
                .catch(error => console.error('Error loading chart data:', error));
        }

        // Auto-refresh charts when filters change
        document.querySelector('.report-filters').addEventListener('submit', function() {
            setTimeout(loadChartData, 1000);
        });

        // Auto-refresh data every 5 minutes
        setInterval(loadChartData, 300000);
    </script>
</body>
</html>