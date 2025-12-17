<?php
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in and is a driver
if (!isLoggedIn() || !isDriver()) {
    redirect('login.php');
}

$driver_id = $_SESSION['user_id'];
$trip_id = isset($_GET['trip_id']) ? sanitize($_GET['trip_id']) : '';
$error = '';
$success = '';

// Handle report submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['submit_report'])) {
        $issue_type = sanitize($_POST['issue_type']);
        $title = sanitize($_POST['title']);
        $description = sanitize($_POST['description']);
        $severity = sanitize($_POST['severity']);
        $report_trip_id = isset($_POST['trip_id']) ? sanitize($_POST['trip_id']) : null;
        
        if (empty($title) || empty($description)) {
            $error = "Please fill in all required fields";
        } else {
            $insert_report = "INSERT INTO issue_reports (driver_id, trip_id, issue_type, title, description, severity) 
                              VALUES ('$driver_id', " . ($report_trip_id ? "'$report_trip_id'" : "NULL") . ", 
                                     '$issue_type', '$title', '$description', '$severity')";
            
            if (mysqli_query($conn, $insert_report)) {
                $success = "Issue report submitted successfully!";
                
                // Clear form if successful
                if ($success) {
                    $_POST = array();
                }
            } else {
                $error = "Error submitting report: " . mysqli_error($conn);
            }
        }
    }
}

// Get driver's recent trips for dropdown
$recent_trips_query = "SELECT t.*, b.bus_number
                      FROM trips t
                      JOIN buses b ON t.bus_id = b.bus_id
                      WHERE t.driver_id = '$driver_id'
                      AND t.trip_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      ORDER BY t.trip_date DESC, t.departure_time DESC
                      LIMIT 10";
$recent_trips_result = mysqli_query($conn, $recent_trips_query);

// Get specific trip if provided
$current_trip = null;
if ($trip_id) {
    $trip_query = "SELECT t.*, b.bus_number
                   FROM trips t
                   JOIN buses b ON t.bus_id = b.bus_id
                   WHERE t.trip_id = '$trip_id'
                   AND t.driver_id = '$driver_id'";
    $trip_result = mysqli_query($conn, $trip_query);
    
    if (mysqli_num_rows($trip_result) == 1) {
        $current_trip = mysqli_fetch_assoc($trip_result);
    }
}

// Get driver's recent reports
$recent_reports_query = "SELECT r.*, t.route, t.trip_date
                        FROM issue_reports r
                        LEFT JOIN trips t ON r.trip_id = t.trip_id
                        WHERE r.driver_id = '$driver_id'
                        ORDER BY r.reported_at DESC
                        LIMIT 10";
$recent_reports_result = mysqli_query($conn, $recent_reports_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Issues - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar sidebar-driver">
            <div class="sidebar-header">
                <i class="fas fa-bus"></i>
                <h3>MainRes Bus</h3>
            </div>
            <nav class="sidebar-nav">
                <a href="driver_dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="driver_scan.php">
                    <i class="fas fa-qrcode"></i> Scan QR Codes
                </a>
                <a href="driver_trips.php">
                    <i class="fas fa-route"></i> My Trips
                </a>
                <a href="driver_reports.php" class="active">
                    <i class="fas fa-exclamation-circle"></i> Report Issues
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
                <h1>Report Issues</h1>
                <div class="header-actions">
                    <a href="driver_dashboard.php" class="btn btn-secondary">
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

            <div class="reports-grid">
                <!-- Left Column - Report Form -->
                <div class="reports-column">
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-plus-circle"></i> Submit New Report</h2>
                        </div>
                        
                        <form method="POST" action="" class="report-form">
                            <!-- Trip Selection -->
                            <div class="form-group">
                                <label for="trip_id"><i class="fas fa-route"></i> Related Trip (Optional)</label>
                                <select id="trip_id" name="trip_id">
                                    <option value="">Select a trip (optional)</option>
                                    <?php if ($current_trip): ?>
                                        <option value="<?php echo $current_trip['trip_id']; ?>" selected>
                                            <?php echo date('d M', strtotime($current_trip['trip_date'])); ?> 
                                            at <?php echo date('H:i', strtotime($current_trip['departure_time'])); ?> - 
                                            <?php echo $current_trip['route']; ?> (Bus <?php echo $current_trip['bus_number']; ?>)
                                        </option>
                                    <?php endif; ?>
                                    
                                    <?php while ($trip = mysqli_fetch_assoc($recent_trips_result)): ?>
                                        <option value="<?php echo $trip['trip_id']; ?>">
                                            <?php echo date('d M', strtotime($trip['trip_date'])); ?> 
                                            at <?php echo date('H:i', strtotime($trip['departure_time'])); ?> - 
                                            <?php echo $trip['route']; ?> (Bus <?php echo $trip['bus_number']; ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <!-- Issue Type -->
                            <div class="form-group">
                                <label for="issue_type"><i class="fas fa-exclamation-triangle"></i> Issue Type *</label>
                                <select id="issue_type" name="issue_type" required>
                                    <option value="">Select issue type</option>
                                    <option value="breakdown" <?php echo (isset($_POST['issue_type']) && $_POST['issue_type'] == 'breakdown') ? 'selected' : ''; ?>>Vehicle Breakdown</option>
                                    <option value="accident" <?php echo (isset($_POST['issue_type']) && $_POST['issue_type'] == 'accident') ? 'selected' : ''; ?>>Accident</option>
                                    <option value="delay" <?php echo (isset($_POST['issue_type']) && $_POST['issue_type'] == 'delay') ? 'selected' : ''; ?>>Delay</option>
                                    <option value="maintenance" <?php echo (isset($_POST['issue_type']) && $_POST['issue_type'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance Issue</option>
                                    <option value="student_issue" <?php echo (isset($_POST['issue_type']) && $_POST['issue_type'] == 'student_issue') ? 'selected' : ''; ?>>Student Issue</option>
                                    <option value="other" <?php echo (isset($_POST['issue_type']) && $_POST['issue_type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <!-- Severity -->
                            <div class="form-group">
                                <label for="severity"><i class="fas fa-fire"></i> Severity *</label>
                                <div class="severity-buttons">
                                    <label class="severity-option">
                                        <input type="radio" name="severity" value="low" <?php echo (isset($_POST['severity']) && $_POST['severity'] == 'low') ? 'checked' : ''; ?> required>
                                        <span class="severity-label severity-low">
                                            <i class="fas fa-info-circle"></i> Low
                                        </span>
                                    </label>
                                    <label class="severity-option">
                                        <input type="radio" name="severity" value="medium" <?php echo (isset($_POST['severity']) && $_POST['severity'] == 'medium') ? 'checked' : ''; ?> required>
                                        <span class="severity-label severity-medium">
                                            <i class="fas fa-exclamation-triangle"></i> Medium
                                        </span>
                                    </label>
                                    <label class="severity-option">
                                        <input type="radio" name="severity" value="high" <?php echo (isset($_POST['severity']) && $_POST['severity'] == 'high') ? 'checked' : ''; ?> required>
                                        <span class="severity-label severity-high">
                                            <i class="fas fa-exclamation-circle"></i> High
                                        </span>
                                    </label>
                                    <label class="severity-option">
                                        <input type="radio" name="severity" value="critical" <?php echo (isset($_POST['severity']) && $_POST['severity'] == 'critical') ? 'checked' : ''; ?> required>
                                        <span class="severity-label severity-critical">
                                            <i class="fas fa-skull-crossbones"></i> Critical
                                        </span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Title -->
                            <div class="form-group">
                                <label for="title"><i class="fas fa-heading"></i> Title *</label>
                                <input type="text" id="title" name="title" required 
                                       placeholder="Brief description of the issue"
                                       value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                            </div>
                            
                            <!-- Description -->
                            <div class="form-group">
                                <label for="description"><i class="fas fa-align-left"></i> Description *</label>
                                <textarea id="description" name="description" rows="6" required 
                                          placeholder="Provide detailed information about the issue..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                            </div>
                            
                            <!-- Emergency Contact Info -->
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Emergency Contacts</label>
                                <div class="emergency-contacts">
                                    <p><strong>Transport Office:</strong> +27 11 123 4567</p>
                                    <p><strong>Maintenance:</strong> +27 11 123 4568</p>
                                    <p><strong>Emergency Services:</strong> 10111</p>
                                </div>
                            </div>
                            
                            <button type="submit" name="submit_report" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Submit Report
                            </button>
                        </form>
                    </section>
                </div>

                <!-- Right Column - Recent Reports -->
                <div class="reports-column">
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-history"></i> Recent Reports</h2>
                            <span class="badge"><?php echo mysqli_num_rows($recent_reports_result); ?> reports</span>
                        </div>
                        
                        <?php if (mysqli_num_rows($recent_reports_result) > 0): ?>
                            <div class="reports-list">
                                <?php while ($report = mysqli_fetch_assoc($recent_reports_result)): ?>
                                    <div class="report-item severity-<?php echo $report['severity']; ?>">
                                        <div class="report-header">
                                            <h4><?php echo $report['title']; ?></h4>
                                            <span class="report-type"><?php echo ucfirst($report['issue_type']); ?></span>
                                        </div>
                                        
                                        <div class="report-body">
                                            <p><?php echo substr($report['description'], 0, 150); ?>...</p>
                                            
                                            <?php if ($report['route']): ?>
                                                <p class="report-trip">
                                                    <i class="fas fa-route"></i> 
                                                    <?php echo $report['route']; ?> 
                                                    (<?php echo date('d M', strtotime($report['trip_date'])); ?>)
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="report-footer">
                                            <div class="report-meta">
                                                <span class="report-date">
                                                    <i class="fas fa-clock"></i> 
                                                    <?php echo date('d M, H:i', strtotime($report['reported_at'])); ?>
                                                </span>
                                                <span class="report-status status-<?php echo $report['status']; ?>">
                                                    <?php echo ucfirst($report['status']); ?>
                                                </span>
                                            </div>
                                            
                                            <?php if ($report['resolution_notes']): ?>
                                                <div class="resolution-notes">
                                                    <strong>Resolution:</strong> <?php echo $report['resolution_notes']; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle fa-3x"></i>
                                <h3>No reports submitted</h3>
                                <p>You haven't submitted any issue reports yet.</p>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- Quick Report Templates -->
                    <section class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-file-alt"></i> Quick Reports</h2>
                        </div>
                        
                        <div class="quick-reports">
                            <button class="quick-report-btn" onclick="fillQuickReport('breakdown', 'Bus breakdown - engine issue', 'The bus engine failed to start this morning. Suspected fuel pump issue.')">
                                <i class="fas fa-car-battery"></i>
                                <span>Bus Breakdown</span>
                            </button>
                            
                            <button class="quick-report-btn" onclick="fillQuickReport('delay', 'Traffic delay - heavy congestion', 'Stuck in traffic on Main Road. Expected delay of 30 minutes.')">
                                <i class="fas fa-traffic-light"></i>
                                <span>Traffic Delay</span>
                            </button>
                            
                            <button class="quick-report-btn" onclick="fillQuickReport('student_issue', 'Student behavioral issue', 'Student causing disturbance on the bus. Need assistance.')">
                                <i class="fas fa-user-graduate"></i>
                                <span>Student Issue</span>
                            </button>
                            
                            <button class="quick-report-btn" onclick="fillQuickReport('maintenance', 'Air conditioning not working', 'Bus AC system not functioning properly. Very hot inside.')">
                                <i class="fas fa-wind"></i>
                                <span>AC Issue</span>
                            </button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // Fill form with quick report template
        function fillQuickReport(issueType, title, description) {
            document.getElementById('issue_type').value = issueType;
            document.getElementById('title').value = title;
            document.getElementById('description').value = description;
            
            // Set medium severity by default
            document.querySelector('input[name="severity"][value="medium"]').checked = true;
            
            // Scroll to form
            document.querySelector('.report-form').scrollIntoView({ behavior: 'smooth' });
            
            alert('Quick report template loaded. Please review and submit.');
        }

        // Form validation
        document.querySelector('.report-form').addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const description = document.getElementById('description').value.trim();
            const issueType = document.getElementById('issue_type').value;
            const severity = document.querySelector('input[name="severity"]:checked');
            
            if (!title || title.length < 5) {
                e.preventDefault();
                alert('Please enter a descriptive title (at least 5 characters)');
                return false;
            }
            
            if (!description || description.length < 20) {
                e.preventDefault();
                alert('Please provide a detailed description (at least 20 characters)');
                return false;
            }
            
            if (!issueType) {
                e.preventDefault();
                alert('Please select an issue type');
                return false;
            }
            
            if (!severity) {
                e.preventDefault();
                alert('Please select a severity level');
                return false;
            }
            
            // Confirm for critical issues
            if (severity.value === 'critical') {
                if (!confirm('This is marked as CRITICAL. Are you sure you want to submit this report?')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            return true;
        });

        // Auto-save draft (local storage)
        function saveDraft() {
            const draft = {
                trip_id: document.getElementById('trip_id').value,
                issue_type: document.getElementById('issue_type').value,
                title: document.getElementById('title').value,
                description: document.getElementById('description').value,
                severity: document.querySelector('input[name="severity"]:checked')?.value,
                timestamp: new Date().toISOString()
            };
            
            localStorage.setItem('report_draft', JSON.stringify(draft));
        }

        // Load draft
        function loadDraft() {
            const draft = localStorage.getItem('report_draft');
            if (draft) {
                const data = JSON.parse(draft);
                
                document.getElementById('trip_id').value = data.trip_id || '';
                document.getElementById('issue_type').value = data.issue_type || '';
                document.getElementById('title').value = data.title || '';
                document.getElementById('description').value = data.description || '';
                
                if (data.severity) {
                    document.querySelector(`input[name="severity"][value="${data.severity}"]`).checked = true;
                }
                
                // Show notification
                const notification = document.createElement('div');
                notification.className = 'alert alert-info';
                notification.innerHTML = `
                    <i class="fas fa-save"></i>
                    Draft loaded from ${new Date(data.timestamp).toLocaleTimeString()}.
                    <button onclick="clearDraft()" class="btn btn-sm btn-secondary">Clear Draft</button>
                `;
                
                document.querySelector('.dashboard-header').after(notification);
            }
        }

        // Clear draft
        function clearDraft() {
            localStorage.removeItem('report_draft');
            alert('Draft cleared');
            window.location.reload();
        }

        // Auto-save every 30 seconds
        const formFields = document.querySelectorAll('#trip_id, #issue_type, #title, #description, input[name="severity"]');
        formFields.forEach(field => {
            field.addEventListener('change', saveDraft);
            field.addEventListener('input', saveDraft);
        });

        // Load draft on page load
        window.addEventListener('load', loadDraft);

        // Clear draft on successful submission
        document.querySelector('.report-form').addEventListener('submit', function() {
            localStorage.removeItem('report_draft');
        });
    </script>
</body>
</html>