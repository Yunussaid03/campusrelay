<?php
session_start();
require 'db_connect.php';

// Ensure the user is a logged-in customer (renter) submitting a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id']) && $_SESSION['role'] === 'customer') {
    
    $renter_id = $_SESSION['user_id'];
    $vehicle_id = (int)$_POST['vehicle_id'];
    $price_per_hour = (float)$_POST['price'];
    
    // Parse scheduled dates from datetime-local input
    if (empty($_POST['rental_start']) || empty($_POST['rental_end'])) {
        header("Location: ../renter_dashboard.php?error=" . urlencode("Start and end times are required."));
        exit;
    }

    $rental_start = date('Y-m-d H:i:s', strtotime($_POST['rental_start']));
    $rental_end = date('Y-m-d H:i:s', strtotime($_POST['rental_end']));
    
    // Calculate duration in hours
    $start_ts = strtotime($rental_start);
    $end_ts = strtotime($rental_end);
    $diff_seconds = $end_ts - $start_ts;
    $duration_hours = ceil($diff_seconds / 3600); // round up to nearest hour

    try {
        if ($duration_hours <= 0) {
            throw new Exception("Rental return time must be set after the start time.");
        }

        // Calculate total cost
        $total_cost = $price_per_hour * $duration_hours;

        $pdo->beginTransaction();

        // 1. Verify that the vehicle is available for rent
        $stmtCheck = $pdo->prepare("SELECT owner_id, availability_status FROM vehicles WHERE vehicle_id = :vehicle_id FOR UPDATE");
        $stmtCheck->execute([':vehicle_id' => $vehicle_id]);
        $vehicle = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {
            throw new Exception("Vehicle not found.");
        }

        if ($vehicle['owner_id'] == $renter_id) {
            throw new Exception("You cannot rent your own vehicle.");
        }

        if ($vehicle['availability_status'] !== 'available') {
            throw new Exception("The selected vehicle is currently not available for rent.");
        }

        // 2. Create the main rental record with status 'pending' (Awaiting Lender approval)
        $stmtRental = $pdo->prepare("
            INSERT INTO rentals (renter_id, vehicle_id, rental_start, rental_end, total_cost, status) 
            VALUES (:renter_id, :vehicle_id, :rental_start, :rental_end, :total_cost, 'pending')
        ");
        $stmtRental->execute([
            ':renter_id' => $renter_id,
            ':vehicle_id' => $vehicle_id,
            ':rental_start' => $rental_start,
            ':rental_end' => $rental_end,
            ':total_cost' => $total_cost
        ]);

        $pdo->commit();
        header("Location: ../renter_dashboard.php?success=Rental request submitted successfully! Awaiting owner approval.");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: ../renter_dashboard.php?error=Failed to submit request. " . urlencode($e->getMessage()));
        exit;
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>
