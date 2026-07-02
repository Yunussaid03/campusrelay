<?php
session_start();
require 'db_connect.php';

// Ensure the user is a logged-in customer (renter) submitting a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    
    $renter_id = $_SESSION['user_id'];
    $vehicle_id = (int)$_POST['vehicle_id'];
    $price_per_hour = (float)$_POST['price'];
    
    // Parse scheduled dates from datetime-local input
    if (empty($_POST['rental_start']) || empty($_POST['rental_end'])) {
        header("Location: ../rent_item.php?id=$vehicle_id&error=" . urlencode("Start and end times are required."));
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
        $deposit_amount = 20.00; // Fixed P2P Security Deposit in RM

        $pdo->beginTransaction();

        // 1. Verify that the renter has sufficient wallet balance for the security deposit
        $stmtWallet = $pdo->prepare("SELECT wallet_balance FROM users WHERE user_id = :user_id FOR UPDATE");
        $stmtWallet->execute([':user_id' => $renter_id]);
        $user = $stmtWallet->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['wallet_balance'] < $deposit_amount) {
            throw new Exception("Insufficient wallet balance. You need at least RM " . number_format($deposit_amount, 2) . " for the security deposit.");
        }

        // 2. Verify that the vehicle is available for rent
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

        // 3. Deduct deposit from renter's wallet
        $stmtDeduct = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance - :deposit WHERE user_id = :user_id");
        $stmtDeduct->execute([
            ':deposit' => $deposit_amount,
            ':user_id' => $renter_id
        ]);

        // 4. Create the main rental record with status 'pending' (Awaiting Lender approval)
        $stmtRental = $pdo->prepare("
            INSERT INTO rentals (renter_id, vehicle_id, rental_start, rental_end, total_cost, security_deposit, status) 
            VALUES (:renter_id, :vehicle_id, :rental_start, :rental_end, :total_cost, :deposit, 'pending')
        ");
        $stmtRental->execute([
            ':renter_id' => $renter_id,
            ':vehicle_id' => $vehicle_id,
            ':rental_start' => $rental_start,
            ':rental_end' => $rental_end,
            ':total_cost' => $total_cost,
            ':deposit' => $deposit_amount
        ]);

        $pdo->commit();
        header("Location: ../renter_dashboard.php?success=" . urlencode("Rental request submitted! RM " . number_format($deposit_amount, 2) . " security deposit held in escrow."));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: ../rent_item.php?id=$vehicle_id&error=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>
