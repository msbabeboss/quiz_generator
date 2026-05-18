<?php

/**
 * config/pusher.php — Pusher real-time event service.
 *
 * Provides a singleton Pusher client and helper functions for triggering
 * real-time events on the quiz channel. All functions degrade gracefully:
 * if Pusher is unavailable, errors are logged and false is returned so the
 * calling code can continue without interruption.
 */

require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/**
 * Returns a singleton Pusher client instance.
 *
 * Credentials are loaded from $_ENV (populated by loadEnv() above):
 *   PUSHER_KEY, PUSHER_SECRET, PUSHER_APP_ID, PUSHER_CLUSTER
 *
 * @return Pusher\Pusher
 */
function getPusher(): Pusher\Pusher {
    static $pusher = null;
    if ($pusher === null) {
        $pusher = new Pusher\Pusher(
            $_ENV['PUSHER_KEY'],
            $_ENV['PUSHER_SECRET'],
            $_ENV['PUSHER_APP_ID'],
            [
                'cluster' => $_ENV['PUSHER_CLUSTER'],
                'useTLS'  => true,
            ]
        );
    }
    return $pusher;
}

/**
 * Triggers a 'quiz-submitted' event on the quiz channel.
 *
 * Notifies the admin dashboard in real time when a student submits a quiz.
 * The username is sanitized with htmlspecialchars() before inclusion in the
 * payload to prevent XSS in any client that renders it without escaping.
 *
 * @param int    $studentId  The ID of the student who submitted.
 * @param string $username   The student's display name (will be escaped).
 * @param int    $quizId     The ID of the quiz that was submitted.
 * @param float  $percentage The student's score as a percentage (0–100).
 * @return bool  true on success, false if Pusher throws an exception.
 */
function triggerQuizSubmitted(int $studentId, string $username, int $quizId, float $percentage): bool {
    try {
        getPusher()->trigger('quiz-channel', 'quiz-submitted', [
            'student_id'   => $studentId,
            'username'     => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
            'quiz_id'      => $quizId,
            'percentage'   => round($percentage, 2),
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    } catch (Exception $e) {
        error_log('Pusher triggerQuizSubmitted error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Triggers a 'score-updated' event on the quiz channel.
 *
 * Broadcasts the current leaderboard to all connected admin clients so the
 * live leaderboard table refreshes without a page reload.
 *
 * @param int   $quizId      The ID of the quiz whose leaderboard changed.
 * @param array $leaderboard Ordered array of result rows (username, score, etc.).
 * @return bool true on success, false if Pusher throws an exception.
 */
function triggerScoreUpdated(int $quizId, array $leaderboard): bool {
    try {
        getPusher()->trigger('quiz-channel', 'score-updated', [
            'quiz_id'     => $quizId,
            'leaderboard' => $leaderboard,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return true;
    } catch (Exception $e) {
        error_log('Pusher triggerScoreUpdated error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Triggers a 'participant-count-updated' event on the quiz channel.
 *
 * Broadcasts the current number of active participants for a quiz so the
 * admin dashboard can display a live participant counter.
 *
 * @param int $quizId The ID of the quiz.
 * @param int $count  The current number of active (in_progress) participants.
 * @return bool true on success, false if Pusher throws an exception.
 */
function triggerParticipantUpdate(int $quizId, int $count): bool {
    try {
        getPusher()->trigger('quiz-channel', 'participant-count-updated', [
            'quiz_id' => $quizId,
            'count'   => $count,
        ]);
        return true;
    } catch (Exception $e) {
        error_log('Pusher triggerParticipantUpdate error: ' . $e->getMessage());
        return false;
    }
}
