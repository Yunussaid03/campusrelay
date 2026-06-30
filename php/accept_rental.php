<?php
session_start();
require 'db_connect.php';

// Ensure the user is a logged-in technician submitting a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id']) && $_SESSION['role'] === 'technician') {
    
    $technician_id = $_SESSION['user_id'];
    $rental_id = $_POST['rental_id'];

    try {
        // Assign the technician to the rental
        // Only allow if the rental is currently reserved and has no technician assigned
        $stmt = $pdo->prepare("UPDATE rentals SET technician_id = :technician_id WHERE rental_id = :rental_id AND status = 'reserved' AND technician_id IS NULL");
        $stmt->execute([
            ':technician_id' => $technician_id,
            ':rental_id' => $rental_id
        ]);

        if ($stmt->rowCount() > 0) {
            header("Location: ../technician_dashboard.php?success=You have accepted Rental Pickup #" . $rental_id);
        } else {
            header("Location: ../technician_dashboard.php?error=Rental pickup already accepted by another technician or doesn't exist.");
        }
        exit;

    } catch (Exception $e) {
        header("Location: ../technician_dashboard.php?error=System error occurred. Details: " . urlencode($e->getMessage()));
        exit;
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>
