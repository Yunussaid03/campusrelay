<?php
session_start();
require 'php/db_connect.php';

$error = '';
$success = '';

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = 'customer'; // Default student/member role for P2P

    // Basic validation
    if (empty($name) || empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        // Hash the password for security
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            // Prepare the SQL statement to prevent SQL injection
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)");
            
            // Execute the query
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':password_hash' => $password_hash,
                ':role' => $role
            ]);

            $success = "Registration successful! You can now login.";
        } catch(PDOException $e) {
            // Error code 23000 means the email already exists (UNIQUE constraint)
            if ($e->getCode() == 23000) {
                $error = "An account with that email already exists.";
            } else {
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}

$body_class = 'auth-page';
include 'php/header.php'; 
?>

<div class="auth-card">
    <h2>Create an Account</h2>
    
    <?php if ($error): ?>
        <p style="color: red; text-align: center; margin-bottom: 1rem;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    <?php if ($success): ?>
        <p style="color: green; text-align: center; margin-bottom: 1rem;"><?php echo htmlspecialchars($success); ?></p>
    <?php endif; ?>

    <form action="register.php" method="POST" class="auth-form">
        <div class="form-group">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" required>
        </div>
        
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

        <button type="submit" class="btn">Register</button>
        <p class="auth-link">Already have an account? <a href="index.php">Login here</a>.</p>
        
        <!-- Authorship & Copyright Statement -->
        <div style="margin-top: 1.5rem; text-align: center; font-size: 0.72rem; color: var(--text-muted); border-top: 1px solid #cbd5e1; padding-top: 0.75rem; line-height: 1.4;">
            <p style="margin: 0; font-weight: bold;">Developed by: Yunus Said (Student Authorship Statement)</p>
            <p style="margin: 0;">Published: June 2026 | Last Edited: July 2, 2026</p>
            <p style="margin: 0; font-size: 0.65rem; color: #94a3b8; margin-top: 0.25rem;">Images sourced from open academic repositories.</p>
        </div>
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

<?php include 'php/footer.php'; ?>