<?php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$rental_id = isset($_GET['rental_id']) ? (int)$_GET['rental_id'] : 0;

if ($rental_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid rental ID']);
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
        ':user_id' => $user_id
    ]);
    
    if (!$stmtCheck->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    // Pull messages along with sender name
    $stmtMsgs = $pdo->prepare("
        SELECT m.message_text, m.created_at, m.sender_id, u.name AS sender_name
        FROM messages m
        JOIN users u ON m.sender_id = u.user_id
        WHERE m.rental_id = :rental_id
        ORDER BY m.created_at ASC
    ");
    $stmtMsgs->execute([':rental_id' => $rental_id]);
    $messages = $stmtMsgs->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'messages' => $messages]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
