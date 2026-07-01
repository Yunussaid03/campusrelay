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
    $role = $_POST['role'];

    // Basic validation
    if (empty($name) || empty($email) || empty($password) || empty($role)) {
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
        <p style="color: red; text-align: center; margin-bottom: 1rem;"><?php echo $error; ?></p>
    <?php endif; ?>
    <?php if ($success): ?>
        <p style="color: green; text-align: center; margin-bottom: 1rem;"><?php echo $success; ?></p>
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
        <div class="form-group">
            <label for="role">I am a...</label>
            <select id="role" name="role" required style="padding: 0.75rem; border-radius: 4px; border: 1px solid #ccc;">
                <option value="customer">Student (Renting vehicles)</option>
                <option value="technician">Technician (Managing returns)</option>
            </select>
        </div>
        <button type="submit" class="btn">Register</button>
        <p class="auth-link">Already have an account? <a href="index.php">Login here</a>.</p>
    </form>
</div>

<?php include 'php/footer.php'; ?>