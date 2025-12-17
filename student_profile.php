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

// Get student data
$student_query = "SELECT u.*, s.faculty, s.residence, s.course, s.year_level, s.student_number
                  FROM users u
                  JOIN students s ON u.user_id = s.student_id
                  WHERE u.user_id = '$user_id'";
$student_result = mysqli_query($conn, $student_query);
$student = mysqli_fetch_assoc($student_result);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = sanitize($_POST['name']);
        $surname = sanitize($_POST['surname']);
        $phone = sanitize($_POST['phone']);
        $course = sanitize($_POST['course']);
        $year_level = sanitize($_POST['year_level']);
        
        // Update user table
        $update_user = "UPDATE users SET 
                        name = '$name',
                        surname = '$surname',
                        phone = '$phone'
                        WHERE user_id = '$user_id'";
        
        // Update students table
        $update_student = "UPDATE students SET 
                           course = '$course',
                           year_level = '$year_level'
                           WHERE student_id = '$user_id'";
        
        mysqli_begin_transaction($conn);
        
        try {
            mysqli_query($conn, $update_user);
            mysqli_query($conn, $update_student);
            
            mysqli_commit($conn);
            
            $_SESSION['name'] = $name;
            $success = "Profile updated successfully!";
            
            // Refresh student data
            $student_result = mysqli_query($conn, $student_query);
            $student = mysqli_fetch_assoc($student_result);
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error updating profile: " . $e->getMessage();
        }
    }
    
    // Handle password change
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        if (!verifyPassword($current_password, $student['password_hash'])) {
            $error = "Current password is incorrect";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters";
        } else {
            // Hash new password
            $new_password_hash = hashPassword($new_password);
            
            $update_password = "UPDATE users SET password_hash = '$new_password_hash' WHERE user_id = '$user_id'";
            
            if (mysqli_query($conn, $update_password)) {
                $success = "Password changed successfully!";
            } else {
                $error = "Error changing password";
            }
        }
    }
}

// Get booking history stats
$stats_query = "SELECT 
                COUNT(*) as total_bookings,
                SUM(CASE WHEN b.status = 'used' THEN 1 ELSE 0 END) as trips_completed,
                SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as trips_cancelled
                FROM bookings b
                WHERE b.student_id = '$user_id'";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - MainRes Bus System</title>
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
                    <i class="fas fa-graduation-cap"></i> <?php echo $student['faculty']; ?><br>
                    <i class="fas fa-home"></i> <?php echo $student['residence']; ?>
                </p>
            </div>
            <nav class="sidebar-nav">
                <a href="student_dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="student_book.php">
                    <i class="fas fa-bus"></i> Book a Trip
                </a>
                <a href="student_mybookings.php">
                    <i class="fas fa-calendar-check"></i> My Bookings
                </a>
                <a href="student_profile.php" class="active">
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
                <h1>My Profile</h1>
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

            <div class="profile-grid">
                <!-- Left Column - Profile Info -->
                <div class="profile-column">
                    <!-- Profile Card -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-user"></i> Profile Information</h2>
                        </div>
                        
                        <div class="profile-info">
                            <div class="profile-header">
                                <div class="profile-avatar">
                                    <i class="fas fa-user-graduate fa-3x"></i>
                                </div>
                                <div>
                                    <h3><?php echo $student['name'] . ' ' . $student['surname']; ?></h3>
                                    <p class="profile-role">Student</p>
                                    <p class="profile-meta">
                                        <i class="fas fa-envelope"></i> <?php echo $student['email']; ?><br>
                                        <i class="fas fa-phone"></i> <?php echo $student['phone'] ?: 'Not provided'; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="profile-stats">
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $stats['total_bookings']; ?></span>
                                    <span class="stat-label">Total Bookings</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $stats['trips_completed']; ?></span>
                                    <span class="stat-label">Trips Completed</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $stats['trips_cancelled']; ?></span>
                                    <span class="stat-label">Cancelled</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Academic Information -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-graduation-cap"></i> Academic Information</h2>
                        </div>
                        
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Faculty</span>
                                <span class="info-value"><?php echo $student['faculty']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Residence</span>
                                <span class="info-value"><?php echo $student['residence']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Course</span>
                                <span class="info-value"><?php echo $student['course'] ?: 'Not specified'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Year Level</span>
                                <span class="info-value">
                                    <?php 
                                    if ($student['year_level']) {
                                        switch($student['year_level']) {
                                            case 1: echo '1st Year'; break;
                                            case 2: echo '2nd Year'; break;
                                            case 3: echo '3rd Year'; break;
                                            case 4: echo '4th Year'; break;
                                            case 5: echo 'Postgraduate'; break;
                                            default: echo $student['year_level'];
                                        }
                                    } else {
                                        echo 'Not specified';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Student Number</span>
                                <span class="info-value"><?php echo $student['student_number'] ?: 'Not assigned'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Account Status</span>
                                <span class="info-value status-badge status-<?php echo $student['status']; ?>">
                                    <?php echo ucfirst($student['status']); ?>
                                </span>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Right Column - Edit Forms -->
                <div class="profile-column">
                    <!-- Update Profile Form -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-edit"></i> Update Profile</h2>
                        </div>
                        
                        <form method="POST" action="" class="profile-form">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="name">First Name *</label>
                                    <input type="text" id="name" name="name" required 
                                           value="<?php echo htmlspecialchars($student['name']); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="surname">Last Name *</label>
                                    <input type="text" id="surname" name="surname" required 
                                           value="<?php echo htmlspecialchars($student['surname']); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($student['phone']); ?>"
                                       placeholder="+27 11 123 4567">
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="course">Course</label>
                                    <input type="text" id="course" name="course" 
                                           value="<?php echo htmlspecialchars($student['course']); ?>"
                                           placeholder="e.g., B.Ed in Senior Phase">
                                </div>
                                <div class="form-group">
                                    <label for="year_level">Year Level</label>
                                    <select id="year_level" name="year_level">
                                        <option value="">Select Year</option>
                                        <option value="1" <?php echo ($student['year_level'] == 1) ? 'selected' : ''; ?>>1st Year</option>
                                        <option value="2" <?php echo ($student['year_level'] == 2) ? 'selected' : ''; ?>>2nd Year</option>
                                        <option value="3" <?php echo ($student['year_level'] == 3) ? 'selected' : ''; ?>>3rd Year</option>
                                        <option value="4" <?php echo ($student['year_level'] == 4) ? 'selected' : ''; ?>>4th Year</option>
                                        <option value="5" <?php echo ($student['year_level'] == 5) ? 'selected' : ''; ?>>Postgraduate</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address (cannot be changed)</label>
                                <input type="email" id="email" value="<?php echo htmlspecialchars($student['email']); ?>" disabled>
                            </div>
                            
                            <div class="form-group">
                                <label for="faculty">Faculty (cannot be changed)</label>
                                <input type="text" id="faculty" value="<?php echo htmlspecialchars($student['faculty']); ?>" disabled>
                            </div>
                            
                            <div class="form-group">
                                <label for="residence">Residence (cannot be changed)</label>
                                <input type="text" id="residence" value="<?php echo htmlspecialchars($student['residence']); ?>" disabled>
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </form>
                    </section>

                    <!-- Change Password Form -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-lock"></i> Change Password</h2>
                        </div>
                        
                        <form method="POST" action="" class="profile-form">
                            <div class="form-group">
                                <label for="current_password">Current Password *</label>
                                <input type="password" id="current_password" name="current_password" required>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="new_password">New Password *</label>
                                    <input type="password" id="new_password" name="new_password" required>
                                    <small>Minimum 6 characters</small>
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password *</label>
                                    <input type="password" id="confirm_password" name="confirm_password" required>
                                </div>
                            </div>
                            
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </form>
                    </section>

                    <!-- Account Status -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-info-circle"></i> Account Information</h2>
                        </div>
                        
                        <div class="account-info">
                            <div class="info-item">
                                <span class="info-label">Member Since</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($student['created_at'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Last Login</span>
                                <span class="info-value">
                                    <?php echo $student['last_login'] ? date('d M Y, H:i', strtotime($student['last_login'])) : 'Never'; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Account Type</span>
                                <span class="info-value">Student Account</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Verification Status</span>
                                <span class="info-value status-badge status-verified">
                                    <?php echo isset($student['verification_status']) ? ucfirst($student['verification_status']) : 'Verified'; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="account-actions">
                            <button class="btn btn-secondary" onclick="downloadData()">
                                <i class="fas fa-download"></i> Download My Data
                            </button>
                            <button class="btn btn-danger" onclick="showDeleteModal()">
                                <i class="fas fa-trash"></i> Request Account Deletion
                            </button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Account Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Request Account Deletion</h3>
                <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This action cannot be undone!
                </div>
                
                <p>Requesting account deletion will:</p>
                <ul>
                    <li>Cancel all your upcoming bookings</li>
                    <li>Remove you from all waitlists</li>
                    <li>Delete your personal information</li>
                    <li>Permanently deactivate your account</li>
                </ul>
                
                <p>Your booking history will be anonymized for statistical purposes.</p>
                
                <form method="POST" action="" onsubmit="return confirmDeletion()">
                    <div class="form-group">
                        <label for="deletion_reason">Reason for leaving (optional)</label>
                        <textarea id="deletion_reason" name="deletion_reason" rows="3" 
                                  placeholder="Please tell us why you're leaving..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" id="confirm_delete" required>
                            <label for="confirm_delete">
                                I understand that this action is permanent and cannot be undone
                            </label>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" class="btn btn-danger">Request Deletion</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // Download user data
        function downloadData() {
            const data = {
                profile: <?php echo json_encode($student); ?>,
                bookings: <?php 
                    $bookings_query = "SELECT b.*, t.trip_date, t.departure_time, t.route 
                                     FROM bookings b 
                                     JOIN trips t ON b.trip_id = t.trip_id 
                                     WHERE b.student_id = '$user_id'";
                    $bookings_result = mysqli_query($conn, $bookings_query);
                    $bookings = [];
                    while ($row = mysqli_fetch_assoc($bookings_result)) {
                        $bookings[] = $row;
                    }
                    echo json_encode($bookings);
                ?>,
                download_date: new Date().toISOString()
            };
            
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'mainres_bus_data_' + new Date().toISOString().split('T')[0] + '.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            
            alert('Your data has been downloaded. Please keep it secure.');
        }

        // Show delete modal
        function showDeleteModal() {
            document.getElementById('deleteModal').style.display = 'block';
        }

        // Close delete modal
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Confirm deletion
        function confirmDeletion() {
            return confirm('Are you absolutely sure? This will permanently delete your account.');
        }

        // Form validation
        document.querySelectorAll('.profile-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const passwordForm = form.querySelector('input[name="new_password"]');
                if (passwordForm) {
                    const newPass = form.querySelector('input[name="new_password"]').value;
                    const confirmPass = form.querySelector('input[name="confirm_password"]').value;
                    
                    if (newPass !== confirmPass) {
                        e.preventDefault();
                        alert('New passwords do not match!');
                        return false;
                    }
                    
                    if (newPass.length < 6) {
                        e.preventDefault();
                        alert('New password must be at least 6 characters!');
                        return false;
                    }
                }
                return true;
            });
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target == modal) {
                closeDeleteModal();
            }
        }
    </script>
</body>
</html>