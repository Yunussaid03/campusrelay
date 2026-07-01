<?php
session_start();
// If the user is already logged in, redirect them to the renter dashboard (marketplace home)
if (isset($_SESSION['user_id'])) {
    header("Location: renter_dashboard.php");
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
        
        <!-- Show Password Checkbox -->
        <div class="form-group" style="flex-direction: row; align-items: center; gap: 0.5rem; margin-top: -0.5rem; margin-bottom: 1.25rem;">
            <input type="checkbox" id="show-password" onclick="togglePasswordVisibility()" style="width: auto; cursor: pointer;">
            <label for="show-password" style="margin-bottom: 0; font-size: 0.85rem; color: var(--text-muted); cursor: pointer; user-select: none;">Show Password</label>
        </div>

        <button type="submit" class="btn">Login</button>
        <p class="auth-link">Don't have an account? <a href="register.php">Register here</a>.</p>
    </form>
</div>

<script>
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
    } else {
        passwordInput.type = 'password';
    }
}
</script>

<?php 
// Include the footer
include 'php/footer.php'; 
?>