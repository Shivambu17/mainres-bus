<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is a driver
if (!isLoggedIn() || !isDriver()) {
    redirect('login.php');
}

$driver_id = $_SESSION['user_id'];
$trip_id = isset($_GET['trip_id']) ? sanitize($_GET['trip_id']) : '';
$error = '';
$success = '';

// Get trip information if specified
$current_trip = null;
if ($trip_id) {
    $trip_query = "SELECT t.*, b.bus_number, 
                   (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count
                   FROM trips t
                   JOIN buses b ON t.bus_id = b.bus_id
                   WHERE t.trip_id = '$trip_id'
                   AND t.driver_id = '$driver_id'";
    $trip_result = mysqli_query($conn, $trip_query);
    
    if (mysqli_num_rows($trip_result) == 1) {
        $current_trip = mysqli_fetch_assoc($trip_query);
    }
}

// Get driver's active trips for today
$active_trips_query = "SELECT t.*, b.bus_number
                      FROM trips t
                      JOIN buses b ON t.bus_id = b.bus_id
                      WHERE t.driver_id = '$driver_id'
                      AND t.trip_date = CURDATE()
                      AND t.status IN ('scheduled', 'boarding')
                      AND t.departure_time BETWEEN DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                      ORDER BY t.departure_time
                      LIMIT 1";
$active_trips_result = mysqli_query($conn, $active_trips_query);

// If no trip specified but driver has active trip, use it
if (!$trip_id && mysqli_num_rows($active_trips_result) > 0) {
    $current_trip = mysqli_fetch_assoc($active_trips_result);
    $trip_id = $current_trip['trip_id'];
}

// Get scanned students for current trip
$scanned_students = [];
if ($trip_id) {
    $scanned_query = "SELECT a.*, u.name, u.surname, b.booking_id
                      FROM attendance a
                      JOIN bookings b ON a.booking_id = b.booking_id
                      JOIN users u ON b.student_id = u.user_id
                      WHERE b.trip_id = '$trip_id'
                      AND a.scanned_by = '$driver_id'
                      ORDER BY a.scanned_time DESC";
    $scanned_result = mysqli_query($conn, $scanned_query);
    
    while ($row = mysqli_fetch_assoc($scanned_result)) {
        $scanned_students[] = $row;
    }
}

// Get expected students for current trip
$expected_students = [];
if ($trip_id) {
    $expected_query = "SELECT b.*, u.name, u.surname, u.email
                       FROM bookings b
                       JOIN users u ON b.student_id = u.user_id
                       WHERE b.trip_id = '$trip_id'
                       AND b.status = 'confirmed'
                       ORDER BY u.surname, u.name";
    $expected_result = mysqli_query($conn, $expected_query);
    
    while ($row = mysqli_fetch_assoc($expected_result)) {
        $expected_students[] = $row;
    }
}

// Handle manual attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['manual_attendance'])) {
        $booking_id = sanitize($_POST['booking_id']);
        $action = sanitize($_POST['action']);
        
        // Get booking details
        $booking_query = "SELECT b.*, u.name, u.surname, t.route, t.trip_date
                         FROM bookings b
                         JOIN users u ON b.student_id = u.user_id
                         JOIN trips t ON b.trip_id = t.trip_id
                         WHERE b.booking_id = '$booking_id'";
        $booking_result = mysqli_query($conn, $booking_query);
        
        if (mysqli_num_rows($booking_result) == 1) {
            $booking = mysqli_fetch_assoc($booking_result);
            
            if ($action == 'mark_present') {
                // Check if already scanned
                $check_scan = "SELECT * FROM attendance WHERE booking_id = '$booking_id'";
                if (mysqli_num_rows(mysqli_query($conn, $check_scan)) == 0) {
                    // Record attendance
                    $insert_attendance = "INSERT INTO attendance (booking_id, scanned_by, scan_method, result) 
                                          VALUES ('$booking_id', '$driver_id', 'manual', 'success')";
                    
                    if (mysqli_query($conn, $insert_attendance)) {
                        // Update booking status
                        mysqli_query($conn, "UPDATE bookings SET status = 'used', attended_at = NOW() WHERE booking_id = '$booking_id'");
                        
                        $success = "Marked " . $booking['name'] . " " . $booking['surname'] . " as present";
                    } else {
                        $error = "Error recording attendance";
                    }
                } else {
                    $error = "Student already scanned";
                }
            } elseif ($action == 'mark_absent') {
                // Mark as no-show
                $update_booking = "UPDATE bookings SET status = 'no_show' WHERE booking_id = '$booking_id'";
                
                if (mysqli_query($conn, $update_booking)) {
                    $success = "Marked " . $booking['name'] . " " . $booking['surname'] . " as absent";
                } else {
                    $error = "Error updating attendance";
                }
            }
        } else {
            $error = "Booking not found";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan QR Codes - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
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
                <a href="driver_scan.php" class="active">
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
                <h1>QR Code Scanner</h1>
                <div class="header-actions">
                    <a href="driver_dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </header>

            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <!-- Current Trip Info -->
            <?php if ($current_trip): ?>
                <section class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-bus"></i> Current Trip</h2>
                        <div class="trip-info-badge">
                            <span class="status-badge status-<?php echo $current_trip['status']; ?>">
                                <?php echo ucfirst($current_trip['status']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="trip-info-card">
                        <div class="trip-info-grid">
                            <div class="info-item">
                                <span class="info-label">Route</span>
                                <span class="info-value"><?php echo $current_trip['route']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Bus</span>
                                <span class="info-value"><?php echo $current_trip['bus_number']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Departure Time</span>
                                <span class="info-value"><?php echo date('h:i A', strtotime($current_trip['departure_time'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Expected Passengers</span>
                                <span class="info-value"><?php echo $current_trip['booked_count']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Scanned</span>
                                <span class="info-value"><?php echo count($scanned_students); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Remaining</span>
                                <span class="info-value"><?php echo $current_trip['booked_count'] - count($scanned_students); ?></span>
                            </div>
                        </div>
                        
                        <!-- Progress Bar -->
                        <div class="attendance-progress">
                            <div class="progress-bar">
                                <?php 
                                $scanned_count = count($scanned_students);
                                $booked_count = $current_trip['booked_count'];
                                $progress_percent = $booked_count > 0 ? ($scanned_count / $booked_count) * 100 : 0;
                                ?>
                                <div class="progress-fill" style="width: <?php echo $progress_percent; ?>%"></div>
                            </div>
                            <div class="progress-stats">
                                <span><?php echo $scanned_count; ?> / <?php echo $booked_count; ?> scanned</span>
                                <span><?php echo round($progress_percent); ?>% complete</span>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <div class="scan-grid">
                <!-- Left Column - QR Scanner -->
                <div class="scan-column">
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-camera"></i> QR Code Scanner</h2>
                            <div class="scanner-controls">
                                <button id="start-scanner" class="btn btn-primary">
                                    <i class="fas fa-play"></i> Start Camera
                                </button>
                                <button id="stop-scanner" class="btn btn-secondary">
                                    <i class="fas fa-stop"></i> Stop Camera
                                </button>
                            </div>
                        </div>
                        
                        <!-- Scanner Container -->
                        <div id="qr-scanner-container" class="qr-scanner-container">
                            <div class="scanner-placeholder">
                                <i class="fas fa-qrcode fa-5x"></i>
                                <h3>Camera will appear here</h3>
                                <p>Click "Start Camera" to begin scanning</p>
                            </div>
                        </div>
                        
                        <!-- Manual Input -->
                        <div class="manual-input">
                            <h3><i class="fas fa-keyboard"></i> Manual Entry</h3>
                            <div class="input-group">
                                <input type="text" id="manual-qr-input" placeholder="Enter QR code data manually">
                                <button id="manual-qr-submit" class="btn btn-primary">
                                    <i class="fas fa-check"></i> Submit
                                </button>
                            </div>
                        </div>
                        
                        <!-- Scan Result -->
                        <div id="scan-result" class="scan-result"></div>
                    </section>
                </div>

                <!-- Right Column - Attendance List -->
                <div class="scan-column">
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-list-check"></i> Attendance List</h2>
                            <div class="attendance-actions">
                                <button class="btn btn-secondary" onclick="refreshAttendance()">
                                    <i class="fas fa-sync"></i> Refresh
                                </button>
                            </div>
                        </div>
                        
                        <!-- Attendance Tabs -->
                        <div class="attendance-tabs">
                            <button class="tab-btn active" onclick="showTab('scanned')">
                                <i class="fas fa-check-circle"></i> Scanned
                                <span class="badge"><?php echo count($scanned_students); ?></span>
                            </button>
                            <button class="tab-btn" onclick="showTab('expected')">
                                <i class="fas fa-clock"></i> Expected
                                <span class="badge"><?php echo count($expected_students); ?></span>
                            </button>
                        </div>
                        
                        <!-- Scanned Students -->
                        <div id="scanned-tab" class="tab-content active">
                            <?php if (count($scanned_students) > 0): ?>
                                <div class="students-list">
                                    <?php foreach ($scanned_students as $student): ?>
                                        <div class="student-item scanned">
                                            <div class="student-info">
                                                <div class="student-avatar">
                                                    <i class="fas fa-user-check"></i>
                                                </div>
                                                <div>
                                                    <h4><?php echo $student['name'] . ' ' . $student['surname']; ?></h4>
                                                    <p class="scan-time">
                                                        <i class="fas fa-clock"></i> 
                                                        <?php echo date('H:i:s', strtotime($student['scanned_time'])); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="student-status">
                                                <span class="status-badge status-success">
                                                    <i class="fas fa-check"></i> Present
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-user-slash fa-3x"></i>
                                    <p>No students scanned yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Expected Students -->
                        <div id="expected-tab" class="tab-content">
                            <?php if (count($expected_students) > 0): ?>
                                <div class="students-list">
                                    <?php foreach ($expected_students as $student): 
                                        // Check if student is already scanned
                                        $is_scanned = false;
                                        foreach ($scanned_students as $scanned) {
                                            if ($scanned['booking_id'] == $student['booking_id']) {
                                                $is_scanned = true;
                                                break;
                                            }
                                        }
                                    ?>
                                        <div class="student-item <?php echo $is_scanned ? 'scanned' : 'pending'; ?>">
                                            <div class="student-info">
                                                <div class="student-avatar">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                                <div>
                                                    <h4><?php echo $student['name'] . ' ' . $student['surname']; ?></h4>
                                                    <p class="student-email"><?php echo $student['email']; ?></p>
                                                </div>
                                            </div>
                                            <div class="student-actions">
                                                <?php if (!$is_scanned): ?>
                                                    <form method="POST" action="" style="display: inline;">
                                                        <input type="hidden" name="booking_id" value="<?php echo $student['booking_id']; ?>">
                                                        <input type="hidden" name="action" value="mark_present">
                                                        <button type="submit" name="manual_attendance" class="btn btn-success btn-sm">
                                                            <i class="fas fa-check"></i> Mark Present
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="" style="display: inline;">
                                                        <input type="hidden" name="booking_id" value="<?php echo $student['booking_id']; ?>">
                                                        <input type="hidden" name="action" value="mark_absent">
                                                        <button type="submit" name="manual_attendance" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-times"></i> Mark Absent
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="status-badge status-success">
                                                        <i class="fas fa-check"></i> Scanned
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-users-slash fa-3x"></i>
                                    <p>No students expected for this trip</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="quick-actions">
                            <button class="btn btn-primary" onclick="markAllPresent()">
                                <i class="fas fa-check-double"></i> Mark All Present
                            </button>
                            <button class="btn btn-secondary" onclick="downloadAttendance()">
                                <i class="fas fa-download"></i> Export List
                            </button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script src="qr_scanner.js"></script>
    <script>
        // Initialize scanner
        const scanner = new QRScanner();
        
        // DOM Ready
        document.addEventListener('DOMLoaded', function() {
            scanner.init('qr-scanner-container', 'scan-result');
            
            // Setup scanner controls
            document.getElementById('start-scanner').addEventListener('click', function() {
                scanner.start();
                document.querySelector('.scanner-placeholder').style.display = 'none';
            });
            
            document.getElementById('stop-scanner').addEventListener('click', function() {
                scanner.stop();
                document.querySelector('.scanner-placeholder').style.display = 'block';
            });
            
            // Auto-start if trip is active
            <?php if ($current_trip && $current_trip['status'] == 'boarding'): ?>
                setTimeout(() => {
                    scanner.start();
                    document.querySelector('.scanner-placeholder').style.display = 'none';
                }, 1000);
            <?php endif; ?>
        });

        // Tab switching
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activate button
            event.target.classList.add('active');
        }

        // Refresh attendance list
        function refreshAttendance() {
            window.location.reload();
        }

        // Mark all present
        function markAllPresent() {
            if (!confirm('Mark all remaining students as present?')) {
                return;
            }
            
            // Get all unmarked students
            const unmarkedForms = document.querySelectorAll('#expected-tab form:not(.scanned)');
            let completed = 0;
            
            unmarkedForms.forEach(form => {
                const formData = new FormData(form);
                formData.set('action', 'mark_present');
                
                fetch('driver_scan.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    completed++;
                    if (completed === unmarkedForms.length) {
                        alert('All students marked as present!');
                        window.location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
            });
        }

        // Download attendance list
        function downloadAttendance() {
            const data = {
                trip: <?php echo json_encode($current_trip); ?>,
                scanned: <?php echo json_encode($scanned_students); ?>,
                expected: <?php echo json_encode($expected_students); ?>,
                download_date: new Date().toISOString()
            };
            
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'attendance_' + new Date().toISOString().split('T')[0] + '.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // Auto-refresh attendance list every 30 seconds
        setInterval(() => {
            if (document.querySelector('.tab-content.active')) {
                refreshAttendance();
            }
        }, 30000);

        // Beep sound for successful scan
        function playBeep(type) {
            const audio = new Audio();
            if (type === 'success') {
                audio.src = 'assets/beep-success.mp3';
            } else {
                audio.src = 'assets/beep-error.mp3';
            }
            audio.play().catch(e => console.log('Audio play failed:', e));
        }
    </script>
</body>
</html>