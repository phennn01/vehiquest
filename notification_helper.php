<?php
// Notification Helper Functions
// Creates in-app notifications instead of sending emails

/**
 * Create a notification in the database
 */
function createNotification($conn, $user_id, $type, $title, $message) {
    $insert_query = "INSERT INTO notifications (user_id, type, title, message, is_read, created_at) 
                     VALUES (?, ?, ?, ?, 0, NOW())";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("isss", $user_id, $type, $title, $message);
    return $stmt->execute();
}

/**
 * Notification: Request Submitted
 */
function notifyRequestSubmitted($conn, $user_id, $request_id) {
    $title = "Trip Request Submitted";
    $message = "Your trip request (#$request_id) has been successfully submitted and is pending admin approval.";
    return createNotification($conn, $user_id, 'submitted', $title, $message);
}

/**
 * Notification: Request Approved
 */
function notifyRequestApproved($conn, $user_id, $request_id, $admin_notes = '') {
    $title = "Trip Request Approved";
    $message = "Great news! Your trip request (#$request_id) has been APPROVED.";
    if (!empty($admin_notes)) {
        $message .= " Admin notes: " . $admin_notes;
    }
    return createNotification($conn, $user_id, 'approved', $title, $message);
}

/**
 * Notification: Request Rejected
 */
function notifyRequestRejected($conn, $user_id, $request_id, $admin_notes = '') {
    $title = "Trip Request Rejected";
    $message = "Your trip request (#$request_id) has been rejected.";
    if (!empty($admin_notes)) {
        $message .= " Reason: " . $admin_notes;
    }
    return createNotification($conn, $user_id, 'rejected', $title, $message);
}

/**
 * Notification: Ticket Processed
 */
function notifyTicketProcessed($conn, $user_id, $trip_id, $destination, $trip_date, $driver_name, $vehicle_name) {
    $title = "Trip Ticket Processed";
    $message = "Your trip ticket (#$trip_id) has been processed and is ready! ";
    $message .= "Destination: $destination | Date: $trip_date | Driver: $driver_name | Vehicle: $vehicle_name";
    return createNotification($conn, $user_id, 'general', $title, $message);
}

/**
 * Notification: New Request for Admin
 */
function notifyAdminNewRequest($conn, $requester_name, $request_id, $destination) {
    // Get all admin users (role = 1)
    $admin_query = "SELECT id FROM user_info WHERE role = 1";
    $result = $conn->query($admin_query);
    
    $title = "New Trip Request";
    $message = "A new trip request (#$request_id) has been submitted by $requester_name to $destination.";
    
    $success = true;
    while ($admin = $result->fetch_assoc()) {
        $success = $success && createNotification($conn, $admin['id'], 'general', $title, $message);
    }
    
    return $success;
}
?>
