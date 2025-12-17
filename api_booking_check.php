<?php
require_once 'config.php';
require_once 'functions.php';

// Allow CORS for API requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session for authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in (for most actions)
$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? $_SESSION['user_id'] : 0;
$userRole = $isLoggedIn ? $_SESSION['role'] : null;

// Get action from request
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// Public actions (don't require login)
$publicActions = ['check_availability', 'get_schedule_info'];

// Check authentication for protected actions
if (!in_array($action, $publicActions) && !$isLoggedIn) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Handle different actions
switch ($action) {
    
    // 1. Check seat availability
    case 'check_availability':
        checkSeatAvailability();
        break;
        
    // 2. Check booking conflict
    case 'check_conflict':
        checkBookingConflict();
        break;
        
    // 3. Validate booking data
    case 'validate_booking':
        validateBooking();
        break;
        
    // 4. Get booking status
    case 'get_booking_status':
        getBookingStatus();
        break;
        
    // 5. Get booking statistics
    case 'get_stats':
        getBookingStats();
        break;
        
    // 6. Get schedule information
    case 'get_schedule_info':
        getScheduleInfo();
        break;
        
    // 7. Check waitlist status
    case 'check_waitlist':
        checkWaitlistStatus();
        break;
        
    // 8. Validate cancellation
    case 'validate_cancellation':
        validateCancellation();
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action specified']);
        exit();
}

// =================== FUNCTION DEFINITIONS ===================

/**
 * Check seat availability for a schedule
 */
function checkSeatAvailability() {
    global $conn;
    
    $scheduleId = isset($_GET['schedule_id']) ? intval($_GET['schedule_id']) : 0;
    $date = isset($_GET['date']) ? sanitize_input($_GET['date']) : date('Y-m-d');
    
    if ($scheduleId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid schedule ID']);
        exit();
    }
    
    // Get schedule details with bus capacity
    $query = "SELECT s.*, b.capacity, b.bus_number, r.route_name, r.start_point, r.end_point
              FROM schedules s 
              JOIN buses b ON s.bus_id = b.id 
              JOIN routes r ON s.route_id = r.id 
              WHERE s.id = ? AND s.status = 'active'";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $scheduleId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found or inactive']);
        exit();
    }
    
    $schedule = $result->fetch_assoc();
    $stmt->close();
    
    // Count confirmed bookings
    $bookingQuery = "SELECT COUNT(*) as booked_seats FROM bookings 
                     WHERE schedule_id = ? AND status = 'confirmed'";
    
    $bookingStmt = $conn->prepare($bookingQuery);
    $bookingStmt->bind_param("i", $scheduleId);
    $bookingStmt->execute();
    $bookingResult = $bookingStmt->get_result();
    $bookingData = $bookingResult->fetch_assoc();
    $bookingStmt->close();
    
    $bookedSeats = $bookingData['booked_seats'];
    $availableSeats = $schedule['capacity'] - $bookedSeats;
    $percentageFull = ($bookedSeats / $schedule['capacity']) * 100;
    
    // Determine availability status
    $availabilityStatus = 'available';
    if ($availableSeats <= 0) {
        $availabilityStatus = 'full';
    } elseif ($percentageFull >= 90) {
        $availabilityStatus = 'almost_full';
    } elseif ($percentageFull >= 70) {
        $availabilityStatus = 'limited';
    }
    
    // Check if user already booked this schedule
    $userBooking = null;
    if (isset($_SESSION['user_id'])) {
        $userQuery = "SELECT id, status, attendance_status FROM bookings 
                     WHERE user_id = ? AND schedule_id = ?";
        $userStmt = $conn->prepare($userQuery);
        $userStmt->bind_param("ii", $_SESSION['user_id'], $scheduleId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userBooking = $userResult->fetch_assoc();
        $userStmt->close();
    }
    
    // Check waitlist count
    $waitlistQuery = "SELECT COUNT(*) as waitlist_count FROM waitlist 
                     WHERE schedule_id = ? AND status = 'pending'";
    $waitlistStmt = $conn->prepare($waitlistQuery);
    $waitlistStmt->bind_param("i", $scheduleId);
    $waitlistStmt->execute();
    $waitlistResult = $waitlistStmt->get_result();
    $waitlistData = $waitlistResult->fetch_assoc();
    $waitlistStmt->close();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'schedule_info' => [
                'id' => $schedule['id'],
                'route_name' => $schedule['route_name'],
                'start_point' => $schedule['start_point'],
                'end_point' => $schedule['end_point'],
                'departure_time' => $schedule['departure_time'],
                'bus_number' => $schedule['bus_number']
            ],
            'availability' => [
                'capacity' => $schedule['capacity'],
                'booked' => $bookedSeats,
                'available' => $availableSeats,
                'percentage_full' => round($percentageFull, 1),
                'status' => $availabilityStatus,
                'waitlist_count' => $waitlistData['waitlist_count']
            ],
            'user_booking' => $userBooking,
            'can_book' => ($availableSeats > 0 && !$userBooking && $availabilityStatus != 'full')
        ]
    ]);
}

/**
 * Check if user has booking conflicts for a schedule
 */
function checkBookingConflict() {
    global $conn, $userId;
    
    $scheduleId = isset($_GET['schedule_id']) ? intval($_GET['schedule_id']) : 0;
    
    if ($scheduleId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid schedule ID']);
        exit();
    }
    
    // Get the selected schedule's departure time
    $scheduleQuery = "SELECT departure_time FROM schedules WHERE id = ?";
    $scheduleStmt = $conn->prepare($scheduleQuery);
    $scheduleStmt->bind_param("i", $scheduleId);
    $scheduleStmt->execute();
    $scheduleResult = $scheduleStmt->get_result();
    
    if ($scheduleResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found']);
        exit();
    }
    
    $schedule = $scheduleResult->fetch_assoc();
    $scheduleStmt->close();
    
    $selectedTime = strtotime($schedule['departure_time']);
    $selectedDate = date('Y-m-d', $selectedTime);
    $selectedDateTime = $schedule['departure_time'];
    
    // Check for other bookings on the same day
    $conflictQuery = "SELECT b.id, b.status, s.departure_time, r.route_name 
                     FROM bookings b 
                     JOIN schedules s ON b.schedule_id = s.id 
                     JOIN routes r ON s.route_id = r.id 
                     WHERE b.user_id = ? 
                     AND b.status IN ('confirmed', 'pending') 
                     AND DATE(s.departure_time) = ? 
                     AND b.schedule_id != ?";
    
    $conflictStmt = $conn->prepare($conflictQuery);
    $conflictStmt->bind_param("isi", $userId, $selectedDate, $scheduleId);
    $conflictStmt->execute();
    $conflictResult = $conflictStmt->get_result();
    
    $conflicts = [];
    while ($row = $conflictResult->fetch_assoc()) {
        $conflicts[] = $row;
    }
    $conflictStmt->close();
    
    // Check for time overlap (if we want to be strict about time windows)
    $overlappingConflicts = [];
    $bufferMinutes = 30; // 30-minute buffer before and after
        
    foreach ($conflicts as $conflict) {
        $conflictTime = strtotime($conflict['departure_time']);
        $timeDiff = abs($selectedTime - $conflictTime) / 60; // Difference in minutes
        
        if ($timeDiff < 60) { // If less than 60 minutes apart
            $conflict['time_difference'] = $timeDiff;
            $overlappingConflicts[] = $conflict;
        }
    }
    
    echo json_encode([
        'success' => true,
        'has_conflict' => !empty($conflicts),
        'has_time_conflict' => !empty($overlappingConflicts),
        'total_conflicts' => count($conflicts),
        'time_conflicts' => count($overlappingConflicts),
        'conflicts' => $conflicts,
        'time_conflict_details' => $overlappingConflicts,
        'selected_schedule' => [
            'date' => $selectedDate,
            'time' => $selectedDateTime,
            'timestamp' => $selectedTime
        ]
    ]);
}

/**
 * Validate booking data before creation
 */
function validateBooking() {
    global $conn, $userId, $userRole;
    
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }
    
    $scheduleId = isset($_POST['schedule_id']) ? intval($_POST['schedule_id']) : 0;
    $passengerCount = isset($_POST['passenger_count']) ? intval($_POST['passenger_count']) : 1;
    
    if ($scheduleId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid schedule ID']);
        exit();
    }
    
    if ($passengerCount < 1 || $passengerCount > 5) {
        echo json_encode(['success' => false, 'message' => 'Passenger count must be between 1 and 5']);
        exit();
    }
    
    // Validation results array
    $validations = [];
    
    // 1. Check if schedule exists and is active
    $scheduleQuery = "SELECT s.*, b.capacity, b.bus_number 
                     FROM schedules s 
                     JOIN buses b ON s.bus_id = b.id 
                     WHERE s.id = ? AND s.status = 'active'";
    
    $scheduleStmt = $conn->prepare($scheduleQuery);
    $scheduleStmt->bind_param("i", $scheduleId);
    $scheduleStmt->execute();
    $scheduleResult = $scheduleStmt->get_result();
    
    if ($scheduleResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found or inactive']);
        exit();
    }
    
    $schedule = $scheduleResult->fetch_assoc();
    $scheduleStmt->close();
    
    $validations['schedule_active'] = true;
    
    // 2. Check seat availability
    $bookingQuery = "SELECT COUNT(*) as booked_seats FROM bookings 
                    WHERE schedule_id = ? AND status = 'confirmed'";
    
    $bookingStmt = $conn->prepare($bookingQuery);
    $bookingStmt->bind_param("i", $scheduleId);
    $bookingStmt->execute();
    $bookingResult = $bookingStmt->get_result();
    $bookingData = $bookingResult->fetch_assoc();
    $bookingStmt->close();
    
    $bookedSeats = $bookingData['booked_seats'];
    $availableSeats = $schedule['capacity'] - $bookedSeats;
    
    if ($availableSeats < $passengerCount) {
        $validations['seat_availability'] = false;
        $validations['available_seats'] = $availableSeats;
    } else {
        $validations['seat_availability'] = true;
        $validations['available_seats'] = $availableSeats;
    }
    
    // 3. Check if user already has booking for this schedule
    $existingQuery = "SELECT id FROM bookings 
                     WHERE user_id = ? AND schedule_id = ? 
                     AND status IN ('confirmed', 'pending')";
    
    $existingStmt = $conn->prepare($existingQuery);
    $existingStmt->bind_param("ii", $userId, $scheduleId);
    $existingStmt->execute();
    $existingResult = $existingStmt->get_result();
    
    $validations['no_duplicate_booking'] = ($existingResult->num_rows === 0);
    $existingStmt->close();
    
    // 4. Check for time conflicts (same day)
    $scheduleTime = strtotime($schedule['departure_time']);
    $scheduleDate = date('Y-m-d', $scheduleTime);
    
    $conflictQuery = "SELECT b.id FROM bookings b 
                     JOIN schedules s ON b.schedule_id = s.id 
                     WHERE b.user_id = ? 
                     AND b.status IN ('confirmed', 'pending') 
                     AND DATE(s.departure_time) = ?";
    
    $conflictStmt = $conn->prepare($conflictQuery);
    $conflictStmt->bind_param("is", $userId, $scheduleDate);
    $conflictStmt->execute();
    $conflictResult = $conflictStmt->get_result();
    
    $validations['no_same_day_conflict'] = ($conflictResult->num_rows === 0);
    $conflictStmt->close();
    
    // 5. Check if schedule departure time is in the past
    $validations['not_past_schedule'] = (time() < $scheduleTime);
    
    // 6. Check booking cutoff time (2 hours before departure)
    $cutoffTime = $scheduleTime - (2 * 3600); // 2 hours before
    $validations['before_cutoff_time'] = (time() < $cutoffTime);
    $validations['cutoff_time'] = date('Y-m-d H:i:s', $cutoffTime);
    
    // 7. Check if user account is active
    $userQuery = "SELECT status FROM users WHERE id = ?";
    $userStmt = $conn->prepare($userQuery);
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $userData = $userResult->fetch_assoc();
    $userStmt->close();
    
    $validations['user_active'] = ($userData['status'] == 'active');
    
    // Determine if all validations passed
    $allValid = true;
    foreach ($validations as $key => $value) {
        if (strpos($key, 'validation_') === false && is_bool($value) && !$value) {
            $allValid = false;
            break;
        }
    }
    
    // Provide helpful messages for failed validations
    $messages = [];
    if (!$validations['seat_availability']) {
        $messages[] = "Only {$validations['available_seats']} seat(s) available";
    }
    if (!$validations['no_duplicate_booking']) {
        $messages[] = "You already have a booking for this schedule";
    }
    if (!$validations['no_same_day_conflict']) {
        $messages[] = "You already have a booking on the same day";
    }
    if (!$validations['not_past_schedule']) {
        $messages[] = "Cannot book past schedules";
    }
    if (!$validations['before_cutoff_time']) {
        $messages[] = "Booking closed. Cutoff was at " . $validations['cutoff_time'];
    }
    if (!$validations['user_active']) {
        $messages[] = "Your account is not active";
    }
    
    echo json_encode([
        'success' => $allValid,
        'all_valid' => $allValid,
        'validations' => $validations,
        'messages' => $messages,
        'schedule_info' => [
            'id' => $scheduleId,
            'departure_time' => $schedule['departure_time'],
            'bus_number' => $schedule['bus_number'],
            'capacity' => $schedule['capacity']
        ],
        'can_proceed' => $allValid
    ]);
}

/**
 * Get booking status by ID
 */
function getBookingStatus() {
    global $conn, $userId, $userRole;
    
    $bookingId = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
    
    if ($bookingId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
        exit();
    }
    
    $query = "SELECT b.*, s.departure_time, s.bus_id, r.route_name, 
              r.start_point, r.end_point, u.name as user_name, 
              u.email, u.phone, u.student_id,
              bus.bus_number, bus.capacity
              FROM bookings b 
              JOIN schedules s ON b.schedule_id = s.id 
              JOIN routes r ON s.route_id = r.id 
              JOIN users u ON b.user_id = u.id 
              JOIN buses bus ON s.bus_id = bus.id 
              WHERE b.id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit();
    }
    
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    // Check authorization
    if ($booking['user_id'] != $userId && $userRole != 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access to this booking']);
        exit();
    }
    
    // Calculate time until departure
    $departureTime = strtotime($booking['departure_time']);
    $currentTime = time();
    $timeUntilDeparture = $departureTime - $currentTime;
    
    $timeStatus = 'past';
    if ($timeUntilDeparture > 0) {
        if ($timeUntilDeparture < 3600) {
            $timeStatus = 'within_hour';
        } elseif ($timeUntilDeparture < 7200) {
            $timeStatus = 'within_2_hours';
        } elseif ($timeUntilDeparture < 86400) {
            $timeStatus = 'today';
        } else {
            $timeStatus = 'future';
        }
    }
    
    // Get status information
    $statusInfo = getBookingStatusInfo($booking['status']);
    $attendanceInfo = getAttendanceStatusInfo($booking['attendance_status']);
    
    // Check if QR code can be generated
    $canGenerateQR = false;
    if ($booking['status'] == 'confirmed' && 
        $booking['attendance_status'] == 'absent' &&
        $timeStatus == 'today' &&
        $timeUntilDeparture > 0 &&
        $timeUntilDeparture < 10800) { // Within 3 hours of departure
        $canGenerateQR = true;
    }
    
    // Check if cancellation is allowed
    $canCancel = false;
    $cancellationDeadline = strtotime('-4 hours', $departureTime);
    if ($booking['status'] == 'confirmed' && 
        $currentTime < $cancellationDeadline) {
        $canCancel = true;
    }
    
    echo json_encode([
        'success' => true,
        'booking' => $booking,
        'status_info' => $statusInfo,
        'attendance_info' => $attendanceInfo,
        'time_info' => [
            'departure_timestamp' => $departureTime,
            'departure_formatted' => date('D, M j, Y \a\t h:i A', $departureTime),
            'time_until_departure' => $timeUntilDeparture,
            'time_until_formatted' => formatTimeDifference($timeUntilDeparture),
            'time_status' => $timeStatus,
            'current_time' => $currentTime
        ],
        'permissions' => [
            'can_generate_qr' => $canGenerateQR,
            'can_cancel' => $canCancel,
            'cancellation_deadline' => date('Y-m-d H:i:s', $cancellationDeadline),
            'can_check_in' => ($booking['attendance_status'] == 'absent' && $timeStatus == 'within_hour')
        ]
    ]);
}

/**
 * Get booking statistics
 */
function getBookingStats() {
    global $conn, $userId, $userRole;
    
    $timeframe = isset($_GET['timeframe']) ? sanitize_input($_GET['timeframe']) : 'today';
    $startDate = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : '';
    $endDate = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : '';
    
    // Set date range based on timeframe
    if ($startDate && $endDate) {
        // Use provided dates
    } else {
        switch ($timeframe) {
            case 'today':
                $startDate = date('Y-m-d');
                $endDate = date('Y-m-d');
                break;
            case 'yesterday':
                $startDate = date('Y-m-d', strtotime('-1 day'));
                $endDate = date('Y-m-d', strtotime('-1 day'));
                break;
            case 'week':
                $startDate = date('Y-m-d', strtotime('-7 days'));
                $endDate = date('Y-m-d');
                break;
            case 'month':
                $startDate = date('Y-m-d', strtotime('-30 days'));
                $endDate = date('Y-m-d');
                break;
            case 'year':
                $startDate = date('Y-m-d', strtotime('-365 days'));
                $endDate = date('Y-m-d');
                break;
            default:
                $startDate = date('Y-m-d');
                $endDate = date('Y-m-d');
        }
    }
    
    // Different stats for different roles
    if ($userRole == 'admin') {
        getAdminStats($startDate, $endDate);
    } elseif ($userRole == 'student') {
        getStudentStats($startDate, $endDate);
    } elseif ($userRole == 'driver') {
        getDriverStats($startDate, $endDate);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid user role for statistics']);
    }
}

/**
 * Get admin booking statistics
 */
function getAdminStats($startDate, $endDate) {
    global $conn;
    
    // Total bookings
    $totalQuery = "SELECT COUNT(*) as total_bookings FROM bookings 
                  WHERE DATE(booking_time) BETWEEN ? AND ?";
    $totalStmt = $conn->prepare($totalQuery);
    $totalStmt->bind_param("ss", $startDate, $endDate);
    $totalStmt->execute();
    $totalResult = $totalStmt->get_result();
    $totalData = $totalResult->fetch_assoc();
    $totalStmt->close();
    
    // Status breakdown
    $statusQuery = "SELECT status, COUNT(*) as count FROM bookings 
                   WHERE DATE(booking_time) BETWEEN ? AND ? 
                   GROUP BY status";
    $statusStmt = $conn->prepare($statusQuery);
    $statusStmt->bind_param("ss", $startDate, $endDate);
    $statusStmt->execute();
    $statusResult = $statusStmt->get_result();
    
    $statusData = [];
    while ($row = $statusResult->fetch_assoc()) {
        $statusData[$row['status']] = $row['count'];
    }
    $statusStmt->close();
    
    // Daily booking trend (last 7 days)
    $trendQuery = "SELECT DATE(booking_time) as date, COUNT(*) as count 
                  FROM bookings 
                  WHERE DATE(booking_time) BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND ? 
                  GROUP BY DATE(booking_time) 
                  ORDER BY date";
    $trendStmt = $conn->prepare($trendQuery);
    $trendStmt->bind_param("ss", $endDate, $endDate);
    $trendStmt->execute();
    $trendResult = $trendStmt->get_result();
    
    $trendData = [];
    while ($row = $trendResult->fetch_assoc()) {
        $trendData[] = $row;
    }
    $trendStmt->close();
    
    // Top routes
    $routesQuery = "SELECT r.route_name, COUNT(b.id) as bookings 
                   FROM bookings b 
                   JOIN schedules s ON b.schedule_id = s.id 
                   JOIN routes r ON s.route_id = r.id 
                   WHERE DATE(b.booking_time) BETWEEN ? AND ? 
                   AND b.status = 'confirmed' 
                   GROUP BY r.id 
                   ORDER BY bookings DESC 
                   LIMIT 5";
    $routesStmt = $conn->prepare($routesQuery);
    $routesStmt->bind_param("ss", $startDate, $endDate);
    $routesStmt->execute();
    $routesResult = $routesStmt->get_result();
    
    $routesData = [];
    while ($row = $routesResult->fetch_assoc()) {
        $routesData[] = $row;
    }
    $routesStmt->close();
    
    // Revenue (if payment system is implemented)
    $revenueQuery = "SELECT COALESCE(SUM(amount_paid), 0) as total_revenue FROM payments 
                    WHERE DATE(payment_time) BETWEEN ? AND ? 
                    AND status = 'completed'";
    $revenueStmt = $conn->prepare($revenueQuery);
    $revenueStmt->bind_param("ss", $startDate, $endDate);
    $revenueStmt->execute();
    $revenueResult = $revenueStmt->get_result();
    $revenueData = $revenueResult->fetch_assoc();
    $revenueStmt->close();
    
    echo json_encode([
        'success' => true,
        'timeframe' => [
            'start' => $startDate,
            'end' => $endDate,
            'days' => round((strtotime($endDate) - strtotime($startDate)) / (60 * 60 * 24)) + 1
        ],
        'stats' => [
            'total_bookings' => $totalData['total_bookings'],
            'status_breakdown' => $statusData,
            'daily_trend' => $trendData,
            'top_routes' => $routesData,
            'revenue' => $revenueData['total_revenue']
        ],
        'summary' => [
            'avg_daily_bookings' => $totalData['total_bookings'] / max(1, round((strtotime($endDate) - strtotime($startDate)) / (60 * 60 * 24)) + 1),
            'confirmation_rate' => isset($statusData['confirmed']) ? ($statusData['confirmed'] / $totalData['total_bookings'] * 100) : 0,
            'cancellation_rate' => isset($statusData['cancelled']) ? ($statusData['cancelled'] / $totalData['total_bookings'] * 100) : 0
        ]
    ]);
}

/**
 * Get student booking statistics
 */
function getStudentStats($startDate, $endDate) {
    global $conn, $userId;
    
    // Total bookings
    $totalQuery = "SELECT COUNT(*) as total_bookings FROM bookings 
                  WHERE user_id = ? AND DATE(booking_time) BETWEEN ? AND ?";
    $totalStmt = $conn->prepare($totalQuery);
    $totalStmt->bind_param("iss", $userId, $startDate, $endDate);
    $totalStmt->execute();
    $totalResult = $totalStmt->get_result();
    $totalData = $totalResult->fetch_assoc();
    $totalStmt->close();
    
    // Status breakdown
    $statusQuery = "SELECT status, COUNT(*) as count FROM bookings 
                   WHERE user_id = ? AND DATE(booking_time) BETWEEN ? AND ? 
                   GROUP BY status";
    $statusStmt = $conn->prepare($statusQuery);
    $statusStmt->bind_param("iss", $userId, $startDate, $endDate);
    $statusStmt->execute();
    $statusResult = $statusStmt->get_result();
    
    $statusData = [];
    while ($row = $statusResult->fetch_assoc()) {
        $statusData[$row['status']] = $row['count'];
    }
    $statusStmt->close();
    
    // Upcoming bookings
    $upcomingQuery = "SELECT COUNT(*) as upcoming FROM bookings b 
                     JOIN schedules s ON b.schedule_id = s.id 
                     WHERE b.user_id = ? AND b.status = 'confirmed' 
                     AND s.departure_time > NOW()";
    $upcomingStmt = $conn->prepare($upcomingQuery);
    $upcomingStmt->bind_param("i", $userId);
    $upcomingStmt->execute();
    $upcomingResult = $upcomingStmt->get_result();
    $upcomingData = $upcomingResult->fetch_assoc();
    $upcomingStmt->close();
    
    // Attendance rate
    $attendanceQuery = "SELECT attendance_status, COUNT(*) as count FROM bookings 
                       WHERE user_id = ? AND status = 'confirmed' 
                       AND attendance_status IN ('present', 'absent') 
                       GROUP BY attendance_status";
    $attendanceStmt = $conn->prepare($attendanceQuery);
    $attendanceStmt->bind_param("i", $userId);
    $attendanceStmt->execute();
    $attendanceResult = $attendanceStmt->get_result();
    
    $attendanceData = ['present' => 0, 'absent' => 0];
    while ($row = $attendanceResult->fetch_assoc()) {
        $attendanceData[$row['attendance_status']] = $row['count'];
    }
    $attendanceStmt->close();
    
    $totalTrips = $attendanceData['present'] + $attendanceData['absent'];
    $attendanceRate = $totalTrips > 0 ? ($attendanceData['present'] / $totalTrips * 100) : 0;
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_bookings' => $totalData['total_bookings'],
            'status_breakdown' => $statusData,
            'upcoming_bookings' => $upcomingData['upcoming'],
            'attendance' => $attendanceData,
            'attendance_rate' => round($attendanceRate, 1)
        ],
        'summary' => [
            'booking_frequency' => $totalData['total_bookings'] > 0 ? 
                round($totalData['total_bookings'] / max(1, round((strtotime($endDate) - strtotime($startDate)) / (60 * 60 * 24)) + 1), 2) : 0,
            'success_rate' => isset($statusData['confirmed']) ? 
                round(($statusData['confirmed'] / $totalData['total_bookings'] * 100), 1) : 0
        ]
    ]);
}

/**
 * Get driver booking statistics
 */
function getDriverStats($startDate, $endDate) {
    global $conn, $userId;
    
    // Only include schedules where user is the driver
    $totalQuery = "SELECT COUNT(DISTINCT s.id) as total_trips 
                  FROM schedules s 
                  WHERE s.driver_id = ? 
                  AND DATE(s.departure_time) BETWEEN ? AND ?";
    $totalStmt = $conn->prepare($totalQuery);
    $totalStmt->bind_param("iss", $userId, $startDate, $endDate);
    $totalStmt->execute();
    $totalResult = $totalStmt->get_result();
    $totalData = $totalResult->fetch_assoc();
    $totalStmt->close();
    
    // Passenger statistics
    $passengerQuery = "SELECT COUNT(b.id) as total_passengers, 
                      SUM(CASE WHEN b.attendance_status = 'present' THEN 1 ELSE 0 END) as present_passengers,
                      SUM(CASE WHEN b.attendance_status = 'absent' THEN 1 ELSE 0 END) as absent_passengers
                      FROM bookings b 
                      JOIN schedules s ON b.schedule_id = s.id 
                      WHERE s.driver_id = ? 
                      AND b.status = 'confirmed' 
                      AND DATE(s.departure_time) BETWEEN ? AND ?";
    $passengerStmt = $conn->prepare($passengerQuery);
    $passengerStmt->bind_param("iss", $userId, $startDate, $endDate);
    $passengerStmt->execute();
    $passengerResult = $passengerStmt->get_result();
    $passengerData = $passengerResult->fetch_assoc();
    $passengerStmt->close();
    
    // Average load factor
    $loadQuery = "SELECT AVG(load_percentage) as avg_load FROM (
                  SELECT (COUNT(b.id) / bus.capacity * 100) as load_percentage
                  FROM schedules s 
                  JOIN buses bus ON s.bus_id = bus.id 
                  LEFT JOIN bookings b ON s.id = b.schedule_id AND b.status = 'confirmed'
                  WHERE s.driver_id = ? 
                  AND DATE(s.departure_time) BETWEEN ? AND ? 
                  GROUP BY s.id) as loads";
    $loadStmt = $conn->prepare($loadQuery);
    $loadStmt->bind_param("iss", $userId, $startDate, $endDate);
    $loadStmt->execute();
    $loadResult = $loadStmt->get_result();
    $loadData = $loadResult->fetch_assoc();
    $loadStmt->close();
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_trips' => $totalData['total_trips'],
            'passenger_stats' => $passengerData,
            'avg_load_factor' => round($loadData['avg_load'] ?? 0, 1),
            'attendance_rate' => $passengerData['total_passengers'] > 0 ? 
                round(($passengerData['present_passengers'] / $passengerData['total_passengers'] * 100), 1) : 0
        ]
    ]);
}

/**
 * Get schedule information
 */
function getScheduleInfo() {
    global $conn;
    
    $scheduleId = isset($_GET['schedule_id']) ? intval($_GET['schedule_id']) : 0;
    $date = isset($_GET['date']) ? sanitize_input($_GET['date']) : date('Y-m-d');
    
    if ($scheduleId <= 0) {
        // If no schedule ID, return schedules for a specific date
        $routeId = isset($_GET['route_id']) ? intval($_GET['route_id']) : 0;
        
        $query = "SELECT s.*, r.route_name, r.start_point, r.end_point, 
                 b.bus_number, b.capacity, 
                 (SELECT COUNT(*) FROM bookings bk WHERE bk.schedule_id = s.id AND bk.status = 'confirmed') as booked_seats
                 FROM schedules s 
                 JOIN routes r ON s.route_id = r.id 
                 JOIN buses b ON s.bus_id = b.id 
                 WHERE DATE(s.departure_time) = ? 
                 AND s.status = 'active'";
        
        $params = [$date];
        $types = "s";
        
        if ($routeId > 0) {
            $query .= " AND s.route_id = ?";
            $params[] = $routeId;
            $types .= "i";
        }
        
        $query .= " ORDER BY s.departure_time ASC";
        
        $stmt = $conn->prepare($query);
        if ($routeId > 0) {
            $stmt->bind_param($types, $date, $routeId);
        } else {
            $stmt->bind_param($types, $date);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $schedules = [];
        while ($row = $result->fetch_assoc()) {
            $availableSeats = $row['capacity'] - $row['booked_seats'];
            $row['available_seats'] = $availableSeats;
            $row['is_available'] = ($availableSeats > 0);
            $schedules[] = $row;
        }
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'date' => $date,
            'schedules' => $schedules,
            'count' => count($schedules)
        ]);
        
    } else {
        // Get specific schedule details
        $query = "SELECT s.*, r.route_name, r.start_point, r.end_point, 
                 b.bus_number, b.capacity, b.features,
                 d.name as driver_name, d.phone as driver_phone,
                 (SELECT COUNT(*) FROM bookings bk WHERE bk.schedule_id = s.id AND bk.status = 'confirmed') as booked_seats
                 FROM schedules s 
                 JOIN routes r ON s.route_id = r.id 
                 JOIN buses b ON s.bus_id = b.id 
                 LEFT JOIN users d ON s.driver_id = d.id 
                 WHERE s.id = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $scheduleId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Schedule not found']);
            exit();
        }
        
        $schedule = $result->fetch_assoc();
        $stmt->close();
        
        // Calculate availability
        $availableSeats = $schedule['capacity'] - $schedule['booked_seats'];
        $loadPercentage = ($schedule['booked_seats'] / $schedule['capacity']) * 100;
        
        // Determine availability status
        $availability = 'available';
        if ($availableSeats <= 0) {
            $availability = 'full';
        } elseif ($loadPercentage >= 90) {
            $availability = 'almost_full';
        } elseif ($loadPercentage >= 70) {
            $availability = 'limited';
        }
        
        // Parse bus features
        $features = !empty($schedule['features']) ? json_decode($schedule['features'], true) : [];
        
        echo json_encode([
            'success' => true,
            'schedule' => [
                'id' => $schedule['id'],
                'route_name' => $schedule['route_name'],
                'start_point' => $schedule['start_point'],
                'end_point' => $schedule['end_point'],
                'departure_time' => $schedule['departure_time'],
                'estimated_duration' => $schedule['estimated_duration'],
                'bus_number' => $schedule['bus_number'],
                'capacity' => $schedule['capacity'],
                'driver' => $schedule['driver_name'] ? [
                    'name' => $schedule['driver_name'],
                    'phone' => $schedule['driver_phone']
                ] : null,
                'features' => $features,
                'status' => $schedule['status']
            ],
            'availability' => [
                'booked' => $schedule['booked_seats'],
                'available' => $availableSeats,
                'load_percentage' => round($loadPercentage, 1),
                'status' => $availability
            ]
        ]);
    }
}

/**
 * Check waitlist status for a schedule
 */
function checkWaitlistStatus() {
    global $conn, $userId, $userRole;
    
    $scheduleId = isset($_GET['schedule_id']) ? intval($_GET['schedule_id']) : 0;
    
    if ($scheduleId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid schedule ID']);
        exit();
    }
    
    // Check if schedule exists
    $scheduleQuery = "SELECT id FROM schedules WHERE id = ?";
    $scheduleStmt = $conn->prepare($scheduleQuery);
    $scheduleStmt->bind_param("i", $scheduleId);
    $scheduleStmt->execute();
    $scheduleResult = $scheduleStmt->get_result();
    
    if ($scheduleResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found']);
        exit();
    }
    $scheduleStmt->close();
    
    // Get user's waitlist position
    $userPosition = 0;
    $userWaitlistId = 0;
    
    if ($userRole == 'student') {
        $userQuery = "SELECT id, position FROM waitlist 
                     WHERE user_id = ? AND schedule_id = ? AND status = 'pending'";
        $userStmt = $conn->prepare($userQuery);
        $userStmt->bind_param("ii", $userId, $scheduleId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        
        if ($userResult->num_rows > 0) {
            $userData = $userResult->fetch_assoc();
            $userPosition = $userData['position'];
            $userWaitlistId = $userData['id'];
        }
        $userStmt->close();
    }
    
    // Get total waitlist count
    $totalQuery = "SELECT COUNT(*) as total FROM waitlist 
                  WHERE schedule_id = ? AND status = 'pending'";
    $totalStmt = $conn->prepare($totalQuery);
    $totalStmt->bind_param("i", $scheduleId);
    $totalStmt->execute();
    $totalResult = $totalStmt->get_result();
    $totalData = $totalResult->fetch_assoc();
    $totalStmt->close();
    
    // Get available seats
    $capacityQuery = "SELECT b.capacity FROM schedules s 
                     JOIN buses b ON s.bus_id = b.id 
                     WHERE s.id = ?";
    $capacityStmt = $conn->prepare($capacityQuery);
    $capacityStmt->bind_param("i", $scheduleId);
    $capacityStmt->execute();
    $capacityResult = $capacityStmt->get_result();
    $capacityData = $capacityResult->fetch_assoc();
    $capacityStmt->close();
    
    $confirmedQuery = "SELECT COUNT(*) as confirmed FROM bookings 
                      WHERE schedule_id = ? AND status = 'confirmed'";
    $confirmedStmt = $conn->prepare($confirmedQuery);
    $confirmedStmt->bind_param("i", $scheduleId);
    $confirmedStmt->execute();
    $confirmedResult = $confirmedStmt->get_result();
    $confirmedData = $confirmedResult->fetch_assoc();
    $confirmedStmt->close();
    
    $availableSeats = max(0, $capacityData['capacity'] - $confirmedData['confirmed']);
    
    // Estimate wait time (assuming 10% cancellation rate)
    $estimatedWait = 0;
    if ($userPosition > 0) {
        $estimatedWait = ceil($userPosition / max(1, $availableSeats * 0.1));
    }
    
    echo json_encode([
        'success' => true,
        'waitlist_info' => [
            'is_on_waitlist' => ($userPosition > 0),
            'user_position' => $userPosition,
            'user_waitlist_id' => $userWaitlistId,
            'total_on_waitlist' => $totalData['total'],
            'available_seats' => $availableSeats,
            'estimated_wait_days' => $estimatedWait,
            'chance_of_confirmation' => min(100, round(($availableSeats / max(1, $userPosition)) * 100, 1))
        ]
    ]);
}

/**
 * Validate if a booking can be cancelled
 */
function validateCancellation() {
    global $conn, $userId, $userRole;
    
    $bookingId = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
    
    if ($bookingId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
        exit();
    }
    
    // Get booking details
    $query = "SELECT b.*, s.departure_time FROM bookings b 
              JOIN schedules s ON b.schedule_id = s.id 
              WHERE b.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit();
    }
    
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    // Check authorization
    if ($booking['user_id'] != $userId && $userRole != 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized to cancel this booking']);
        exit();
    }
    
    $departureTime = strtotime($booking['departure_time']);
    $currentTime = time();
    
    // Check cancellation rules
    $canCancel = false;
    $cancellationFee = 0;
    $refundAmount = 0;
    $messages = [];
    
    // Rule 1: Must be confirmed booking
    if ($booking['status'] != 'confirmed') {
        $messages[] = 'Only confirmed bookings can be cancelled';
    } else {
        // Rule 2: Must be before cutoff (4 hours before departure)
        $cancellationDeadline = strtotime('-4 hours', $departureTime);
        
        if ($currentTime > $cancellationDeadline) {
            $messages[] = 'Cancellation deadline has passed (4 hours before departure)';
        } else {
            $canCancel = true;
            
            // Rule 3: Calculate cancellation fee based on time
            $hoursBeforeDeparture = ($departureTime - $currentTime) / 3600;
            
            if ($hoursBeforeDeparture < 12) {
                $cancellationFee = 50; // 50% fee if less than 12 hours
                $messages[] = '50% cancellation fee applies (less than 12 hours notice)';
            } elseif ($hoursBeforeDeparture < 24) {
                $cancellationFee = 25; // 25% fee if less than 24 hours
                $messages[] = '25% cancellation fee applies (less than 24 hours notice)';
            } else {
                $cancellationFee = 0; // No fee if more than 24 hours
                $messages[] = 'No cancellation fee (more than 24 hours notice)';
            }
            
            // Calculate refund (if payment system exists)
            $paidAmount = $booking['amount_paid'] ?? 0;
            $refundAmount = $paidAmount * (1 - ($cancellationFee / 100));
        }
    }
    
    // Check if user has cancelled too many bookings recently
    if ($canCancel && $userRole == 'student') {
        $recentCancellations = $conn->query("SELECT COUNT(*) as count FROM bookings 
                                            WHERE user_id = $userId 
                                            AND status = 'cancelled' 
                                            AND DATE(cancelled_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['count'];
        
        if ($recentCancellations >= 3) {
            $canCancel = false;
            $messages[] = 'Cancellation limit reached (3 cancellations in 30 days)';
        }
    }
    
    echo json_encode([
        'success' => true,
        'can_cancel' => $canCancel,
        'cancellation_info' => [
            'booking_id' => $bookingId,
            'current_status' => $booking['status'],
            'departure_time' => $booking['departure_time'],
            'hours_until_departure' => round(($departureTime - $currentTime) / 3600, 1),
            'cancellation_deadline' => date('Y-m-d H:i:s', $cancellationDeadline),
            'cancellation_fee_percent' => $cancellationFee,
            'refund_amount' => $refundAmount,
            'paid_amount' => $booking['amount_paid'] ?? 0
        ],
        'messages' => $messages,
        'restrictions' => [
            'recent_cancellations' => $recentCancellations ?? 0,
            'cancellation_limit' => 3,
            'cancellation_period_days' => 30
        ]
    ]);
}

// =================== HELPER FUNCTIONS ===================

/**
 * Get booking status information
 */
function getBookingStatusInfo($status) {
    $statuses = [
        'pending' => [
            'label' => 'Pending',
            'color' => 'warning',
            'description' => 'Awaiting confirmation',
            'icon' => 'clock',
            'action' => 'waiting'
        ],
        'confirmed' => [
            'label' => 'Confirmed',
            'color' => 'success',
            'description' => 'Booking confirmed',
            'icon' => 'check-circle',
            'action' => 'active'
        ],
        'cancelled' => [
            'label' => 'Cancelled',
            'color' => 'danger',
            'description' => 'Booking cancelled',
            'icon' => 'times-circle',
            'action' => 'cancelled'
        ],
        'waitlisted' => [
            'label' => 'Waitlisted',
            'color' => 'info',
            'description' => 'On waiting list',
            'icon' => 'hourglass',
            'action' => 'waiting'
        ],
        'completed' => [
            'label' => 'Completed',
            'color' => 'secondary',
            'description' => 'Trip completed',
            'icon' => 'flag-checkered',
            'action' => 'completed'
        ],
        'no_show' => [
            'label' => 'No Show',
            'color' => 'dark',
            'description' => 'Did not show up',
            'icon' => 'user-times',
            'action' => 'missed'
        ]
    ];
    
    return $statuses[$status] ?? [
        'label' => 'Unknown',
        'color' => 'secondary',
        'description' => 'Status not recognized',
        'icon' => 'question-circle',
        'action' => 'unknown'
    ];
}

/**
 * Get attendance status information
 */
function getAttendanceStatusInfo($status) {
    $statuses = [
        'present' => [
            'label' => 'Present',
            'color' => 'success',
            'description' => 'Checked in and boarded',
            'icon' => 'user-check',
            'action' => 'checked_in'
        ],
        'absent' => [
            'label' => 'Not Checked In',
            'color' => 'warning',
            'description' => 'Not yet checked in',
            'icon' => 'user-clock',
            'action' => 'pending'
        ],
        'no_show' => [
            'label' => 'No Show',
            'color' => 'danger',
            'description' => 'Did not show up',
            'icon' => 'user-times',
            'action' => 'missed'
        ],
        'cancelled' => [
            'label' => 'Cancelled',
            'color' => 'secondary',
            'description' => 'Booking cancelled',
            'icon' => 'ban',
            'action' => 'cancelled'
        ]
    ];
    
    return $statuses[$status] ?? [
        'label' => 'Unknown',
        'color' => 'secondary',
        'description' => 'Status not recognized',
        'icon' => 'question-circle',
        'action' => 'unknown'
    ];
}

/**
 * Format time difference in human-readable format
 */
function formatTimeDifference($seconds) {
    if ($seconds <= 0) {
        return 'Departed';
    }
    
    $days = floor($seconds / (60 * 60 * 24));
    $hours = floor(($seconds % (60 * 60 * 24)) / (60 * 60));
    $minutes = floor(($seconds % (60 * 60)) / 60);
    
    if ($days > 0) {
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ' . $hours . ' hour' . ($hours > 1 ? 's' : '');
    } elseif ($hours > 0) {
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ' . $minutes . ' minute' . ($minutes > 1 ? 's' : '');
    } else {
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '');
    }
}

?>