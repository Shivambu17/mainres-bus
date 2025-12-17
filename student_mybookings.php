<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is a student
if (!isLoggedIn() || !isStudent()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$filter = isset($_GET['filter']) ? sanitize($_GET['filter']) : 'upcoming';

// Get bookings based on filter
$query = "SELECT b.*, t.trip_date, t.departure_time, t.route, bu.bus_number,
          (SELECT COUNT(*) FROM attendance a WHERE a.booking_id = b.booking_id) as attendance_count
          FROM bookings b
          JOIN trips t ON b.trip_id = t.trip_id
          JOIN buses bu ON t.bus_id = bu.bus_id
          WHERE b.student_id = '$user_id'";

switch ($filter) {
    case 'upcoming':
        $query .= " AND t.trip_date >= CURDATE() AND b.status IN ('confirmed', 'waitlisted')";
        break;
    case 'past':
        $query .= " AND t.trip_date < CURDATE()";
        break;
    case 'cancelled':
        $query .= " AND b.status = 'cancelled'";
        break;
    case 'all':
    default:
        // No date filter
        break;
}

$query .= " ORDER BY t.trip_date DESC, t.departure_time DESC";
$result = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - MainRes Bus System</title>
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
                    <i class="fas fa-graduation-cap"></i> <?php echo $_SESSION['faculty']; ?><br>
                    <i class="fas fa-home"></i> <?php echo $_SESSION['residence']; ?>
                </p>
            </div>
            <nav class="sidebar-nav">
                <a href="student_dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="student_book.php">
                    <i class="fas fa-bus"></i> Book a Trip
                </a>
                <a href="student_mybookings.php" class="active">
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
                <h1>My Bookings</h1>
                <div class="header-actions">
                    <a href="student_book.php" class="btn btn-primary">
                        <i class="fas fa-bus"></i> Book New Trip
                    </a>
                </div>
            </header>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="?filter=upcoming" class="<?php echo ($filter == 'upcoming') ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i> Upcoming
                </a>
                <a href="?filter=past" class="<?php echo ($filter == 'past') ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i> Past Trips
                </a>
                <a href="?filter=cancelled" class="<?php echo ($filter == 'cancelled') ? 'active' : ''; ?>">
                    <i class="fas fa-times-circle"></i> Cancelled
                </a>
                <a href="?filter=all" class="<?php echo ($filter == 'all') ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i> All Bookings
                </a>
            </div>

            <!-- Bookings Table -->
            <section class="dashboard-section">
                <?php if (mysqli_num_rows($result) > 0): ?>
                    <div class="bookings-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Route</th>
                                    <th>Bus</th>
                                    <th>Status</th>
                                    <th>QR Code</th>
                                    <th>Attendance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($booking = mysqli_fetch_assoc($result)): 
                                    $trip_datetime = $booking['trip_date'] . ' ' . $booking['departure_time'];
                                    $is_upcoming = strtotime($trip_datetime) > time();
                                    $cancel_deadline = date('Y-m-d H:i:s', strtotime('-1 hour', strtotime($trip_datetime)));
                                    $now = date('Y-m-d H:i:s');
                                    $can_cancel = ($booking['status'] == 'confirmed' && $is_upcoming && $now < $cancel_deadline);
                                    
                                    // Determine QR code status
                                    $qr_status = '';
                                    if ($booking['status'] == 'confirmed' && $booking['qr_code']) {
                                        $qr_status = 'available';
                                    } elseif ($booking['status'] == 'waitlisted') {
                                        $qr_status = 'waitlisted';
                                    } else {
                                        $qr_status = 'unavailable';
                                    }
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
                                            <?php if ($qr_status == 'available'): ?>
                                                <a href="uploads/qr_codes/<?php echo $booking['qr_code']; ?>" 
                                                   download="QR_<?php echo $booking['booking_id']; ?>.png" 
                                                   class="btn-qr">
                                                    <i class="fas fa-qrcode"></i> Download
                                                </a>
                                                <button class="btn-qr" onclick="printQRCode('uploads/qr_codes/<?php echo $booking['qr_code']; ?>')">
                                                    <i class="fas fa-print"></i> Print
                                                </button>
                                            <?php elseif ($qr_status == 'waitlisted'): ?>
                                                <span class="text-muted">Waitlisted</span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($booking['attendance_count'] > 0): ?>
                                                <span class="status-badge status-success">
                                                    <i class="fas fa-check"></i> Attended
                                                </span>
                                            <?php elseif (!$is_upcoming && $booking['status'] == 'confirmed'): ?>
                                                <span class="status-badge status-error">
                                                    <i class="fas fa-times"></i> No Show
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($can_cancel): ?>
                                                <button class="btn btn-danger btn-sm" 
                                                        onclick="cancelBooking(<?php echo $booking['booking_id']; ?>)">
                                                    Cancel
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
                        <?php if ($filter == 'upcoming'): ?>
                            <i class="fas fa-calendar-plus fa-3x"></i>
                            <h3>No upcoming bookings</h3>
                            <p>You don't have any upcoming bus trips booked.</p>
                            <a href="student_book.php" class="btn btn-primary">Book Your First Trip</a>
                        <?php else: ?>
                            <i class="fas fa-calendar-times fa-3x"></i>
                            <h3>No bookings found</h3>
                            <p>No bookings match your selected filter.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        function cancelBooking(bookingId) {
            if (confirm('Are you sure you want to cancel this booking?')) {
                const reason = prompt('Please enter a reason for cancellation (optional):', '');
                
                // Submit cancellation form
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'student_book.php';
                
                const bookingIdInput = document.createElement('input');
                bookingIdInput.type = 'hidden';
                bookingIdInput.name = 'booking_id';
                bookingIdInput.value = bookingId;
                
                const reasonInput = document.createElement('input');
                reasonInput.type = 'hidden';
                reasonInput.name = 'cancellation_reason';
                reasonInput.value = reason || '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'cancel_booking';
                actionInput.value = '1';
                
                form.appendChild(bookingIdInput);
                form.appendChild(reasonInput);
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function printQRCode(qrCodeUrl) {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Print QR Code</title>
                        <style>
                            body { 
                                font-family: Arial, sans-serif; 
                                text-align: center; 
                                padding: 50px;
                            }
                            img { 
                                max-width: 300px; 
                                margin: 20px 0; 
                                border: 1px solid #ddd;
                                padding: 10px;
                            }
                            .print-info {
                                margin-top: 30px;
                                font-size: 14px;
                                color: #666;
                                border-top: 1px solid #ddd;
                                padding-top: 20px;
                            }
                            .instruction {
                                margin: 20px 0;
                                padding: 15px;
                                background-color: #f8f9fa;
                                border-radius: 5px;
                            }
                        </style>
                    </head>
                    <body>
                        <h2>MainRes Bus System</h2>
                        <h3>Boarding QR Code</h3>
                        <img src="${qrCodeUrl}" alt="QR Code">
                        <div class="instruction">
                            <p><strong>Instructions:</strong></p>
                            <p>1. Show this QR code to the driver when boarding</p>
                            <p>2. Keep this code safe for the entire trip</p>
                            <p>3. Do not share this code with others</p>
                        </div>
                        <div class="print-info">
                            Generated on: ${new Date().toLocaleString()}<br>
                            System: MainRes Bus Booking System
                        </div>
                    </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Auto-refresh page every 60 seconds for waitlist updates
        if (window.location.search.includes('filter=upcoming')) {
            setTimeout(function() {
                window.location.reload();
            }, 60000); // 60 seconds
        }
    </script>
</body>
</html>