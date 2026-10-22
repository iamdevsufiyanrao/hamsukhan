/**
 * Only one sound at a time across the whole app: sample voices (Web Audio), call recordings, voice previews
 * and <audio> players. Whoever starts playing "claims" the focus and the previous player is stopped.
 */
type Owner = { stop: () => void };

let current: Owner | null = null;
const mediaOwners = new WeakMap<HTMLMediaElement, Owner>();

export function claimAudio(owner: Owner) {
    if (current && current !== owner) {
        try {
            current.stop();
        } catch {}
    }
    current = owner;
}

export function releaseAudio(owner: Owner) {
    if (current === owner) current = null;
}

function ownerOf(el: HTMLMediaElement): Owner {
    let o = mediaOwners.get(el);
    if (!o) {
        o = { stop: () => el.pause() };
        mediaOwners.set(el, o);
        el.addEventListener('ended', () => releaseAudio(o!));
    }
    return o;
}

/** Every <audio>/<video> on the page (also the browser's own controls) takes part automatically. */
export function installAudioFocus() {
    document.addEventListener('play', (e) => e.target instanceof HTMLMediaElement && claimAudio(ownerOf(e.target)), true);
}

let shared: HTMLAudioElement | null = null;

/** Plays a URL on one shared player (not in the DOM). Same URL while playing = pause. */
export function toggleUrl(url: string): Promise<boolean> {
    if (!shared) shared = new Audio();
    const a = shared;
    const owner = ownerOf(a);
    if (!a.paused && a.src.endsWith(url)) {
        a.pause();
        releaseAudio(owner);
        return Promise.resolve(false);
    }
    claimAudio(owner);
    if (!a.src.endsWith(url)) a.src = url;
    return a.play().then(() => true);
}
