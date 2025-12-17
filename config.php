<?php
// config.php

// Start session
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'mainres-bus');
define('SITE_URL', 'http://localhost/mainres-bus/');

// System constants
define('BUS_CAPACITY', 65);
define('ALERT_THRESHOLD', 75);
define('WAITLIST_ENABLED', true);
define('MAX_ADVANCE_BOOKINGS', 2);
define('CANCEL_DEADLINE_HOURS', 1);

// Create database connection
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Set timezone
date_default_timezone_set('Africa/Johannesburg');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>