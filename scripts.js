// scripts.js

// DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    initTooltips();
    
    // Initialize form validation
    initFormValidation();
    
    // Initialize notifications
    initNotifications();
    
    // Initialize QR Scanner if on scan page
    if (document.getElementById('qr-reader')) {
        initQRScanner();
    }
});

// Tooltips
function initTooltips() {
    const tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(element => {
        element.addEventListener('mouseenter', showTooltip);
        element.addEventListener('mouseleave', hideTooltip);
    });
}

function showTooltip(e) {
    const tooltipText = e.target.getAttribute('data-tooltip');
    const tooltip = document.createElement('div');
    tooltip.className = 'tooltip';
    tooltip.textContent = tooltipText;
    
    const rect = e.target.getBoundingClientRect();
    tooltip.style.position = 'fixed';
    tooltip.style.top = (rect.top - 40) + 'px';
    tooltip.style.left = (rect.left + rect.width / 2) + 'px';
    tooltip.style.transform = 'translateX(-50%)';
    
    document.body.appendChild(tooltip);
    
    e.target._tooltip = tooltip;
}

function hideTooltip(e) {
    if (e.target._tooltip) {
        e.target._tooltip.remove();
        e.target._tooltip = null;
    }
}

// Form Validation
function initFormValidation() {
    const forms = document.querySelectorAll('form[needs-validation]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
            }
        });
    });
}

function validateForm(form) {
    let isValid = true;
    const requiredFields = form.querySelectorAll('[required]');
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            showFieldError(field, 'This field is required');
            isValid = false;
        } else {
            clearFieldError(field);
            
            // Additional validations
            if (field.type === 'email' && !isValidEmail(field.value)) {
                showFieldError(field, 'Please enter a valid email address');
                isValid = false;
            }
            
            if (field.type === 'tel' && !isValidPhone(field.value)) {
                showFieldError(field, 'Please enter a valid phone number');
                isValid = false;
            }
            
            if (field.type === 'password' && field.value.length < 6) {
                showFieldError(field, 'Password must be at least 6 characters');
                isValid = false;
            }
        }
    });
    
    // Check password confirmation
    const password = form.querySelector('[name="password"]');
    const confirmPassword = form.querySelector('[name="confirm_password"]');
    if (password && confirmPassword && password.value !== confirmPassword.value) {
        showFieldError(confirmPassword, 'Passwords do not match');
        isValid = false;
    }
    
    return isValid;
}

function showFieldError(field, message) {
    clearFieldError(field);
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'field-error';
    errorDiv.textContent = message;
    errorDiv.style.color = '#e74c3c';
    errorDiv.style.fontSize = '12px';
    errorDiv.style.marginTop = '5px';
    
    field.parentNode.appendChild(errorDiv);
    field.style.borderColor = '#e74c3c';
}

function clearFieldError(field) {
    const existingError = field.parentNode.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
    field.style.borderColor = '';
}

function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function isValidPhone(phone) {
    const re = /^[\d\s\-\+\(\)]{10,}$/;
    return re.test(phone);
}

// Notifications
function initNotifications() {
    const notificationBtn = document.getElementById('notificationBtn');
    if (notificationBtn) {
        notificationBtn.addEventListener('click', toggleNotifications);
    }
}

function toggleNotifications() {
    // This would typically fetch notifications via AJAX
    // For now, we'll just show a simple alert
    alert('Notifications feature coming soon!');
    
    // In a full implementation, you would:
    // 1. Fetch unread notifications via AJAX
    // 2. Display them in a dropdown/modal
    // 3. Mark them as read when viewed
}

// AJAX Helper Functions
function makeAjaxRequest(url, data, method = 'POST') {
    return fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .catch(error => {
        console.error('AJAX Error:', error);
        return { success: false, message: 'Network error occurred' };
    });
}

// Check Seat Availability
function checkSeatAvailability(tripId, callback) {
    fetch(`api_booking_check.php?trip_id=${tripId}`)
        .then(response => response.json())
        .then(data => callback(data))
        .catch(error => console.error('Error checking seat availability:', error));
}

// Update Booking Counts in Real-time
function updateBookingCounts() {
    const tripCards = document.querySelectorAll('.trip-card');
    tripCards.forEach(card => {
        const tripId = card.dataset.tripId;
        if (tripId) {
            checkSeatAvailability(tripId, function(data) {
                if (data.success) {
                    const seatElement = card.querySelector('.available-seats');
                    if (seatElement) {
                        seatElement.textContent = data.available_seats + ' seats available';
                        
                        // Update status badge
                        const statusBadge = card.querySelector('.trip-status');
                        if (statusBadge) {
                            if (data.available_seats <= 0) {
                                statusBadge.textContent = 'FULL';
                                statusBadge.className = 'trip-status status-full';
                                card.classList.add('trip-full');
                            } else {
                                statusBadge.textContent = data.available_seats + ' seats';
                                statusBadge.className = 'trip-status status-available';
                                card.classList.remove('trip-full');
                            }
                        }
                    }
                }
            });
        }
    });
}

// Auto-refresh booking counts every 30 seconds
if (document.querySelector('.trip-card')) {
    setInterval(updateBookingCounts, 30000);
}

// QR Scanner
function initQRScanner() {
    const html5QrCode = new Html5Qrcode("qr-reader");
    const qrCodeSuccessCallback = (decodedText, decodedResult) => {
        // Handle scanned QR code
        processQRCode(decodedText);
        
        // Stop scanning after successful scan
        html5QrCode.stop().then((ignore) => {
            // QR Code scanning is stopped.
        }).catch((err) => {
            // Stop failed, handle it.
        });
    };
    
    const config = { fps: 10, qrbox: 250 };
    
    // Start scanning
    html5QrCode.start(
        { facingMode: "environment" },
        config,
        qrCodeSuccessCallback
    ).catch(err => {
        console.error("Unable to start scanning:", err);
        document.getElementById('qr-reader').innerHTML = 
            '<p class="error">Unable to access camera. Please check permissions.</p>';
    });
}

function processQRCode(qrData) {
    // Split QR data
    const parts = qrData.split('|');
    if (parts[0] === 'MainResBus') {
        const bookingId = parts[1];
        
        // Send to server for verification
        fetch('api_attendance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                booking_id: bookingId,
                qr_data: qrData
            })
        })
        .then(response => response.json())
        .then(data => {
            displayScanResult(data);
        })
        .catch(error => {
            displayScanResult({
                success: false,
                message: 'Network error occurred'
            });
        });
    } else {
        displayScanResult({
            success: false,
            message: 'Invalid QR code format'
        });
    }
}

function displayScanResult(result) {
    const resultDiv = document.getElementById('scan-result');
    if (resultDiv) {
        if (result.success) {
            resultDiv.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <strong>Success!</strong> ${result.message}
                    ${result.student_name ? `<p>Student: ${result.student_name}</p>` : ''}
                    ${result.trip_info ? `<p>Trip: ${result.trip_info}</p>` : ''}
                </div>
            `;
            
            // Play success sound
            playSound('success');
        } else {
            resultDiv.innerHTML = `
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Error!</strong> ${result.message}
                </div>
            `;
            
            // Play error sound
            playSound('error');
        }
        
        // Auto-clear result after 5 seconds
        setTimeout(() => {
            resultDiv.innerHTML = '';
        }, 5000);
    }
}

function playSound(type) {
    const audio = new Audio();
    if (type === 'success') {
        audio.src = 'assets/success.mp3';
    } else {
        audio.src = 'assets/error.mp3';
    }
    audio.play().catch(e => console.log('Audio play failed:', e));
}

// Toast Notifications
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <i class="fas fa-${getToastIcon(type)}"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(toast);
    
    // Show toast
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    // Hide and remove after 3 seconds
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 3000);
}

function getToastIcon(type) {
    switch(type) {
        case 'success': return 'check-circle';
        case 'error': return 'exclamation-circle';
        case 'warning': return 'exclamation-triangle';
        default: return 'info-circle';
    }
}

// Auto-logout timer
let idleTimer;
function resetIdleTimer() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(logoutWarning, 30 * 60 * 1000); // 30 minutes
}

function logoutWarning() {
    if (confirm('You have been idle for 30 minutes. Would you like to stay logged in?')) {
        resetIdleTimer();
    } else {
        window.location.href = 'logout.php';
    }
}

// Set up idle timer if user is logged in
if (document.body.classList.contains('logged-in')) {
    resetIdleTimer();
    document.addEventListener('mousemove', resetIdleTimer);
    document.addEventListener('keypress', resetIdleTimer);
    document.addEventListener('click', resetIdleTimer);
}

// Print QR Code
function printQRCode(qrCodeUrl) {
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Print QR Code</title>
                <style>
                    body { 
                        font-family: Arial, sans-serif; 
                        text-align: center; 
                        padding: 50px;
                    }
                    img { 
                        max-width: 300px; 
                        margin: 20px 0; 
                    }
                    .print-info {
                        margin-top: 30px;
                        font-size: 14px;
                        color: #666;
                    }
                </style>
            </head>
            <body>
                <h2>MainRes Bus QR Code</h2>
                <img src="${qrCodeUrl}" alt="QR Code">
                <p>Please show this QR code to the driver when boarding</p>
                <div class="print-info">
                    Generated on: ${new Date().toLocaleString()}<br>
                    Keep this code safe for boarding
                </div>
            </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// Add to Home Screen (PWA)
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent Chrome 67 and earlier from automatically showing the prompt
    e.preventDefault();
    // Stash the event so it can be triggered later
    deferredPrompt = e;
    
    // Show install button
    const installBtn = document.getElementById('installBtn');
    if (installBtn) {
        installBtn.style.display = 'block';
        installBtn.addEventListener('click', () => {
            // Show the install prompt
            deferredPrompt.prompt();
            // Wait for the user to respond to the prompt
            deferredPrompt.userChoice.then((choiceResult) => {
                if (choiceResult.outcome === 'accepted') {
                    console.log('User accepted the install prompt');
                } else {
                    console.log('User dismissed the install prompt');
                }
                deferredPrompt = null;
            });
        });
    }
});

// Service Worker Registration for PWA
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then(registration => {
            console.log('ServiceWorker registration successful');
        }, err => {
            console.log('ServiceWorker registration failed: ', err);
        });
    });
}

// Export data to CSV
function exportToCSV(data, filename) {
    const csv = convertToCSV(data);
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', filename);
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function convertToCSV(objArray) {
    const array = typeof objArray != 'object' ? JSON.parse(objArray) : objArray;
    let str = '';
    let row = '';

    for (let index in objArray[0]) {
        row += index + ',';
    }
    row = row.slice(0, -1);
    str += row + '\r\n';

    for (let i = 0; i < array.length; i++) {
        let line = '';
        for (let index in array[i]) {
            if (line != '') line += ',';
            line += array[i][index];
        }
        str += line + '\r\n';
    }
    return str;
}