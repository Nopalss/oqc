/**
 * Core Application JavaScript Utilities (jQuery Powered)
 * Handles Sidebar Toggle, State Persistence, and SweetAlert2 Confirmations
 */

$(document).ready(function () {
    console.log("OQC System JavaScript Initialized Successfully.");

    // Helper to check if current viewport matches mobile breakpoint (< 768px)
    function isMobile() {
        return window.matchMedia('(max-width: 767.98px)').matches;
    }

    // Function to handle initial responsive sidebar state
    function initSidebar() {
        if (!isMobile()) {
            $("body").removeClass("sidebar-mobile-open");
            var isClosed = localStorage.getItem('oqc_sidebar_closed') === 'true';
            if (isClosed) {
                $("body").addClass("sidebar-closed");
            } else {
                $("body").removeClass("sidebar-closed");
            }
        } else {
            $("body").removeClass("sidebar-closed sidebar-mobile-open");
        }
    }

    initSidebar();

    // Re-evaluate on window resize
    $(window).on("resize", function () {
        if (!isMobile()) {
            $("body").removeClass("sidebar-mobile-open");
        } else {
            $("body").removeClass("sidebar-closed");
        }
    });

    // Toggle Sidebar Handler (Navbar Hamburger)
    $(document).on("click", "#navbar-sidebar-toggle", function (e) {
        e.preventDefault();
        if (isMobile()) {
            $("body").removeClass("sidebar-closed").toggleClass("sidebar-mobile-open");
        } else {
            $("body").removeClass("sidebar-mobile-open").toggleClass("sidebar-closed");
            var nowClosed = $("body").hasClass("sidebar-closed");
            localStorage.setItem('oqc_sidebar_closed', nowClosed);
        }
    });

    // Desktop Collapse Button Handler
    $(document).on("click", "#sidebar-collapse-btn", function (e) {
        e.preventDefault();
        $("body").removeClass("sidebar-mobile-open").toggleClass("sidebar-closed");
        var nowClosed = $("body").hasClass("sidebar-closed");
        localStorage.setItem('oqc_sidebar_closed', nowClosed);
    });

    // Mobile Close Button & Overlay Click Handler
    $(document).on("click", "#sidebar-overlay, #sidebar-close-mobile", function (e) {
        e.preventDefault();
        $("body").removeClass("sidebar-mobile-open");
    });

    // Close Mobile Sidebar on Nav Link Click
    $(document).on("click", "#sidebar a", function () {
        if (isMobile()) {
            $("body").removeClass("sidebar-mobile-open");
        }
    });

    // Auto dismiss flash alert after 5 seconds
    setTimeout(function () {
        $("#flash-alert").fadeOut(400, function () {
            $(this).remove();
        });
    }, 5000);
});

/**
 * Show SweetAlert2 Confirmation Dialog for Deletion Actions
 * 
 * @param {string} deleteUrl Target URL to execute deletion
 * @param {string} name Item name/title for display
 */
function confirmDelete(deleteUrl, name) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Konfirmasi Hapus Data',
            html: `Apakah Anda yakin ingin menghapus data <b>"${name}"</b>?<br><span style="font-size: 11px; color: #64748b;">Tindakan ini tidak dapat dibatalkan.</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus Sekarang!',
            cancelButtonText: 'Batal'
        }).then(function(result) {
            if (result.isConfirmed) {
                window.location.href = deleteUrl;
            }
        });
    } else {
        if (confirm(`Apakah Anda yakin ingin menghapus "${name}"?`)) {
            window.location.href = deleteUrl;
        }
    }
}
