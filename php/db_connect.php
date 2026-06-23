<?php
// Database credentials (Default for XAMPP)
$host = 'localhost';
$dbname = 'campusrelay_db'; // Database name 
$username = 'root'; 
$password = ''; // XAMPP default password is blank

try {
    // Create a new PDO instance
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    
    // Set the PDO error mode to exception for easier debugging
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Uncomment the line below just to test if it works, then comment it out again
    // echo "Connected successfully!"; 

} catch(PDOException $e) {
    // If the connection fails, kill the script and print the error
    die("Connection failed: " . $e->getMessage());
}
?>