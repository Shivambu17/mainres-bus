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
$bus_id = isset($_GET['id']) ? sanitize($_GET['id']) : '';

// Handle bus actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_bus'])) {
        $bus_number = sanitize($_POST['bus_number']);
        $capacity = sanitize($_POST['capacity']);
        $model = sanitize($_POST['model']);
        $registration = sanitize($_POST['registration_number']);
        $status = sanitize($_POST['status']);
        
        // Check if bus number already exists
        $check_bus = "SELECT * FROM buses WHERE bus_number = '$bus_number'";
        $result = mysqli_query($conn, $check_bus);
        
        if (mysqli_num_rows($result) > 0) {
            $error = "Bus number already exists";
        } else {
            $insert_bus = "INSERT INTO buses (bus_number, capacity, model, registration_number, status) 
                           VALUES ('$bus_number', '$capacity', '$model', '$registration', '$status')";
            
            if (mysqli_query($conn, $insert_bus)) {
                $success = "Bus added successfully";
                // Clear form
                $_POST = array();
            } else {
                $error = "Error adding bus: " . mysqli_error($conn);
            }
        }
    }
    
    elseif (isset($_POST['update_bus'])) {
        $bus_id = sanitize($_POST['bus_id']);
        $bus_number = sanitize($_POST['bus_number']);
        $capacity = sanitize($_POST['capacity']);
        $model = sanitize($_POST['model']);
        $registration = sanitize($_POST['registration_number']);
        $status = sanitize($_POST['status']);
        
        $update_bus = "UPDATE buses SET 
                       bus_number = '$bus_number',
                       capacity = '$capacity',
                       model = '$model',
                       registration_number = '$registration',
                       status = '$status'
                       WHERE bus_id = '$bus_id'";
        
        if (mysqli_query($conn, $update_bus)) {
            $success = "Bus updated successfully";
        } else {
            $error = "Error updating bus: " . mysqli_error($conn);
        }
    }
    
    elseif (isset($_POST['delete_bus'])) {
        $bus_id = sanitize($_POST['bus_id']);
        
        // Check if bus has any upcoming trips
        $check_trips = "SELECT * FROM trips WHERE bus_id = '$bus_id' AND trip_date >= CURDATE()";
        $result = mysqli_query($conn, $check_trips);
        
        if (mysqli_num_rows($result) > 0) {
            $error = "Cannot delete bus with upcoming trips. Please reassign trips first.";
        } else {
            $delete_bus = "DELETE FROM buses WHERE bus_id = '$bus_id'";
            
            if (mysqli_query($conn, $delete_bus)) {
                $success = "Bus deleted successfully";
            } else {
                $error = "Error deleting bus: " . mysqli_error($conn);
            }
        }
    }
}

// Get buses list
$buses_query = "SELECT * FROM buses ORDER BY bus_number";
$buses_result = mysqli_query($conn, $buses_query);

// Get specific bus for editing
$current_bus = null;
if ($bus_id) {
    $bus_query = "SELECT * FROM buses WHERE bus_id = '$bus_id'";
    $bus_result = mysqli_query($conn, $bus_query);
    if (mysqli_num_rows($bus_result) == 1) {
        $current_bus = mysqli_fetch_assoc($bus_result);
    }
}

// Get bus utilization stats
$utilization_query = "SELECT b.bus_id, b.bus_number, b.capacity,
                      COUNT(t.trip_id) as total_trips,
                      SUM(t.booked_count) as total_bookings,
                      ROUND(AVG((t.booked_count / b.capacity) * 100), 2) as avg_utilization
                      FROM buses b
                      LEFT JOIN trips t ON b.bus_id = t.bus_id AND t.trip_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                      GROUP BY b.bus_id
                      ORDER BY b.bus_number";
$utilization_result = mysqli_query($conn, $utilization_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bus Management - MainRes Bus System</title>
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
                <a href="admin_buses.php" class="active">
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
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <header class="dashboard-header">
                <h1>Bus Management</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="showAddBusModal()">
                        <i class="fas fa-plus"></i> Add New Bus
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

            <!-- Bus List -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-list"></i> All Buses</h2>
                    <span class="badge"><?php echo mysqli_num_rows($buses_result); ?> buses</span>
                </div>
                
                <?php if (mysqli_num_rows($buses_result) > 0): ?>
                    <div class="buses-grid">
                        <?php while ($bus = mysqli_fetch_assoc($buses_result)): 
                            // Get upcoming trips for this bus
                            $upcoming_trips = mysqli_fetch_assoc(mysqli_query($conn, 
                                "SELECT COUNT(*) as count FROM trips 
                                 WHERE bus_id = '{$bus['bus_id']}' 
                                 AND trip_date >= CURDATE()"))['count'];
                        ?>
                            <div class="bus-card">
                                <div class="bus-header">
                                    <h3>Bus <?php echo $bus['bus_number']; ?></h3>
                                    <span class="bus-status status-<?php echo $bus['status']; ?>">
                                        <?php echo ucfirst($bus['status']); ?>
                                    </span>
                                </div>
                                <div class="bus-body">
                                    <p><i class="fas fa-users"></i> Capacity: <?php echo $bus['capacity']; ?> seats</p>
                                    <p><i class="fas fa-car"></i> Model: <?php echo $bus['model'] ?: 'Not specified'; ?></p>
                                    <p><i class="fas fa-id-card"></i> Reg: <?php echo $bus['registration_number']; ?></p>
                                    <p><i class="fas fa-calendar-alt"></i> Upcoming trips: <?php echo $upcoming_trips; ?></p>
                                    
                                    <?php if ($bus['last_service']): ?>
                                        <p><i class="fas fa-wrench"></i> Last service: <?php echo date('d M Y', strtotime($bus['last_service'])); ?></p>
                                    <?php endif; ?>
                                    
                                    <?php if ($bus['next_service']): ?>
                                        <p><i class="fas fa-calendar-check"></i> Next service: <?php echo date('d M Y', strtotime($bus['next_service'])); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="bus-footer">
                                    <button class="btn btn-primary btn-sm" onclick="editBus(<?php echo $bus['bus_id']; ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="deleteBus(<?php echo $bus['bus_id']; ?>, '<?php echo $bus['bus_number']; ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-bus fa-3x"></i>
                        <h3>No buses found</h3>
                        <p>Add your first bus to get started.</p>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Bus Utilization -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-chart-line"></i> Bus Utilization (Last 30 Days)</h2>
                </div>
                
                <?php if (mysqli_num_rows($utilization_result) > 0): ?>
                    <div class="utilization-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Bus Number</th>
                                    <th>Capacity</th>
                                    <th>Trips</th>
                                    <th>Total Bookings</th>
                                    <th>Avg Utilization</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($util = mysqli_fetch_assoc($utilization_result)): 
                                    $bus_status = mysqli_fetch_assoc(mysqli_query($conn, 
                                        "SELECT status FROM buses WHERE bus_id = '{$util['bus_id']}'"))['status'];
                                ?>
                                    <tr>
                                        <td>Bus <?php echo $util['bus_number']; ?></td>
                                        <td><?php echo $util['capacity']; ?> seats</td>
                                        <td><?php echo $util['total_trips']; ?></td>
                                        <td><?php echo $util['total_bookings']; ?></td>
                                        <td>
                                            <div class="utilization-bar">
                                                <div class="utilization-fill" style="width: <?php echo min($util['avg_utilization'], 100); ?>%">
                                                    <?php echo $util['avg_utilization']; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $bus_status; ?>">
                                                <?php echo ucfirst($bus_status); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- Add/Edit Bus Modal -->
    <div id="busModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Bus</h3>
                <button class="modal-close" onclick="closeBusModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="busForm">
                    <input type="hidden" name="bus_id" id="busId">
                    
                    <div class="form-group">
                        <label for="bus_number"><i class="fas fa-bus"></i> Bus Number *</label>
                        <input type="text" id="bus_number" name="bus_number" required 
                               placeholder="e.g., BUS001">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="capacity"><i class="fas fa-users"></i> Capacity *</label>
                            <input type="number" id="capacity" name="capacity" required 
                                   min="1" max="100" value="65">
                        </div>
                        
                        <div class="form-group">
                            <label for="status"><i class="fas fa-circle"></i> Status *</label>
                            <select id="status" name="status" required>
                                <option value="active">Active</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="model"><i class="fas fa-car"></i> Model</label>
                        <input type="text" id="model" name="model" 
                               placeholder="e.g., Mercedes Sprinter">
                    </div>
                    
                    <div class="form-group">
                        <label for="registration_number"><i class="fas fa-id-card"></i> Registration Number</label>
                        <input type="text" id="registration_number" name="registration_number" 
                               placeholder="e.g., CA 123-456">
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeBusModal()">Cancel</button>
                        <button type="submit" name="add_bus" id="submitButton" class="btn btn-primary">Add Bus</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Deletion</h3>
                <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="deleteMessage">Are you sure you want to delete this bus?</p>
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="bus_id" id="deleteBusId">
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" name="delete_bus" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // Show add bus modal
        function showAddBusModal() {
            document.getElementById('modalTitle').textContent = 'Add New Bus';
            document.getElementById('busId').value = '';
            document.getElementById('bus_number').value = '';
            document.getElementById('capacity').value = '65';
            document.getElementById('model').value = '';
            document.getElementById('registration_number').value = '';
            document.getElementById('status').value = 'active';
            
            document.getElementById('submitButton').name = 'add_bus';
            document.getElementById('submitButton').textContent = 'Add Bus';
            
            document.getElementById('busModal').style.display = 'block';
        }

        // Edit bus
        function editBus(busId) {
            // Fetch bus details via AJAX
            fetch(`api_bus_details.php?bus_id=${busId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').textContent = 'Edit Bus';
                        document.getElementById('busId').value = data.bus.bus_id;
                        document.getElementById('bus_number').value = data.bus.bus_number;
                        document.getElementById('capacity').value = data.bus.capacity;
                        document.getElementById('model').value = data.bus.model;
                        document.getElementById('registration_number').value = data.bus.registration_number;
                        document.getElementById('status').value = data.bus.status;
                        
                        document.getElementById('submitButton').name = 'update_bus';
                        document.getElementById('submitButton').textContent = 'Update Bus';
                        
                        document.getElementById('busModal').style.display = 'block';
                    } else {
                        alert('Error loading bus details');
                    }
                });
        }

        // Delete bus
        function deleteBus(busId, busNumber) {
            document.getElementById('deleteMessage').textContent = `Are you sure you want to delete Bus ${busNumber}?`;
            document.getElementById('deleteBusId').value = busId;
            document.getElementById('deleteModal').style.display = 'block';
        }

        // Close modals
        function closeBusModal() {
            document.getElementById('busModal').style.display = 'none';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const busModal = document.getElementById('busModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target == busModal) {
                closeBusModal();
            }
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        }

        // Form validation
        document.getElementById('busForm').addEventListener('submit', function(e) {
            const busNumber = document.getElementById('bus_number').value.trim();
            const capacity = document.getElementById('capacity').value;
            
            if (!busNumber) {
                e.preventDefault();
                alert('Bus number is required');
                return false;
            }
            
            if (capacity < 1 || capacity > 100) {
                e.preventDefault();
                alert('Capacity must be between 1 and 100');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>