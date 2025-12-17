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
$trip_id = isset($_GET['id']) ? sanitize($_GET['id']) : '';
$date_filter = isset($_GET['date']) ? sanitize($_GET['date']) : date('Y-m-d');

// Handle schedule actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_trip'])) {
        $bus_id = sanitize($_POST['bus_id']);
        $trip_date = sanitize($_POST['trip_date']);
        $departure_time = sanitize($_POST['departure_time']);
        $route = sanitize($_POST['route']);
        $pickup_point = sanitize($_POST['pickup_point']);
        $dropoff_point = sanitize($_POST['dropoff_point']);
        $driver_id = sanitize($_POST['driver_id']);
        
        // Check if bus is available at that time
        $check_bus = "SELECT * FROM trips 
                      WHERE bus_id = '$bus_id' 
                      AND trip_date = '$trip_date'
                      AND departure_time = '$departure_time'";
        $result = mysqli_query($conn, $check_bus);
        
        if (mysqli_num_rows($result) > 0) {
            $error = "Bus is already scheduled for another trip at this time";
        } else {
            $insert_trip = "INSERT INTO trips (bus_id, trip_date, departure_time, route, pickup_point, dropoff_point, driver_id) 
                            VALUES ('$bus_id', '$trip_date', '$departure_time', '$route', '$pickup_point', '$dropoff_point', " . ($driver_id ? "'$driver_id'" : "NULL") . ")";
            
            if (mysqli_query($conn, $insert_trip)) {
                $success = "Trip scheduled successfully!";
                // Clear form
                $_POST = array();
            } else {
                $error = "Error scheduling trip: " . mysqli_error($conn);
            }
        }
    }
    
    elseif (isset($_POST['update_trip'])) {
        $trip_id = sanitize($_POST['trip_id']);
        $bus_id = sanitize($_POST['bus_id']);
        $trip_date = sanitize($_POST['trip_date']);
        $departure_time = sanitize($_POST['departure_time']);
        $route = sanitize($_POST['route']);
        $pickup_point = sanitize($_POST['pickup_point']);
        $dropoff_point = sanitize($_POST['dropoff_point']);
        $driver_id = sanitize($_POST['driver_id']);
        $status = sanitize($_POST['status']);
        
        $update_trip = "UPDATE trips SET 
                        bus_id = '$bus_id',
                        trip_date = '$trip_date',
                        departure_time = '$departure_time',
                        route = '$route',
                        pickup_point = '$pickup_point',
                        dropoff_point = '$dropoff_point',
                        driver_id = " . ($driver_id ? "'$driver_id'" : "NULL") . ",
                        status = '$status'
                        WHERE trip_id = '$trip_id'";
        
        if (mysqli_query($conn, $update_trip)) {
            $success = "Trip updated successfully!";
        } else {
            $error = "Error updating trip: " . mysqli_error($conn);
        }
    }
    
    elseif (isset($_POST['delete_trip'])) {
        $trip_id = sanitize($_POST['trip_id'];
        
        // Check if trip has bookings
        $check_bookings = "SELECT COUNT(*) as count FROM bookings WHERE trip_id = '$trip_id' AND status IN ('confirmed', 'waitlisted')";
        $result = mysqli_query($conn, $check_bookings);
        $row = mysqli_fetch_assoc($result);
        
        if ($row['count'] > 0) {
            $error = "Cannot delete trip with active bookings. Please cancel bookings first.";
        } else {
            $delete_trip = "DELETE FROM trips WHERE trip_id = '$trip_id'";
            
            if (mysqli_query($conn, $delete_trip)) {
                $success = "Trip deleted successfully!";
            } else {
                $error = "Error deleting trip: " . mysqli_error($conn);
            }
        }
    }
    
    elseif (isset($_POST['bulk_action'])) {
        $action_type = sanitize($_POST['bulk_action_type']);
        $selected_trips = isset($_POST['selected_trips']) ? $_POST['selected_trips'] : [];
        
        if (empty($selected_trips)) {
            $error = "No trips selected";
        } else {
            $trip_ids = implode(',', array_map('sanitize', $selected_trips));
            
            switch ($action_type) {
                case 'cancel':
                    $update = "UPDATE trips SET status = 'cancelled' WHERE trip_id IN ($trip_ids)";
                    break;
                case 'delete':
                    // Check if any trips have bookings
                    $check = "SELECT COUNT(*) as count FROM bookings WHERE trip_id IN ($trip_ids) AND status IN ('confirmed', 'waitlisted')";
                    $result = mysqli_query($conn, $check);
                    $row = mysqli_fetch_assoc($result);
                    
                    if ($row['count'] > 0) {
                        $error = "Some trips have active bookings and cannot be deleted";
                        break;
                    }
                    $update = "DELETE FROM trips WHERE trip_id IN ($trip_ids)";
                    break;
                case 'assign_driver':
                    $driver_id = sanitize($_POST['bulk_driver_id']);
                    $update = "UPDATE trips SET driver_id = '$driver_id' WHERE trip_id IN ($trip_ids)";
                    break;
                default:
                    $error = "Invalid bulk action";
                    break;
            }
            
            if (!$error && isset($update)) {
                if (mysqli_query($conn, $update)) {
                    $success = "Bulk action completed successfully!";
                } else {
                    $error = "Error performing bulk action: " . mysqli_error($conn);
                }
            }
        }
    }
}

// Get trips for the selected date
$trips_query = "SELECT t.*, b.bus_number, u.name as driver_name,
                (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count
                FROM trips t
                JOIN buses b ON t.bus_id = b.bus_id
                LEFT JOIN users u ON t.driver_id = u.user_id
                WHERE t.trip_date = '$date_filter'";

if (isset($_GET['bus_id']) && $_GET['bus_id'] != '') {
    $bus_id = sanitize($_GET['bus_id']);
    $trips_query .= " AND t.bus_id = '$bus_id'";
}

if (isset($_GET['driver_id']) && $_GET['driver_id'] != '') {
    $driver_id = sanitize($_GET['driver_id']);
    $trips_query .= " AND t.driver_id = '$driver_id'";
}

$trips_query .= " ORDER BY t.departure_time";
$trips_result = mysqli_query($conn, $trips_query);

// Get specific trip for editing
$current_trip = null;
if ($trip_id) {
    $trip_query = "SELECT * FROM trips WHERE trip_id = '$trip_id'";
    $trip_result = mysqli_query($conn, $trip_query);
    if (mysqli_num_rows($trip_result) == 1) {
        $current_trip = mysqli_fetch_assoc($trip_result);
    }
}

// Get available buses
$buses_query = "SELECT * FROM buses WHERE status = 'active' ORDER BY bus_number";
$buses_result = mysqli_query($conn, $buses_query);

// Get available drivers
$drivers_query = "SELECT u.user_id, u.name, u.surname 
                  FROM users u
                  JOIN drivers d ON u.user_id = d.driver_id
                  WHERE u.status = 'active'
                  ORDER BY u.surname, u.name";
$drivers_result = mysqli_query($conn, $drivers_query);

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total_trips,
                SUM(CASE WHEN t.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_trips,
                SUM(CASE WHEN t.status = 'boarding' THEN 1 ELSE 0 END) as boarding_trips,
                SUM((SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed')) as total_bookings
                FROM trips t
                WHERE t.trip_date = '$date_filter'";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Schedules - MainRes Bus System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
                <a href="admin_schedules.php" class="active">
                    <i class="fas fa-calendar-alt"></i> Trip Schedules
                </a>
                <a href="admin_bookings.php">
                    <i class="fas fa-ticket-alt"></i> Bookings
                </a>
                <a href="admin_users.php">
                    <i class="fas fa-users"></i> User Management
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
                <h1>Trip Schedule Management</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="showAddTripModal()">
                        <i class="fas fa-plus"></i> Add New Trip
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
                        <i class="fas fa-route"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_trips']; ?></h3>
                        <p>Total Trips Today</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #2ecc71;">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['scheduled_trips']; ?></h3>
                        <p>Scheduled</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #f39c12;">
                        <i class="fas fa-bus"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['boarding_trips']; ?></h3>
                        <p>Boarding Now</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9b59b6;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_bookings']; ?></h3>
                        <p>Total Bookings</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-filter"></i> Filter Trips</h2>
                </div>
                
                <form method="GET" action="" class="filter-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="date"><i class="fas fa-calendar"></i> Date</label>
                            <input type="date" id="date" name="date" value="<?php echo $date_filter; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="bus_id"><i class="fas fa-bus"></i> Bus</label>
                            <select id="bus_id" name="bus_id">
                                <option value="">All Buses</option>
                                <?php 
                                mysqli_data_seek($buses_result, 0);
                                while ($bus = mysqli_fetch_assoc($buses_result)): 
                                ?>
                                    <option value="<?php echo $bus['bus_id']; ?>" <?php echo (isset($_GET['bus_id']) && $_GET['bus_id'] == $bus['bus_id']) ? 'selected' : ''; ?>>
                                        Bus <?php echo $bus['bus_number']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="driver_id"><i class="fas fa-user-tie"></i> Driver</label>
                            <select id="driver_id" name="driver_id">
                                <option value="">All Drivers</option>
                                <?php 
                                mysqli_data_seek($drivers_result, 0);
                                while ($driver = mysqli_fetch_assoc($drivers_result)): 
                                ?>
                                    <option value="<?php echo $driver['user_id']; ?>" <?php echo (isset($_GET['driver_id']) && $_GET['driver_id'] == $driver['user_id']) ? 'selected' : ''; ?>>
                                        <?php echo $driver['name'] . ' ' . $driver['surname']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="admin_schedules.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </form>
            </section>

            <!-- Bulk Actions -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-tasks"></i> Bulk Actions</h2>
                </div>
                
                <form method="POST" action="" class="bulk-actions-form">
                    <div class="bulk-actions-row">
                        <div class="form-group">
                            <select id="bulk_action_type" name="bulk_action_type" required>
                                <option value="">Select Action</option>
                                <option value="cancel">Cancel Selected Trips</option>
                                <option value="delete">Delete Selected Trips</option>
                                <option value="assign_driver">Assign Driver to Selected</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="bulk_driver_container" style="display: none;">
                            <select id="bulk_driver_id" name="bulk_driver_id">
                                <option value="">Select Driver</option>
                                <?php 
                                mysqli_data_seek($drivers_result, 0);
                                while ($driver = mysqli_fetch_assoc($drivers_result)): 
                                ?>
                                    <option value="<?php echo $driver['user_id']; ?>">
                                        <?php echo $driver['name'] . ' ' . $driver['surname']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <button type="submit" name="bulk_action" class="btn btn-primary">
                            <i class="fas fa-play"></i> Apply to Selected
                        </button>
                    </div>
                    
                    <!-- Trips List -->
                    <div class="trips-table">
                        <table>
                            <thead>
                                <tr>
                                    <th width="30">
                                        <input type="checkbox" id="select-all">
                                    </th>
                                    <th>Time</th>
                                    <th>Route</th>
                                    <th>Bus</th>
                                    <th>Driver</th>
                                    <th>Booked</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($trips_result) > 0): ?>
                                    <?php while ($trip = mysqli_fetch_assoc($trips_result)): 
                                        $available_seats = BUS_CAPACITY - $trip['booked_count'];
                                        $occupancy_percent = ($trip['booked_count'] / BUS_CAPACITY) * 100;
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="selected_trips[]" value="<?php echo $trip['trip_id']; ?>" class="trip-checkbox">
                                            </td>
                                            <td><?php echo date('H:i', strtotime($trip['departure_time'])); ?></td>
                                            <td><?php echo $trip['route']; ?></td>
                                            <td><?php echo $trip['bus_number']; ?></td>
                                            <td><?php echo $trip['driver_name'] ?: 'Not assigned'; ?></td>
                                            <td>
                                                <div class="capacity-info">
                                                    <span><?php echo $trip['booked_count']; ?>/<?php echo BUS_CAPACITY; ?></span>
                                                    <div class="capacity-bar">
                                                        <div class="capacity-fill" style="width: <?php echo $occupancy_percent; ?>%"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $trip['status']; ?>">
                                                    <?php echo ucfirst($trip['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-primary btn-sm" onclick="editTrip(<?php echo $trip['trip_id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="admin_bookings.php?trip_id=<?php echo $trip['trip_id']; ?>" class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-list"></i>
                                                </a>
                                                <button class="btn btn-danger btn-sm" onclick="deleteTrip(<?php echo $trip['trip_id']; ?>, '<?php echo $trip['route']; ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="fas fa-calendar-times fa-3x"></i>
                                                <h3>No trips found for selected date</h3>
                                                <p>Try selecting a different date or add a new trip.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </section>

            <!-- Schedule Calendar View -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2><i class="fas fa-calendar-alt"></i> Weekly Schedule Preview</h2>
                    <div class="week-navigation">
                        <?php
                        $prev_week = date('Y-m-d', strtotime($date_filter . ' -7 days'));
                        $next_week = date('Y-m-d', strtotime($date_filter . ' +7 days'));
                        ?>
                        <a href="?date=<?php echo $prev_week; ?>" class="btn btn-secondary">
                            <i class="fas fa-chevron-left"></i> Previous Week
                        </a>
                        <span>Week of <?php echo date('M j', strtotime($date_filter)); ?></span>
                        <a href="?date=<?php echo $next_week; ?>" class="btn btn-secondary">
                            Next Week <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
                
                <?php
                // Get weekly schedule
                $week_start = date('Y-m-d', strtotime('monday this week', strtotime($date_filter)));
                $week_end = date('Y-m-d', strtotime('sunday this week', strtotime($date_filter)));
                
                $weekly_query = "SELECT t.*, b.bus_number, u.name as driver_name,
                                DAYNAME(t.trip_date) as day_name,
                                (SELECT COUNT(*) FROM bookings bk WHERE bk.trip_id = t.trip_id AND bk.status = 'confirmed') as booked_count
                                FROM trips t
                                JOIN buses b ON t.bus_id = b.bus_id
                                LEFT JOIN users u ON t.driver_id = u.user_id
                                WHERE t.trip_date BETWEEN '$week_start' AND '$week_end'
                                ORDER BY t.trip_date, t.departure_time";
                $weekly_result = mysqli_query($conn, $weekly_query);
                
                $weekly_schedule = [];
                while ($row = mysqli_fetch_assoc($weekly_result)) {
                    $day = $row['day_name'];
                    if (!isset($weekly_schedule[$day])) {
                        $weekly_schedule[$day] = [];
                    }
                    $weekly_schedule[$day][] = $row;
                }
                ?>
                
                <div class="weekly-calendar">
                    <?php
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    foreach ($days as $day):
                        $day_date = date('Y-m-d', strtotime($week_start . ' +' . array_search($day, $days) . ' days'));
                        $day_trips = isset($weekly_schedule[$day]) ? $weekly_schedule[$day] : [];
                    ?>
                        <div class="calendar-day <?php echo ($day_date == date('Y-m-d')) ? 'today' : ''; ?>">
                            <div class="day-header">
                                <h3><?php echo $day; ?></h3>
                                <span class="date"><?php echo date('j M', strtotime($day_date)); ?></span>
                            </div>
                            
                            <div class="day-schedule">
                                <?php if (count($day_trips) > 0): ?>
                                    <?php foreach ($day_trips as $trip): ?>
                                        <div class="schedule-item" onclick="editTrip(<?php echo $trip['trip_id']; ?>)">
                                            <div class="schedule-time">
                                                <?php echo date('H:i', strtotime($trip['departure_time'])); ?>
                                            </div>
                                            <div class="schedule-details">
                                                <p class="schedule-route"><?php echo $trip['route']; ?></p>
                                                <p class="schedule-meta">
                                                    Bus <?php echo $trip['bus_number']; ?> 
                                                    | <?php echo $trip['booked_count']; ?>/<?php echo BUS_CAPACITY; ?>
                                                    <?php if ($trip['driver_name']): ?>
                                                        | <?php echo $trip['driver_name']; ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <div class="schedule-status">
                                                <span class="status-dot status-<?php echo $trip['status']; ?>"></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-schedule">
                                        <i class="fas fa-calendar-plus"></i>
                                        <p>No trips scheduled</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>

    <!-- Add/Edit Trip Modal -->
    <div id="tripModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Trip</h3>
                <button class="modal-close" onclick="closeTripModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="tripForm">
                    <input type="hidden" name="trip_id" id="tripId">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="bus_id"><i class="fas fa-bus"></i> Bus *</label>
                            <select id="bus_id" name="bus_id" required>
                                <option value="">Select Bus</option>
                                <?php 
                                mysqli_data_seek($buses_result, 0);
                                while ($bus = mysqli_fetch_assoc($buses_result)): 
                                ?>
                                    <option value="<?php echo $bus['bus_id']; ?>">
                                        Bus <?php echo $bus['bus_number']; ?> (Capacity: <?php echo $bus['capacity']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="driver_id"><i class="fas fa-user-tie"></i> Driver (Optional)</label>
                            <select id="driver_id" name="driver_id">
                                <option value="">Not assigned</option>
                                <?php 
                                mysqli_data_seek($drivers_result, 0);
                                while ($driver = mysqli_fetch_assoc($drivers_result)): 
                                ?>
                                    <option value="<?php echo $driver['user_id']; ?>">
                                        <?php echo $driver['name'] . ' ' . $driver['surname']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="trip_date"><i class="fas fa-calendar"></i> Date *</label>
                            <input type="date" id="trip_date" name="trip_date" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="departure_time"><i class="fas fa-clock"></i> Departure Time *</label>
                            <input type="time" id="departure_time" name="departure_time" required 
                                   value="07:00">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="route"><i class="fas fa-route"></i> Route *</label>
                        <select id="route" name="route" required>
                            <option value="">Select Route</option>
                            <option value="MainRes to Campus">MainRes to Campus</option>
                            <option value="Campus to MainRes">Campus to MainRes</option>
                            <option value="MainRes to City Center">MainRes to City Center</option>
                            <option value="Campus to Station">Campus to Station</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="pickup_point"><i class="fas fa-map-marker-alt"></i> Pickup Point</label>
                            <input type="text" id="pickup_point" name="pickup_point" 
                                   placeholder="e.g., Main Residence Gate">
                        </div>
                        
                        <div class="form-group">
                            <label for="dropoff_point"><i class="fas fa-flag-checkered"></i> Dropoff Point</label>
                            <input type="text" id="dropoff_point" name="dropoff_point" 
                                   placeholder="e.g., Main Campus Bus Stop">
                        </div>
                    </div>
                    
                    <div class="form-group" id="statusField" style="display: none;">
                        <label for="status"><i class="fas fa-circle"></i> Status</label>
                        <select id="status" name="status">
                            <option value="scheduled">Scheduled</option>
                            <option value="boarding">Boarding</option>
                            <option value="departed">Departed</option>
                            <option value="arrived">Arrived</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeTripModal()">Cancel</button>
                        <button type="submit" name="add_trip" id="submitButton" class="btn btn-primary">Add Trip</button>
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
                <p id="deleteMessage">Are you sure you want to delete this trip?</p>
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="trip_id" id="deleteTripId">
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" name="delete_trip" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date picker
        flatpickr("#date", {
            dateFormat: "Y-m-d",
        });
        
        flatpickr("#trip_date", {
            dateFormat: "Y-m-d",
            minDate: "today"
        });

        // Show add trip modal
        function showAddTripModal() {
            document.getElementById('modalTitle').textContent = 'Add New Trip';
            document.getElementById('tripId').value = '';
            document.getElementById('bus_id').value = '';
            document.getElementById('driver_id').value = '';
            document.getElementById('trip_date').value = '<?php echo date("Y-m-d"); ?>';
            document.getElementById('departure_time').value = '07:00';
            document.getElementById('route').value = '';
            document.getElementById('pickup_point').value = '';
            document.getElementById('dropoff_point').value = '';
            document.getElementById('statusField').style.display = 'none';
            
            document.getElementById('submitButton').name = 'add_trip';
            document.getElementById('submitButton').textContent = 'Add Trip';
            
            document.getElementById('tripModal').style.display = 'block';
        }

        // Edit trip
        function editTrip(tripId) {
            fetch(`api_trip_details.php?trip_id=${tripId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').textContent = 'Edit Trip';
                        document.getElementById('tripId').value = data.trip.trip_id;
                        document.getElementById('bus_id').value = data.trip.bus_id;
                        document.getElementById('driver_id').value = data.trip.driver_id || '';
                        document.getElementById('trip_date').value = data.trip.trip_date;
                        document.getElementById('departure_time').value = data.trip.departure_time.substring(0, 5);
                        document.getElementById('route').value = data.trip.route;
                        document.getElementById('pickup_point').value = data.trip.pickup_point || '';
                        document.getElementById('dropoff_point').value = data.trip.dropoff_point || '';
                        document.getElementById('status').value = data.trip.status;
                        
                        document.getElementById('statusField').style.display = 'block';
                        document.getElementById('submitButton').name = 'update_trip';
                        document.getElementById('submitButton').textContent = 'Update Trip';
                        
                        document.getElementById('tripModal').style.display = 'block';
                    } else {
                        alert('Error loading trip details');
                    }
                });
        }

        // Delete trip
        function deleteTrip(tripId, route) {
            document.getElementById('deleteMessage').textContent = `Are you sure you want to delete trip: ${route}?`;
            document.getElementById('deleteTripId').value = tripId;
            document.getElementById('deleteModal').style.display = 'block';
        }

        // Close modals
        function closeTripModal() {
            document.getElementById('tripModal').style.display = 'none';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const tripModal = document.getElementById('tripModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target == tripModal) {
                closeTripModal();
            }
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        }

        // Bulk action dropdown
        document.getElementById('bulk_action_type').addEventListener('change', function() {
            const driverContainer = document.getElementById('bulk_driver_container');
            if (this.value === 'assign_driver') {
                driverContainer.style.display = 'block';
            } else {
                driverContainer.style.display = 'none';
            }
        });

        // Select all trips
        document.getElementById('select-all').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.trip-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Form validation
        document.getElementById('tripForm').addEventListener('submit', function(e) {
            const tripDate = new Date(document.getElementById('trip_date').value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (tripDate < today) {
                e.preventDefault();
                alert('Trip date cannot be in the past!');
                return false;
            }
            
            const departureTime = document.getElementById('departure_time').value;
            if (!departureTime) {
                e.preventDefault();
                alert('Please select departure time');
                return false;
            }
            
            return true;
        });

        // Auto-refresh table every 60 seconds
        setInterval(function() {
            if (window.location.pathname.includes('admin_schedules.php')) {
                // Only refresh if no modal is open
                if (!document.getElementById('tripModal').style.display && 
                    !document.getElementById('deleteModal').style.display) {
                    window.location.reload();
                }
            }
        }, 60000);
    </script>
</body>
</html>