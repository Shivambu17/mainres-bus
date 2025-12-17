<?php
// functions.php
require_once 'config.php';

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check user role
function isStudent() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'student';
}

function isDriver() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'driver';
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'admin';
}

// Redirect function
function redirect($page) {
    header("Location: " . SITE_URL . $page);
    exit();
}

// Sanitize input
function sanitize($input) {
    global $conn;
    return mysqli_real_escape_string($conn, htmlspecialchars(trim($input)));
}

// Password hashing
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

// Verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Get available seats for a trip
function getAvailableSeats($trip_id) {
    global $conn;
    $query = "SELECT booked_count FROM trips WHERE trip_id = '$trip_id'";
    $result = mysqli_query($conn, $query);
    if ($row = mysqli_fetch_assoc($result)) {
        return BUS_CAPACITY - $row['booked_count'];
    }
    return 0;
}

// Check if trip is full
function isTripFull($trip_id) {
    global $conn;
    $query = "SELECT booked_count FROM trips WHERE trip_id = '$trip_id'";
    $result = mysqli_query($conn, $query);
    if ($row = mysqli_fetch_assoc($result)) {
        return $row['booked_count'] >= BUS_CAPACITY;
    }
    return true;
}

// Send notification
function sendNotification($user_id, $message, $type = 'info') {
    global $conn;
    $query = "INSERT INTO notifications (user_id, message, type) 
              VALUES ('$user_id', '$message', '$type')";
    return mysqli_query($conn, $query);
}

// Check waitlist position
function getWaitlistPosition($student_id, $trip_id) {
    global $conn;
    $query = "SELECT waitlist_position FROM bookings 
              WHERE student_id = '$student_id' AND trip_id = '$trip_id' 
              AND waitlist_position > 0";
    $result = mysqli_query($conn, $query);
    if ($row = mysqli_fetch_assoc($result)) {
        return $row['waitlist_position'];
    }
    return 0;
}

// Generate QR code data (simplified - in real app, use QR code library)
function generateQRCode($booking_id) {
    $data = "MainResBus|" . $booking_id . "|" . time();
    $qr_filename = 'qr_' . $booking_id . '_' . md5($data) . '.png';
    
    // In a real application, use a QR code library like PHP QR Code
    // For now, we'll create a simple text file
    $qr_path = 'uploads/qr_codes/' . $qr_filename;
    
    // Create directory if it doesn't exist
    if (!is_dir('uploads/qr_codes')) {
        mkdir('uploads/qr_codes', 0777, true);
    }
    
    // Save QR data to file
    file_put_contents($qr_path, $data);
    
    return $qr_filename;
}

// Check booking restrictions
function canStudentBook($student_id) {
    global $conn;
    $query = "SELECT COUNT(*) as count FROM bookings 
              WHERE student_id = '$student_id' 
              AND status = 'confirmed' 
              AND trip_date >= CURDATE()";
    $result = mysqli_query($conn, $query);
    $row = mysqli_fetch_assoc($result);
    return $row['count'] < MAX_ADVANCE_BOOKINGS;
}

// Get user's name
function getUserName($user_id) {
    global $conn;
    $query = "SELECT name FROM users WHERE user_id = '$user_id'";
    $result = mysqli_query($conn, $query);
    if ($row = mysqli_fetch_assoc($result)) {
        return $row['name'];
    }
    return 'User';
}
?>