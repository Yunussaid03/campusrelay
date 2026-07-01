<?php
session_start();
require 'db_connect.php';

// Ensure the user is a logged-in renter submitting a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id']) && $_SESSION['role'] === 'customer') {
    
    $renter_id = $_SESSION['user_id'];
    $vehicle_id = (int)$_POST['vehicle_id'];
    $duration_hours = (int)$_POST['quantity'];
    $price_per_hour = (float)$_POST['price'];
    
    // Calculate total cost
    $total_cost = $price_per_hour * $duration_hours;
    
    // Calculate start and end times
    $rental_start = date('Y-m-d H:i:s');
    $rental_end = date('Y-m-d H:i:s', strtotime("+$duration_hours hours"));

    try {
        $pdo->beginTransaction();

        // 1. Verify that the vehicle is available for rent
        $stmtCheck = $pdo->prepare("SELECT availability_status FROM vehicles WHERE vehicle_id = :vehicle_id FOR UPDATE");
        $stmtCheck->execute([':vehicle_id' => $vehicle_id]);
        $vehicle = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle || $vehicle['availability_status'] !== 'available') {
            throw new Exception("The selected vehicle is currently not available for rent.");
        }

        // 2. Create the main rental record with status 'active'
        $stmtRental = $pdo->prepare("
            INSERT INTO rentals (renter_id, vehicle_id, rental_start, rental_end, total_cost, status) 
            VALUES (:renter_id, :vehicle_id, :rental_start, :rental_end, :total_cost, 'active')
        ");
        $stmtRental->execute([
            ':renter_id' => $renter_id,
            ':vehicle_id' => $vehicle_id,
            ':rental_start' => $rental_start,
            ':rental_end' => $rental_end,
            ':total_cost' => $total_cost
        ]);

        // 3. Update the vehicle availability_status to 'reserved'
        $stmtVehicle = $pdo->prepare("UPDATE vehicles SET availability_status = 'reserved' WHERE vehicle_id = :vehicle_id");
        $stmtVehicle->execute([':vehicle_id' => $vehicle_id]);

        $pdo->commit();
        header("Location: ../renter_dashboard.php?success=Rental reservation completed successfully!");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: ../renter_dashboard.php?error=Failed to process rental. " . urlencode($e->getMessage()));
        exit;
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>
