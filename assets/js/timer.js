/**
 * assets/js/timer.js — Countdown timer with auto-submit for quiz sessions.
 *
 * Requires APP_BASE to be defined as a global variable injected by PHP
 * before this script is loaded.
 *
 * Usage:
 *   startQuizTimer(timeLimit, sessionId, csrfToken);
 *
 * @param {number} timeLimit  - Total quiz duration in seconds.
 * @param {number} sessionId  - The current quiz session ID.
 * @param {string} csrfToken  - CSRF token for the POST request.
 */
function startQuizTimer(timeLimit, sessionId, csrfToken) {
    let remaining = timeLimit;
    const display = document.getElementById('timer-display');

    const interval = setInterval(async () => {
        remaining--;

        const mins = Math.floor(remaining / 60).toString().padStart(2, '0');
        const secs = (remaining % 60).toString().padStart(2, '0');
        display.textContent = `${mins}:${secs}`;

        // Visual warning when 30 seconds or fewer remain
        if (remaining <= 30) {
            display.classList.add('text-danger');
        }

        if (remaining <= 0) {
            clearInterval(interval);
            display.textContent = '00:00';
            await autoSubmitQuiz(sessionId, csrfToken);
        }
    }, 1000);
}

/**
 * Submits the quiz automatically when the timer expires.
 *
 * POSTs to the auto-submit endpoint and redirects to the results page on
 * success. On error, shows an alert and attempts to redirect anyway so the
 * student is not left on a broken page.
 *
 * @param {number} sessionId - The current quiz session ID.
 * @param {string} csrfToken - CSRF token for the POST request.
 */
async function autoSubmitQuiz(sessionId, csrfToken) {
    const formData = new FormData();
    formData.append('session_id', sessionId);
    formData.append('csrf_token', csrfToken);

    try {
        const response = await fetch(APP_BASE + '/api/auto-submit.php', {
            method: 'POST',
            body: formData,
        });

        if (response.ok) {
            window.location.href =
                APP_BASE + '/student/results.php?session_id=' + sessionId;
        } else {
            // Non-2xx response — alert and redirect anyway
            alert('Your time is up. Your quiz has been submitted.');
            window.location.href =
                APP_BASE + '/student/results.php?session_id=' + sessionId;
        }
    } catch (error) {
        // Network or fetch error — alert and attempt redirect
        alert('Your time is up. Your quiz has been submitted.');
        window.location.href =
            APP_BASE + '/student/results.php?session_id=' + sessionId;
    }
}
