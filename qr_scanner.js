// qr_scanner.js

class QRScanner {
    constructor() {
        this.scanner = null;
        this.isScanning = false;
        this.lastScanTime = 0;
        this.scanCooldown = 2000; // 2 seconds between scans
    }

    // Initialize QR scanner
    init(containerId, resultContainerId) {
        this.container = document.getElementById(containerId);
        this.resultContainer = document.getElementById(resultContainerId);
        
        if (!this.container) {
            console.error('QR scanner container not found');
            return false;
        }
        
        // Check if browser supports camera
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            this.showError('Camera access not supported by your browser');
            return false;
        }
        
        return true;
    }

    // Start scanning
    async start() {
        try {
            // Get camera stream
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'environment',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                }
            });
            
            // Create video element
            const video = document.createElement('video');
            video.id = 'qr-video';
            video.autoplay = true;
            video.playsInline = true;
            video.srcObject = stream;
            
            // Clear container and add video
            this.container.innerHTML = '';
            this.container.appendChild(video);
            
            // Add scan overlay
            this.addScanOverlay();
            
            // Play video
            await video.play();
            
            // Start scanning
            this.startScanning(video);
            
            this.isScanning = true;
            this.showMessage('Camera started. Point at QR code to scan.', 'info');
            
        } catch (error) {
            console.error('Error accessing camera:', error);
            this.showError('Unable to access camera. Please check permissions.');
            return false;
        }
        
        return true;
    }

    // Stop scanning
    stop() {
        if (this.scanner) {
            this.scanner.stop();
            this.scanner = null;
        }
        
        // Stop video stream
        const video = document.getElementById('qr-video');
        if (video && video.srcObject) {
            video.srcObject.getTracks().forEach(track => track.stop());
        }
        
        this.isScanning = false;
        this.showMessage('Camera stopped', 'info');
    }

    // Add scan overlay
    addScanOverlay() {
        const overlay = document.createElement('div');
        overlay.className = 'scan-overlay';
        
        // Scan frame
        const frame = document.createElement('div');
        frame.className = 'scan-frame';
        
        // Corner elements
        for (let i = 0; i < 4; i++) {
            const corner = document.createElement('div');
            corner.className = `scan-corner corner-${i + 1}`;
            frame.appendChild(corner);
        }
        
        // Scan line
        const scanLine = document.createElement('div');
        scanLine.className = 'scan-line';
        frame.appendChild(scanLine);
        
        overlay.appendChild(frame);
        this.container.appendChild(overlay);
        
        // Animate scan line
        this.animateScanLine(scanLine);
    }

    // Animate scan line
    animateScanLine(scanLine) {
        let position = 0;
        let direction = 1;
        
        const animate = () => {
            position += direction;
            
            if (position >= 100) {
                direction = -1;
            } else if (position <= 0) {
                direction = 1;
            }
            
            scanLine.style.top = position + '%';
            
            if (this.isScanning) {
                requestAnimationFrame(animate);
            }
        };
        
        animate();
    }

    // Start scanning with jsQR library
    startScanning(video) {
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        
        const scanFrame = () => {
            if (!this.isScanning) return;
            
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvas.height = video.videoHeight;
                canvas.width = video.videoWidth;
                
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                
                const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                
                // Use jsQR library to decode QR code
                if (typeof jsQR !== 'undefined') {
                    const code = jsQR(imageData.data, imageData.width, imageData.height, {
                        inversionAttempts: 'dontInvert'
                    });
                    
                    if (code) {
                        this.handleScanResult(code.data);
                    }
                }
            }
            
            requestAnimationFrame(scanFrame);
        };
        
        scanFrame();
    }

    // Handle scan result
    handleScanResult(qrData) {
        const now = Date.now();
        
        // Prevent multiple scans in quick succession
        if (now - this.lastScanTime < this.scanCooldown) {
            return;
        }
        
        this.lastScanTime = now;
        
        // Play scan sound
        this.playScanSound();
        
        // Visual feedback
        this.showScanSuccess();
        
        // Process QR data
        this.processQRData(qrData);
    }

    // Process QR data
    processQRData(qrData) {
        // Show scanning message
        this.showMessage('Processing QR code...', 'info');
        
        // Validate QR format
        if (!qrData.startsWith('MainResBus|')) {
            this.showError('Invalid QR code format');
            return;
        }
        
        // Extract booking ID from QR data
        const parts = qrData.split('|');
        if (parts.length < 2) {
            this.showError('Invalid QR code data');
            return;
        }
        
        const bookingId = parts[1];
        
        // Send to server for verification
        this.verifyBooking(bookingId, qrData);
    }

    // Verify booking with server
    verifyBooking(bookingId, qrData) {
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
            this.showScanResult(data);
            
            // Auto-clear result after 5 seconds if successful
            if (data.success) {
                setTimeout(() => {
                    this.clearResult();
                }, 5000);
            }
        })
        .catch(error => {
            console.error('Error verifying booking:', error);
            this.showError('Network error occurred. Please try again.');
        });
    }

    // Show scan result
    showScanResult(data) {
        if (!this.resultContainer) return;
        
        let html = '';
        
        if (data.success) {
            html = `
                <div class="scan-result success">
                    <div class="result-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="result-content">
                        <h3>Success!</h3>
                        <p>${data.message}</p>
                        ${data.student_name ? `<p><strong>Student:</strong> ${data.student_name}</p>` : ''}
                        ${data.trip_info ? `<p><strong>Trip:</strong> ${data.trip_info}</p>` : ''}
                        ${data.scan_time ? `<p><strong>Time:</strong> ${data.scan_time}</p>` : ''}
                    </div>
                </div>
            `;
        } else {
            html = `
                <div class="scan-result error">
                    <div class="result-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="result-content">
                        <h3>Error!</h3>
                        <p>${data.message}</p>
                        ${data.student_name ? `<p><strong>Student:</strong> ${data.student_name}</p>` : ''}
                        ${data.trip_info ? `<p><strong>Trip:</strong> ${data.trip_info}</p>` : ''}
                    </div>
                </div>
            `;
        }
        
        this.resultContainer.innerHTML = html;
        this.resultContainer.scrollIntoView({ behavior: 'smooth' });
    }

    // Show message
    showMessage(message, type = 'info') {
        if (!this.resultContainer) return;
        
        const html = `
            <div class="scan-message ${type}">
                <i class="fas fa-${this.getMessageIcon(type)}"></i>
                <span>${message}</span>
            </div>
        `;
        
        this.resultContainer.innerHTML = html;
    }

    // Show error
    showError(message) {
        this.showMessage(message, 'error');
    }

    // Get message icon
    getMessageIcon(type) {
        switch(type) {
            case 'success': return 'check-circle';
            case 'error': return 'exclamation-circle';
            case 'warning': return 'exclamation-triangle';
            default: return 'info-circle';
        }
    }

    // Clear result
    clearResult() {
        if (this.resultContainer) {
            this.resultContainer.innerHTML = '';
        }
    }

    // Show visual feedback for successful scan
    showScanSuccess() {
        const frame = document.querySelector('.scan-frame');
        if (frame) {
            frame.classList.add('scan-success');
            setTimeout(() => {
                frame.classList.remove('scan-success');
            }, 500);
        }
    }

    // Play scan sound
    playScanSound() {
        const audio = new Audio();
        audio.src = 'assets/scan-beep.mp3';
        audio.play().catch(e => console.log('Audio play failed:', e));
    }

    // Manual QR code input
    manualInput(qrCode) {
        if (!qrCode) {
            this.showError('Please enter a QR code');
            return;
        }
        
        this.processQRData(qrCode);
    }

    // Toggle camera
    toggleCamera() {
        if (this.isScanning) {
            this.stop();
        } else {
            this.start();
        }
    }

    // Switch camera (front/back)
    async switchCamera() {
        // Stop current stream
        this.stop();
        
        // Wait a moment
        await new Promise(resolve => setTimeout(resolve, 500));
        
        // Start with opposite facing mode
        // This would need more complex camera switching logic
        this.start();
    }

    // Take photo for manual verification
    takePhoto() {
        const video = document.getElementById('qr-video');
        if (!video) return null;
        
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        
        const context = canvas.getContext('2d');
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        return canvas.toDataURL('image/jpeg');
    }
}

// Initialize scanner when page loads
document.addEventListener('DOMContentLoaded', function() {
    window.qrScanner = new QRScanner();
    
    // Initialize if on scan page
    if (document.getElementById('qr-scanner-container')) {
        window.qrScanner.init('qr-scanner-container', 'scan-result');
        
        // Auto-start scanner
        setTimeout(() => {
            window.qrScanner.start();
        }, 1000);
    }
    
    // Setup camera controls
    const startBtn = document.getElementById('start-scanner');
    const stopBtn = document.getElementById('stop-scanner');
    const toggleBtn = document.getElementById('toggle-scanner');
    const manualInput = document.getElementById('manual-qr-input');
    const manualSubmit = document.getElementById('manual-qr-submit');
    
    if (startBtn) {
        startBtn.addEventListener('click', () => window.qrScanner.start());
    }
    
    if (stopBtn) {
        stopBtn.addEventListener('click', () => window.qrScanner.stop());
    }
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => window.qrScanner.toggleCamera());
    }
    
    if (manualSubmit && manualInput) {
        manualSubmit.addEventListener('click', () => {
            window.qrScanner.manualInput(manualInput.value);
            manualInput.value = '';
        });
        
        manualInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                window.qrScanner.manualInput(manualInput.value);
                manualInput.value = '';
            }
        });
    }
});