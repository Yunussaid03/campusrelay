// Wait for the HTML to fully load before running the script
document.addEventListener('DOMContentLoaded', () => {

    // --- FEATURE 1: Dynamic Price Calculation (Customer Dashboard) ---
    const orderForms = document.querySelectorAll('.order-form');

    orderForms.forEach(form => {
        // Grab the specific inputs for this exact item card
        const qtyInput = form.querySelector('input[name="quantity"]');
        const priceInput = form.querySelector('input[name="price"]');
        const submitBtn = form.querySelector('button[type="submit"]');

        if (qtyInput && priceInput && submitBtn) {
            const basePrice = parseFloat(priceInput.value);

            // Function to calculate and update the text
            const updateTotal = () => {
                const qty = parseInt(qtyInput.value) || 1;
                const total = (basePrice * qty).toFixed(2);
                submitBtn.textContent = `Rent Now ($${total})`;
            };

            // Run it once on page load to set the initial button text
            updateTotal();

            // Listen for any changes to the quantity input (typing or clicking the arrows)
            qtyInput.addEventListener('input', updateTotal);
            qtyInput.addEventListener('change', updateTotal);
        }
    });

    // --- FEATURE 2: Auto-Fading Alerts ---
    const alerts = document.querySelectorAll('.alert');
    
    alerts.forEach(alert => {
        // Wait 3 seconds (3000 milliseconds)
        setTimeout(() => {
            // Fade it out
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            
            // Remove it from the DOM completely after the fade animation finishes
            setTimeout(() => alert.remove(), 500);
        }, 3000);
    });

});