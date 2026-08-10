            </div><!-- End Content -->
            
            <!-- Footer -->
            <footer style="padding: 20px; text-align: center; color: #6c757d; font-size: 13px; border-top: 1px solid #eee; margin-top: auto;">
                <p>&copy; <?php echo date('Y'); ?> SAINT CLAIRE CLINIC AND LYING-IN MEDICAL RECORDS AND INVENTORY MANAGEMENT SYSTEM.</p>
                <p>Version ni Roldan <?php echo APP_VERSION; ?></p>
            </footer>
        </main><!-- End Main Content -->
    </div><!-- End Wrapper -->
    
    <script>
        // Safe DOM helpers
        function qs(selector, context) { return (context || document).querySelector(selector); }
        function qsa(selector, context) { return Array.from((context || document).querySelectorAll(selector)); }

        // Toggle Sidebar (safe attach) + overlay + aria management
        (function() {
            var toggle = document.getElementById('toggleSidebar');
            var sidebar = document.getElementById('sidebar');
            var main = document.querySelector('.main-content');
            var overlayId = 'sidebarOverlay';
            var mobileQuery = window.matchMedia('(max-width: 767px)');

            function isMobile() {
                return mobileQuery.matches;
            }

            function isSidebarOpen() {
                return sidebar.classList.contains('sidebar-open') || sidebar.classList.contains('active');
            }

            function createOverlay() {
                var existing = document.getElementById(overlayId);
                if (existing) return existing;
                var o = document.createElement('div');
                o.id = overlayId;
                o.className = 'sidebar-overlay';
                o.setAttribute('role', 'button');
                o.setAttribute('aria-label', 'Close navigation');
                o.setAttribute('aria-hidden', 'true');
                o.setAttribute('tabindex', '-1');
                document.body.appendChild(o);
                o.addEventListener('click', function() {
                    closeSidebar();
                });
                return o;
            }

            function openSidebar() {
                if (isMobile()) {
                    sidebar.classList.add('sidebar-open');
                    sidebar.classList.remove('active');
                    if (main) main.classList.remove('sidebar-collapsed');
                } else {
                    sidebar.classList.add('active');
                    sidebar.classList.remove('sidebar-open');
                    if (main) main.classList.add('sidebar-collapsed');
                }
                var o = createOverlay();
                if (isMobile()) {
                    o.classList.add('active');
                    o.setAttribute('aria-hidden', 'false');
                    document.documentElement.style.overflow = 'hidden';
                    if (main) main.setAttribute('aria-hidden', 'true');
                } else {
                    o.classList.remove('active');
                    o.setAttribute('aria-hidden', 'true');
                    document.documentElement.style.overflow = '';
                    if (main) main.removeAttribute('aria-hidden');
                }
                toggle.setAttribute('aria-expanded', 'true');
                try {
                    var first = sidebar.querySelector('a, button, input, [tabindex]:not([tabindex="-1"])');
                    if (first) first.focus(); else sidebar.focus();
                } catch (e) { sidebar.focus(); }
            }

            function closeSidebar() {
                sidebar.classList.remove('active', 'sidebar-open');
                if (main) main.classList.remove('sidebar-collapsed');
                var o = document.getElementById(overlayId);
                if (o) o.classList.remove('active');
                if (o) o.setAttribute('aria-hidden', 'true');
                toggle.setAttribute('aria-expanded', 'false');
                if (main) main.removeAttribute('aria-hidden');
                document.documentElement.style.overflow = '';
            }

            if (toggle && sidebar) {
                toggle.addEventListener('click', function(e) {
                    if (isSidebarOpen()) closeSidebar();
                    else openSidebar();
                });

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && isSidebarOpen()) closeSidebar();
                });

                sidebar.querySelectorAll('.menu-item').forEach(function(link) {
                    link.addEventListener('click', function() {
                        if (isMobile()) closeSidebar();
                    });
                });

                mobileQuery.addEventListener('change', function() {
                    if (!isMobile()) closeSidebar();
                });

                window.addEventListener('resize', function() {
                    if (!isMobile() && sidebar.classList.contains('sidebar-open')) {
                        closeSidebar();
                    }
                });
            }
        })();

        // User menu: attach dropdown behavior to the existing header user menu
        var baseUrl = '<?php echo BASE_URL; ?>';
        (function() {
            var userDropdown = qs('.user-dropdown');
            if (!userDropdown) return;

            var menu = qs('#userMenu');
            if (!menu) {
                menu = document.createElement('div');
                menu.id = 'userMenu';
                menu.className = 'user-menu';
                menu.innerHTML = '<a href="' + baseUrl + 'profile.php">Profile</a><a href="' + baseUrl + 'logout.php">Logout</a>';
                document.body.appendChild(menu);
            }

            userDropdown.addEventListener('click', function(e) {
                e.stopPropagation();
                var rect = userDropdown.getBoundingClientRect();
                menu.style.position = 'fixed';
                menu.style.top = (rect.bottom + 6) + 'px';
                menu.style.right = Math.max(10, (window.innerWidth - rect.right)) + 'px';
                menu.classList.toggle('active');
                var noti = qs('.notifications-panel'); if (noti) noti.classList.remove('active');
            });

            document.addEventListener('click', function() {
                if (menu) menu.classList.remove('active');
            });

            if (menu) {
                menu.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
        })();

        // Notifications panel
        (function() {
            var bell = qs('i.fa-bell');
            if (!bell) return;
            var bellParent = bell.closest('.header-icon');
            var panel = qs('.notifications-panel');
            if (!panel) {
                panel = document.createElement('div');
                panel.className = 'notifications-panel';
                panel.innerHTML = '<div class="item">No new notifications</div>';
                document.body.appendChild(panel);
            }

            bellParent.addEventListener('click', function(e) {
                e.stopPropagation();
                var rect = bellParent.getBoundingClientRect();
                panel.style.position = 'fixed';
                panel.style.top = (rect.bottom + 6) + 'px';
                panel.style.right = Math.max(10, (window.innerWidth - rect.right)) + 'px';
                panel.classList.toggle('active');
                // close user menu if open
                var menu = qs('.user-menu'); if (menu) menu.classList.remove('active');
            });

            document.addEventListener('click', function() { panel.classList.remove('active'); });
        })();
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(function() {
                    alert.style.display = 'none';
                }, 500);
            });
        }, 5000);

        // Reveal page after JavaScript is loaded on all pages
        document.addEventListener('DOMContentLoaded', function () {
            document.body.classList.add('page-enter-ready');

            var staggeredItems = document.querySelectorAll('.sidebar, .header, .content, .stats-card, .chart-card, .card');
            staggeredItems.forEach(function (item, index) {
                var delay = 0;
                if (item.classList.contains('sidebar')) delay = 0.28;
                else if (item.classList.contains('header')) delay = 0.42;
                else if (item.classList.contains('content')) delay = 0.58;
                else delay = Math.min(1.0, 0.64 + (index * 0.08));

                item.style.transitionDelay = delay + 's';
            });
        });

        (function themeSwitcher() {
            var themeToggle = qs('#themeToggle');
            if (!themeToggle) return;

            var themeIcon = themeToggle.querySelector('i');
            var themeLabel = themeToggle.querySelector('.theme-label');

            function updateTheme(mode) {
                document.documentElement.setAttribute('data-theme', mode);
                localStorage.setItem('siteTheme', mode);
                if (themeIcon) {
                    themeIcon.className = mode === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
                }
                if (themeLabel) {
                    themeLabel.textContent = mode === 'dark' ? 'Dark' : 'Light';
                }
                themeToggle.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');
                window.dispatchEvent(new Event('themeChanged'));
            }

            function loadTheme() {
                var stored = localStorage.getItem('siteTheme');
                if (stored === 'dark' || stored === 'light') {
                    updateTheme(stored);
                    return;
                }
                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                updateTheme(prefersDark ? 'dark' : 'light');
            }

            themeToggle.addEventListener('click', function() {
                var current = document.documentElement.getAttribute('data-theme');
                updateTheme(current === 'dark' ? 'light' : 'dark');
            });

            loadTheme();
        })();
        
        // Tab functionality
        document.querySelectorAll('.tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // Remove active from all tabs
                document.querySelectorAll('.tab').forEach(function(t) {
                    t.classList.remove('active');
                });
                document.querySelectorAll('.tab-content').forEach(function(c) {
                    c.classList.remove('active');
                });
                
                // Add active to clicked tab
                this.classList.add('active');
                document.getElementById(tabId).classList.add('active');
            });
        });
        
        // Modal functionality
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        // Close modal when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(function(modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // Confirm delete
        function confirmDelete(message) {
            return confirm(message || 'Are you sure you want to delete this record?');
        }
        
        // Print function
        function printPage() {
            window.print();
        }
        
        // Calculate BMI
        function calculateBMI(weight, height) {
            if (weight && height) {
                const heightInMeters = height / 100;
                return (weight / (heightInMeters * heightInMeters)).toFixed(2);
            }
            return '';
        }
        
        // Auto-calculate age from birthdate
        function calculateAge(birthdate) {
            if (birthdate) {
                const today = new Date();
                const birth = new Date(birthdate);
                let age = today.getFullYear() - birth.getFullYear();
                const monthDiff = today.getMonth() - birth.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                    age--;
                }
                return age;
            }
            return '';
        }
        
        // Search table functionality
        function searchTable(inputId, tableId) {
            const input = document.getElementById(inputId);
            const filter = input.value.toUpperCase();
            const table = document.getElementById(tableId);
            const tr = table.getElementsByTagName('tr');
            
            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < td.length; j++) {
                    if (td[j]) {
                        const txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                tr[i].style.display = found ? '' : 'none';
            }
        }
    </script>
    <!-- Choices.js (searchable, scrollable select) -->
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
</body>
</html>
