<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Handle approval/rejection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action'];
    $admin_notes = $_POST['admin_notes'] ?? '';
    
    // Fetch request details and user info
    $request_query = "SELECT tr.*, ui.id as user_id 
                      FROM trip_requests tr 
                      LEFT JOIN user_info ui ON tr.user_id = ui.id 
                      WHERE tr.id = ?";
    $stmt_fetch = $conn->prepare($request_query);
    $stmt_fetch->bind_param("i", $request_id);
    $stmt_fetch->execute();
    $request_data = $stmt_fetch->get_result()->fetch_assoc();
    
    if ($action == 'approve') {
        $update_query = "UPDATE trip_requests SET status = 'approved', approved_by = ?, approved_at = NOW(), admin_notes = ? WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("isi", $_SESSION['user_id'], $admin_notes, $request_id);
        $stmt->execute();
        $success_message = "Trip request approved successfully!";
        
        // Create notification for user
        if ($request_data) {
            notifyRequestApproved($conn, $request_data['user_id'], $request_id, $admin_notes);
        }
    } elseif ($action == 'reject') {
        $update_query = "UPDATE trip_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), admin_notes = ? WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("isi", $_SESSION['user_id'], $admin_notes, $request_id);
        $stmt->execute();
        $success_message = "Trip request rejected.";
        
        // Create notification for user
        if ($request_data) {
            notifyRequestRejected($conn, $request_data['user_id'], $request_id, $admin_notes);
        }
    }
}

// Fetch all trip requests
$filter = $_GET['filter'] ?? 'all';
$where_clause = "";
if ($filter == 'pending') {
    $where_clause = "WHERE status = 'pending'";
} elseif ($filter == 'approved') {
    $where_clause = "WHERE status = 'approved'";
} elseif ($filter == 'rejected') {
    $where_clause = "WHERE status = 'rejected'";
}

// Check if trip_requests table exists
$table_check = $conn->query("SHOW TABLES LIKE 'trip_requests'");
if ($table_check->num_rows == 0) {
    die("Error: trip_requests table does not exist. Please run trip_requests_table.sql first.");
}

$requests_query = "SELECT tr.*, ui.username 
                   FROM trip_requests tr 
                   LEFT JOIN user_info ui ON tr.user_id = ui.id 
                   $where_clause 
                   ORDER BY tr.created_at DESC";
$requests_result = $conn->query($requests_query);

if (!$requests_result) {
    die("Query error: " . $conn->error);
}

// Count statistics
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM trip_requests";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Requests - Admin Dashboard</title>
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
            color: white;
            text-decoration: none;
            border-radius: 25px;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .nav-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        .nav-btn.active {
            background: white;
            color: #667eea;
        }

        .logout-btn {
            padding: 12px 25px;
            background: rgba(255,255,255,0.2);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            font-weight: bold;
        }

        .container {
            background: white;
            padding: 35px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-title {
            color: #667eea;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid #667eea;
            font-size: 24px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            border-radius: 15px;
            color: white;
            text-align: center;
        }

        .stat-card h3 {
            font-size: 32px;
        }

        .stat-card.pending {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
        }

        .stat-card.approved {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .stat-card.rejected {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .filter-tab {
            padding: 10px 20px;
            background: #f8f9fa;
            border-radius: 25px;
            text-decoration: none;
            color: #555;
            font-weight: 600;
        }

        .filter-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        table th, table td {
            padding: 12px;
            text-align: left;
        }

        table tbody tr {
            border-bottom: 1px solid #e0e0e0;
        }

        table tbody tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .view-btn {
            padding: 6px 15px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 20px;
            width: 90%;
            max-width: 800px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #667eea;
        }

        .modal-header h2 {
            color: #667eea;
        }

        .close {
            font-size: 28px;
            cursor: pointer;
            color: #999;
        }

        .detail-row {
            display: grid;
            grid-template-columns: 200px 1fr;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .detail-label {
            font-weight: 600;
            color: #666;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn-approve, .btn-reject {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-approve {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .btn-reject {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }

        .btn-create-ticket {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 25px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: bold;
        }

        textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-top">
        <h1>Manage Requests</h1>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
    <div class="nav-menu">
        <a href="admin_index.php" class="nav-btn">Create Trip Ticket</a>
        <a href="admin_requests.php" class="nav-btn active">Manage Requests</a>
        <a href="admin_driver_status.php" class="nav-btn">Driver Status</a>
    </div>
</div>

<div class="container">
    <?php if (isset($success_message)): ?>
        <div class="alert-success">✓ <?php echo $success_message; ?></div>
    <?php endif; ?>

    <h2 class="page-title">📋 Trip Request Management</h2>

    <div class="stats-row">
        <div class="stat-card">
            <h3><?php echo $stats['total']; ?></h3>
            <p>Total</p>
        </div>
        <div class="stat-card pending">
            <h3><?php echo $stats['pending']; ?></h3>
            <p>Pending</p>
        </div>
        <div class="stat-card approved">
            <h3><?php echo $stats['approved']; ?></h3>
            <p>Approved</p>
        </div>
        <div class="stat-card rejected">
            <h3><?php echo $stats['rejected']; ?></h3>
            <p>Rejected</p>
        </div>
    </div>

    <div class="filter-tabs">
        <a href="?filter=all" class="filter-tab <?php echo $filter == 'all' ? 'active' : ''; ?>">All</a>
        <a href="?filter=pending" class="filter-tab <?php echo $filter == 'pending' ? 'active' : ''; ?>">Pending</a>
        <a href="?filter=approved" class="filter-tab <?php echo $filter == 'approved' ? 'active' : ''; ?>">Approved</a>
        <a href="?filter=rejected" class="filter-tab <?php echo $filter == 'rejected' ? 'active' : ''; ?>">Rejected</a>
    </div>

    <?php if ($requests_result->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Requester</th>
                    <th>Department</th>
                    <th>Destination</th>
                    <th>Trip Date</th>
                    <th>Passengers</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($request = $requests_result->fetch_assoc()): ?>
                    <tr>
                        <td>#<?php echo $request['id']; ?></td>
                        <td><?php echo htmlspecialchars($request['requester_name']); ?></td>
                        <td><?php echo htmlspecialchars($request['department']); ?></td>
                        <td><?php echo htmlspecialchars($request['destination']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($request['trip_date'])); ?></td>
                        <td><?php echo $request['number_of_passengers']; ?></td>
                        <td><span class="status-badge status-<?php echo $request['status']; ?>"><?php echo $request['status']; ?></span></td>
                        <td><button class="view-btn" onclick="viewRequest(<?php echo $request['id']; ?>)">View</button></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="text-align: center; padding: 40px; color: #999;">No requests found.</p>
    <?php endif; ?>
</div>

<div id="requestModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Request Details</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div id="modalContent"></div>
    </div>
</div>

<script>
function viewRequest(requestId) {
    fetch('get_request_details.php?id=' + requestId)
        .then(response => response.json())
        .then(data => {
            let html = '';
            html += '<div class="detail-row"><div class="detail-label">Request ID:</div><div>#' + data.id + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">Requester:</div><div>' + data.requester_name + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">Position:</div><div>' + data.requester_position + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">Department:</div><div>' + data.department + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">Passengers:</div><div>' + data.passenger_names + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">Destination:</div><div>' + data.destination + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">Purpose:</div><div>' + data.purpose + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">Trip Date:</div><div>' + data.trip_date + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">Status:</div><div><span class="status-badge status-' + data.status + '">' + data.status + '</span></div></div>';
            
            if (data.status === 'pending') {
                html += '<form method="POST"><input type="hidden" name="request_id" value="' + data.id + '">';
                html += '<textarea name="admin_notes" rows="3" placeholder="Admin notes (optional)"></textarea>';
                html += '<div class="action-buttons">';
                html += '<button type="submit" name="action" value="approve" class="btn-approve">✓ Approve</button>';
                html += '<button type="submit" name="action" value="reject" class="btn-reject">✗ Reject</button>';
                html += '</div></form>';
            } else if (data.status === 'approved') {
                html += '<div class="action-buttons"><a href="create_ticket_from_request.php?request_id=' + data.id + '" class="btn-create-ticket">Create Trip Ticket</a></div>';
            }
            
            document.getElementById('modalContent').innerHTML = html;
            document.getElementById('requestModal').style.display = 'block';
        });
}

function closeModal() {
    document.getElementById('requestModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == document.getElementById('requestModal')) {
        closeModal();
    }
}
</script>

</body>
</html>
