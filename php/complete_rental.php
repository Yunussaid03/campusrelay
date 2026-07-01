<?php
session_start();
require 'db_connect.php';

// Ensure the user is a logged-in technician submitting a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id']) && $_SESSION['role'] === 'technician') {
    
    $technician_id = $_SESSION['user_id'];
    $rental_id = (int)$_POST['rental_id'];

    try {
        $pdo->beginTransaction();

        // 1. Mark the rental status as 'returned' if it is currently 'active' and assigned to this technician
        $stmt = $pdo->prepare("
            UPDATE rentals 
            SET status = 'returned' 
            WHERE rental_id = :rental_id AND technician_id = :technician_id AND status = 'active'
        ");
        $stmt->execute([
            ':technician_id' => $technician_id,
            ':rental_id' => $rental_id
        ]);

        if ($stmt->rowCount() > 0) {
            // 2. Fetch the associated vehicle_id for this rental
            $stmtFetch = $pdo->prepare("SELECT vehicle_id FROM rentals WHERE rental_id = :rental_id");
            $stmtFetch->execute([':rental_id' => $rental_id]);
            $rental = $stmtFetch->fetch(PDO::FETCH_ASSOC);

            if ($rental) {
                // 3. Re-enable vehicle availability by setting it to 'available'
                $stmtVehicle = $pdo->prepare("UPDATE vehicles SET availability_status = 'available' WHERE vehicle_id = :vehicle_id");
                $stmtVehicle->execute([':vehicle_id' => $rental['vehicle_id']]);
            }

            $pdo->commit();
            header("Location: ../technician_dashboard.php?success=Rental #" . $rental_id . " marked as successfully returned.");
        } else {
            throw new Exception("Unable to mark rental as returned. Verify task assignment.");
        }
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: ../technician_dashboard.php?error=System error occurred. Details: " . urlencode($e->getMessage()));
        exit;
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>
