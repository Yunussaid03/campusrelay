<?php
session_start();
// If the user is already logged in, redirect them to their dashboard
if (isset($_SESSION['user_id'])) {
    $redirect = ($_SESSION['role'] === 'customer') ? 'renter_dashboard.php' : 'technician_dashboard.php';
    header("Location: $redirect");
    exit;
}

$body_class = 'auth-page';
include 'php/header.php'; 
?>

<div class="auth-card">
    <h2>Login to Campus Relay</h2>
    <form action="php/login_process.php" method="POST" class="auth-form">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button type="submit" class="btn">Login</button>
        <p class="auth-link">Don't have an account? <a href="register.php">Register here</a>.</p>
    </form>
</div>

<?php 
// Include the footer
include 'php/footer.php'; 
?>