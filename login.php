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
        // Build the base query based on role
        if ($role == 'student') {
            $query = "SELECT u.*, s.faculty, s.residence 
                      FROM users u 
                      LEFT JOIN students s ON u.user_id = s.student_id 
                      WHERE u.email = '$email' AND u.role = '$role' AND u.status = 'active'";
        } elseif ($role == 'driver') {
            $query = "SELECT u.*, d.license_number 
                      FROM users u 
                      LEFT JOIN drivers d ON u.user_id = d.driver_id 
                      WHERE u.email = '$email' AND u.role = '$role' AND u.status = 'active'";
        } elseif ($role == 'admin') {
            $query = "SELECT u.* FROM users u 
                      WHERE u.email = '$email' AND u.role = '$role' AND u.status = 'active'";
        } else {
            $error = "Invalid role selected";
        }
        
        if (!$error) {
            $result = mysqli_query($conn, $query);
            
            if ($result) {
                if (mysqli_num_rows($result) == 1) {
                    $user = mysqli_fetch_assoc($result);
                    
                    // Debug: Uncomment to see what data is being fetched
                    // echo "<pre>User data: ";
                    // print_r($user);
                    // echo "</pre>";
                    
                    // Check password
                    if (password_verify($password, $user['password_hash'])) {
                        // Set session variables
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['name'] = $user['name'];
                        $_SESSION['surname'] = $user['surname'] ?? '';
                        
                        // Set role-specific session data
                        if ($role == 'student') {
                            $_SESSION['faculty'] = $user['faculty'] ?? '';
                            $_SESSION['residence'] = $user['residence'] ?? '';
                            redirect('student_dashboard.php');
                        } elseif ($role == 'driver') {
                            $_SESSION['license_number'] = $user['license_number'] ?? '';
                            redirect('driver_dashboard.php');
                        } elseif ($role == 'admin') {
                            redirect('admin_dashboard.php');
                        }
                    } else {
                        $error = "Invalid email or password";
                    }
                } else {
                    $error = "No active account found with these credentials";
                }
            } else {
                $error = "Database query error: " . mysqli_error($conn);
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
    <title>Login - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .auth-page {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }
        .auth-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .auth-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 30px 20px;
        }
        .auth-header i {
            margin-bottom: 15px;
        }
        .auth-header h2 {
            margin: 10px 0;
            font-size: 24px;
        }
        .auth-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .auth-form {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
        }
        .auth-links {
            margin-top: 20px;
            text-align: center;
        }
        .auth-links a {
            display: block;
            color: #667eea;
            text-decoration: none;
            margin: 10px 0;
            font-size: 14px;
        }
        .auth-links a:hover {
            text-decoration: underline;
        }
        .alert {
            padding: 12px 15px;
            margin: 20px;
            border-radius: 5px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        .alert-success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }
        .alert i {
            margin-right: 10px;
        }
    </style>
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
                           placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <input type="password" id="password" name="password" required 
                           placeholder="Enter your password">
                    <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                        <i class="fas fa-info-circle"></i> Passwords are case-sensitive
                    </small>
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
                    <a href="forgot_password.php"><i class="fas fa-key"></i> Forgot Password?</a>
                    <a href="index.php"><i class="fas fa-home"></i> Back to Home</a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="scripts.js"></script>
    <script>
        // Add some client-side validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const role = document.getElementById('role').value;
            
            if (!email || !password || !role) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }
            
            // Simple email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }
        });
    </script>
</body>
</html>