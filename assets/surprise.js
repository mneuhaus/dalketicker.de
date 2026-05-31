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
    const restartBtn = root.querySelector('[data-surprise-restart]');

    let order = shuffle([...cards.keys()]);
    let pos = 0;

    function render() {
        cards.forEach((c) => {
            c.classList.add('hidden');
            c.classList.remove('surprise-leaving');
            c.style.transform = '';
            c.style.transition = '';
        });
        if (pos >= order.length) {
            if (done) done.classList.remove('hidden');
            if (nextBtn) nextBtn.disabled = true;
            if (counter) counter.textContent = '';
            return;
        }
        if (done) done.classList.add('hidden');
        if (nextBtn) nextBtn.disabled = false;
        cards[order[pos]].classList.remove('hidden');
        if (counter) counter.textContent = `Vorschlag ${pos + 1} von ${order.length}`;
    }

    function next() {
        const card = cards[order[pos]];
        if (card && !card.classList.contains('hidden')) {
            card.classList.add('surprise-leaving');
            window.setTimeout(() => { pos++; render(); }, 180);
        } else {
            pos++;
            render();
        }
    }

    if (nextBtn) nextBtn.addEventListener('click', next);
    if (restartBtn) restartBtn.addEventListener('click', () => { order = shuffle([...cards.keys()]); pos = 0; render(); });

    // Swipe-left to skip; drag follows the finger and snaps back otherwise.
    let startX = null;
    let card = null;
    root.addEventListener('touchstart', (e) => {
        card = cards[order[pos]];
        if (!card || card.classList.contains('hidden')) { card = null; return; }
        startX = e.touches[0].clientX;
        card.style.transition = 'none';
    }, { passive: true });
    root.addEventListener('touchmove', (e) => {
        if (startX === null || !card) return;
        const dx = e.touches[0].clientX - startX;
        card.style.transform = `translateX(${dx}px) rotate(${dx / 40}deg)`;
    }, { passive: true });
    root.addEventListener('touchend', (e) => {
        if (startX === null || !card) return;
        const dx = e.changedTouches[0].clientX - startX;
        card.style.transition = '';
        card.style.transform = '';
        if (dx < -60) next();
        startX = null;
        card = null;
    });

    render();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
