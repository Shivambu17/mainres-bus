            </div> <!-- Close main content div from header -->
        </div> <!-- Close row -->
    </div> <!-- Close container -->

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Custom JS -->
    <script src="scripts.js"></script>
    
    <?php if (isset($customScripts)): ?>
        <?php echo $customScripts; ?>
    <?php endif; ?>
    
    <footer class="footer mt-auto py-3 bg-light border-top">
        <div class="container text-center">
            <span class="text-muted">Bus Reservation System &copy; <?php echo date('Y'); ?> | Version 1.0</span>
        </div>
    </footer>
</body>
</html>