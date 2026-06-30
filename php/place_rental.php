<?php
session_start();
require 'db_connect.php';

// Ensure the user is a logged-in customer submitting a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id']) && $_SESSION['role'] === 'customer') {
    
    $renter_id = $_SESSION['user_id'];
    $vehicle_id = $_POST['vehicle_id'];
    $quantity = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    
    // Calculate total
    $total_price = $price * $quantity;

    try {
        // Begin Transaction
        $pdo->beginTransaction();

        // 1. Create the main rental record with status 'reserved'
        $stmtRental = $pdo->prepare("INSERT INTO rentals (renter_id, total_price, status) VALUES (:renter_id, :total_price, 'reserved')");
        $stmtRental->execute([
            ':renter_id' => $renter_id,
            ':total_price' => $total_price
        ]);
        
        // Grab the ID of the rental we just created
        $rental_id = $pdo->lastInsertId();

        // 2. Create the rental details record
        $stmtDetails = $pdo->prepare("INSERT INTO rental_details (rental_id, vehicle_id, quantity) VALUES (:rental_id, :vehicle_id, :quantity)");
        $stmtDetails->execute([
            ':rental_id' => $rental_id,
            ':vehicle_id' => $vehicle_id,
            ':quantity' => $quantity
        ]);

        // Commit Transaction
        $pdo->commit();
        
        header("Location: ../customer_dashboard.php?success=Rental reserved successfully!");
        exit;

    } catch (Exception $e) {
        // If anything fails, roll back the changes
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: ../customer_dashboard.php?error=Failed to process rental reservation. Error: " . urlencode($e->getMessage()));
        exit;
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>
