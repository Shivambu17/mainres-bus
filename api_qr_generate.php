<?php
require_once 'config.php';
require_once 'functions.php';

// Allow CORS for mobile apps if needed
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$userId = $_SESSION['user_id'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Generate QR code for a booking
if ($action == 'generate_booking_qr') {
    $bookingId = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
    
    if ($bookingId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
        exit();
    }
    
    // Verify booking belongs to user
    $stmt = $conn->prepare("SELECT b.*, s.departure_time, r.route_name, s.bus_id 
                           FROM bookings b 
                           JOIN schedules s ON b.schedule_id = s.id 
                           JOIN routes r ON s.route_id = r.id 
                           WHERE b.id = ? AND b.user_id = ? AND b.status = 'confirmed'");
    $stmt->bind_param("ii", $bookingId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Booking not found or not confirmed']);
        exit();
    }
    
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    // Check if departure time is today
    $departureDate = date('Y-m-d', strtotime($booking['departure_time']));
    $today = date('Y-m-d');
    
    if ($departureDate != $today) {
        echo json_encode(['success' => false, 'message' => 'QR code is only available on the day of travel']);
        exit();
    }
    
    // Check if already checked in
    if ($booking['attendance_status'] == 'present') {
        echo json_encode(['success' => false, 'message' => 'Already checked in']);
        exit();
    }
    
    // Create QR data (encrypted for security)
    $qrData = [
        'booking_id' => $bookingId,
        'user_id' => $userId,
        'schedule_id' => $booking['schedule_id'],
        'timestamp' => time(),
        'expires' => strtotime('+2 hours') // QR valid for 2 hours
    ];
    
    $qrString = json_encode($qrData);
    $encryptedData = encryptData($qrString, ENCRYPTION_KEY);
    
    // Generate QR code using a library (in real implementation, you'd use a QR library)
    // For this example, we'll create a base64 encoded SVG using a simple method
    
    // In a real application, you would use a library like chillerlan/php-qrcode
    // Here's a simplified example that would work with a QR code generation library:
    
    /*
    // Example with chillerlan/php-qrcode (would need to be installed via composer)
    use chillerlan\QRCode\QRCode;
    use chillerlan\QRCode\QROptions;
    
    $options = new QROptions([
        'version'      => 5,
        'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel'     => QRCode::ECC_L,
        'scale'        => 5,
    ]);
    
    $qrcode = new QRCode($options);
    $qrImage = $qrcode->render($encryptedData);
    */
    
    // For now, we'll create a simple API response with the data
    // In production, you would generate the actual QR image
    
    $response = [
        'success' => true,
        'qr_data' => $encryptedData,
        'booking_info' => [
            'booking_id' => $bookingId,
            'route_name' => $booking['route_name'],
            'departure_time' => $booking['departure_time'],
            'valid_until' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'qr_text' => "BUS-BOOKING-" . $bookingId . "-" . $userId . "-" . date('Ymd')
        ],
        'qr_image_url' => "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($encryptedData)
    ];
    
    echo json_encode($response);
    exit();
}

// Get active QR code for today's booking
if ($action == 'get_active_qr') {
    $today = date('Y-m-d');
    
    // Find today's confirmed booking
    $stmt = $conn->prepare("SELECT b.id, s.departure_time, r.route_name 
                           FROM bookings b 
                           JOIN schedules s ON b.schedule_id = s.id 
                           JOIN routes r ON s.route_id = r.id 
                           WHERE b.user_id = ? AND b.status = 'confirmed' 
                           AND DATE(s.departure_time) = ? 
                           AND b.attendance_status = 'absent' 
                           ORDER BY s.departure_time ASC 
                           LIMIT 1");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No active booking for today']);
        exit();
    }
    
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    // Check if departure time is within 2 hours
    $departureTime = strtotime($booking['departure_time']);
    $currentTime = time();
    $timeDifference = $departureTime - $currentTime;
    
    if ($timeDifference > 7200) { // More than 2 hours before departure
        echo json_encode(['success' => false, 'message' => 'QR code will be available 2 hours before departure']);
        exit();
    }
    
    // Generate QR data
    $qrData = [
        'booking_id' => $booking['id'],
        'user_id' => $userId,
        'timestamp' => time(),
        'expires' => $departureTime + 3600 // 1 hour after departure
    ];
    
    $qrString = json_encode($qrData);
    $encryptedData = encryptData($qrString, ENCRYPTION_KEY);
    
    $response = [
        'success' => true,
        'booking_id' => $booking['id'],
        'route_name' => $booking['route_name'],
        'departure_time' => $booking['departure_time'],
        'qr_data' => $encryptedData,
        'qr_image_url' => "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($encryptedData),
        'valid_for' => floor(($departureTime + 3600 - $currentTime) / 60) . " minutes"
    ];
    
    echo json_encode($response);
    exit();
}

// Verify QR code (for driver scanning)
if ($action == 'verify_qr') {
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }
    
    $qrData = isset($_POST['qr_data']) ? $_POST['qr_data'] : '';
    $tripId = isset($_POST['trip_id']) ? intval($_POST['trip_id']) : 0;
    
    if (empty($qrData) || $tripId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid QR data or trip ID']);
        exit();
    }
    
    // Decrypt QR data
    try {
        $decryptedData = decryptData($qrData, ENCRYPTION_KEY);
        $qrInfo = json_decode($decryptedData, true);
        
        if (!$qrInfo || !isset($qrInfo['booking_id'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid QR code format']);
            exit();
        }
        
        // Check if QR code is expired
        if (isset($qrInfo['expires']) && time() > $qrInfo['expires']) {
            echo json_encode(['success' => false, 'message' => 'QR code has expired']);
            exit();
        }
        
        $bookingId = $qrInfo['booking_id'];
        
        // Verify booking exists and is valid for this trip
        $stmt = $conn->prepare("SELECT b.*, u.name, u.student_id, u.email 
                               FROM bookings b 
                               JOIN users u ON b.user_id = u.id 
                               WHERE b.id = ? AND b.schedule_id = ? AND b.status = 'confirmed'");
        $stmt->bind_param("ii", $bookingId, $tripId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid booking for this trip']);
            exit();
        }
        
        $booking = $result->fetch_assoc();
        $stmt->close();
        
        // Check if already checked in
        if ($booking['attendance_status'] == 'present') {
            echo json_encode([
                'success' => false, 
                'message' => 'Already checked in',
                'student_name' => $booking['name'],
                'student_id' => $booking['student_id'],
                'checked_in_at' => $booking['checked_in_at']
            ]);
            exit();
        }
        
        // Update attendance
        $updateStmt = $conn->prepare("UPDATE bookings SET attendance_status = 'present', 
                                    checked_in_at = NOW() WHERE id = ?");
        $updateStmt->bind_param("i", $bookingId);
        
        if ($updateStmt->execute()) {
            // Log the check-in
            $logStmt = $conn->prepare("INSERT INTO attendance_logs (booking_id, schedule_id, user_id, check_in_time, method) 
                                      VALUES (?, ?, ?, NOW(), 'qr_scan')");
            $logStmt->bind_param("iii", $bookingId, $tripId, $booking['user_id']);
            $logStmt->execute();
            $logStmt->close();
            
            echo json_encode([
                'success' => true,
                'message' => 'Check-in successful',
                'student_name' => $booking['name'],
                'student_id' => $booking['student_id'],
                'time' => date('h:i A'),
                'booking_id' => $bookingId
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating attendance']);
        }
        
        $updateStmt->close();
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'QR code verification failed: ' . $e->getMessage()]);
    }
    
    exit();
}

// Function to encrypt data
function encryptData($data, $key) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

// Function to decrypt data
function decryptData($data, $key) {
    list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
}

// Default response
echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit();
?>