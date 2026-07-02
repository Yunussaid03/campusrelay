<?php
session_start();
require 'php/db_connect.php';

// Protect the page
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$renter_id = $_SESSION['user_id'];
$vehicle_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($vehicle_id <= 0) {
    header("Location: renter_dashboard.php");
    exit;
}

// Fetch vehicle and owner details
try {
    $stmt = $pdo->prepare("
        SELECT v.*, u.name AS owner_name 
        FROM vehicles v 
        JOIN users u ON v.owner_id = u.user_id 
        WHERE v.vehicle_id = :vehicle_id
    ");
    $stmt->execute([':vehicle_id' => $vehicle_id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vehicle) {
        header("Location: renter_dashboard.php?error=Vehicle not found.");
        exit;
    }

    if ($vehicle['owner_id'] == $renter_id) {
        header("Location: renter_dashboard.php?error=You cannot rent your own vehicle.");
        exit;
    }

    if ($vehicle['availability_status'] !== 'available') {
        header("Location: renter_dashboard.php?error=This vehicle is currently not available.");
        exit;
    }

    // Fetch renter's wallet balance
    $user_stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE user_id = :user_id");
    $user_stmt->execute([':user_id' => $renter_id]);
    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
    $wallet_balance = $user_data ? (float)$user_data['wallet_balance'] : 0.00;

} catch (Exception $e) {
    header("Location: renter_dashboard.php?error=" . urlencode($e->getMessage()));
    exit;
}

$body_class = 'dashboard-body';
$container_class = 'container'; // Center form vertically and horizontally
include 'php/header.php';
?>

<div style="width: 100%; display: flex; justify-content: center; align-items: center; min-height: calc(100vh - 120px); padding: 2rem 1rem;">
    <!-- Glassmorphism Booking Card -->
    <div class="settings-card" style="width: 100%; max-width: 650px; background: rgba(255, 255, 255, 0.9); box-shadow: 0 15px 35px rgba(0,0,0,0.25);">
        
        <!-- Back Button -->
        <a href="renter_dashboard.php" style="text-decoration: none; font-weight: 700; color: var(--primary-blue); display: inline-flex; align-items: center; gap: 0.25rem; margin-bottom: 1.25rem; font-size: 0.9rem;">
            &larr; Back to Marketplace
        </a>

        <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
            <!-- Left Side: Image -->
            <div style="flex: 1 1 200px;">
                <img src="<?php echo !empty($vehicle['image_path']) ? htmlspecialchars($vehicle['image_path']) : 'assets/images/placeholder.jpg'; ?>" 
                     alt="<?php echo htmlspecialchars($vehicle['name']); ?>" 
                     style="width: 100%; aspect-ratio: 16/9; object-fit: cover; border-radius: var(--border-radius-sm); border: 1px solid rgba(0,0,0,0.08);">
            </div>
            
            <!-- Right Side: Details -->
            <div style="flex: 1.2 1 250px; display: flex; flex-direction: column; justify-content: center;">
                <span class="role-badge" style="background-color: var(--primary-blue); color: var(--heritage-gold); align-self: flex-start; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($vehicle['category']); ?></span>
                <h2 style="color: var(--primary-blue); font-size: 1.5rem; margin-bottom: 0.5rem; font-weight: 800;"><?php echo htmlspecialchars($vehicle['name']); ?></h2>
                <p style="font-size: 0.9rem; color: #475569; margin-bottom: 1rem; line-height: 1.4;"><?php echo htmlspecialchars($vehicle['description']); ?></p>
                
                <div style="font-size: 0.88rem; color: #1e293b; display: grid; grid-template-columns: 1fr; gap: 0.35rem;">
                    <span><strong>Lender:</strong> <?php echo htmlspecialchars($vehicle['owner_name']); ?></span>
                    <span><strong>Parking Spot:</strong> <span class="role-badge" style="padding: 0.1rem 0.5rem; font-size: 0.75rem; border-radius: 4px;"><?php echo htmlspecialchars($vehicle['location']); ?></span></span>
                    <span><strong>Rate:</strong> <strong style="color: var(--primary-blue);">RM <?php echo number_format($vehicle['price'], 2); ?> / hr</strong></span>
                </div>
            </div>
        </div>

        <hr style="border: 0; border-top: 1px solid #cbd5e1; margin: 1.5rem 0;">

        <!-- Warnings & Success messages -->
        <?php if (isset($_GET['error'])): ?>
            <div class="alert error" style="margin-bottom: 1.5rem;"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <!-- Checkout Booking Form -->
        <form action="php/request_rental.php" method="POST" id="checkout-form">
            <input type="hidden" name="vehicle_id" value="<?php echo $vehicle['vehicle_id']; ?>">
            <input type="hidden" name="price" value="<?php echo $vehicle['price']; ?>">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="rental_start" style="font-weight: 700; font-size: 0.76rem; color: var(--primary-blue); text-transform: uppercase; letter-spacing: 0.5px;">Start Date & Time</label>
                    <input type="datetime-local" id="rental_start" name="rental_start" required style="width: 100%;" onchange="calculateReservation()">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="rental_end" style="font-weight: 700; font-size: 0.76rem; color: var(--primary-blue); text-transform: uppercase; letter-spacing: 0.5px;">End Date & Time</label>
                    <input type="datetime-local" id="rental_end" name="rental_end" required style="width: 100%;" onchange="calculateReservation()">
                </div>
            </div>

            <!-- Cost Calculations Summary Box -->
            <div style="background-color: #f8fafc; border: 1.5px solid #e2e8f0; padding: 1.25rem; border-radius: var(--border-radius-sm); margin-bottom: 1.5rem;">
                <h4 style="color: var(--primary-blue); font-size: 0.9rem; font-weight: 700; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Reservation Summary</h4>
                
                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 0.4rem; color: #475569;">
                    <span>Calculated Duration:</span>
                    <span id="summary-duration" style="font-weight: bold; color: var(--primary-blue);">-- hours</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 0.4rem; color: #475569;">
                    <span>Security Deposit (Escrow Refundable):</span>
                    <span style="font-weight: bold; color: var(--primary-blue);">RM 20.00</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 0.65rem; color: #475569;">
                    <span>Estimated Rental Fee:</span>
                    <span id="summary-rental-fee" style="font-weight: bold; color: var(--primary-blue);">RM 0.00</span>
                </div>
                
                <hr style="border: 0; border-top: 1px dashed #cbd5e1; margin: 0.5rem 0;">

                <div style="display: flex; justify-content: space-between; font-size: 0.95rem; font-weight: bold; color: var(--primary-blue); margin-top: 0.5rem;">
                    <span>My Escrow Wallet Balance:</span>
                    <span>RM <?php echo number_format($wallet_balance, 2); ?></span>
                </div>

                <div id="wallet-warning" style="display: none; background-color: #fee2e2; border: 1px solid #fca5a5; padding: 0.65rem; border-radius: 4px; color: #b91c1c; font-size: 0.8rem; font-weight: bold; margin-top: 0.75rem; text-align: center;">
                    ⚠️ Insufficient wallet balance to request this rental. Security deposit is RM 20.00.
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" id="submit-booking-btn" class="btn" style="width: 100%; background-color: var(--primary-blue); color: white; font-weight: bold; padding: 1rem; border-radius: var(--border-radius-sm);" disabled>
                Confirm Request Booking
            </button>
        </form>
    </div>
</div>

<script>
// Pre-populate datetime values to local current times
document.addEventListener('DOMContentLoaded', () => {
    const now = new Date();
    
    // Format to yyyy-MM-ddThh:mm
    const pad = (n) => n.toString().padStart(2, '0');
    const localISO = now.getFullYear() + '-' + pad(now.getMonth()+1) + '-' + pad(now.getDate()) + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
    
    const startInput = document.getElementById('rental_start');
    const endInput = document.getElementById('rental_end');
    
    startInput.min = localISO;
    startInput.value = localISO;
    
    // Set default end to +2 hours
    const end = new Date(now.getTime() + (2 * 60 * 60 * 1000));
    const endISO = end.getFullYear() + '-' + pad(end.getMonth()+1) + '-' + pad(end.getDate()) + 'T' + pad(end.getHours()) + ':' + pad(end.getMinutes());
    endInput.min = localISO;
    endInput.value = endISO;
    
    calculateReservation();
});

function calculateReservation() {
    const startVal = document.getElementById('rental_start').value;
    const endVal = document.getElementById('rental_end').value;
    const rate = parseFloat(<?php echo $vehicle['price']; ?>);
    const balance = parseFloat(<?php echo $wallet_balance; ?>);
    
    const summaryDuration = document.getElementById('summary-duration');
    const summaryRentalFee = document.getElementById('summary-rental-fee');
    const warningDiv = document.getElementById('wallet-warning');
    const submitBtn = document.getElementById('submit-booking-btn');
    
    if (!startVal || !endVal) {
        submitBtn.disabled = true;
        return;
    }
    
    const startTs = new Date(startVal).getTime();
    const endTs = new Date(endVal).getTime();
    
    const diffMs = endTs - startTs;
    if (diffMs <= 0) {
        summaryDuration.textContent = "Invalid date range";
        summaryDuration.style.color = 'var(--danger-red)';
        summaryRentalFee.textContent = "RM 0.00";
        submitBtn.disabled = true;
        return;
    }
    
    const hours = Math.ceil(diffMs / (1000 * 60 * 60)); // round up
    const rentalFee = rate * hours;
    
    summaryDuration.textContent = `${hours} hour${hours > 1 ? 's' : ''}`;
    summaryDuration.style.color = 'var(--primary-blue)';
    summaryRentalFee.textContent = `RM ${rentalFee.toFixed(2)}`;
    
    // Check security deposit constraint (RM 20.00)
    const depositNeeded = 20.00;
    if (balance < depositNeeded) {
        warningDiv.style.display = 'block';
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
    } else {
        warningDiv.style.display = 'none';
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
    }
}
</script>

<?php include 'php/footer.php'; ?>
