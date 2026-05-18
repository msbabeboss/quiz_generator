# Notification Sound

This directory contains audio assets used by the real-time quiz interface.

## notification.mp3

The file `notification.mp3` in this directory is a **placeholder**. It is a 0-byte file committed to the repository so the path `/assets/sounds/notification.mp3` resolves without a 404.

### Replacing the placeholder

To enable actual notification sounds, replace `notification.mp3` with a real MP3 audio file:

1. Obtain or record a short notification sound (recommended: < 1 second, < 50 KB).
2. Export/save it as `notification.mp3`.
3. Place it in this directory, overwriting the placeholder.

The `playNotificationSound()` function in `assets/js/realtime.js` will automatically use the file at this path. Autoplay policy errors (e.g. when the browser blocks audio before user interaction) are silently suppressed.
