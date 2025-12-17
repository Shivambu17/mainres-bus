<?php
// db_setup.php
require_once 'config.php';

echo "<h2>MainRes Bus System - Database Setup</h2>";
echo "<p>Creating database and tables...</p>";

// Create database if it doesn't exist
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if (mysqli_query($conn, $sql)) {
    echo "<p>✓ Database created successfully</p>";
} else {
    die("Error creating database: " . mysqli_error($conn));
}

// Select database
mysqli_select_db($conn, DB_NAME);

// Create tables
$tables = [
    // Users table (for all user types)
    "CREATE TABLE IF NOT EXISTS users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('student', 'driver', 'admin') NOT NULL,
        name VARCHAR(100) NOT NULL,
        surname VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        status ENUM('active', 'suspended', 'pending') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_login TIMESTAMP NULL,
        INDEX idx_role (role),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Students table
    "CREATE TABLE IF NOT EXISTS students (
        student_id INT PRIMARY KEY,
        faculty VARCHAR(50) NOT NULL,
        residence VARCHAR(100) NOT NULL,
        course VARCHAR(100),
        year_level INT,
        student_number VARCHAR(20),
        verification_status ENUM('verified', 'pending', 'rejected') DEFAULT 'pending',
        FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
        CHECK (faculty = 'Teaching'),
        CHECK (residence = 'MainRes')
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Drivers table
    "CREATE TABLE IF NOT EXISTS drivers (
        driver_id INT PRIMARY KEY,
        license_number VARCHAR(50) NOT NULL UNIQUE,
        license_expiry DATE,
        employment_date DATE,
        status ENUM('active', 'on_leave', 'inactive') DEFAULT 'active',
        FOREIGN KEY (driver_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Admins table
    "CREATE TABLE IF NOT EXISTS admins (
        admin_id INT PRIMARY KEY,
        admin_level ENUM('super', 'manager', 'support') DEFAULT 'support',
        permissions TEXT,
        last_activity TIMESTAMP NULL,
        FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Buses table
    "CREATE TABLE IF NOT EXISTS buses (
        bus_id INT AUTO_INCREMENT PRIMARY KEY,
        bus_number VARCHAR(20) UNIQUE NOT NULL,
        capacity INT DEFAULT 65,
        model VARCHAR(50),
        registration_number VARCHAR(20) UNIQUE,
        status ENUM('active', 'maintenance', 'inactive') DEFAULT 'active',
        last_service DATE,
        next_service DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Trips table
    "CREATE TABLE IF NOT EXISTS trips (
        trip_id INT AUTO_INCREMENT PRIMARY KEY,
        bus_id INT NOT NULL,
        trip_date DATE NOT NULL,
        departure_time TIME NOT NULL,
        arrival_time TIME,
        route VARCHAR(100) NOT NULL,
        pickup_point VARCHAR(100),
        dropoff_point VARCHAR(100),
        driver_id INT,
        booked_count INT DEFAULT 0,
        waitlist_count INT DEFAULT 0,
        status ENUM('scheduled', 'boarding', 'departed', 'arrived', 'cancelled') DEFAULT 'scheduled',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (bus_id) REFERENCES buses(bus_id) ON DELETE CASCADE,
        FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE SET NULL,
        INDEX idx_trip_date (trip_date),
        INDEX idx_status (status),
        INDEX idx_driver (driver_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Bookings table
    "CREATE TABLE IF NOT EXISTS bookings (
        booking_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        trip_id INT NOT NULL,
        booking_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        qr_code VARCHAR(255),
        waitlist_position INT DEFAULT 0,
        status ENUM('confirmed', 'waitlisted', 'cancelled', 'used', 'no_show') DEFAULT 'confirmed',
        cancelled_at TIMESTAMP NULL,
        cancellation_reason TEXT,
        attended_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
        FOREIGN KEY (trip_id) REFERENCES trips(trip_id) ON DELETE CASCADE,
        UNIQUE KEY unique_booking (student_id, trip_id),
        INDEX idx_status (status),
        INDEX idx_student (student_id),
        INDEX idx_trip (trip_id),
        INDEX idx_booking_date (booking_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Attendance table
    "CREATE TABLE IF NOT EXISTS attendance (
        attendance_id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        scanned_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        scanned_by INT,
        scan_method ENUM('qr', 'manual', 'system') DEFAULT 'qr',
        result ENUM('success', 'invalid', 'duplicate', 'late', 'early') DEFAULT 'success',
        notes TEXT,
        FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
        FOREIGN KEY (scanned_by) REFERENCES drivers(driver_id) ON DELETE SET NULL,
        INDEX idx_scanned_time (scanned_time),
        INDEX idx_result (result)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Notifications table
    "CREATE TABLE IF NOT EXISTS notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(200),
        message TEXT NOT NULL,
        type ENUM('info', 'warning', 'success', 'error', 'booking', 'system') DEFAULT 'info',
        is_read BOOLEAN DEFAULT FALSE,
        is_emailed BOOLEAN DEFAULT FALSE,
        link VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        INDEX idx_user_read (user_id, is_read),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Issue Reports table
    "CREATE TABLE IF NOT EXISTS issue_reports (
        report_id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        trip_id INT,
        issue_type ENUM('breakdown', 'accident', 'delay', 'maintenance', 'student_issue', 'other') NOT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NOT NULL,
        severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
        status ENUM('pending', 'in_progress', 'resolved', 'closed') DEFAULT 'pending',
        reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL,
        resolution_notes TEXT,
        FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE CASCADE,
        FOREIGN KEY (trip_id) REFERENCES trips(trip_id) ON DELETE SET NULL,
        INDEX idx_status (status),
        INDEX idx_driver (driver_id),
        INDEX idx_severity (severity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Audit Log table
    "CREATE TABLE IF NOT EXISTS audit_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        user_role VARCHAR(20),
        action VARCHAR(100) NOT NULL,
        table_name VARCHAR(50),
        record_id INT,
        old_value TEXT,
        new_value TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_action (action),
        INDEX idx_user (user_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // System Settings table
    "CREATE TABLE IF NOT EXISTS system_settings (
        setting_id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) UNIQUE NOT NULL,
        setting_value TEXT,
        setting_type ENUM('string', 'integer', 'boolean', 'json', 'array') DEFAULT 'string',
        category VARCHAR(50),
        description TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    
    // Waitlist table
    "CREATE TABLE IF NOT EXISTS waitlist (
        waitlist_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        trip_id INT NOT NULL,
        position INT NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('waiting', 'promoted', 'cancelled', 'expired') DEFAULT 'waiting',
        promoted_at TIMESTAMP NULL,
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
        FOREIGN KEY (trip_id) REFERENCES trips(trip_id) ON DELETE CASCADE,
        UNIQUE KEY unique_waitlist (student_id, trip_id),
        INDEX idx_position (position),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

// Execute table creation
foreach ($tables as $sql) {
    if (mysqli_query($conn, $sql)) {
        echo "<p>✓ Table created successfully</p>";
    } else {
        echo "<p>✗ Error creating table: " . mysqli_error($conn) . "</p>";
        echo "<p>SQL: " . htmlspecialchars(substr($sql, 0, 100)) . "...</p>";
    }
}

// Insert default data
echo "<h3>Inserting Default Data...</h3>";

// Default admin user
$check_admin = "SELECT * FROM users WHERE email = 'admin@mainres.ac.za'";
$result = mysqli_query($conn, $check_admin);
if (mysqli_num_rows($result) == 0) {
    $admin_pass = password_hash('admin123', PASSWORD_BCRYPT);
    $insert_admin = "INSERT INTO users (email, password_hash, role, name, surname, phone, status) 
                     VALUES ('admin@mainres.ac.za', '$admin_pass', 'admin', 'System', 'Administrator', '+27111234567', 'active')";
    
    if (mysqli_query($conn, $insert_admin)) {
        $admin_id = mysqli_insert_id($conn);
        $insert_admin_details = "INSERT INTO admins (admin_id, admin_level) VALUES ('$admin_id', 'super')";
        mysqli_query($conn, $insert_admin_details);
        echo "<p>✓ Default admin user created (email: admin@mainres.ac.za, password: admin123)</p>";
    }
}

// Default driver
$check_driver = "SELECT * FROM users WHERE email = 'driver@mainres.ac.za'";
$result = mysqli_query($conn, $check_driver);
if (mysqli_num_rows($result) == 0) {
    $driver_pass = password_hash('driver123', PASSWORD_BCRYPT);
    $insert_driver = "INSERT INTO users (email, password_hash, role, name, surname, phone, status) 
                      VALUES ('driver@mainres.ac.za', '$driver_pass', 'driver', 'John', 'Driver', '+27111234568', 'active')";
    
    if (mysqli_query($conn, $insert_driver)) {
        $driver_id = mysqli_insert_id($conn);
        $insert_driver_details = "INSERT INTO drivers (driver_id, license_number, employment_date) 
                                  VALUES ('$driver_id', 'DRV001', CURDATE())";
        mysqli_query($conn, $insert_driver_details);
        echo "<p>✓ Default driver created (email: driver@mainres.ac.za, password: driver123)</p>";
    }
}

// Sample buses
$buses = [
    ['BUS001', 65, 'Mercedes', 'CA 123-456'],
    ['BUS002', 65, 'Toyota', 'CA 123-457'],
    ['BUS003', 65, 'Volvo', 'CA 123-458']
];

foreach ($buses as $bus) {
    $check_bus = "SELECT * FROM buses WHERE bus_number = '{$bus[0]}'";
    $result = mysqli_query($conn, $check_bus);
    if (mysqli_num_rows($result) == 0) {
        $insert_bus = "INSERT INTO buses (bus_number, capacity, model, registration_number) 
                       VALUES ('{$bus[0]}', {$bus[1]}, '{$bus[2]}', '{$bus[3]}')";
        mysqli_query($conn, $insert_bus);
        echo "<p>✓ Bus {$bus[0]} created</p>";
    }
}

// System settings
$settings = [
    ['site_name', 'MainRes Bus System', 'string', 'General'],
    ['bus_capacity', '65', 'integer', 'Booking'],
    ['alert_threshold', '75', 'integer', 'Booking'],
    ['max_advance_bookings', '2', 'integer', 'Booking'],
    ['cancel_deadline_hours', '1', 'integer', 'Booking'],
    ['system_email', 'transport@mainres.ac.za', 'string', 'Email'],
    ['admin_email', 'admin@mainres.ac.za', 'string', 'Email'],
    ['enable_waitlist', '1', 'boolean', 'Booking'],
    ['auto_waitlist_promotion', '1', 'boolean', 'Booking'],
    ['booking_open_days', '7', 'integer', 'Booking']
];

foreach ($settings as $setting) {
    $check_setting = "SELECT * FROM system_settings WHERE setting_key = '{$setting[0]}'";
    $result = mysqli_query($conn, $check_setting);
    if (mysqli_num_rows($result) == 0) {
        $insert_setting = "INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description) 
                           VALUES ('{$setting[0]}', '{$setting[1]}', '{$setting[2]}', '{$setting[3]}', 'System setting')";
        mysqli_query($conn, $insert_setting);
        echo "<p>✓ Setting {$setting[0]} added</p>";
    }
}

// Create sample trips for next 7 days
echo "<h3>Creating Sample Trips...</h3>";
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime("+$i days"));
    
    // Morning trips
    $routes = [
        ['07:00:00', 'MainRes to Campus', 'Main Residence Gate', 'Main Campus Bus Stop'],
        ['07:30:00', 'MainRes to Campus', 'Main Residence Gate', 'Main Campus Bus Stop'],
        ['08:00:00', 'MainRes to Campus', 'Main Residence Gate', 'Main Campus Bus Stop'],
        ['16:00:00', 'Campus to MainRes', 'Main Campus Bus Stop', 'Main Residence Gate'],
        ['16:30:00', 'Campus to MainRes', 'Main Campus Bus Stop', 'Main Residence Gate'],
        ['17:00:00', 'Campus to MainRes', 'Main Campus Bus Stop', 'Main Residence Gate']
    ];
    
    foreach ($routes as $route) {
        $check_trip = "SELECT * FROM trips WHERE trip_date = '$date' AND departure_time = '{$route[0]}' AND route = '{$route[1]}'";
        $result = mysqli_query($conn, $check_trip);
        if (mysqli_num_rows($result) == 0) {
            $bus_id = rand(1, 3); // Random bus
            $insert_trip = "INSERT INTO trips (bus_id, trip_date, departure_time, route, pickup_point, dropoff_point, status) 
                            VALUES ('$bus_id', '$date', '{$route[0]}', '{$route[1]}', '{$route[2]}', '{$route[3]}', 'scheduled')";
            if (mysqli_query($conn, $insert_trip)) {
                echo "<p>✓ Trip created: $date {$route[0]} - {$route[1]}</p>";
            }
        }
    }
}

echo "<h2 style='color: green;'>Database Setup Complete!</h2>";
echo "<p>The database has been successfully set up with all necessary tables and sample data.</p>";
echo "<p><a href='index.php'>Go to Home Page</a> | <a href='login.php'>Login to System</a></p>";

// Close connection
mysqli_close($conn);
?>