<?php
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

// Check if user is logged in and is a driver
if (!isLoggedIn() || !isDriver()) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

$driver_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['booking_id'])) {
        $booking_id = sanitize($data['booking_id']);
        $qr_data = isset($data['qr_data']) ? sanitize($data['qr_data']) : '';
        
        // Validate QR code format
        if (strpos($qr_data, 'MainResBus|') !== 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid QR code format']);
            exit;
        }
        
        // Get booking details
        $booking_query = "SELECT b.*, u.name, u.surname, t.route, t.departure_time, t.trip_date
                         FROM bookings b
                         JOIN users u ON b.student_id = u.user_id
                         JOIN trips t ON b.trip_id = t.trip_id
                         WHERE b.booking_id = '$booking_id'";
        $booking_result = mysqli_query($conn, $booking_query);
        
        if (mysqli_num_rows($booking_result) != 1) {
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            exit;
        }
        
        $booking = mysqli_fetch_assoc($booking_result);
        
        // Check if booking is already used
        $attendance_check = "SELECT * FROM attendance WHERE booking_id = '$booking_id'";
        if (mysqli_num_rows(mysqli_query($conn, $attendance_check)) > 0) {
            // Record duplicate scan
            $insert_attendance = "INSERT INTO attendance (booking_id, scanned_by, result) 
                                  VALUES ('$booking_id', '$driver_id', 'duplicate')";
            mysqli_query($conn, $insert_attendance);
            
            echo json_encode([
                'success' => false,
                'message' => 'Duplicate scan - this booking has already been used',
                'student_name' => $booking['name'] . ' ' . $booking['surname'],
                'trip_info' => date('h:i A', strtotime($booking['departure_time'])) . ' - ' . $booking['route']
            ]);
            exit;
        }
        
        // Check if booking is valid
        if ($booking['status'] != 'confirmed') {
            // Record invalid scan
            $insert_attendance = "INSERT INTO attendance (booking_id, scanned_by, result) 
                                  VALUES ('$booking_id', '$driver_id', 'invalid')";
            mysqli_query($conn, $insert_attendance);
            
            echo json_encode([
                'success' => false,
                'message' => 'Invalid booking - status: ' . $booking['status'],
                'student_name' => $booking['name'] . ' ' . $booking['surname']
            ]);
            exit;
        }
        
        // Check if trip is today
        $trip_date = $booking['trip_date'];
        $today = date('Y-m-d');
        
        if ($trip_date != $today) {
            // Record scan with status
            $result = strtotime($trip_date) > strtotime($today) ? 'early' : 'late';
            $insert_attendance = "INSERT INTO attendance (booking_id, scanned_by, result) 
                                  VALUES ('$booking_id', '$driver_id', '$result')";
            mysqli_query($conn, $insert_attendance);
            
            $message = $result == 'early' ? 
                'Early scan - trip is not today' : 
                'Late scan - trip was on ' . date('d M Y', strtotime($trip_date));
            
            echo json_encode([
                'success' => false,
                'message' => $message,
                'student_name' => $booking['name'] . ' ' . $booking['surname'],
                'trip_info' => date('d M Y', strtotime($trip_date)) . ' at ' . date('h:i A', strtotime($booking['departure_time']))
            ]);
            exit;
        }
        
        // All checks passed - record successful attendance
        mysqli_begin_transaction($conn);
        
        try {
            // Update booking status
            $update_booking = "UPDATE bookings SET status = 'used', attended_at = NOW() 
                               WHERE booking_id = '$booking_id'";
            mysqli_query($conn, $update_booking);
            
            // Record attendance
            $insert_attendance = "INSERT INTO attendance (booking_id, scanned_by, result) 
                                  VALUES ('$booking_id', '$driver_id', 'success')";
            mysqli_query($conn, $insert_attendance);
            
            // Send notification to student
            $notification_msg = "You have successfully boarded the bus for your trip to " . $booking['route'];
            sendNotification($booking['student_id'], $notification_msg, 'success');
            
            mysqli_commit($conn);
            
            echo json_encode([
                'success' => true,
                'message' => 'Attendance recorded successfully',
                'student_name' => $booking['name'] . ' ' . $booking['surname'],
                'trip_info' => date('h:i A', strtotime($booking['departure_time'])) . ' - ' . $booking['route'],
                'scan_time' => date('H:i:s')
            ]);
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            echo json_encode(['success' => false, 'message' => 'Error recording attendance']);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'No booking ID provided']);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>