<?php
session_start();
require 'db_connect.php';

// Ensure the user is a logged-in technician submitting a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id']) && $_SESSION['role'] === 'technician') {
    
    $technician_id = $_SESSION['user_id'];
    $rental_id = $_POST['rental_id'];

    try {
        // Mark the rental status as 'returned'
        // Ensure it is only modified if assigned to this technician and currently in 'reserved' status
        $stmt = $pdo->prepare("UPDATE rentals SET status = 'returned' WHERE rental_id = :rental_id AND technician_id = :technician_id AND status = 'reserved'");
        $stmt->execute([
            ':technician_id' => $technician_id,
            ':rental_id' => $rental_id
        ]);

        if ($stmt->rowCount() > 0) {
            header("Location: ../technician_dashboard.php?success=Rental #" . $rental_id . " marked as successfully returned.");
        } else {
            header("Location: ../technician_dashboard.php?error=Unable to mark rental as returned. Verify task assignment.");
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
