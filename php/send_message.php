<?php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$sender_id = $_SESSION['user_id'];
$rental_id = isset($_POST['rental_id']) ? (int)$_POST['rental_id'] : 0;
$message_text = isset($_POST['message_text']) ? trim($_POST['message_text']) : '';

if ($rental_id <= 0 || empty($message_text)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    // Verify user is either the renter or the owner of the vehicle in this rental
    $stmtCheck = $pdo->prepare("
        SELECT r.rental_id 
        FROM rentals r
        JOIN vehicles v ON r.vehicle_id = v.vehicle_id
        WHERE r.rental_id = :rental_id AND (r.renter_id = :user_id OR v.owner_id = :user_id)
    ");
    $stmtCheck->execute([
        ':rental_id' => $rental_id,
        ':user_id' => $sender_id
    ]);
    
    if (!$stmtCheck->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    // Insert message
    $stmtInsert = $pdo->prepare("
        INSERT INTO messages (rental_id, sender_id, message_text) 
        VALUES (:rental_id, :sender_id, :message_text)
    ");
    $stmtInsert->execute([
        ':rental_id' => $rental_id,
        ':sender_id' => $sender_id,
        ':message_text' => $message_text
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
