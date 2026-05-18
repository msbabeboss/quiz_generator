/**
 * Dark Mode Toggle
 *
 * - On DOMContentLoaded: restores the user's saved preference from localStorage.
 * - toggleDarkMode(): toggles the `.dark-mode` class on <body> and persists the
 *   new preference to localStorage under the key 'darkMode'.
 * - The toggle button (#dark-mode-toggle) calls toggleDarkMode() on click.
 */

(function () {
    'use strict';

    /**
     * Apply the stored dark-mode preference as early as possible to avoid
     * a flash of unstyled (light) content on page load.
     */
    function applyStoredPreference() {
        if (localStorage.getItem('darkMode') === 'enabled') {
            document.body.classList.add('dark-mode');
        }
    }

    /**
     * Toggle dark mode on/off and persist the preference.
     * Exposed on `window` so inline onclick handlers and other scripts can call it.
     */
    function toggleDarkMode() {
        var body = document.body;

        if (body.classList.contains('dark-mode')) {
            body.classList.remove('dark-mode');
            localStorage.setItem('darkMode', 'disabled');
            updateToggleButton(false);
        } else {
            body.classList.add('dark-mode');
            localStorage.setItem('darkMode', 'enabled');
            updateToggleButton(true);
        }
    }

    /**
     * Update the toggle button icon/label to reflect the current mode.
     * @param {boolean} isDark - true when dark mode is now active.
     */
    function updateToggleButton(isDark) {
        var btn = document.getElementById('dark-mode-toggle');
        if (!btn) return;

        if (isDark) {
            btn.textContent = '☀️';
            btn.setAttribute('aria-label', 'Switch to light mode');
            btn.setAttribute('title', 'Switch to light mode');
        } else {
            btn.textContent = '🌙';
            btn.setAttribute('aria-label', 'Switch to dark mode');
            btn.setAttribute('title', 'Switch to dark mode');
        }
    }

    /**
     * Wire up the toggle button once the DOM is ready.
     */
    function init() {
        // Apply preference immediately (may already be applied above, but
        // calling again is safe and ensures the button label is also set).
        var isDark = document.body.classList.contains('dark-mode');
        updateToggleButton(isDark);

        var btn = document.getElementById('dark-mode-toggle');
        if (btn) {
            btn.addEventListener('click', toggleDarkMode);
        }
    }

    // Apply preference as early as possible (before full DOM parse).
    // If <body> is not yet available (script in <head>), defer to DOMContentLoaded.
    if (document.body) {
        applyStoredPreference();
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Ensure preference is applied even if the script ran before <body> existed.
        applyStoredPreference();
        init();
    });

    // Expose toggleDarkMode globally so the button's onclick attribute can call it.
    window.toggleDarkMode = toggleDarkMode;
}());
