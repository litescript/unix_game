// Client-side polish for the cs-lab-3 terminal.
// - arrow-key history with draft preservation
// - tab autocomplete via /complete.php
// - Ctrl-L clears the scrollback
// - keeps the input focused and the page scrolled to the bottom
// - CRT mode (Alt+C): block cursor positioning and phosphor-burst on new output

(function () {
    'use strict';

    var HISTORY_LIMIT = 200;
    var CRT_KEY = 'cs-lab-3:crt';
    var BURST_MS = 220;

    var history = [];
    var cursor = 0;
    var draft = '';

    // ---- DOM lookups ------------------------------------------------------

    function inputEl() {
        return document.querySelector('#prompt-line input[name="command"]');
    }
    function csrfValue() {
        var el = document.querySelector('#prompt-line input[name="csrf"]');
        return el ? el.value : '';
    }
    function scrollbackEl() {
        return document.getElementById('scrollback');
    }
    function promptLabel() {
        var el = document.querySelector('#prompt-line .prompt');
        return el ? el.textContent : '';
    }
    function cursorEl() {
        return document.querySelector('#prompt-line .crt-cursor');
    }
    function measureEl() {
        return document.querySelector('#prompt-line .crt-cursor-measure');
    }

    function moveCursorToEnd(i) {
        var n = i.value.length;
        i.setSelectionRange(n, n);
    }

    function pushHistory(line) {
        if (line === '') {
            cursor = history.length;
            return;
        }
        if (history.length > 0 && history[history.length - 1] === line) {
            cursor = history.length;
            return;
        }
        history.push(line);
        if (history.length > HISTORY_LIMIT) history.shift();
        cursor = history.length;
    }

    // ---- block cursor -----------------------------------------------------

    // Position the fake cursor at input.selectionStart by mirroring the
    // typed text into a hidden span and reading its width. Same font, same
    // line-height — alignment is exact for monospace.
    function updateCursor() {
        var input   = inputEl();
        var blk     = cursorEl();
        var measure = measureEl();
        if (!input || !blk || !measure) return;

        var pos = input.selectionStart;
        if (typeof pos !== 'number') pos = input.value.length;
        measure.textContent = input.value.substring(0, pos);
        blk.style.left = measure.offsetWidth + 'px';

        // Restart the blink animation so the cursor is visible the moment
        // the user types a character (rather than potentially mid-blink-off).
        blk.style.animation = 'none';
        // force a reflow before reapplying the animation
        void blk.offsetWidth;
        blk.style.animation = '';
    }

    // ---- phosphor burst ---------------------------------------------------

    function flashBurst(el) {
        if (!el) return;
        if (!document.body.classList.contains('crt')) return;
        el.classList.add('phosphor-burst');
        setTimeout(function () {
            el.classList.remove('phosphor-burst');
        }, BURST_MS);
    }

    // ---- keyboard ---------------------------------------------------------

    document.addEventListener('keydown', function (e) {
        var i = inputEl();
        if (!i || document.activeElement !== i) return;

        if (e.ctrlKey && (e.key === 'l' || e.key === 'L')) {
            e.preventDefault();
            var s = scrollbackEl();
            if (s) s.innerHTML = '';
            return;
        }

        if (e.key === 'Enter') {
            pushHistory(i.value);
            draft = '';
            return;
        }

        if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (history.length === 0) return;
            if (cursor === history.length) draft = i.value;
            if (cursor > 0) {
                cursor--;
                i.value = history[cursor];
                moveCursorToEnd(i);
                updateCursor();
            }
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (cursor < history.length) {
                cursor++;
                i.value = (cursor === history.length) ? draft : history[cursor];
                moveCursorToEnd(i);
                updateCursor();
            }
            return;
        }

        if (e.key === 'Tab') {
            e.preventDefault();
            requestCompletion(i);
            return;
        }
    });

    // Any value- or caret-changing event on the command input updates the
    // block cursor. Delegated on document so it survives htmx swaps.
    ['input', 'keyup', 'click', 'focusin', 'select'].forEach(function (evt) {
        document.addEventListener(evt, function (e) {
            if (e.target && e.target.matches &&
                e.target.matches('#prompt-line input[name="command"]')) {
                updateCursor();
            }
        });
    });

    // ---- tab completion ---------------------------------------------------

    function requestCompletion(i) {
        var original = i.value;
        var body = new URLSearchParams();
        body.set('line', original);
        body.set('csrf', csrfValue());

        fetch('complete.php', {
            method: 'POST',
            body: body,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.ok) return;
                if (data.matches && data.matches.length > 1) {
                    appendCompletionDisplay(promptLabel(), original, data.matches);
                }
                var live = inputEl();
                if (!live) return;
                if (typeof data.line === 'string') {
                    live.value = data.line;
                    moveCursorToEnd(live);
                }
                live.focus();
                updateCursor();
            })
            .catch(function () { /* swallow */ });
    }

    function appendCompletionDisplay(prompt, line, matches) {
        var s = scrollbackEl();
        if (!s) return;

        var wrap = document.createElement('div');
        wrap.className = 'exchange';

        var echo = document.createElement('div');
        echo.className = 'echo';
        var ps = document.createElement('span');
        ps.className = 'prompt';
        ps.textContent = prompt;
        echo.appendChild(ps);
        echo.appendChild(document.createTextNode(line));

        var out = document.createElement('pre');
        out.className = 'output';
        out.textContent = matches.join('  ');

        wrap.appendChild(echo);
        wrap.appendChild(out);
        s.appendChild(wrap);
        flashBurst(wrap);
        window.scrollTo(0, document.body.scrollHeight);
    }

    // ---- htmx swap hooks --------------------------------------------------

    document.body.addEventListener('htmx:afterSwap', function () {
        var i = inputEl();
        if (i) i.focus();
        window.scrollTo(0, document.body.scrollHeight);
        cursor = history.length;
        draft = '';

        // burst on the freshly-appended exchange (last child of scrollback)
        var s = scrollbackEl();
        if (s) {
            var last = s.lastElementChild;
            if (last && last.classList && last.classList.contains('exchange')) {
                flashBurst(last);
            }
        }

        updateCursor();
    });

    // ---- CRT toggle (Alt+C, persisted) -----------------------------------

    // CRT is on by default (body.crt is set in index.php). Only opt-out
    // when the user has explicitly toggled it off via Alt+C.
    try {
        if (localStorage.getItem(CRT_KEY) === '0') {
            document.body.classList.remove('crt');
        }
    } catch (e) { /* localStorage may be unavailable (private mode) */ }

    document.addEventListener('keydown', function (e) {
        if (e.altKey && !e.ctrlKey && !e.metaKey && (e.key === 'c' || e.key === 'C')) {
            e.preventDefault();
            var on = document.body.classList.toggle('crt');
            try { localStorage.setItem(CRT_KEY, on ? '1' : '0'); } catch (err) {}
            updateCursor();
        }
    });

    // initial position
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateCursor);
    } else {
        updateCursor();
    }
})();
