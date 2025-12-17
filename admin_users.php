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
$user_id = isset($_GET['id']) ? sanitize($_GET['id']) : '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_user'])) {
        $role = sanitize($_POST['role']);
        $name = sanitize($_POST['name']);
        $surname = sanitize($_POST['surname']);
        $email = sanitize($_POST['email']);
        $phone = sanitize($_POST['phone']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Additional fields based on role
        $faculty = isset($_POST['faculty']) ? sanitize($_POST['faculty']) : '';
        $residence = isset($_POST['residence']) ? sanitize($_POST['residence']) : '';
        $course = isset($_POST['course']) ? sanitize($_POST['course']) : '';
        $year_level = isset($_POST['year_level']) ? sanitize($_POST['year_level']) : '';
        $license_number = isset($_POST['license_number']) ? sanitize($_POST['license_number']) : '';
        $admin_level = isset($_POST['admin_level']) ? sanitize($_POST['admin_level']) : 'support';
        
        // Validation
        if (empty($name) || empty($surname) || empty($email) || empty($password)) {
            $error = "Please fill in all required fields";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters";
        } else {
            // Check if email already exists
            $check_email = "SELECT * FROM users WHERE email = '$email'";
            $result = mysqli_query($conn, $check_email);
            
            if (mysqli_num_rows($result) > 0) {
                $error = "Email already registered";
            } else {
                // Hash password
                $password_hash = hashPassword($password);
                
                // Start transaction
                mysqli_begin_transaction($conn);
                
                try {
                    // Insert into users table
                    $user_query = "INSERT INTO users (email, password_hash, role, name, surname, phone, status) 
                                   VALUES ('$email', '$password_hash', '$role', '$name', '$surname', '$phone', 'active')";
                    
                    if (mysqli_query($conn, $user_query)) {
                        $new_user_id = mysqli_insert_id($conn);
                        
                        // Insert into role-specific table
                        if ($role == 'student') {
                            $role_query = "INSERT INTO students (student_id, faculty, residence, course, year_level, verification_status) 
                                           VALUES ('$new_user_id', '$faculty', '$residence', '$course', '$year_level', 'verified')";
                        } elseif ($role == 'driver') {
                            $role_query = "INSERT INTO drivers (driver_id, license_number, employment_date) 
                                           VALUES ('$new_user_id', '$license_number', CURDATE())";
                        } elseif ($role == 'admin') {
                            $role_query = "INSERT INTO admins (admin_id, admin_level) 
                                           VALUES ('$new_user_id', '$admin_level')";
                        }
                        
                        if (isset($role_query) && mysqli_query($conn, $role_query)) {
                            mysqli_commit($conn);
                            $success = "User created successfully!";
                            
                            // Send welcome notification
                            sendNotification($new_user_id, "Welcome to MainRes Bus System! Your account has been created.", 'success');
                            
                            // Clear form
                            $_POST = array();
                            
                        } else {
                            throw new Exception("Error creating role-specific record");
                        }
                    } else {
                        throw new Exception("Error creating user account");
                    }
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = "User creation failed: " . $e->getMessage();
                }
            }
        }
    }
    
    elseif (isset($_POST['update_user'])) {
        $user_id = sanitize($_POST['user_id']);
        $name = sanitize($_POST['name']);
        $surname = sanitize($_POST['surname']);
        $phone = sanitize($_POST['phone']);
        $status = sanitize($_POST['status']);
        
        // Get current role
        $role_query = "SELECT role FROM users WHERE user_id = '$user_id'";
        $role_result = mysqli_query($conn, $role_query);
        $user = mysqli_fetch_assoc($role_result);
        $role = $user['role'];
        
        // Additional fields based on role
        $faculty = isset($_POST['faculty']) ? sanitize($_POST['faculty']) : '';
        $residence = isset($_POST['residence']) ? sanitize($_POST['residence']) : '';
        $course = isset($_POST['course']) ? sanitize($_POST['course']) : '';
        $year_level = isset($_POST['year_level']) ? sanitize($_POST['year_level']) : '';
        $license_number = isset($_POST['license_number']) ? sanitize($_POST['license_number']) : '';
        $admin_level = isset($_POST['admin_level']) ? sanitize($_POST['admin_level']) : '';
        
        mysqli_begin_transaction($conn);
        
        try {
            // Update users table
            $update_user = "UPDATE users SET 
                            name = '$name',
                            surname = '$surname',
                            phone = '$phone',
                            status = '$status'
                            WHERE user_id = '$user_id'";
            
            if (mysqli_query($conn, $update_user)) {
                // Update role-specific table
                if ($role == 'student') {
                    $role_query = "UPDATE students SET 
                                   faculty = '$faculty',
                                   residence = '$residence',
                                   course = '$course',
                                   year_level = '$year_level'
                                   WHERE student_id = '$user_id'";
                } elseif ($role == 'driver') {
                    $role_query = "UPDATE drivers SET 
                                   license_number = '$license_number'
                                   WHERE driver_id = '$user_id'";
                } elseif ($role == 'admin') {
                    $role_query = "UPDATE admins SET 
                                   admin_level = '$admin_level'
                                   WHERE admin_id = '$user_id'";
                }
                
                if (isset($role_query) && mysqli_query($conn, $role_query)) {
                    mysqli_commit($conn);
                    $success = "User updated successfully!";
                } else {
                    throw new Exception("Error updating role-specific record");
                }
            } else {
                throw new Exception("Error updating user account");
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "User update failed: " . $e->getMessage();
        }
    }
    
    elseif (isset($_POST['reset_password'])) {
        $user_id = sanitize($_POST['user_id']);
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password !== $confirm_password) {
            $error = "Passwords do not match";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters";
        } else {
            $password_hash = hashPassword($new_password);
            $update_password = "UPDATE users SET password_hash = '$password_hash' WHERE user_id = '$user_id'";
            
            if (mysqli_query($conn, $update_password)) {
                $success = "Password reset successfully!";
                
                // Send notification
                sendNotification($user_id, "Your password has been reset by administrator.", 'warning');
            } else {
                $error = "Error resetting password";
            }
        }
    }
    
    elseif (isset($_POST['delete_user'])) {
        $user_id = sanitize($_POST['user_id']);
        
        // Check if user has active bookings
        if ($_POST['role'] == 'student') {
            $check_bookings = "SELECT COUNT(*) as count FROM bookings WHERE student_id = '$user_id' AND status IN ('confirmed', 'waitlisted')";
            $result = mysqli_query($conn, $check_bookings);
            $row = mysqli_fetch_assoc($result);
            
            if ($row['count'] > 0) {
                $error = "Cannot delete student with active bookings";
            }
        } elseif ($_POST['role'] == 'driver') {
            $check_trips = "SELECT COUNT(*) as count FROM trips WHERE driver_id = '$user_id' AND trip_date >= CURDATE()";
            $result = mysqli_query($conn, $check_trips);
            $row = mysqli_fetch_assoc($result);
            
            if ($row['count'] > 0) {
                $error = "Cannot delete driver with upcoming trips";
            }
        }
        
        if (!$error) {
            $delete_user = "DELETE FROM users WHERE user_id = '$user_id'";
            
            if (mysqli_query($conn, $delete_user)) {
                $success = "User deleted successfully!";
            } else {
                $error = "Error deleting user";
            }
        }
    }
}

// Get users with filters
$filter_role = isset($_GET['role']) ? sanitize($_GET['role']) : '';
$filter_status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$filter_search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

$users_query = "SELECT u.* FROM users u WHERE 1=1";

if ($filter_role && $filter_role != 'all') {
    $users_query .= " AND u.role = '$filter_role'";
}
if ($filter_status && $filter_status != 'all') {
    $users_query .= " AND u.status = '$filter_status'";
}
if ($filter_search) {
    $users_query .= " AND (u.name LIKE '%$filter_search%' OR u.surname LIKE '%$filter_search%' OR u.email LIKE '%$filter_search%')";
}

$users_query .= " ORDER BY u.role, u.surname, u.name";
$users_result = mysqli_query($conn, $users_query);

// Get specific user for editing
$current_user = null;
if ($user_id) {
    $user_query = "SELECT u.* FROM users u WHERE u.user_id = '$user_id'";
    $user_result = mysqli_query($conn, $user_query);
    if (mysqli_num_rows($user_result) == 1) {
        $current_user = mysqli_fetch_assoc($user_result);
        
        // Get role-specific data
        if ($current_user['role'] == 'student') {
            $student_query = "SELECT * FROM students WHERE student_id = '$user_id'";
            $student_result = mysqli_query($conn, $student_query);
            $current_user['student_data'] = mysqli_fetch_assoc($student_result);
        } elseif ($current_user['role'] == 'driver') {
            $driver_query = "SELECT * FROM drivers WHERE driver_id = '$user_id'";
            $driver_result = mysqli_query($conn, $driver_query);
            $current_user['driver_data'] = mysqli_fetch_assoc($driver_result);
        } elseif ($current_user['role'] == 'admin') {
            $admin_query = "SELECT * FROM admins WHERE admin_id = '$user_id'";
            $admin_result = mysqli_query($conn, $admin_query);
            $current_user['admin_data'] = mysqli_fetch_assoc($admin_result);
        }
    }
}

// Get user statistics
$stats_query = "SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as total_students,
                SUM(CASE WHEN role = 'driver' THEN 1 ELSE 0 END) as total_drivers,
                SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as total_admins,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_users
                FROM users";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                <a href="admin_bookings.php">
                    <i class="fas fa-ticket-alt"></i> Bookings
                </a>
                <a href="admin_users.php" class="active">
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
                <h1>User Management</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="showAddUserModal()">
                        <i class="fas fa-plus"></i> Add New User
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
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_users']; ?></h3>
                        <p>Total Users</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #2ecc71;">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_students']; ?></h3>
                        <p>Students</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9b59b6;">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_drivers']; ?></h3>
                        <p>Drivers</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #e74c3c;">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_admins']; ?></h3>
                        <p>Admins</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-filter"></i> Filter Users</h2>
                </div>
                
                <form method="GET" action="" class="filter-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="role"><i class="fas fa-user-tag"></i> Role</label>
                            <select id="role" name="role">
                                <option value="all">All Roles</option>
                                <option value="student" <?php echo ($filter_role == 'student') ? 'selected' : ''; ?>>Students</option>
                                <option value="driver" <?php echo ($filter_role == 'driver') ? 'selected' : ''; ?>>Drivers</option>
                                <option value="admin" <?php echo ($filter_role == 'admin') ? 'selected' : ''; ?>>Admins</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="status"><i class="fas fa-circle"></i> Status</label>
                            <select id="status" name="status">
                                <option value="all">All Statuses</option>
                                <option value="active" <?php echo ($filter_status == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="suspended" <?php echo ($filter_status == 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                <option value="pending" <?php echo ($filter_status == 'pending') ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="search"><i class="fas fa-search"></i> Search</label>
                            <input type="text" id="search" name="search" value="<?php echo $filter_search; ?>" 
                                   placeholder="Name, surname or email">
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="admin_users.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </form>
            </section>

            <!-- Users Table -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-list"></i> All Users</h2>
                    <span class="badge"><?php echo mysqli_num_rows($users_result); ?> users</span>
                </div>
                
                <?php if (mysqli_num_rows($users_result) > 0): ?>
                    <div class="users-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($user = mysqli_fetch_assoc($users_result)): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $user['name'] . ' ' . $user['surname']; ?></strong><br>
                                            <small>ID: <?php echo $user['user_id']; ?></small>
                                        </td>
                                        <td><?php echo $user['email']; ?></td>
                                        <td>
                                            <span class="role-badge role-<?php echo $user['role']; ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $user['phone'] ?: 'Not set'; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $user['status']; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d M Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <button class="btn btn-primary btn-sm" onclick="editUser(<?php echo $user['user_id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-secondary btn-sm" onclick="resetPassword(<?php echo $user['user_id']; ?>, '<?php echo $user['name']; ?>')">
                                                <i class="fas fa-key"></i> Reset PW
                                            </button>
                                            <button class="btn btn-danger btn-sm" onclick="deleteUser(<?php echo $user['user_id']; ?>, '<?php echo $user['role']; ?>', '<?php echo $user['name']; ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users-slash fa-3x"></i>
                        <h3>No users found</h3>
                        <p>Try adjusting your filters or add a new user.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New User</h3>
                <button class="modal-close" onclick="closeUserModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="userForm">
                    <input type="hidden" name="user_id" id="userId">
                    
                    <!-- Role Selection -->
                    <div class="form-group">
                        <label for="role"><i class="fas fa-user-tag"></i> Role *</label>
                        <select id="role" name="role" required onchange="toggleRoleFields()">
                            <option value="">Select Role</option>
                            <option value="student">Student</option>
                            <option value="driver">Driver</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                    
                    <!-- Basic Information -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name"><i class="fas fa-user"></i> First Name *</label>
                            <input type="text" id="name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="surname"><i class="fas fa-user"></i> Last Name *</label>
                            <input type="text" id="surname" name="surname" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone"><i class="fas fa-phone"></i> Phone Number</label>
                        <input type="tel" id="phone" name="phone">
                    </div>
                    
                    <!-- Student Fields -->
                    <div id="studentFields" style="display: none;">
                        <h4>Student Information</h4>
                        <div class="form-group">
                            <label for="faculty"><i class="fas fa-graduation-cap"></i> Faculty</label>
                            <select id="faculty" name="faculty">
                                <option value="Teaching">Teaching</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="residence"><i class="fas fa-home"></i> Residence</label>
                            <select id="residence" name="residence">
                                <option value="MainRes">Main Residence (MainRes)</option>
                            </select>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="course"><i class="fas fa-book"></i> Course</label>
                                <input type="text" id="course" name="course">
                            </div>
                            <div class="form-group">
                                <label for="year_level"><i class="fas fa-calendar-alt"></i> Year Level</label>
                                <select id="year_level" name="year_level">
                                    <option value="">Select Year</option>
                                    <option value="1">1st Year</option>
                                    <option value="2">2nd Year</option>
                                    <option value="3">3rd Year</option>
                                    <option value="4">4th Year</option>
                                    <option value="5">Postgraduate</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Driver Fields -->
                    <div id="driverFields" style="display: none;">
                        <h4>Driver Information</h4>
                        <div class="form-group">
                            <label for="license_number"><i class="fas fa-id-card"></i> License Number *</label>
                            <input type="text" id="license_number" name="license_number">
                        </div>
                    </div>
                    
                    <!-- Admin Fields -->
                    <div id="adminFields" style="display: none;">
                        <h4>Administrator Information</h4>
                        <div class="form-group">
                            <label for="admin_level"><i class="fas fa-shield-alt"></i> Admin Level</label>
                            <select id="admin_level" name="admin_level">
                                <option value="support">Support</option>
                                <option value="manager">Manager</option>
                                <option value="super">Super Admin</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Status Field (for editing) -->
                    <div class="form-group" id="statusField" style="display: none;">
                        <label for="status"><i class="fas fa-circle"></i> Status</label>
                        <select id="status" name="status">
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    
                    <!-- Password Fields (for new users) -->
                    <div id="passwordFields">
                        <h4>Account Security</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="password"><i class="fas fa-lock"></i> Password *</label>
                                <input type="password" id="password" name="password">
                                <small>Minimum 6 characters</small>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password"><i class="fas fa-lock"></i> Confirm Password *</label>
                                <input type="password" id="confirm_password" name="confirm_password">
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeUserModal()">Cancel</button>
                        <button type="submit" name="add_user" id="submitButton" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetPasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reset Password</h3>
                <button class="modal-close" onclick="closeResetPasswordModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Reset password for <span id="resetUserName"></span></p>
                <form method="POST" action="" id="resetPasswordForm">
                    <input type="hidden" name="user_id" id="resetUserId">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_password">New Password *</label>
                            <input type="password" id="new_password" name="new_password" required>
                            <small>Minimum 6 characters</small>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeResetPasswordModal()">Cancel</button>
                        <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div id="deleteUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Deletion</h3>
                <button class="modal-close" onclick="closeDeleteUserModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="deleteUserMessage">Are you sure you want to delete this user?</p>
                <div id="deleteWarning" class="alert alert-warning" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="warningText"></span>
                </div>
                <form method="POST" action="" id="deleteUserForm">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <input type="hidden" name="role" id="deleteUserRole">
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteUserModal()">Cancel</button>
                        <button type="submit" name="delete_user" class="btn btn-danger">Delete User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // Show add user modal
        function showAddUserModal() {
            document.getElementById('modalTitle').textContent = 'Add New User';
            document.getElementById('userId').value = '';
            
            // Reset form
            document.getElementById('userForm').reset();
            document.getElementById('statusField').style.display = 'none';
            document.getElementById('passwordFields').style.display = 'block';
            
            // Reset role fields
            document.getElementById('studentFields').style.display = 'none';
            document.getElementById('driverFields').style.display = 'none';
            document.getElementById('adminFields').style.display = 'none';
            
            document.getElementById('submitButton').name = 'add_user';
            document.getElementById('submitButton').textContent = 'Add User';
            
            document.getElementById('userModal').style.display = 'block';
        }

        // Edit user
        function editUser(userId) {
            fetch(`api_user_details.php?user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').textContent = 'Edit User';
                        document.getElementById('userId').value = data.user.user_id;
                        document.getElementById('role').value = data.user.role;
                        document.getElementById('name').value = data.user.name;
                        document.getElementById('surname').value = data.user.surname;
                        document.getElementById('email').value = data.user.email;
                        document.getElementById('phone').value = data.user.phone || '';
                        document.getElementById('status').value = data.user.status;
                        
                        // Show status field for editing
                        document.getElementById('statusField').style.display = 'block';
                        document.getElementById('passwordFields').style.display = 'none';
                        
                        // Toggle role fields
                        toggleRoleFields();
                        
                        // Fill role-specific fields
                        if (data.user.role == 'student' && data.user.student_data) {
                            document.getElementById('faculty').value = data.user.student_data.faculty;
                            document.getElementById('residence').value = data.user.student_data.residence;
                            document.getElementById('course').value = data.user.student_data.course || '';
                            document.getElementById('year_level').value = data.user.student_data.year_level || '';
                        } else if (data.user.role == 'driver' && data.user.driver_data) {
                            document.getElementById('license_number').value = data.user.driver_data.license_number || '';
                        } else if (data.user.role == 'admin' && data.user.admin_data) {
                            document.getElementById('admin_level').value = data.user.admin_data.admin_level || 'support';
                        }
                        
                        document.getElementById('submitButton').name = 'update_user';
                        document.getElementById('submitButton').textContent = 'Update User';
                        
                        document.getElementById('userModal').style.display = 'block';
                    } else {
                        alert('Error loading user details');
                    }
                });
        }

        // Toggle role-specific fields
        function toggleRoleFields() {
            const role = document.getElementById('role').value;
            
            // Hide all role fields
            document.getElementById('studentFields').style.display = 'none';
            document.getElementById('driverFields').style.display = 'none';
            document.getElementById('adminFields').style.display = 'none';
            
            // Show selected role fields
            if (role === 'student') {
                document.getElementById('studentFields').style.display = 'block';
            } else if (role === 'driver') {
                document.getElementById('driverFields').style.display = 'block';
            } else if (role === 'admin') {
                document.getElementById('adminFields').style.display = 'block';
            }
        }

        // Reset password
        function resetPassword(userId, userName) {
            document.getElementById('resetUserName').textContent = userName;
            document.getElementById('resetUserId').value = userId;
            document.getElementById('resetPasswordModal').style.display = 'block';
        }

        // Close reset password modal
        function closeResetPasswordModal() {
            document.getElementById('resetPasswordModal').style.display = 'none';
        }

        // Delete user
        function deleteUser(userId, role, userName) {
            document.getElementById('deleteUserMessage').textContent = `Are you sure you want to delete user: ${userName}?`;
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserRole').value = role;
            
            // Show warning based on role
            const warningDiv = document.getElementById('deleteWarning');
            const warningText = document.getElementById('warningText');
            
            if (role === 'student') {
                warningText.textContent = 'Warning: This student may have active bookings. Cancellations will be required first.';
                warningDiv.style.display = 'block';
            } else if (role === 'driver') {
                warningText.textContent = 'Warning: This driver may have upcoming trips. Reassignment will be required first.';
                warningDiv.style.display = 'block';
            } else {
                warningDiv.style.display = 'none';
            }
            
            document.getElementById('deleteUserModal').style.display = 'block';
        }

        // Close delete user modal
        function closeDeleteUserModal() {
            document.getElementById('deleteUserModal').style.display = 'none';
        }

        // Close modals
        function closeUserModal() {
            document.getElementById('userModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const userModal = document.getElementById('userModal');
            const resetModal = document.getElementById('resetPasswordModal');
            const deleteModal = document.getElementById('deleteUserModal');
            
            if (event.target == userModal) {
                closeUserModal();
            }
            if (event.target == resetModal) {
                closeResetPasswordModal();
            }
            if (event.target == deleteModal) {
                closeDeleteUserModal();
            }
        }

        // Form validation
        document.getElementById('userForm').addEventListener('submit', function(e) {
            const role = document.getElementById('role').value;
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (!role) {
                e.preventDefault();
                alert('Please select a role');
                return false;
            }
            
            // For new users, check password
            if (document.getElementById('submitButton').name === 'add_user') {
                if (password.length < 6) {
                    e.preventDefault();
                    alert('Password must be at least 6 characters');
                    return false;
                }
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match');
                    return false;
                }
            }
            
            // Role-specific validation
            if (role === 'driver') {
                const license = document.getElementById('license_number').value;
                if (!license) {
                    e.preventDefault();
                    alert('License number is required for drivers');
                    return false;
                }
            }
            
            return true;
        });

        // Reset password form validation
        document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            
            if (newPass.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters');
                return false;
            }
            
            if (newPass !== confirmPass) {
                e.preventDefault();
                alert('Passwords do not match');
                return false;
            }
            
            return true;
        });

        // Auto-refresh users every 30 seconds
        setInterval(function() {
            if (window.location.pathname.includes('admin_users.php')) {
                // Only refresh if no modal is open
                if (!document.getElementById('userModal').style.display && 
                    !document.getElementById('resetPasswordModal').style.display &&
                    !document.getElementById('deleteUserModal').style.display) {
                    window.location.reload();
                }
            }
        }, 30000);
    </script>
</body>
</html>