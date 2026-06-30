<?php
session_start();
require 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Fetch the user from the database
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify user exists AND the typed password matches the hashed password
    if ($user && password_verify($password, $user['password_hash'])) {
        // Login successful! Set session variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];

        // Redirect based on role
        if ($user['role'] === 'customer') {
            header("Location: ../customer_dashboard.php");
        } else if ($user['role'] === 'technician') {
            header("Location: ../technician_dashboard.php");
        } else {
            header("Location: ../index.php");
        }
        exit;
    } else {
        // Login failed. Redirect back to login with an error message (using URL parameters for simplicity)
        header("Location: ../index.php?error=invalid_credentials");
        exit;
    }
} else {
    // If someone tries to access this file directly without submitting the form
    header("Location: ../index.php");
    exit;
}
?>