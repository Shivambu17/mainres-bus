<?php
require_once 'config.php';
require_once 'functions.php';

$error = '';
$success = '';

if (isLoggedIn()) {
    if (isStudent()) {
        redirect('student_dashboard.php');
    } else {
        redirect('index.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = sanitize($_POST['name']);
    $surname = sanitize($_POST['surname']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $faculty = sanitize($_POST['faculty']);
    $residence = sanitize($_POST['residence']);
    $course = sanitize($_POST['course']);
    $year_level = sanitize($_POST['year_level']);
    
    // Validation
    if (empty($name) || empty($surname) || empty($email) || empty($password) || 
        empty($faculty) || empty($residence)) {
        $error = "Please fill in all required fields";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters";
    } elseif ($faculty != 'Teaching') {
        $error = "Only Teaching faculty students can register";
    } elseif ($residence != 'MainRes') {
        $error = "Only MainRes residents can register";
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
                $user_query = "INSERT INTO users (email, password_hash, role, name, surname, phone) 
                               VALUES ('$email', '$password_hash', 'student', '$name', '$surname', '$phone')";
                
                if (mysqli_query($conn, $user_query)) {
                    $user_id = mysqli_insert_id($conn);
                    
                    // Insert into students table
                    $student_query = "INSERT INTO students (student_id, faculty, residence, course, year_level) 
                                      VALUES ('$user_id', '$faculty', '$residence', '$course', '$year_level')";
                    
                    if (mysqli_query($conn, $student_query)) {
                        mysqli_commit($conn);
                        $success = "Registration successful! You can now login.";
                        
                        // Send welcome notification
                        sendNotification($user_id, "Welcome to MainRes Bus System! Your account has been created successfully.", "success");
                        
                        // Redirect to login after 2 seconds
                        echo '<script>
                            setTimeout(function() {
                                window.location.href = "login.php?registered=true";
                            }, 2000);
                        </script>';
                    } else {
                        throw new Exception("Error creating student record");
                    }
                } else {
                    throw new Exception("Error creating user account");
                }
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <i class="fas fa-bus fa-3x"></i>
                <h2>Student Registration</h2>
                <p>Register for MainRes Bus System (Teaching Faculty Only)</p>
            </div>
            
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
            
            <form method="POST" action="" class="auth-form">
                <h3>Personal Information</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="name"><i class="fas fa-user"></i> First Name *</label>
                        <input type="text" id="name" name="name" required 
                               value="<?php echo isset($_POST['name']) ? $_POST['name'] : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="surname"><i class="fas fa-user"></i> Last Name *</label>
                        <input type="text" id="surname" name="surname" required 
                               value="<?php echo isset($_POST['surname']) ? $_POST['surname'] : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                    <input type="email" id="email" name="email" required 
                           value="<?php echo isset($_POST['email']) ? $_POST['email'] : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="phone"><i class="fas fa-phone"></i> Phone Number</label>
                    <input type="tel" id="phone" name="phone" 
                           value="<?php echo isset($_POST['phone']) ? $_POST['phone'] : ''; ?>">
                </div>
                
                <h3>Academic Information</h3>
                <div class="form-group">
                    <label for="faculty"><i class="fas fa-graduation-cap"></i> Faculty *</label>
                    <select id="faculty" name="faculty" required>
                        <option value="">Select Faculty</option>
                        <option value="Teaching" <?php echo (isset($_POST['faculty']) && $_POST['faculty'] == 'Teaching') ? 'selected' : ''; ?>>
                            Teaching
                        </option>
                    </select>
                    <small class="form-text">Only Teaching faculty students can register</small>
                </div>
                
                <div class="form-group">
                    <label for="residence"><i class="fas fa-home"></i> Residence *</label>
                    <select id="residence" name="residence" required>
                        <option value="">Select Residence</option>
                        <option value="MainRes" <?php echo (isset($_POST['residence']) && $_POST['residence'] == 'MainRes') ? 'selected' : ''; ?>>
                            Main Residence (MainRes)
                        </option>
                    </select>
                    <small class="form-text">Only MainRes residents can register</small>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="course"><i class="fas fa-book"></i> Course</label>
                        <input type="text" id="course" name="course" 
                               value="<?php echo isset($_POST['course']) ? $_POST['course'] : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="year_level"><i class="fas fa-calendar-alt"></i> Year Level</label>
                        <select id="year_level" name="year_level">
                            <option value="">Select Year</option>
                            <option value="1" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == '1') ? 'selected' : ''; ?>>1st Year</option>
                            <option value="2" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == '2') ? 'selected' : ''; ?>>2nd Year</option>
                            <option value="3" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == '3') ? 'selected' : ''; ?>>3rd Year</option>
                            <option value="4" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == '4') ? 'selected' : ''; ?>>4th Year</option>
                            <option value="5" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == '5') ? 'selected' : ''; ?>>Postgrad</option>
                        </select>
                    </div>
                </div>
                
                <h3>Account Security</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Password *</label>
                        <input type="password" id="password" name="password" required>
                        <small class="form-text">Minimum 6 characters</small>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-lock"></i> Confirm Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="form-check">
                        <input type="checkbox" id="terms" name="terms" required>
                        <label for="terms">
                            I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>
                        </label>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-user-plus"></i> Register Account
                </button>
                
                <div class="auth-links">
                    <a href="login.php"><i class="fas fa-sign-in-alt"></i> Already have an account? Login</a>
                    <a href="index.php"><i class="fas fa-home"></i> Back to Home</a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="scripts.js"></script>
</body>
</html>