<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('login.php');
}

$error = '';
$success = '';
$action = isset($_GET['action']) ? sanitize($_GET['action']) : '';
$booking_id = isset($_GET['id']) ? sanitize($_GET['id']) : '';
$trip_id = isset($_GET['trip_id']) ? sanitize($_GET['trip_id']) : '';

// Handle booking actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['cancel_booking'])) {
        $booking_id = sanitize($_POST['booking_id']);
        $reason = sanitize($_POST['reason']);
        
        // Get booking details
        $booking_query = "SELECT b.*, t.trip_date, t.departure_time 
                         FROM bookings b
                         JOIN trips t ON b.trip_id = t.trip_id
                         WHERE b.booking_id = '$booking_id'";
        $booking_result = mysqli_query($conn, $booking_query);
        
        if (mysqli_num_rows($booking_result) == 1) {
            $booking = mysqli_fetch_assoc($booking_result);
            
            mysqli_begin_transaction($conn);
            
            try {
                // Update booking status
                $update_booking = "UPDATE bookings SET 
                                   status = 'cancelled',
                                   cancelled_at = NOW(),
                                   cancellation_reason = '$reason'
                                   WHERE booking_id = '$booking_id'";
                
                if (mysqli_query($conn, $update_booking)) {
                    // Decrease booked count for trip
                    $update_trip = "UPDATE trips SET booked_count = booked_count - 1 WHERE trip_id = '{$booking['trip_id']}'";
                    mysqli_query($conn, $update_trip);
                    
                    // Check and promote waitlist
                    $waitlist_query = "SELECT w.* FROM waitlist w 
                                       WHERE w.trip_id = '{$booking['trip_id']}' 
                                       AND w.status = 'waiting'
                                       ORDER BY w.position ASC 
                                       LIMIT 1";
                    $waitlist_result = mysqli_query($conn, $waitlist_query);
                    
                    if (mysqli_num_rows($waitlist_result) > 0) {
                        $waitlist_entry = mysqli_fetch_assoc($waitlist_result);
                        
                        // Promote waitlisted student
                        $promote_booking = "INSERT INTO bookings (student_id, trip_id, status) 
                                            VALUES ('{$waitlist_entry['student_id']}', '{$booking['trip_id']}', 'confirmed')";
                        if (mysqli_query($conn, $promote_booking)) {
                            $new_booking_id = mysqli_insert_id($conn);
                            
                            // Generate QR code
                            $qr_code = generateQRCode($new_booking_id);
                            mysqli_query($conn, "UPDATE bookings SET qr_code = '$qr_code' WHERE booking_id = '$new_booking_id'");
                            
                            // Update waitlist entry
                            mysqli_query($conn, "UPDATE waitlist SET status = 'promoted', promoted_at = NOW() WHERE waitlist_id = '{$waitlist_entry['waitlist_id']}'");
                            
                            // Update trip booked count
                            mysqli_query($conn, "UPDATE trips SET booked_count = booked_count + 1 WHERE trip_id = '{$booking['trip_id']}'");
                            
                            // Send notification
                            $student_name = getUserName($waitlist_entry['student_id']);
                            $trip_info = getTripInfo($booking['trip_id']);
                            $notification_msg = "You have been promoted from waitlist for trip: $trip_info";
                            sendNotification($waitlist_entry['student_id'], $notification_msg, 'success');
                        }
                    }
                    
                    mysqli_commit($conn);
                    $success = "Booking cancelled successfully!";
                    
                    // Send notification to student
                    sendNotification($booking['student_id'], "Your booking has been cancelled by admin. Reason: $reason", 'info');
                    
                } else {
                    throw new Exception("Error cancelling booking");
                }
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = "Cancellation failed: " . $e->getMessage();
            }
        } else {
            $error = "Booking not found";
        }
    }
    
    elseif (isset($_POST['force_book'])) {
        $student_id = sanitize($_POST['student_id']);
        $trip_id = sanitize($_POST['trip_id']);
        
        // Check if student already booked this trip
        $check_existing = "SELECT * FROM bookings WHERE student_id = '$student_id' AND trip_id = '$trip_id' AND status != 'cancelled'";
        $result = mysqli_query($conn, $check_existing);
        
        if (mysqli_num_rows($result) > 0) {
            $error = "Student already has a booking for this trip";
        } else {
            // Check if trip is full
            $trip_query = "SELECT * FROM trips WHERE trip_id = '$trip_id'";
            $trip_result = mysqli_query($conn, $trip_query);
            
            if (mysqli_num_rows($trip_result) == 1) {
                $trip = mysqli_fetch_assoc($trip_result);
                
                mysqli_begin_transaction($conn);
                
                try {
                    // Create booking
                    $insert_booking = "INSERT INTO bookings (student_id, trip_id, status) 
                                       VALUES ('$student_id', '$trip_id', 'confirmed')";
                    
                    if (mysqli_query($conn, $insert_booking)) {
                        $booking_id = mysqli_insert_id($conn);
                        
                        // Generate QR code
                        $qr_code = generateQRCode($booking_id);
                        mysqli_query($conn, "UPDATE bookings SET qr_code = '$qr_code' WHERE booking_id = '$booking_id'");
                        
                        // Update trip booked count
                        $update_trip = "UPDATE trips SET booked_count = booked_count + 1 WHERE trip_id = '$trip_id'";
                        mysqli_query($conn, $update_trip);
                        
                        mysqli_commit($conn);
                        $success = "Booking created successfully!";
                        
                        // Send notification
                        $trip_info = date('d M Y', strtotime($trip['trip_date'])) . " at " . date('h:i A', strtotime($trip['departure_time']));
                        sendNotification($student_id, "Admin has booked you for trip on $trip_info", 'success');
                        
                    } else {
                        throw new Exception("Error creating booking");
                    }
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = "Booking failed: " . $e->getMessage();
                }
            } else {
                $error = "Trip not found";
            }
        }
    }
}

// Get bookings with filters
$filter_date = isset($_GET['date']) ? sanitize($_GET['date']) : '';
$filter_status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$filter_student = isset($_GET['student']) ? sanitize($_GET['student']) : '';

$bookings_query = "SELECT b.*, 
                   u.name as student_name, u.surname as student_surname, u.email as student_email,
                   t.trip_date, t.departure_time, t.route,
                   bu.bus_number,
                   a.scanned_time as attendance_time
                   FROM bookings b
                   JOIN users u ON b.student_id = u.user_id
                   JOIN trips t ON b.trip_id = t.trip_id
                   JOIN buses bu ON t.bus_id = bu.bus_id
                   LEFT JOIN attendance a ON b.booking_id = a.booking_id AND a.result = 'success'";

$conditions = [];
if ($trip_id) {
    $conditions[] = "b.trip_id = '$trip_id'";
}
if ($filter_date) {
    $conditions[] = "t.trip_date = '$filter_date'";
}
if ($filter_status && $filter_status != 'all') {
    $conditions[] = "b.status = '$filter_status'";
}
if ($filter_student) {
    $conditions[] = "(u.name LIKE '%$filter_student%' OR u.surname LIKE '%$filter_student%' OR u.email LIKE '%$filter_student%')";
}

if (count($conditions) > 0) {
    $bookings_query .= " WHERE " . implode(" AND ", $conditions);
}

$bookings_query .= " ORDER BY t.trip_date DESC, t.departure_time DESC, b.booking_date DESC";
$bookings_result = mysqli_query($conn, $bookings_query);

// Get booking statistics
$stats_query = "SELECT 
                COUNT(*) as total_bookings,
                SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
                SUM(CASE WHEN b.status = 'waitlisted' THEN 1 ELSE 0 END) as waitlisted_bookings,
                SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
                SUM(CASE WHEN b.status = 'used' THEN 1 ELSE 0 END) as used_bookings
                FROM bookings b";
if ($trip_id) {
    $stats_query .= " WHERE b.trip_id = '$trip_id'";
}
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get all active students for force booking
$students_query = "SELECT u.user_id, u.name, u.surname, u.email, s.faculty, s.residence
                   FROM users u
                   JOIN students s ON u.user_id = s.student_id
                   WHERE u.status = 'active'
                   ORDER BY u.surname, u.name";
$students_result = mysqli_query($conn, $students_query);

// Get upcoming trips for force booking
$upcoming_trips_query = "SELECT t.*, b.bus_number,
                         (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count
                         FROM trips t
                         JOIN buses b ON t.bus_id = b.bus_id
                         WHERE t.trip_date >= CURDATE()
                         AND t.status = 'scheduled'
                         ORDER BY t.trip_date, t.departure_time";
$upcoming_trips_result = mysqli_query($conn, $upcoming_trips_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Management - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
                <a href="admin_bookings.php" class="active">
                    <i class="fas fa-ticket-alt"></i> Bookings
                </a>
                <a href="admin_users.php">
                    <i class="fas fa-users"></i> User Management
                </a>
                <a href="admin_reports.php">
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
                <h1>Booking Management</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="showForceBookModal()">
                        <i class="fas fa-plus"></i> Force Book
                    </button>
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

            <!-- Statistics -->
            <div class="quick-stats">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #3498db;">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_bookings']; ?></h3>
                        <p>Total Bookings</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #2ecc71;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['confirmed_bookings']; ?></h3>
                        <p>Confirmed</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #f39c12;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['waitlisted_bookings']; ?></h3>
                        <p>Waitlisted</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #e74c3c;">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['cancelled_bookings']; ?></h3>
                        <p>Cancelled</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-filter"></i> Filter Bookings</h2>
                </div>
                
                <form method="GET" action="" class="filter-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="date"><i class="fas fa-calendar"></i> Date</label>
                            <input type="date" id="date" name="date" value="<?php echo $filter_date; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="status"><i class="fas fa-circle"></i> Status</label>
                            <select id="status" name="status">
                                <option value="all">All Statuses</option>
                                <option value="confirmed" <?php echo ($filter_status == 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="waitlisted" <?php echo ($filter_status == 'waitlisted') ? 'selected' : ''; ?>>Waitlisted</option>
                                <option value="cancelled" <?php echo ($filter_status == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="used" <?php echo ($filter_status == 'used') ? 'selected' : ''; ?>>Used</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="student"><i class="fas fa-user"></i> Student Search</label>
                            <input type="text" id="student" name="student" value="<?php echo $filter_student; ?>" 
                                   placeholder="Name, surname or email">
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="admin_bookings.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </form>
            </section>

            <!-- Bookings Table -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-list"></i> All Bookings</h2>
                    <div class="export-actions">
                        <button class="btn btn-secondary" onclick="exportBookings('csv')">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </button>
                        <button class="btn btn-secondary" onclick="exportBookings('excel')">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                    </div>
                </div>
                
                <?php if (mysqli_num_rows($bookings_result) > 0): ?>
                    <div class="bookings-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Trip Details</th>
                                    <th>Bus</th>
                                    <th>Booking Date</th>
                                    <th>Status</th>
                                    <th>Attendance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($booking = mysqli_fetch_assoc($bookings_result)): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $booking['student_name'] . ' ' . $booking['student_surname']; ?></strong><br>
                                            <small><?php echo $booking['student_email']; ?></small>
                                        </td>
                                        <td>
                                            <?php echo date('d M Y', strtotime($booking['trip_date'])); ?><br>
                                            <?php echo date('h:i A', strtotime($booking['departure_time'])); ?> - <?php echo $booking['route']; ?>
                                        </td>
                                        <td><?php echo $booking['bus_number']; ?></td>
                                        <td><?php echo date('d M H:i', strtotime($booking['booking_date'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $booking['status']; ?>">
                                                <?php echo ucfirst($booking['status']); ?>
                                                <?php if ($booking['waitlist_position'] > 0): ?>
                                                    (#<?php echo $booking['waitlist_position']; ?>)
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($booking['attendance_time']): ?>
                                                <span class="status-badge status-success">
                                                    <i class="fas fa-check"></i> Present
                                                </span><br>
                                                <small><?php echo date('H:i', strtotime($booking['attendance_time'])); ?></small>
                                            <?php elseif ($booking['status'] == 'used'): ?>
                                                <span class="text-muted">No scan</span>
                                            <?php else: ?>
                                                <span class="text-muted">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($booking['status'] == 'confirmed'): ?>
                                                <button class="btn btn-danger btn-sm" onclick="cancelBooking(<?php echo $booking['booking_id']; ?>, '<?php echo $booking['student_name']; ?>')">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($booking['qr_code']): ?>
                                                <a href="uploads/qr_codes/<?php echo $booking['qr_code']; ?>" download class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-qrcode"></i> QR
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="pagination">
                        <span>Showing <?php echo mysqli_num_rows($bookings_result); ?> bookings</span>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-ticket-alt fa-3x"></i>
                        <h3>No bookings found</h3>
                        <p>Try adjusting your filters or add a new booking.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- Force Book Modal -->
    <div id="forceBookModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Force Create Booking</h3>
                <button class="modal-close" onclick="closeForceBookModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="forceBookForm">
                    <div class="form-group">
                        <label for="student_id"><i class="fas fa-user"></i> Student *</label>
                        <select id="student_id" name="student_id" required>
                            <option value="">Select Student</option>
                            <?php while ($student = mysqli_fetch_assoc($students_result)): ?>
                                <option value="<?php echo $student['user_id']; ?>">
                                    <?php echo $student['name'] . ' ' . $student['surname']; ?> 
                                    (<?php echo $student['email']; ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="trip_id"><i class="fas fa-route"></i> Trip *</label>
                        <select id="trip_id" name="trip_id" required>
                            <option value="">Select Trip</option>
                            <?php while ($trip = mysqli_fetch_assoc($upcoming_trips_result)): 
                                $available_seats = BUS_CAPACITY - $trip['booked_count'];
                            ?>
                                <option value="<?php echo $trip['trip_id']; ?>" data-available="<?php echo $available_seats; ?>">
                                    <?php echo date('d M', strtotime($trip['trip_date'])); ?> 
                                    at <?php echo date('h:i A', strtotime($trip['departure_time'])); ?> - 
                                    <?php echo $trip['route']; ?> 
                                    (Bus <?php echo $trip['bus_number']; ?>, <?php echo $available_seats; ?> seats left)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-info-circle"></i> Trip Info</label>
                        <div id="tripInfo" class="trip-info-display">
                            Select a trip to see details
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeForceBookModal()">Cancel</button>
                        <button type="submit" name="force_book" class="btn btn-primary">Create Booking</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cancel Booking Modal -->
    <div id="cancelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Cancel Booking</h3>
                <button class="modal-close" onclick="closeCancelModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel this booking for <span id="studentName"></span>?</p>
                <form method="POST" action="" id="cancelForm">
                    <input type="hidden" name="booking_id" id="bookingId">
                    <div class="form-group">
                        <label for="reason">Cancellation Reason *</label>
                        <textarea id="reason" name="reason" rows="3" required 
                                  placeholder="Reason for cancellation"></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">Keep Booking</button>
                        <button type="submit" name="cancel_booking" class="btn btn-danger">Confirm Cancellation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date picker
        flatpickr("#date", {
            dateFormat: "Y-m-d",
        });

        // Show force book modal
        function showForceBookModal() {
            document.getElementById('forceBookModal').style.display = 'block';
        }

        // Close force book modal
        function closeForceBookModal() {
            document.getElementById('forceBookModal').style.display = 'none';
        }

        // Cancel booking
        function cancelBooking(bookingId, studentName) {
            document.getElementById('studentName').textContent = studentName;
            document.getElementById('bookingId').value = bookingId;
            document.getElementById('cancelModal').style.display = 'block';
        }

        // Close cancel modal
        function closeCancelModal() {
            document.getElementById('cancelModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const forceBookModal = document.getElementById('forceBookModal');
            const cancelModal = document.getElementById('cancelModal');
            
            if (event.target == forceBookModal) {
                closeForceBookModal();
            }
            if (event.target == cancelModal) {
                closeCancelModal();
            }
        }

        // Update trip info when selection changes
        document.getElementById('trip_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const availableSeats = selectedOption.getAttribute('data-available');
            
            if (availableSeats !== null) {
                document.getElementById('tripInfo').innerHTML = `
                    <div class="alert ${availableSeats > 0 ? 'alert-info' : 'alert-warning'}">
                        <i class="fas fa-info-circle"></i>
                        <strong>Available seats:</strong> ${availableSeats}<br>
                        <strong>Trip:</strong> ${selectedOption.textContent}
                    </div>
                `;
            } else {
                document.getElementById('tripInfo').innerHTML = 'Select a trip to see details';
            }
        });

        // Export bookings
        function exportBookings(format) {
            const params = new URLSearchParams(window.location.search);
            params.append('export', format);
            
            window.location.href = `api_export.php?${params.toString()}`;
        }

        // Form validation
        document.getElementById('forceBookForm').addEventListener('submit', function(e) {
            const tripSelect = document.getElementById('trip_id');
            const selectedOption = tripSelect.options[tripSelect.selectedIndex];
            const availableSeats = parseInt(selectedOption.getAttribute('data-available'));
            
            if (availableSeats <= 0) {
                if (!confirm('This trip has no available seats. Force booking will override capacity. Continue?')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            return true;
        });

        // Auto-refresh bookings every 60 seconds
        setInterval(function() {
            if (window.location.pathname.includes('admin_bookings.php')) {
                // Only refresh if no modal is open
                if (!document.getElementById('forceBookModal').style.display && 
                    !document.getElementById('cancelModal').style.display) {
                    window.location.reload();
                }
            }
        }, 60000);
    </script>
</body>
</html>