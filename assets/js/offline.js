/* AgriSense - offline page: Retry reloads the page that failed to load. */
(function () {
    'use strict';

    var button = document.getElementById('offRetry');
    var note = document.getElementById('offNote');

    function retry() {
        if (!navigator.onLine) {
            note.textContent = 'Still offline. Check the connection and try again.';
            return;
        }
        note.textContent = 'Reconnecting...';
        window.location.reload();
    }

    button.addEventListener('click', retry);
    window.addEventListener('online', retry);
})();
