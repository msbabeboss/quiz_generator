/**
 * realtime.js — Pusher client-side event listeners
 *
 * Requires: Pusher JS SDK loaded before this script
 * Globals injected by PHP: PUSHER_KEY, PUSHER_CLUSTER
 */

(function () {
    'use strict';

    // Guard: only initialize if Pusher credentials are available
    if (typeof PUSHER_KEY === 'undefined' || typeof PUSHER_CLUSTER === 'undefined') {
        console.warn('Pusher credentials not found. Real-time updates disabled.');
        return;
    }

    // Initialize Pusher with server-injected credentials
    var pusher  = new Pusher(PUSHER_KEY, { cluster: PUSHER_CLUSTER });
    var channel = pusher.subscribe('quiz-channel');

    // Bind: new quiz submission received
    channel.bind('quiz-submitted', function (data) {
        appendSubmissionRow(data);
        playNotificationSound();
        updateParticipantCount(data.quiz_id);
    });

    // Bind: leaderboard scores updated
    channel.bind('score-updated', function (data) {
        renderLeaderboard(data.leaderboard);
    });

    // Bind: participant count changed (triggered by server after session changes)
    channel.bind('participant-count-updated', function (data) {
        var el = document.getElementById('participant-count');
        if (el) {
            el.textContent = data.count;
        }
    });

    /**
     * Prepend a new row to the #live-submissions tbody.
     *
     * @param {Object} data - Event payload from 'quiz-submitted'
     * @param {string} data.username     - Student username
     * @param {number} data.percentage   - Score percentage (0–100)
     * @param {string} data.submitted_at - Submission timestamp
     */
    function appendSubmissionRow(data) {
        var tbody = document.querySelector('#live-submissions tbody');
        if (!tbody) return;

        var row = document.createElement('tr');

        var tdUsername = document.createElement('td');
        tdUsername.appendChild(document.createTextNode(String(data.username || '')));

        var tdPercentage = document.createElement('td');
        tdPercentage.appendChild(document.createTextNode(String(data.percentage || '0') + '%'));

        var tdSubmittedAt = document.createElement('td');
        tdSubmittedAt.appendChild(document.createTextNode(String(data.submitted_at || '')));

        row.appendChild(tdUsername);
        row.appendChild(tdPercentage);
        row.appendChild(tdSubmittedAt);

        tbody.insertBefore(row, tbody.firstChild);
    }

    /**
     * Re-render the #leaderboard-body with fresh leaderboard data.
     *
     * @param {Array} leaderboard - Array of result objects from 'score-updated'
     * @param {string} leaderboard[].username    - Student username
     * @param {number} leaderboard[].score       - Points scored
     * @param {number} leaderboard[].total_points - Total possible points
     * @param {number} leaderboard[].percentage  - Score percentage
     */
    function renderLeaderboard(leaderboard) {
        var tbody = document.getElementById('leaderboard-body');
        if (!tbody) return;

        // Clear existing rows
        while (tbody.firstChild) {
            tbody.removeChild(tbody.firstChild);
        }

        if (!Array.isArray(leaderboard) || leaderboard.length === 0) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.setAttribute('colspan', '5');
            emptyCell.appendChild(document.createTextNode('No results yet.'));
            emptyRow.appendChild(emptyCell);
            tbody.appendChild(emptyRow);
            return;
        }

        leaderboard.forEach(function (entry, index) {
            var row = document.createElement('tr');
            if (index === 0) row.className = 'table-warning text-dark fw-bold';

            var pct = parseFloat(entry.percentage || 0).toFixed(2);

            var tdRank = document.createElement('td');
            tdRank.appendChild(document.createTextNode(String(index + 1)));

            var tdUsername = document.createElement('td');
            tdUsername.appendChild(document.createTextNode(String(entry.username || '')));

            var tdScore = document.createElement('td');
            tdScore.appendChild(document.createTextNode(
                String(entry.score || '0') + ' / ' + String(entry.total_points || '0')
            ));

            var tdPercentage = document.createElement('td');
            tdPercentage.appendChild(document.createTextNode(pct + '%'));

            var tdSubmittedAt = document.createElement('td');
            tdSubmittedAt.appendChild(document.createTextNode(String(entry.submitted_at || '—')));

            row.appendChild(tdRank);
            row.appendChild(tdUsername);
            row.appendChild(tdScore);
            row.appendChild(tdPercentage);
            row.appendChild(tdSubmittedAt);

            tbody.appendChild(row);
        });
    }

    /**
     * Request an updated participant count from the server for a given quiz.
     * The server responds via the 'participant-count-updated' Pusher event,
     * which updates #participant-count automatically via the bound handler above.
     *
     * @param {number} quizId - The quiz ID to fetch participant count for
     */
    function updateParticipantCount(quizId) {
        // The count is delivered via the 'participant-count-updated' Pusher event
        // triggered by the server after each submission. No additional fetch needed
        // here — the bound handler above handles the DOM update when the event arrives.
        // This function is a no-op placeholder that satisfies the event binding contract.
        void quizId;
    }

    /**
     * XSS-safe string escaping using DOM text nodes.
     * Returns the HTML-escaped representation of the input string.
     *
     * @param  {*}      str - Value to escape
     * @returns {string}     HTML-escaped string
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    /**
     * Play a notification sound, silently ignoring autoplay policy errors.
     */
    function playNotificationSound() {
        var audio = new Audio('/assets/sounds/notification.mp3');
        audio.play().catch(function () {});
    }

    // Expose helpers on window for use by inline scripts or other modules
    window.appendSubmissionRow  = appendSubmissionRow;
    window.renderLeaderboard    = renderLeaderboard;
    window.updateParticipantCount = updateParticipantCount;
    window.escapeHtml           = escapeHtml;
    window.playNotificationSound = playNotificationSound;

}());
