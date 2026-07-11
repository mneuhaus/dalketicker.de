/*
 * "Überrasch mich" — show today's events one random card at a time. Tap
 * "was anderes" or swipe the card left to get the next suggestion.
 */

function shuffle(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}

function init() {
    const root = document.querySelector('[data-surprise]');
    if (!root) return;

    const cards = Array.from(root.querySelectorAll('[data-surprise-card]'));
    if (cards.length === 0) return;

    const done = root.querySelector('[data-surprise-done]');
    const counter = document.querySelector('[data-surprise-counter]');
    const nextBtn = document.querySelector('[data-surprise-next]');
    const goLink = document.querySelector('[data-surprise-go]');
    const actions = document.querySelector('[data-surprise-actions]');
    const restartBtn = root.querySelector('[data-surprise-restart]');

    let order = shuffle([...cards.keys()]);
    let pos = 0;
    // While the leave animation runs, further next() calls are ignored so a
    // rapid double-tap can't skip a never-shown suggestion.
    let leaving = false;
    let leaveTimer = 0;

    function render() {
        window.clearTimeout(leaveTimer);
        leaving = false;
        cards.forEach((c) => {
            c.classList.add('hidden');
            c.classList.remove('surprise-leaving');
            c.style.transform = '';
            c.style.transition = '';
        });
        if (pos >= order.length) {
            if (done) done.classList.remove('hidden');
            if (actions) actions.classList.add('hidden');
            if (counter) counter.textContent = '';
            return;
        }
        if (done) done.classList.add('hidden');
        if (actions) actions.classList.remove('hidden');
        const card = cards[order[pos]];
        card.classList.remove('hidden');
        if (goLink && card.dataset.url) goLink.href = card.dataset.url;
        if (counter) counter.textContent = `Vorschlag ${pos + 1} von ${order.length}`;
    }

    function next() {
        if (leaving) return;
        const card = cards[order[pos]];
        if (card && !card.classList.contains('hidden')) {
            leaving = true;
            card.classList.add('surprise-leaving');
            leaveTimer = window.setTimeout(() => { pos++; render(); }, 180);
        } else {
            pos++;
            render();
        }
    }

    function prev() {
        if (leaving) return;
        if (pos >= order.length) { pos = order.length - 1; }
        else if (pos > 0) { pos--; }
        else { return; }
        render();
    }

    if (nextBtn) nextBtn.addEventListener('click', next);
    if (restartBtn) restartBtn.addEventListener('click', () => { order = shuffle([...cards.keys()]); pos = 0; render(); });

    // Arrow keys: → next event, ← previous event.
    document.addEventListener('keydown', (e) => {
        if (e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) return;
        const t = e.target;
        if (t && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName))) return;
        if (e.key === 'ArrowRight') { e.preventDefault(); next(); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
    });

    // Swipe-left to skip; drag follows the finger and snaps back otherwise.
    // Requires real horizontal movement so stray taps never advance the stack.
    let startX = null;
    let moved = false;
    let card = null;
    root.addEventListener('touchstart', (e) => {
        card = cards[order[pos]];
        if (!card || card.classList.contains('hidden')) { card = null; return; }
        startX = e.touches[0].clientX;
        moved = false;
        card.style.transition = 'none';
    }, { passive: true });
    root.addEventListener('touchmove', (e) => {
        if (startX === null || !card) return;
        const dx = e.touches[0].clientX - startX;
        if (Math.abs(dx) > 4) moved = true;
        card.style.transform = `translateX(${dx}px) rotate(${dx / 40}deg)`;
    }, { passive: true });
    root.addEventListener('touchend', (e) => {
        if (startX === null || !card) return;
        const dx = e.changedTouches[0].clientX - startX;
        card.style.transition = '';
        card.style.transform = '';
        if (moved && dx < -60) next();
        startX = null;
        moved = false;
        card = null;
    });

    render();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
