<?php
session_start();
require_once('connection.php');
/** @var mysqli $conn */
$page_title = 'Driver Status';

// Get current date and time
$current_datetime = date('Y-m-d H:i:s');
$current_date = date('Y-m-d');

// Get all drivers with their current status
$drivers_query = "SELECT * FROM drivers ORDER BY driver_name ASC";
$drivers_result = $conn->query($drivers_query);

$drivers_status = [];

while ($driver = $drivers_result->fetch_assoc()) {
    $driver_id = $driver['id'];
    
    // Check if driver has ongoing trips (from trips table)
    $ongoing_trips_query = "SELECT * FROM trips 
                           WHERE driver_id = ? 
                           AND departure_date <= ? 
                           AND (arrival_date >= ? OR arrival_date IS NULL)
                           ORDER BY departure_date DESC
                           LIMIT 1";
    $stmt = $conn->prepare($ongoing_trips_query);
    $stmt->bind_param("iss", $driver_id, $current_datetime, $current_datetime);
    $stmt->execute();
    $ongoing_trip = $stmt->get_result()->fetch_assoc();
    
    // Check if driver has approved requests for today (currently active)
    $today_requests_query = "SELECT * FROM trip_requests 
                            WHERE status IN ('approved', 'completed')
                            AND trip_date = ?
                            ORDER BY departure_time ASC";
    $stmt2 = $conn->prepare($today_requests_query);
    $stmt2->bind_param("s", $current_date);
    $stmt2->execute();
    $today_requests = $stmt2->get_result();
    
    $current_request = null;
    while ($request = $today_requests->fetch_assoc()) {
        // Check if this request is currently active
        $departure = strtotime($current_date . ' ' . $request['departure_time']);
        $return = $request['return_time'] ? strtotime($current_date . ' ' . $request['return_time']) : strtotime($current_date . ' 23:59:59');
        $now = strtotime($current_datetime);
        
        if ($now >= $departure && $now <= $return) {
            $current_request = $request;
            break;
        }
    }
    
    // Check if driver has future approved requests (reserved)
    $future_requests_query = "SELECT * FROM trip_requests 
                             WHERE status = 'approved'
                             AND (
                                 trip_date > ? 
                                 OR (trip_date = ? AND departure_time > ?)
                             )
                             ORDER BY trip_date ASC, departure_time ASC
                             LIMIT 1";
    $stmt3 = $conn->prepare($future_requests_query);
    $current_time = date('H:i:s');
    $stmt3->bind_param("sss", $current_date, $current_date, $current_time);
    $stmt3->execute();
    $future_request = $stmt3->get_result()->fetch_assoc();
    
    // Determine status
    $status = 'available';
    $status_details = null;
    
    if ($ongoing_trip) {
        $status = 'not_available';
        $status_details = [
            'type' => 'trip',
            'destination' => $ongoing_trip['place_visited'],
            'departure' => $ongoing_trip['departure_date'],
            'arrival' => $ongoing_trip['arrival_date'],
            'passenger' => $ongoing_trip['passenger_name']
        ];
    } elseif ($current_request) {
        $status = 'not_available';
        $status_details = [
            'type' => 'request',
            'destination' => $current_request['destination'],
            'departure' => $current_date . ' ' . $current_request['departure_time'],
            'arrival' => $current_request['return_time'] ? $current_date . ' ' . $current_request['return_time'] : null,
            'passenger' => $current_request['requester_name']
        ];
    } elseif ($future_request) {
        $status = 'reserved';
        $status_details = [
            'type' => 'future_request',
            'destination' => $future_request['destination'],
            'trip_date' => $future_request['trip_date'],
            'departure_time' => $future_request['departure_time'],
            'return_time' => $future_request['return_time'],
            'passenger' => $future_request['requester_name']
        ];
    }
    
    $drivers_status[] = [
        'driver' => $driver,
        'status' => $status,
        'details' => $status_details
    ];
}

// Count statistics
$total_drivers = count($drivers_status);
$available_drivers = count(array_filter($drivers_status, function($d) { return $d['status'] == 'available'; }));
$reserved_drivers = count(array_filter($drivers_status, function($d) { return $d['status'] == 'reserved'; }));
$busy_drivers = count(array_filter($drivers_status, function($d) { return $d['status'] == 'not_available'; }));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Status - Admin Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            margin-bottom: 30px;
            animation: slideDown 0.5s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .header h1 {
            color: white;
            font-size: 28px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .nav-menu {
            display: flex;
            gap: 15px;
            padding-top: 20px;
            border-top: 2px solid rgba(255,255,255,0.2);
        }

        .nav-btn {
            padding: 12px 25px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            font-weight: bold;
            border: 2px solid rgba(255,255,255,0.3);
            transition: all 0.3s ease;
        }

        .nav-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .nav-btn.active {
            background: white;
            color: #667eea;
            border-color: white;
        }

        .logout-btn {
            padding: 12px 25px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            font-weight: bold;
            border: 2px solid rgba(255,255,255,0.3);
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .container {
            background: white;
            padding: 35px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 1200px;
            margin: 0 auto;
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-header h2 {
            color: #667eea;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .page-header p {
            color: #666;
            font-size: 14px;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .stat-card.total {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .stat-card.available {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .stat-card.busy {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
        }

        .stat-card.reserved {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .stat-card h3 {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-card .stat-value {
            font-size: 48px;
            font-weight: bold;
        }

        /* Driver Grid */
        .drivers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .driver-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            padding: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .driver-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
        }

        .driver-card.available::before {
            background: #48bb78;
        }

        .driver-card.reserved::before {
            background: #4299e1;
        }

        .driver-card.not_available::before {
            background: #f56565;
        }

        .driver-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            transform: translateY(-3px);
        }

        .driver-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .driver-info h3 {
            font-size: 18px;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .driver-info p {
            font-size: 13px;
            color: #718096;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-badge.available {
            background: #f0fff4;
            color: #22543d;
            border: 2px solid #48bb78;
        }

        .status-badge.reserved {
            background: #ebf8ff;
            color: #2c5282;
            border: 2px solid #4299e1;
        }

        .status-badge.not_available {
            background: #fff5f5;
            color: #742a2a;
            border: 2px solid #f56565;
        }

        .vehicle-info {
            background: #f7fafc;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .vehicle-info p {
            font-size: 13px;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .vehicle-info p:last-child {
            margin-bottom: 0;
        }

        .vehicle-info strong {
            color: #2d3748;
        }

        .trip-details {
            background: #fff5f5;
            border-left: 4px solid #f56565;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .trip-details h4 {
            font-size: 14px;
            color: #742a2a;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .trip-details p {
            font-size: 13px;
            color: #742a2a;
            margin-bottom: 5px;
        }

        .trip-details p:last-child {
            margin-bottom: 0;
        }

        .reserved-details {
            background: #ebf8ff;
            border-left: 4px solid #4299e1;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .reserved-details h4 {
            font-size: 14px;
            color: #2c5282;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .reserved-details p {
            font-size: 13px;
            color: #2c5282;
            margin-bottom: 5px;
        }

        .reserved-details p:last-child {
            margin-bottom: 0;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #718096;
            font-size: 16px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .drivers-grid {
                grid-template-columns: 1fr;
            }

            .nav-menu {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-top">
        <h1>Admin Dashboard</h1>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
    <div class="nav-menu">
        <a href="admin_index.php" class="nav-btn">Create Trip Ticket</a>
        <a href="admin_requests.php" class="nav-btn">Manage Requests</a>
        <a href="admin_driver_status.php" class="nav-btn active">Driver Status</a>
    </div>
</div>

<div class="container">
    <div class="page-header">
        <h2>🚗 Driver Status Dashboard</h2>
        <p>Real-time overview of all drivers and their current status</p>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card total">
            <h3>Total Drivers</h3>
            <div class="stat-value"><?php echo $total_drivers; ?></div>
        </div>

        <div class="stat-card available">
            <h3>Available</h3>
            <div class="stat-value"><?php echo $available_drivers; ?></div>
        </div>

        <div class="stat-card reserved">
            <h3>Reserved</h3>
            <div class="stat-value"><?php echo $reserved_drivers; ?></div>
        </div>

        <div class="stat-card busy">
            <h3>Not Available</h3>
            <div class="stat-value"><?php echo $busy_drivers; ?></div>
        </div>
    </div>

    <!-- Drivers Grid -->
    <?php if (count($drivers_status) > 0): ?>
        <div class="drivers-grid">
            <?php foreach ($drivers_status as $driver_data): 
                $driver = $driver_data['driver'];
                $status = $driver_data['status'];
                $details = $driver_data['details'];
            ?>
                <div class="driver-card <?php echo $status; ?>">
                    <div class="driver-header">
                        <div class="driver-info">
                            <h3><?php echo htmlspecialchars($driver['driver_name']); ?></h3>
                            <p>Driver ID: #<?php echo $driver['id']; ?></p>
                        </div>
                        <span class="status-badge <?php echo $status; ?>">
                            <?php 
                            if ($status == 'available') {
                                echo '✓ Available';
                            } elseif ($status == 'reserved') {
                                echo '📅 Reserved';
                            } else {
                                echo '🚗 Not Available';
                            }
                            ?>
                        </span>
                    </div>

                    <div class="vehicle-info">
                        <p><strong>Vehicle:</strong> <?php echo htmlspecialchars($driver['vehicle_name']); ?></p>
                        <p><strong>Plate Number:</strong> <?php echo htmlspecialchars($driver['plate_number']); ?></p>
                    </div>

                    <?php if ($status == 'not_available' && $details): ?>
                        <div class="trip-details">
                            <h4>🚗 Currently On Trip</h4>
                            <p><strong>Destination:</strong> <?php echo htmlspecialchars($details['destination']); ?></p>
                            <p><strong>Passenger:</strong> <?php echo htmlspecialchars($details['passenger']); ?></p>
                            <p><strong>Departure:</strong> <?php echo date('M j, Y g:i A', strtotime($details['departure'])); ?></p>
                            <?php if ($details['arrival']): ?>
                                <p><strong>Expected Return:</strong> <?php echo date('M j, Y g:i A', strtotime($details['arrival'])); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($status == 'reserved' && $details): ?>
                        <div class="reserved-details">
                            <h4>📅 Reserved for Upcoming Trip</h4>
                            <p><strong>Destination:</strong> <?php echo htmlspecialchars($details['destination']); ?></p>
                            <p><strong>Passenger:</strong> <?php echo htmlspecialchars($details['passenger']); ?></p>
                            <p><strong>Trip Date:</strong> <?php echo date('M j, Y', strtotime($details['trip_date'])); ?></p>
                            <p><strong>Departure Time:</strong> <?php echo date('g:i A', strtotime($details['departure_time'])); ?></p>
                            <?php if ($details['return_time']): ?>
                                <p><strong>Return Time:</strong> <?php echo date('g:i A', strtotime($details['return_time'])); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <h3>No Drivers Found</h3>
            <p>There are no drivers registered in the system.</p>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
