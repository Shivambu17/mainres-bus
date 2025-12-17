<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is a student
if (!isLoggedIn() || !isStudent()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';
$trip_id = isset($_GET['trip_id']) ? sanitize($_GET['trip_id']) : '';
$action = isset($_GET['action']) ? sanitize($_GET['action']) : '';
$cancel_booking_id = isset($_GET['cancel']) ? sanitize($_GET['cancel']) : '';

// Handle cancellation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = sanitize($_POST['booking_id']);
    $reason = sanitize($_POST['cancellation_reason']);
    
    // Check if booking belongs to student
    $check_booking = "SELECT b.*, t.trip_date, t.departure_time 
                      FROM bookings b
                      JOIN trips t ON b.trip_id = t.trip_id
                      WHERE b.booking_id = '$booking_id' AND b.student_id = '$user_id'";
    $result = mysqli_query($conn, $check_booking);
    
    if (mysqli_num_rows($result) == 1) {
        $booking = mysqli_fetch_assoc($result);
        
        // Check cancellation deadline (1 hour before departure)
        $trip_datetime = $booking['trip_date'] . ' ' . $booking['departure_time'];
        $cancel_deadline = date('Y-m-d H:i:s', strtotime('-1 hour', strtotime($trip_datetime)));
        $now = date('Y-m-d H:i:s');
        
        if ($now < $cancel_deadline) {
            // Start transaction
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
                    if (WAITLIST_ENABLED) {
                        // Get first waitlisted student for this trip
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
                                
                                // Generate QR code for new booking
                                $qr_code = generateQRCode($new_booking_id);
                                mysqli_query($conn, "UPDATE bookings SET qr_code = '$qr_code' WHERE booking_id = '$new_booking_id'");
                                
                                // Update waitlist entry
                                mysqli_query($conn, "UPDATE waitlist SET status = 'promoted', promoted_at = NOW() WHERE waitlist_id = '{$waitlist_entry['waitlist_id']}'");
                                
                                // Update trip booked count
                                mysqli_query($conn, "UPDATE trips SET booked_count = booked_count + 1 WHERE trip_id = '{$booking['trip_id']}'");
                                
                                // Send notification to promoted student
                                $student_name = getUserName($waitlist_entry['student_id']);
                                $trip_info = getTripInfo($booking['trip_id']);
                                $notification_msg = "You have been promoted from waitlist for trip: $trip_info";
                                sendNotification($waitlist_entry['student_id'], $notification_msg, 'success');
                            }
                        }
                    }
                    
                    mysqli_commit($conn);
                    $success = "Booking cancelled successfully!";
                    
                    // Send notification
                    sendNotification($user_id, "Your booking has been cancelled. Reason: $reason", 'info');
                    
                } else {
                    throw new Exception("Error cancelling booking");
                }
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = "Cancellation failed: " . $e->getMessage();
            }
        } else {
            $error = "Cannot cancel booking less than 1 hour before departure";
        }
    } else {
        $error = "Booking not found or access denied";
    }
}

// Handle new booking
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['book_trip'])) {
    $trip_id = sanitize($_POST['trip_id']);
    
    // Check if student already booked this trip
    $check_existing = "SELECT * FROM bookings WHERE student_id = '$user_id' AND trip_id = '$trip_id' AND status != 'cancelled'";
    $result = mysqli_query($conn, $check_existing);
    
    if (mysqli_num_rows($result) > 0) {
        $error = "You already have a booking for this trip";
    } else {
        // Check booking limit (max 2 advance bookings)
        $booking_count = mysqli_fetch_assoc(mysqli_query($conn, 
            "SELECT COUNT(*) as count FROM bookings b 
             JOIN trips t ON b.trip_id = t.trip_id 
             WHERE b.student_id = '$user_id' 
             AND b.status IN ('confirmed', 'waitlisted')
             AND t.trip_date >= CURDATE()"))['count'];
        
        if ($booking_count >= MAX_ADVANCE_BOOKINGS) {
            $error = "You cannot book more than " . MAX_ADVANCE_BOOKINGS . " trips in advance";
        } else {
            // Get trip details
            $trip_query = "SELECT * FROM trips WHERE trip_id = '$trip_id'";
            $trip_result = mysqli_query($conn, $trip_query);
            
            if (mysqli_num_rows($trip_result) == 1) {
                $trip = mysqli_fetch_assoc($trip_result);
                
                // Start transaction
                mysqli_begin_transaction($conn);
                
                try {
                    if ($trip['booked_count'] < BUS_CAPACITY) {
                        // Book directly
                        $insert_booking = "INSERT INTO bookings (student_id, trip_id, status) 
                                           VALUES ('$user_id', '$trip_id', 'confirmed')";
                        
                        if (mysqli_query($conn, $insert_booking)) {
                            $booking_id = mysqli_insert_id($conn);
                            
                            // Generate QR code
                            $qr_code = generateQRCode($booking_id);
                            mysqli_query($conn, "UPDATE bookings SET qr_code = '$qr_code' WHERE booking_id = '$booking_id'");
                            
                            // Update trip booked count
                            $update_trip = "UPDATE trips SET booked_count = booked_count + 1 WHERE trip_id = '$trip_id'";
                            mysqli_query($conn, $update_trip);
                            
                            mysqli_commit($conn);
                            $success = "Booking confirmed! Your seat has been reserved.";
                            
                            // Send notification
                            $trip_info = date('d M Y', strtotime($trip['trip_date'])) . " at " . date('h:i A', strtotime($trip['departure_time']));
                            sendNotification($user_id, "Booking confirmed for trip on $trip_info", 'success');
                            
                        } else {
                            throw new Exception("Error creating booking");
                        }
                    } else {
                        // Add to waitlist if enabled
                        if (WAITLIST_ENABLED) {
                            // Get next waitlist position
                            $waitlist_position = mysqli_fetch_assoc(mysqli_query($conn, 
                                "SELECT MAX(position) as max_pos FROM waitlist WHERE trip_id = '$trip_id' AND status = 'waiting'"))['max_pos'];
                            $position = ($waitlist_position ? $waitlist_position + 1 : 1);
                            
                            // Add to waitlist
                            $insert_waitlist = "INSERT INTO waitlist (student_id, trip_id, position) 
                                                VALUES ('$user_id', '$trip_id', '$position')";
                            
                            if (mysqli_query($conn, $insert_waitlist)) {
                                // Create waitlisted booking
                                $insert_booking = "INSERT INTO bookings (student_id, trip_id, status, waitlist_position) 
                                                   VALUES ('$user_id', '$trip_id', 'waitlisted', '$position')";
                                
                                if (mysqli_query($conn, $insert_booking)) {
                                    // Update trip waitlist count
                                    mysqli_query($conn, "UPDATE trips SET waitlist_count = waitlist_count + 1 WHERE trip_id = '$trip_id'");
                                    
                                    mysqli_commit($conn);
                                    $success = "Trip is full! You have been added to waitlist (position #$position).";
                                    
                                    // Send notification
                                    sendNotification($user_id, "You have been added to waitlist for trip. Position: #$position", 'warning');
                                    
                                } else {
                                    throw new Exception("Error creating waitlisted booking");
                                }
                            } else {
                                throw new Exception("Error adding to waitlist");
                            }
                        } else {
                            $error = "Trip is full and waitlist is not available";
                        }
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

// Get available trips
$date_filter = isset($_GET['date']) ? sanitize($_GET['date']) : date('Y-m-d');
$route_filter = isset($_GET['route']) ? sanitize($_GET['route']) : '';

$trips_query = "SELECT t.*, b.bus_number, b.capacity,
                (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count
                FROM trips t
                JOIN buses b ON t.bus_id = b.bus_id
                WHERE t.trip_date >= '$date_filter'
                AND t.status = 'scheduled'";

if ($route_filter) {
    $trips_query .= " AND t.route LIKE '%$route_filter%'";
}

$trips_query .= " ORDER BY t.trip_date, t.departure_time";
$trips_result = mysqli_query($conn, $trips_query);

// Get student's current bookings
$student_bookings_query = "SELECT b.*, t.trip_date, t.departure_time, t.route, bu.bus_number 
                          FROM bookings b
                          JOIN trips t ON b.trip_id = t.trip_id
                          JOIN buses bu ON t.bus_id = bu.bus_id
                          WHERE b.student_id = '$user_id' 
                          AND b.status IN ('confirmed', 'waitlisted')
                          AND t.trip_date >= CURDATE()
                          ORDER BY t.trip_date, t.departure_time";
$student_bookings_result = mysqli_query($conn, $student_bookings_query);

// Helper function to get trip info
function getTripInfo($trip_id) {
    global $conn;
    $query = "SELECT CONCAT(DATE_FORMAT(trip_date, '%d %b %Y'), ' at ', TIME_FORMAT(departure_time, '%h:%i %p'), ' - ', route) as info 
              FROM trips WHERE trip_id = '$trip_id'";
    $result = mysqli_query($conn, $query);
    $row = mysqli_fetch_assoc($result);
    return $row['info'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Trip - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
                    <i class="fas fa-graduation-cap"></i> <?php echo $_SESSION['faculty']; ?><br>
                    <i class="fas fa-home"></i> <?php echo $_SESSION['residence']; ?>
                </p>
            </div>
            <nav class="sidebar-nav">
                <a href="student_dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="student_book.php" class="active">
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
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <header class="dashboard-header">
                <h1>Book a Bus Trip</h1>
                <div class="header-actions">
                    <a href="student_dashboard.php" class="btn btn-secondary">
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

            <!-- Current Bookings -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-calendar-check"></i> Your Current Bookings</h2>
                </div>
                <?php if (mysqli_num_rows($student_bookings_result) > 0): ?>
                    <div class="bookings-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Route</th>
                                    <th>Bus</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($booking = mysqli_fetch_assoc($student_bookings_result)): 
                                    $trip_datetime = $booking['trip_date'] . ' ' . $booking['departure_time'];
                                    $cancel_deadline = date('Y-m-d H:i:s', strtotime('-1 hour', strtotime($trip_datetime)));
                                    $now = date('Y-m-d H:i:s');
                                    $can_cancel = ($booking['status'] == 'confirmed' && $now < $cancel_deadline);
                                ?>
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
                                                <?php if ($booking['waitlist_position'] > 0): ?>
                                                    (#<?php echo $booking['waitlist_position']; ?>)
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($can_cancel): ?>
                                                <button class="btn btn-danger btn-sm" onclick="showCancelModal(<?php echo $booking['booking_id']; ?>, '<?php echo $booking['route']; ?>')">
                                                    Cancel
                                                </button>
                                            <?php elseif ($booking['status'] == 'waitlisted'): ?>
                                                <button class="btn btn-secondary btn-sm" disabled>
                                                    Waitlisted
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-plus fa-3x"></i>
                        <h3>No current bookings</h3>
                        <p>Book your first trip below!</p>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Available Trips -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-calendar-alt"></i> Available Trips</h2>
                    <div class="filter-controls">
                        <form method="GET" action="" class="filter-form">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="date"><i class="fas fa-calendar"></i> Date</label>
                                    <input type="date" id="date" name="date" value="<?php echo $date_filter; ?>" min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="route"><i class="fas fa-route"></i> Route</label>
                                    <select id="route" name="route">
                                        <option value="">All Routes</option>
                                        <option value="MainRes to Campus" <?php echo ($route_filter == 'MainRes to Campus') ? 'selected' : ''; ?>>MainRes to Campus</option>
                                        <option value="Campus to MainRes" <?php echo ($route_filter == 'Campus to MainRes') ? 'selected' : ''; ?>>Campus to MainRes</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Filter
                                    </button>
                                    <a href="student_book.php" class="btn btn-secondary">
                                        <i class="fas fa-redo"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (mysqli_num_rows($trips_result) > 0): ?>
                    <div class="trips-grid">
                        <?php while ($trip = mysqli_fetch_assoc($trips_result)): 
                            $available_seats = $trip['capacity'] - $trip['booked_count'];
                            $is_full = $available_seats <= 0;
                            $occupancy_percent = ($trip['booked_count'] / $trip['capacity']) * 100;
                            
                            // Determine status color
                            if ($is_full) {
                                $status_class = 'status-full';
                            } elseif ($occupancy_percent >= 80) {
                                $status_class = 'status-warning';
                            } else {
                                $status_class = 'status-available';
                            }
                        ?>
                            <div class="trip-card <?php echo $is_full ? 'trip-full' : ''; ?>" data-trip-id="<?php echo $trip['trip_id']; ?>">
                                <div class="trip-header">
                                    <div>
                                        <h3><?php echo date('h:i A', strtotime($trip['departure_time'])); ?></h3>
                                        <p class="trip-date"><?php echo date('D, d M Y', strtotime($trip['trip_date'])); ?></p>
                                    </div>
                                    <span class="trip-status <?php echo $status_class; ?>">
                                        <?php if ($is_full): ?>
                                            FULL
                                        <?php else: ?>
                                            <?php echo $available_seats; ?> seats
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="trip-body">
                                    <p><i class="fas fa-route"></i> <strong><?php echo $trip['route']; ?></strong></p>
                                    <p><i class="fas fa-bus"></i> Bus <?php echo $trip['bus_number']; ?></p>
                                    <p><i class="fas fa-map-marker-alt"></i> <?php echo $trip['pickup_point']; ?></p>
                                    <p><i class="fas fa-flag-checkered"></i> <?php echo $trip['dropoff_point']; ?></p>
                                    
                                    <div class="capacity-info">
                                        <div class="capacity-stats">
                                            <span>Capacity: <?php echo $trip['booked_count']; ?>/<?php echo $trip['capacity']; ?></span>
                                            <span><?php echo round($occupancy_percent); ?>% full</span>
                                        </div>
                                        <div class="capacity-bar">
                                            <div class="capacity-fill" style="width: <?php echo $occupancy_percent; ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($occupancy_percent >= 75): ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle"></i> Almost full
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="trip-footer">
                                    <?php if (!$is_full): ?>
                                        <form method="POST" action="" onsubmit="return confirm('Confirm booking for this trip?')">
                                            <input type="hidden" name="trip_id" value="<?php echo $trip['trip_id']; ?>">
                                            <button type="submit" name="book_trip" class="btn btn-primary btn-block">
                                                <i class="fas fa-check"></i> Book Now
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <?php if (WAITLIST_ENABLED): ?>
                                            <form method="POST" action="" onsubmit="return confirm('Join waitlist for this trip?')">
                                                <input type="hidden" name="trip_id" value="<?php echo $trip['trip_id']; ?>">
                                                <button type="submit" name="book_trip" class="btn btn-warning btn-block">
                                                    <i class="fas fa-clock"></i> Join Waitlist
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-block" disabled>
                                                <i class="fas fa-times"></i> Trip Full
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times fa-3x"></i>
                        <h3>No trips available for selected date</h3>
                        <p>Try selecting a different date or check back later.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- Cancel Modal -->
    <div id="cancelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Cancel Booking</h3>
                <button class="modal-close" onclick="closeCancelModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel this booking?</p>
                <p id="tripDetails"></p>
                <form method="POST" action="" id="cancelForm">
                    <input type="hidden" name="booking_id" id="bookingId">
                    <div class="form-group">
                        <label for="cancellation_reason">Cancellation Reason (optional)</label>
                        <textarea id="cancellation_reason" name="cancellation_reason" rows="3" placeholder="Reason for cancellation"></textarea>
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
            minDate: "today",
            dateFormat: "Y-m-d",
        });

        // Show cancel modal
        function showCancelModal(bookingId, route) {
            document.getElementById('bookingId').value = bookingId;
            document.getElementById('tripDetails').textContent = 'Trip: ' + route;
            document.getElementById('cancelModal').style.display = 'block';
        }

        // Close cancel modal
        function closeCancelModal() {
            document.getElementById('cancelModal').style.display = 'none';
        }

        // Auto-update seat availability
        function updateSeatAvailability() {
            const tripCards = document.querySelectorAll('.trip-card');
            tripCards.forEach(card => {
                const tripId = card.dataset.tripId;
                if (tripId) {
                    fetch(`api_booking_check.php?trip_id=${tripId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const statusElement = card.querySelector('.trip-status');
                                const capacityFill = card.querySelector('.capacity-fill');
                                const capacityStats = card.querySelector('.capacity-stats span:first-child');
                                
                                if (statusElement && capacityFill && capacityStats) {
                                    const availableSeats = data.available_seats;
                                    const bookedCount = data.capacity - availableSeats;
                                    const occupancyPercent = (bookedCount / data.capacity) * 100;
                                    
                                    // Update status
                                    if (availableSeats <= 0) {
                                        statusElement.textContent = 'FULL';
                                        statusElement.className = 'trip-status status-full';
                                        card.classList.add('trip-full');
                                    } else {
                                        statusElement.textContent = availableSeats + ' seats';
                                        statusElement.className = 'trip-status status-available';
                                        card.classList.remove('trip-full');
                                    }
                                    
                                    // Update capacity bar
                                    capacityFill.style.width = occupancyPercent + '%';
                                    capacityStats.textContent = `Capacity: ${bookedCount}/${data.capacity}`;
                                }
                            }
                        });
                }
            });
        }

        // Update every 30 seconds
        setInterval(updateSeatAvailability, 30000);
    </script>
</body>
</html>