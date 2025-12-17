<?php
require_once 'config.php';
require_once 'functions.php';

// Allow CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Add to waitlist
if ($action == 'add_to_waitlist') {
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }
    
    // Only students can join waitlist
    if ($userRole != 'student') {
        echo json_encode(['success' => false, 'message' => 'Only students can join waitlist']);
        exit();
    }
    
    $scheduleId = isset($_POST['schedule_id']) ? intval($_POST['schedule_id']) : 0;
    
    if ($scheduleId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid schedule ID']);
        exit();
    }
    
    // Check if schedule exists and has seats
    $scheduleQuery = $conn->query("SELECT s.*, b.capacity 
                                  FROM schedules s 
                                  JOIN buses b ON s.bus_id = b.id 
                                  WHERE s.id = $scheduleId AND s.status = 'active'");
    
    if ($scheduleQuery->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found or inactive']);
        exit();
    }
    
    $schedule = $scheduleQuery->fetch_assoc();
    
    // Count confirmed bookings
    $confirmedBookings = $conn->query("SELECT COUNT(*) as count FROM bookings 
                                      WHERE schedule_id = $scheduleId AND status = 'confirmed'")->fetch_assoc()['count'];
    
    // Check if there are available seats
    if ($confirmedBookings < $schedule['capacity']) {
        echo json_encode(['success' => false, 'message' => 'Seats are still available. Please book normally.']);
        exit();
    }
    
    // Check if user already has a booking on this schedule
    $existingBooking = $conn->query("SELECT id FROM bookings 
                                    WHERE user_id = $userId AND schedule_id = $scheduleId 
                                    AND status IN ('confirmed', 'pending', 'waitlisted')")->fetch_assoc();
    
    if ($existingBooking) {
        echo json_encode(['success' => false, 'message' => 'You already have a booking for this schedule']);
        exit();
    }
    
    // Check if already on waitlist
    $existingWaitlist = $conn->query("SELECT id FROM waitlist 
                                     WHERE user_id = $userId AND schedule_id = $scheduleId 
                                     AND status = 'pending'")->fetch_assoc();
    
    if ($existingWaitlist) {
        echo json_encode(['success' => false, 'message' => 'You are already on the waitlist for this schedule']);
        exit();
    }
    
    // Get current waitlist position
    $waitlistCount = $conn->query("SELECT COUNT(*) as count FROM waitlist 
                                  WHERE schedule_id = $scheduleId AND status = 'pending'")->fetch_assoc()['count'];
    $position = $waitlistCount + 1;
    
    // Add to waitlist
    $stmt = $conn->prepare("INSERT INTO waitlist (user_id, schedule_id, position, status, joined_at) 
                           VALUES (?, ?, ?, 'pending', NOW())");
    $stmt->bind_param("iii", $userId, $scheduleId, $position);
    
    if ($stmt->execute()) {
        $waitlistId = $stmt->insert_id;
        
        // Send notification to user
        $userQuery = $conn->query("SELECT email, name FROM users WHERE id = $userId");
        $user = $userQuery->fetch_assoc();
        
        $subject = "Waitlist Confirmation - Bus Reservation";
        $message = "Dear " . $user['name'] . ",\n\n";
        $message .= "You have been added to the waitlist for bus schedule ID: " . $scheduleId . "\n";
        $message .= "Your position in line: " . $position . "\n";
        $message .= "We will notify you if a seat becomes available.\n\n";
        $message .= "Thank you for using our bus reservation system.";
        
        sendEmail($user['email'], $subject, $message);
        
        echo json_encode([
            'success' => true,
            'message' => 'Added to waitlist successfully',
            'waitlist_id' => $waitlistId,
            'position' => $position,
            'schedule_id' => $scheduleId
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error adding to waitlist: ' . $conn->error]);
    }
    
    $stmt->close();
    exit();
}

// Remove from waitlist
if ($action == 'remove_from_waitlist') {
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }
    
    $waitlistId = isset($_POST['waitlist_id']) ? intval($_POST['waitlist_id']) : 0;
    
    if ($waitlistId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid waitlist ID']);
        exit();
    }
    
    // Verify user owns this waitlist entry
    $verifyQuery = $conn->query("SELECT id FROM waitlist WHERE id = $waitlistId AND user_id = $userId");
    
    if ($verifyQuery->num_rows === 0 && $userRole != 'admin') {
        echo json_encode(['success' => false, 'message' => 'Waitlist entry not found or unauthorized']);
        exit();
    }
    
    // Update waitlist status to cancelled
    $stmt = $conn->prepare("UPDATE waitlist SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $waitlistId);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Removed from waitlist successfully']);
        
        // Update positions of remaining waitlist entries
        if ($userRole == 'admin') {
            $conn->query("UPDATE waitlist w1 
                         JOIN (SELECT w2.id, ROW_NUMBER() OVER (ORDER BY w2.joined_at) as new_position 
                               FROM waitlist w2 
                               WHERE w2.schedule_id = (SELECT schedule_id FROM waitlist WHERE id = $waitlistId) 
                               AND w2.status = 'pending') as w3 
                         ON w1.id = w3.id 
                         SET w1.position = w3.new_position");
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Error removing from waitlist: ' . $conn->error]);
    }
    
    $stmt->close();
    exit();
}

// Get user's waitlist status
if ($action == 'get_my_waitlist') {
    if ($userRole != 'student') {
        echo json_encode(['success' => false, 'message' => 'Only students can view waitlist']);
        exit();
    }
    
    $waitlistQuery = $conn->query("SELECT w.*, s.departure_time, r.route_name, b.bus_number 
                                  FROM waitlist w 
                                  JOIN schedules s ON w.schedule_id = s.id 
                                  JOIN routes r ON s.route_id = r.id 
                                  JOIN buses b ON s.bus_id = b.id 
                                  WHERE w.user_id = $userId AND w.status = 'pending' 
                                  ORDER BY w.position ASC");
    
    $waitlist = [];
    while ($row = $waitlistQuery->fetch_assoc()) {
        $waitlist[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'waitlist' => $waitlist,
        'count' => count($waitlist)
    ]);
    exit();
}

// Admin: Get waitlist for schedule
if ($action == 'get_schedule_waitlist') {
    if ($userRole != 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit();
    }
    
    $scheduleId = isset($_GET['schedule_id']) ? intval($_GET['schedule_id']) : 0;
    
    if ($scheduleId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid schedule ID']);
        exit();
    }
    
    $waitlistQuery = $conn->query("SELECT w.*, u.name, u.email, u.student_id, u.phone 
                                  FROM waitlist w 
                                  JOIN users u ON w.user_id = u.id 
                                  WHERE w.schedule_id = $scheduleId AND w.status = 'pending' 
                                  ORDER BY w.position ASC");
    
    $waitlist = [];
    $waitlistCount = 0;
    
    while ($row = $waitlistQuery->fetch_assoc()) {
        $waitlist[] = $row;
        $waitlistCount++;
    }
    
    // Get schedule info
    $scheduleQuery = $conn->query("SELECT s.*, r.route_name, b.bus_number, b.capacity 
                                  FROM schedules s 
                                  JOIN routes r ON s.route_id = r.id 
                                  JOIN buses b ON s.bus_id = b.id 
                                  WHERE s.id = $scheduleId");
    $schedule = $scheduleQuery->fetch_assoc();
    
    // Count confirmed bookings
    $confirmedBookings = $conn->query("SELECT COUNT(*) as count FROM bookings 
                                      WHERE schedule_id = $scheduleId AND status = 'confirmed'")->fetch_assoc()['count'];
    
    echo json_encode([
        'success' => true,
        'waitlist' => $waitlist,
        'schedule_info' => $schedule,
        'stats' => [
            'total_waitlist' => $waitlistCount,
            'confirmed_bookings' => $confirmedBookings,
            'available_seats' => max(0, $schedule['capacity'] - $confirmedBookings),
            'capacity' => $schedule['capacity']
        ]
    ]);
    exit();
}

// Admin: Promote from waitlist to booking
if ($action == 'promote_from_waitlist') {
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }
    
    if ($userRole != 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit();
    }
    
    $waitlistId = isset($_POST['waitlist_id']) ? intval($_POST['waitlist_id']) : 0;
    $scheduleId = isset($_POST['schedule_id']) ? intval($_POST['schedule_id']) : 0;
    
    if ($waitlistId <= 0 || $scheduleId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid waitlist or schedule ID']);
        exit();
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get waitlist entry
        $waitlistQuery = $conn->query("SELECT * FROM waitlist WHERE id = $waitlistId AND status = 'pending' FOR UPDATE");
        $waitlistEntry = $waitlistQuery->fetch_assoc();
        
        if (!$waitlistEntry) {
            throw new Exception('Waitlist entry not found or already processed');
        }
        
        // Check if seats are available
        $capacityQuery = $conn->query("SELECT b.capacity FROM schedules s 
                                      JOIN buses b ON s.bus_id = b.id 
                                      WHERE s.id = $scheduleId");
        $capacity = $capacityQuery->fetch_assoc()['capacity'];
        
        $confirmedCount = $conn->query("SELECT COUNT(*) as count FROM bookings 
                                       WHERE schedule_id = $scheduleId AND status = 'confirmed'")->fetch_assoc()['count'];
        
        if ($confirmedCount >= $capacity) {
            throw new Exception('No seats available');
        }
        
        // Create booking
        $bookingStmt = $conn->prepare("INSERT INTO bookings (user_id, schedule_id, booking_time, status) 
                                      VALUES (?, ?, NOW(), 'confirmed')");
        $bookingStmt->bind_param("ii", $waitlistEntry['user_id'], $scheduleId);
        $bookingStmt->execute();
        $bookingId = $bookingStmt->insert_id;
        $bookingStmt->close();
        
        // Update waitlist status
        $updateStmt = $conn->prepare("UPDATE waitlist SET status = 'promoted', promoted_at = NOW(), booking_id = ? WHERE id = ?");
        $updateStmt->bind_param("ii", $bookingId, $waitlistId);
        $updateStmt->execute();
        $updateStmt->close();
        
        // Update positions of remaining waitlist entries
        $conn->query("UPDATE waitlist w1 
                     JOIN (SELECT w2.id, ROW_NUMBER() OVER (ORDER BY w2.joined_at) as new_position 
                           FROM waitlist w2 
                           WHERE w2.schedule_id = $scheduleId AND w2.status = 'pending') as w3 
                     ON w1.id = w3.id 
                     SET w1.position = w3.new_position");
        
        // Get user info for notification
        $userQuery = $conn->query("SELECT email, name FROM users WHERE id = " . $waitlistEntry['user_id']);
        $user = $userQuery->fetch_assoc();
        
        // Send notification
        $subject = "Waitlist Promotion - Seat Confirmed!";
        $message = "Dear " . $user['name'] . ",\n\n";
        $message .= "Great news! A seat has become available and you have been promoted from the waitlist.\n";
        $message .= "Your booking has been confirmed for schedule ID: " . $scheduleId . "\n";
        $message .= "Booking ID: " . $bookingId . "\n\n";
        $message .= "Please log in to view your booking details.\n\n";
        $message .= "Thank you for using our bus reservation system.";
        
        sendEmail($user['email'], $subject, $message);
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Waitlist entry promoted successfully',
            'booking_id' => $bookingId,
            'waitlist_id' => $waitlistId
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit();
}

// Check waitlist status
if ($action == 'check_waitlist_status') {
    $scheduleId = isset($_GET['schedule_id']) ? intval($_GET['schedule_id']) : 0;
    
    if ($scheduleId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid schedule ID']);
        exit();
    }
    
    // Get user's position if on waitlist
    $userPosition = 0;
    $totalWaitlist = 0;
    
    if ($userRole == 'student') {
        $userWaitlist = $conn->query("SELECT position FROM waitlist 
                                     WHERE user_id = $userId AND schedule_id = $scheduleId AND status = 'pending'")->fetch_assoc();
        
        if ($userWaitlist) {
            $userPosition = $userWaitlist['position'];
        }
    }
    
    // Get total waitlist count
    $totalResult = $conn->query("SELECT COUNT(*) as count FROM waitlist 
                                WHERE schedule_id = $scheduleId AND status = 'pending'")->fetch_assoc();
    $totalWaitlist = $totalResult['count'];
    
    // Get available seats
    $capacityQuery = $conn->query("SELECT b.capacity FROM schedules s 
                                  JOIN buses b ON s.bus_id = b.id 
                                  WHERE s.id = $scheduleId");
    $capacity = $capacityQuery->fetch_assoc()['capacity'];
    
    $confirmedCount = $conn->query("SELECT COUNT(*) as count FROM bookings 
                                   WHERE schedule_id = $scheduleId AND status = 'confirmed'")->fetch_assoc()['count'];
    
    $availableSeats = max(0, $capacity - $confirmedCount);
    
    echo json_encode([
        'success' => true,
        'user_position' => $userPosition,
        'total_waitlist' => $totalWaitlist,
        'available_seats' => $availableSeats,
        'is_on_waitlist' => ($userPosition > 0)
    ]);
    exit();
}

// Function to send email
function sendEmail($to, $subject, $message) {
    // In a real application, use PHPMailer or similar
    $headers = "From: bus-system@example.com\r\n";
    $headers .= "Reply-To: no-reply@example.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    // For demo purposes, we'll just log it
    error_log("Email to $to - Subject: $subject\nMessage: $message");
    
    // Uncomment to actually send email
    // mail($to, $subject, $message, $headers);
}

// Default response
echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit();
?>