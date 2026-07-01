// Wait for the HTML to fully load before running the script
document.addEventListener('DOMContentLoaded', () => {

    // --- FEATURE 1: Dynamic Price Calculation based on start_time and end_time ---
    const orderForms = document.querySelectorAll('.order-form');

    orderForms.forEach(form => {
        const startInput = form.querySelector('input[name="rental_start"]');
        const endInput = form.querySelector('input[name="rental_end"]');
        const priceInput = form.querySelector('input[name="price"]');
        const submitBtn = form.querySelector('button[type="submit"]');

        if (startInput && endInput && priceInput && submitBtn) {
            const basePrice = parseFloat(priceInput.value);

            // Function to calculate and update the text
            const updateTotal = () => {
                const startVal = startInput.value;
                const endVal = endInput.value;

                if (startVal && endVal) {
                    const startDate = new Date(startVal);
                    const endDate = new Date(endVal);
                    const diffMs = endDate - startDate;
                    const diffHours = Math.ceil(diffMs / (1000 * 60 * 60)); // Round up to nearest hour

                    if (diffHours > 0) {
                        const total = (basePrice * diffHours).toFixed(2);
                        submitBtn.textContent = `Rent Now ($${total})`;
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '1';
                    } else {
                        submitBtn.textContent = `End time must be after start`;
                        submitBtn.disabled = true;
                        submitBtn.style.opacity = '0.6';
                    }
                } else {
                    submitBtn.textContent = `Rent Now`;
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                }
            };

            // Listen for any changes to start/end dates
            startInput.addEventListener('change', updateTotal);
            startInput.addEventListener('input', updateTotal);
            endInput.addEventListener('change', updateTotal);
            endInput.addEventListener('input', updateTotal);
            
            // Set initial min date to current time to prevent past reservations
            const now = new Date();
            // Format to YYYY-MM-DDTHH:MM
            const formatDateTimeLocal = (date) => {
                const pad = (num) => String(num).padStart(2, '0');
                const yyyy = date.getFullYear();
                const mm = pad(date.getMonth() + 1);
                const dd = pad(date.getDate());
                const hh = pad(date.getHours());
                const min = pad(date.getMinutes());
                return `${yyyy}-${mm}-${dd}T${hh}:${min}`;
            };
            startInput.min = formatDateTimeLocal(now);
            endInput.min = formatDateTimeLocal(now);
        }
    });

    // --- FEATURE 2: Auto-Fading Alerts ---
    const alerts = document.querySelectorAll('.alert');
    
    alerts.forEach(alert => {
        // Wait 3 seconds
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            
            // Remove it from the DOM completely
            setTimeout(() => alert.remove(), 500);
        }, 3000);
    });

});