==================================================
MAINRES BUS SYSTEM - INSTALLATION GUIDE
==================================================

SYSTEM REQUIREMENTS
===================
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache 2.4 or higher (or Nginx)
- 100MB disk space
- 1GB RAM minimum

INSTALLATION STEPS
==================

1. DOWNLOAD AND EXTRACT
-----------------------
- Download the system files
- Extract to your web server directory
  Example: /var/www/html/mainres-bus/

2. CONFIGURE DATABASE
---------------------
- Open config.php in a text editor
- Update database credentials:
  define('DB_HOST', 'localhost');
  define('DB_USER', 'your_username');
  define('DB_PASS', 'your_password');
  define('DB_NAME', 'mainres_bus');

3. SET UP DATABASE
------------------
- Navigate to db_setup.php in your browser
  Example: http://localhost/mainres-bus/db_setup.php
- This will create all necessary tables
- Default admin credentials will be created:
  Email: admin@mainres.ac.za
  Password: admin123

4. FILE PERMISSIONS
-------------------
Set proper permissions:
- Folders: 755
- Files: 644
- Uploads folder: 777 (or ensure web server can write)

5. CONFIGURE WEB SERVER
-----------------------
For Apache:
- Ensure mod_rewrite is enabled
- AllowOverride All for the directory

For Nginx:
- Configure proper rewrite rules
- Set up PHP-FPM

6. SECURITY CONFIGURATION
-------------------------
- Update .htaccess for your server
- Change default passwords
- Set up SSL certificate for production
- Configure email settings in functions.php

7. TEST THE SYSTEM
------------------
- Access the system: http://localhost/mainres-bus/
- Login with default admin credentials
- Create test student and driver accounts
- Test booking and scanning functionality

DEFAULT USER ACCOUNTS
=====================
1. Administrator:
   Email: admin@mainres.ac.za
   Password: admin123
   Role: System Administrator

2. Driver:
   Email: driver@mainres.ac.za
   Password: driver123
   Role: Bus Driver

3. Students:
   - No default students
   - Students must register with Teaching faculty and MainRes residence

SYSTEM FEATURES
===============
✓ Student registration and verification
✓ Online bus seat booking
✓ Real-time seat availability
✓ QR code generation and scanning
✓ Waitlist management
✓ Admin dashboard with analytics
✓ Driver attendance system
✓ Email notifications
✓ Mobile-responsive design

CONFIGURATION OPTIONS
=====================
Edit config.php to customize:
- Bus capacity (default: 65)
- Alert threshold (default: 75 bookings)
- Max advance bookings per student (default: 2)
- Cancellation deadline (default: 1 hour)
- Site URL and email settings

SECURITY RECOMMENDATIONS
========================
1. CHANGE DEFAULT PASSWORDS immediately
2. Enable HTTPS/SSL in production
3. Regular database backups
4. Keep PHP and MySQL updated
5. Monitor system logs
6. Regular security audits

TROUBLESHOOTING
===============
Common issues and solutions:

1. Database connection error:
   - Check credentials in config.php
   - Verify MySQL is running
   - Check user permissions

2. File upload issues:
   - Check uploads folder permissions
   - Verify PHP upload settings
   - Check disk space

3. QR code not generating:
   - Check GD library is installed
   - Verify uploads folder permissions
   - Check PHP memory limit

4. Email not sending:
   - Configure SMTP settings
   - Check spam folder
   - Verify email server settings

SUPPORT
=======
For support and issues:
- Email: transport@mainres.ac.za
- Phone: +27 11 123 4567
- Office: Main Residence Transport Office

UPDATES AND MAINTENANCE
=======================
- Regular database backups
- Update system files
- Monitor system performance
- User feedback collection

LICENSE
=======
This system is for educational purposes.
For commercial use, contact system administrator.

VERSION
=======
MainRes Bus System v1.0
Last Updated: 2024