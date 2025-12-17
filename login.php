<?php
require_once 'config.php';
require_once 'functions.php';

$error = '';
$success = '';

if (isLoggedIn()) {
    if (isStudent()) {
        redirect('student_dashboard.php');
    } elseif (isDriver()) {
        redirect('driver_dashboard.php');
    } elseif (isAdmin()) {
        redirect('admin_dashboard.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];
    $role = sanitize($_POST['role']);
    
    if (empty($email) || empty($password) || empty($role)) {
        $error = "Please fill in all fields";
    } else {
        $query = "SELECT u.* FROM users u WHERE u.email = '$email' AND u.role = '$role' AND u.status = 'active'";
        
        // Join with specific tables based on role
        if ($role == 'student') {
            $query = "SELECT u.*, s.faculty, s.residence 
                      FROM users u 
                      JOIN students s ON u.user_id = s.student_id 
                      WHERE u.email = '$email' AND u.role = 'student' AND u.status = 'active'";
        } elseif ($role == 'driver') {
            $query = "SELECT u.*, d.license_number 
                      FROM users u 
                      JOIN drivers d ON u.user_id = d.driver_id 
                      WHERE u.email = '$email' AND u.role = 'driver' AND u.status = 'active'";
        }
        
        $result = mysqli_query($conn, $query);
        
        if ($result && mysqli_num_rows($result) == 1) {
            $user = mysqli_fetch_assoc($result);
            
            if (verifyPassword($password, $user['password_hash'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];
                
                if ($role == 'student') {
                    $_SESSION['faculty'] = $user['faculty'];
                    $_SESSION['residence'] = $user['residence'];
                    redirect('student_dashboard.php');
                } elseif ($role == 'driver') {
                    redirect('driver_dashboard.php');
                } elseif ($role == 'admin') {
                    redirect('admin_dashboard.php');
                }
            } else {
                $error = "Invalid password";
            }
        } else {
            $error = "No account found with these credentials";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <i class="fas fa-bus fa-3x"></i>
                <h2>MainRes Bus System</h2>
                <p>Login to your account</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> Registration successful! Please login.
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="auth-form">
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" id="email" name="email" required 
                           placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? $_POST['email'] : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <input type="password" id="password" name="password" required 
                           placeholder="Enter your password">
                </div>
                
                <div class="form-group">
                    <label for="role"><i class="fas fa-user-tag"></i> Login As</label>
                    <select id="role" name="role" required>
                        <option value="">Select your role</option>
                        <option value="student" <?php echo (isset($_POST['role']) && $_POST['role'] == 'student') ? 'selected' : ''; ?>>Student</option>
                        <option value="driver" <?php echo (isset($_POST['role']) && $_POST['role'] == 'driver') ? 'selected' : ''; ?>>Driver</option>
                        <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] == 'admin') ? 'selected' : ''; ?>>Administrator</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
                
                <div class="auth-links">
                    <a href="register.php"><i class="fas fa-user-plus"></i> Don't have an account? Register</a>
                    <a href="index.php"><i class="fas fa-home"></i> Back to Home</a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="scripts.js"></script>
</body>
</html>