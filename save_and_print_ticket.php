<?php
session_start();
require_once('connection.php');
require_once('notification_helper.php');

// Check if logged in AND if the role is Admin (1)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    header("Location: login.php");
    exit();
}

// Declare $conn for static analysis
/** @var mysqli $conn */

// Get form data
$driver_id = $_POST['driver_id'] ?? 0;
$driver_name = $_POST['driver_name'] ?? '';
$vehicle_name = $_POST['vehicle_name'] ?? '';
$plate_number = $_POST['plate_number'] ?? '';
$passenger_name = $_POST['passenger_name'] ?? '';
$requester_name = $_POST['requester_name'] ?? '';
$passenger_names = $_POST['passenger_names'] ?? '';
$place_visited = $_POST['place_visited'] ?? '';
$purpose = $_POST['purpose'] ?? '';
$authorized_by = $_POST['authorized_by'] ?? '';
$departure_date = $_POST['departure_date'] ?? '';
$arrival_date = $_POST['arrival_date'] ?? '';
$items_purchased = $_POST['items_purchased'] ?? '';
$gasoline_issued = $_POST['gasoline_issued'] ?? 0;
$gasoline_purchased = $_POST['gasoline_purchased'] ?? 0;
$oil_issued = $_POST['oil_issued'] ?? 0;
$gear_oil = $_POST['gear_oil'] ?? 0;
$grease_issued = $_POST['grease_issued'] ?? '';
$speedometer_start = $_POST['speedometer_start'] ?? 0;
$speedometer_end = $_POST['speedometer_end'] ?? 0;
$distance_traveled = $_POST['distance_traveled'] ?? 0;
$remarks = $_POST['remarks'] ?? '';

// Insert into trips table
$insert_query = "INSERT INTO trips (driver_id, driver_name, vehicle_name, plate_number, passenger_name, 
                 requester_name, passenger_names, place_visited, purpose, authorized_by, departure_date, 
                 arrival_date, items_purchased, gasoline_issued, gasoline_purchased, oil_issued, gear_oil, 
                 grease_issued, speedometer_start, speedometer_end, distance_traveled, remarks, created_by) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($insert_query);
$stmt->bind_param("issssssssssssddddsdddsi", 
    $driver_id, $driver_name, $vehicle_name, $plate_number, $passenger_name,
    $requester_name, $passenger_names, $place_visited, $purpose, $authorized_by, 
    $departure_date, $arrival_date, $items_purchased, $gasoline_issued, $gasoline_purchased, 
    $oil_issued, $gear_oil, $grease_issued, $speedometer_start, $speedometer_end, 
    $distance_traveled, $remarks, $_SESSION['user_id']
);

if ($stmt->execute()) {
    $trip_id = $stmt->insert_id;
    
    // Check if this ticket was created from a request
    $request_id = $_POST['request_id'] ?? 0;
    
    if ($request_id > 0) {
        // Update request status to 'completed'
        $update_request = "UPDATE trip_requests SET status = 'completed' WHERE id = ?";
        $update_stmt = $conn->prepare($update_request);
        $update_stmt->bind_param("i", $request_id);
        $update_stmt->execute();
        
        // Get user info from the request
        $user_query = "SELECT tr.user_id, tr.requester_name, tr.trip_date 
                       FROM trip_requests tr 
                       WHERE tr.id = ?";
        $user_stmt = $conn->prepare($user_query);
        $user_stmt->bind_param("i", $request_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_data = $user_result->fetch_assoc();
        
        // Create notification for user
        if ($user_data) {
            $formatted_date = date('F j, Y', strtotime($departure_date));
            notifyTicketProcessed(
                $conn,
                $user_data['user_id'],
                $trip_id,
                $place_visited,
                $formatted_date,
                $driver_name,
                $vehicle_name
            );
        }
    }
    
    // Redirect to print page
    header("Location: print_ticket.php?trip_id=" . $trip_id);
    exit();
} else {
    die("Error saving trip ticket: " . $conn->error);
}
