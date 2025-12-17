<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('login.php');
}

// Check if user is super admin
$admin_query = "SELECT a.admin_level FROM admins a WHERE a.admin_id = '{$_SESSION['user_id']}'";
$admin_result = mysqli_query($conn, $admin_query);
$admin = mysqli_fetch_assoc($admin_result);

if ($admin['admin_level'] != 'super') {
    redirect('admin_dashboard.php');
}

$error = '';
$success = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_settings'])) {
        mysqli_begin_transaction($conn);
        
        try {
            // Update each setting
            foreach ($_POST['settings'] as $key => $value) {
                $value = sanitize($value);
                $update_query = "UPDATE system_settings SET setting_value = '$value' WHERE setting_key = '$key'";
                mysqli_query($conn, $update_query);
            }
            
            mysqli_commit($conn);
            $success = "Settings updated successfully!";
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error updating settings: " . $e->getMessage();
        }
    }
    
    elseif (isset($_POST['send_test_email'])) {
        $test_email = sanitize($_POST['test_email']);
        
        if (filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
            // Send test email
            $subject = "MainRes Bus System - Test Email";
            $message = "This is a test email from the MainRes Bus System.\n\n";
            $message .= "Sent: " . date('Y-m-d H:i:s') . "\n";
            $message .= "System: " . SITE_URL . "\n\n";
            $message .= "If you received this email, your email configuration is working correctly.";
            
            $headers = "From: transport@mainres.ac.za\r\n";
            $headers .= "Reply-To: transport@mainres.ac.za\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            if (mail($test_email, $subject, $message, $headers)) {
                $success = "Test email sent to $test_email";
            } else {
                $error = "Failed to send test email. Check your server mail configuration.";
            }
        } else {
            $error = "Invalid email address";
        }
    }
    
    elseif (isset($_POST['backup_database'])) {
        // Create database backup
        $backup_file = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $backup_path = 'backups/' . $backup_file;
        
        // Create backups directory if it doesn't exist
        if (!is_dir('backups')) {
            mkdir('backups', 0777, true);
        }
        
        // Get all table names
        $tables = [];
        $result = mysqli_query($conn, "SHOW TABLES");
        while ($row = mysqli_fetch_row($result)) {
            $tables[] = $row[0];
        }
        
        $backup_content = "-- MainRes Bus System Database Backup\n";
        $backup_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $backup_content .= "-- Database: " . DB_NAME . "\n\n";
        
        foreach ($tables as $table) {
            // Table structure
            $backup_content .= "--\n-- Table structure for table `$table`\n--\n";
            $backup_content .= "DROP TABLE IF EXISTS `$table`;\n";
            $result = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
            $row = mysqli_fetch_row($result);
            $backup_content .= $row[1] . ";\n\n";
            
            // Table data
            $backup_content .= "--\n-- Dumping data for table `$table`\n--\n";
            $result = mysqli_query($conn, "SELECT * FROM `$table`");
            while ($row = mysqli_fetch_assoc($result)) {
                $columns = implode('`, `', array_keys($row));
                $values = implode("', '", array_map('addslashes', array_values($row)));
                $backup_content .= "INSERT INTO `$table` (`$columns`) VALUES ('$values');\n";
            }
            $backup_content .= "\n";
        }
        
        if (file_put_contents($backup_path, $backup_content)) {
            $success = "Database backup created: $backup_file";
        } else {
            $error = "Failed to create database backup";
        }
    }
    
    elseif (isset($_POST['clear_cache'])) {
        // Clear cache directory
        $cache_dir = 'cache/';
        if (is_dir($cache_dir)) {
            $files = glob($cache_dir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $success = "Cache cleared successfully";
        } else {
            $error = "Cache directory not found";
        }
    }
    
    elseif (isset($_POST['optimize_database'])) {
        // Optimize all tables
        $tables = [];
        $result = mysqli_query($conn, "SHOW TABLES");
        while ($row = mysqli_fetch_row($result)) {
            $tables[] = $row[0];
        }
        
        $optimized = 0;
        foreach ($tables as $table) {
            mysqli_query($conn, "OPTIMIZE TABLE `$table`");
            $optimized++;
        }
        
        $success = "Optimized $optimized database tables";
    }
}

// Get all system settings
$settings_query = "SELECT * FROM system_settings ORDER BY category, setting_key";
$settings_result = mysqli_query($conn, $settings_query);
$settings = [];
while ($row = mysqli_fetch_assoc($settings_result)) {
    $settings[$row['category']][$row['setting_key']] = $row;
}

// Get system information
$sys_info = [
    'php_version' => phpversion(),
    'mysql_version' => mysqli_get_server_info($conn),
    'server_software' => $_SERVER['SERVER_SOFTWARE'],
    'server_name' => $_SERVER['SERVER_NAME'],
    'max_upload_size' => ini_get('upload_max_filesize'),
    'max_execution_time' => ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit'),
    'timezone' => date_default_timezone_get(),
    'site_url' => SITE_URL,
    'active_users' => mysqli_fetch_assoc(mysqli_query($conn, 
        "SELECT COUNT(*) as count FROM users WHERE status = 'active'"))['count'],
    'total_trips' => mysqli_fetch_assoc(mysqli_query($conn, 
        "SELECT COUNT(*) as count FROM trips WHERE trip_date >= CURDATE()"))['count'],
    'disk_usage' => round(disk_free_space(__DIR__) / 1024 / 1024 / 1024, 2) . ' GB free'
];

// Get recent backups
$backups = [];
if (is_dir('backups')) {
    $files = glob('backups/backup_*.sql');
    rsort($files);
    foreach ($files as $file) {
        $backups[] = [
            'filename' => basename($file),
            'size' => round(filesize($file) / 1024, 2) . ' KB',
            'modified' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }
}

// Get system logs
$logs_query = "SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 50";
$logs_result = mysqli_query($conn, $logs_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - MainRes Bus System</title>
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
                <a href="admin_users.php">
                    <i class="fas fa-users"></i> User Management
                </a>
                <a href="admin_reports.php">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
                <a href="admin_settings.php" class="active">
                    <i class="fas fa-cog"></i> Settings
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
                <h1>System Settings</h1>
                <div class="header-actions">
                    <span class="user-role">
                        <i class="fas fa-shield-alt"></i> Super Administrator
                    </span>
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

            <!-- Settings Tabs -->
            <div class="settings-tabs">
                <button class="tab-btn active" onclick="showTab('general')">
                    <i class="fas fa-cog"></i> General
                </button>
                <button class="tab-btn" onclick="showTab('booking')">
                    <i class="fas fa-ticket-alt"></i> Booking
                </button>
                <button class="tab-btn" onclick="showTab('email')">
                    <i class="fas fa-envelope"></i> Email
                </button>
                <button class="tab-btn" onclick="showTab('system')">
                    <i class="fas fa-server"></i> System
                </button>
                <button class="tab-btn" onclick="showTab('backup')">
                    <i class="fas fa-database"></i> Backup
                </button>
                <button class="tab-btn" onclick="showTab('logs')">
                    <i class="fas fa-history"></i> Logs
                </button>
            </div>

            <!-- General Settings -->
            <div id="general-tab" class="tab-content active">
                <section class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-cog"></i> General Settings</h2>
                    </div>
                    
                    <form method="POST" action="" class="settings-form">
                        <?php if (isset($settings['General'])): ?>
                            <?php foreach ($settings['General'] as $key => $setting): ?>
                                <div class="form-group">
                                    <label for="<?php echo $key; ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $key)); ?>
                                        <?php if ($setting['description']): ?>
                                            <small><?php echo $setting['description']; ?></small>
                                        <?php endif; ?>
                                    </label>
                                    
                                    <?php if ($setting['setting_type'] == 'boolean'): ?>
                                        <select name="settings[<?php echo $key; ?>]" id="<?php echo $key; ?>">
                                            <option value="1" <?php echo ($setting['setting_value'] == '1') ? 'selected' : ''; ?>>Enabled</option>
                                            <option value="0" <?php echo ($setting['setting_value'] == '0') ? 'selected' : ''; ?>>Disabled</option>
                                        </select>
                                    <?php elseif ($setting['setting_type'] == 'integer'): ?>
                                        <input type="number" name="settings[<?php echo $key; ?>]" id="<?php echo $key; ?>"
                                               value="<?php echo $setting['setting_value']; ?>">
                                    <?php else: ?>
                                        <input type="text" name="settings[<?php echo $key; ?>]" id="<?php echo $key; ?>"
                                               value="<?php echo $setting['setting_value']; ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <div class="form-actions">
                            <button type="submit" name="update_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save General Settings
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <!-- Booking Settings -->
            <div id="booking-tab" class="tab-content">
                <section class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-ticket-alt"></i> Booking Settings</h2>
                    </div>
                    
                    <form method="POST" action="" class="settings-form">
                        <?php if (isset($settings['Booking'])): ?>
                            <?php foreach ($settings['Booking'] as $key => $setting): ?>
                                <div class="form-group">
                                    <label for="<?php echo $key; ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $key)); ?>
                                        <?php if ($setting['description']): ?>
                                            <small><?php echo $setting['description']; ?></small>
                                        <?php endif; ?>
                                    </label>
                                    
                                    <?php if ($setting['setting_type'] == 'boolean'): ?>
                                        <select name="settings[<?php echo $key; ?>]" id="<?php echo $key; ?>">
                                            <option value="1" <?php echo ($setting['setting_value'] == '1') ? 'selected' : ''; ?>>Enabled</option>
                                            <option value="0" <?php echo ($setting['setting_value'] == '0') ? 'selected' : ''; ?>>Disabled</option>
                                        </select>
                                    <?php elseif ($setting['setting_type'] == 'integer'): ?>
                                        <input type="number" name="settings[<?php echo $key; ?>]" id="<?php echo $key; ?>"
                                               value="<?php echo $setting['setting_value']; ?>">
                                    <?php else: ?>
                                        <input type="text" name="settings[<?php echo $key; ?>]" id="<?php echo $key; ?>"
                                               value="<?php echo $setting['setting_value']; ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <div class="form-actions">
                            <button type="submit" name="update_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Booking Settings
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <!-- Email Settings -->
            <div id="email-tab" class="tab-content">
                <section class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-envelope"></i> Email Settings</h2>
                    </div>
                    
                    <form method="POST" action="" class="settings-form">
                        <?php if (isset($settings['Email'])): ?>
                            <?php foreach ($settings['Email'] as $key => $setting): ?>
                                <div class="form-group">
                                    <label for="<?php echo $key; ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $key)); ?>
                                        <?php if ($setting['description']): ?>
                                            <small><?php echo $setting['description']; ?></small>
                                        <?php endif; ?>
                                    </label>
                                    
                                    <?php if ($setting['setting_type'] == 'boolean'): ?>
                                        <select name="settings[<?php echo $key; ?>]" id="<?php echo $key; ?>">
                                            <option value="1" <?php echo ($setting['setting_value'] == '1') ? 'selected' : ''; ?>>Enabled</option>
                                            <option value="0" <?php echo ($setting['setting_value'] == '0') ? 'selected' : ''; ?>>Disabled</option>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" name="settings[<?php echo $key; ?>]" id="<?php echo $key; ?>"
                                               value="<?php echo $setting['setting_value']; ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- Test Email -->
                        <div class="form-group">
                            <label for="test_email">Test Email Configuration</label>
                            <div class="input-group">
                                <input type="email" id="test_email" name="test_email" 
                                       placeholder="Enter email address to send test">
                                <button type="submit" name="send_test_email" class="btn btn-secondary">
                                    <i class="fas fa-paper-plane"></i> Send Test
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="update_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Email Settings
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <!-- System Information -->
            <div id="system-tab" class="tab-content">
                <section class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-server"></i> System Information</h2>
                        <div class="system-actions">
                            <button type="button" class="btn btn-secondary" onclick="clearCache()">
                                <i class="fas fa-broom"></i> Clear Cache
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="optimizeDatabase()">
                                <i class="fas fa-database"></i> Optimize DB
                            </button>
                        </div>
                    </div>
                    
                    <div class="system-info-grid">
                        <div class="info-card">
                            <h3><i class="fas fa-code"></i> PHP Information</h3>
                            <div class="info-item">
                                <span class="info-label">PHP Version</span>
                                <span class="info-value"><?php echo $sys_info['php_version']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Memory Limit</span>
                                <span class="info-value"><?php echo $sys_info['memory_limit']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Max Execution Time</span>
                                <span class="info-value"><?php echo $sys_info['max_execution_time']; ?>s</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Max Upload Size</span>
                                <span class="info-value"><?php echo $sys_info['max_upload_size']; ?></span>
                            </div>
                        </div>
                        
                        <div class="info-card">
                            <h3><i class="fas fa-database"></i> Database Information</h3>
                            <div class="info-item">
                                <span class="info-label">MySQL Version</span>
                                <span class="info-value"><?php echo $sys_info['mysql_version']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Active Users</span>
                                <span class="info-value"><?php echo $sys_info['active_users']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Today's Trips</span>
                                <span class="info-value"><?php echo $sys_info['total_trips']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Timezone</span>
                                <span class="info-value"><?php echo $sys_info['timezone']; ?></span>
                            </div>
                        </div>
                        
                        <div class="info-card">
                            <h3><i class="fas fa-network-wired"></i> Server Information</h3>
                            <div class="info-item">
                                <span class="info-label">Server Software</span>
                                <span class="info-value"><?php echo $sys_info['server_software']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Server Name</span>
                                <span class="info-value"><?php echo $sys_info['server_name']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Site URL</span>
                                <span class="info-value"><?php echo $sys_info['site_url']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Disk Space</span>
                                <span class="info-value"><?php echo $sys_info['disk_usage']; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- System Actions Forms -->
                    <form method="POST" action="" id="clearCacheForm" style="display: none;">
                        <input type="hidden" name="clear_cache" value="1">
                    </form>
                    
                    <form method="POST" action="" id="optimizeDatabaseForm" style="display: none;">
                        <input type="hidden" name="optimize_database" value="1">
                    </form>
                </section>
            </div>

            <!-- Backup & Restore -->
            <div id="backup-tab" class="tab-content">
                <section class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-database"></i> Database Backups</h2>
                        <form method="POST" action="" style="display: inline;">
                            <button type="submit" name="backup_database" class="btn btn-primary">
                                <i class="fas fa-save"></i> Create New Backup
                            </button>
                        </form>
                    </div>
                    
                    <?php if (count($backups) > 0): ?>
                        <div class="backups-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Filename</th>
                                        <th>Size</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $backup): ?>
                                        <tr>
                                            <td><?php echo $backup['filename']; ?></td>
                                            <td><?php echo $backup['size']; ?></td>
                                            <td><?php echo $backup['modified']; ?></td>
                                            <td>
                                                <a href="backups/<?php echo $backup['filename']; ?>" 
                                                   download class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                                <button class="btn btn-danger btn-sm" 
                                                        onclick="deleteBackup('<?php echo $backup['filename']; ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-database fa-3x"></i>
                            <h3>No backups found</h3>
                            <p>Create your first database backup.</p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Backup Schedule -->
                    <div class="backup-schedule">
                        <h3><i class="fas fa-calendar"></i> Backup Schedule</h3>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <p>Automatic backups are configured to run daily at 2:00 AM.</p>
                            <p>Backups are kept for 30 days before automatic deletion.</p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- System Logs -->
            <div id="logs-tab" class="tab-content">
                <section class="dashboard-section">
                    <div class="section-header">
                        <h2><i class="fas fa-history"></i> System Logs</h2>
                        <div class="log-actions">
                            <button class="btn btn-secondary" onclick="refreshLogs()">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <button class="btn btn-secondary" onclick="exportLogs()">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <button class="btn btn-danger" onclick="clearLogs()">
                                <i class="fas fa-trash"></i> Clear Logs
                            </button>
                        </div>
                    </div>
                    
                    <?php if (mysqli_num_rows($logs_result) > 0): ?>
                        <div class="logs-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Details</th>
                                        <th>IP Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($log = mysqli_fetch_assoc($logs_result)): ?>
                                        <tr>
                                            <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                            <td>
                                                <?php echo $log['user_role'] ?: 'System'; ?><br>
                                                <small>ID: <?php echo $log['user_id'] ?: 'N/A'; ?></small>
                                            </td>
                                            <td>
                                                <span class="log-action action-<?php echo strtolower($log['action']); ?>">
                                                    <?php echo $log['action']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo $log['table_name'] ?: 'System'; ?>
                                                <?php if ($log['record_id']): ?>
                                                    (ID: <?php echo $log['record_id']; ?>)
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $log['ip_address'] ?: 'N/A'; ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list fa-3x"></i>
                            <h3>No logs found</h3>
                            <p>System logs will appear here as actions are performed.</p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // Tab switching
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activate button
            event.target.classList.add('active');
        }

        // Clear cache
        function clearCache() {
            if (confirm('Are you sure you want to clear all cache?')) {
                document.getElementById('clearCacheForm').submit();
            }
        }

        // Optimize database
        function optimizeDatabase() {
            if (confirm('Optimize database tables? This may improve performance.')) {
                document.getElementById('optimizeDatabaseForm').submit();
            }
        }

        // Delete backup
        function deleteBackup(filename) {
            if (confirm(`Delete backup file: ${filename}?`)) {
                fetch(`api_delete_backup.php?filename=${filename}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Backup deleted successfully');
                            location.reload();
                        } else {
                            alert('Error deleting backup: ' + data.message);
                        }
                    });
            }
        }

        // Refresh logs
        function refreshLogs() {
            location.reload();
        }

        // Export logs
        function exportLogs() {
            window.location.href = 'api_export_logs.php';
        }

        // Clear logs
        function clearLogs() {
            if (confirm('Clear all system logs? This action cannot be undone.')) {
                fetch('api_clear_logs.php', { method: 'POST' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Logs cleared successfully');
                            location.reload();
                        } else {
                            alert('Error clearing logs');
                        }
                    });
            }
        }

        // Form validation for email test
        document.querySelector('button[name="send_test_email"]').addEventListener('click', function(e) {
            const emailInput = document.getElementById('test_email');
            if (!emailInput.value) {
                e.preventDefault();
                alert('Please enter an email address for testing');
                return false;
            }
            
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(emailInput.value)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }
            
            return true;
        });

        // Auto-save settings
        let settingsTimeout;
        document.querySelectorAll('.settings-form input, .settings-form select').forEach(element => {
            element.addEventListener('change', function() {
                clearTimeout(settingsTimeout);
                settingsTimeout = setTimeout(() => {
                    // Show saving indicator
                    const form = this.closest('form');
                    const submitBtn = form.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                    submitBtn.disabled = true;
                    
                    // Submit form after delay
                    setTimeout(() => {
                        form.submit();
                    }, 500);
                }, 1000);
            });
        });

        // Show active tab based on URL hash
        window.addEventListener('load', function() {
            const hash = window.location.hash.substring(1);
            if (hash) {
                const tabBtn = document.querySelector(`.tab-btn[onclick*="${hash}"]`);
                if (tabBtn) {
                    tabBtn.click();
                }
            }
        });
    </script>
</body>
</html>