// In-browser analysis engine — lichess-org/stockfish-web (WASM, multi-threaded).
//
// This is the *default* analysis engine: it runs entirely client-side, so games are
// analyzed without hitting the server. It requires cross-origin isolation
// (COOP/COEP headers, see .htaccess) because the build uses SharedArrayBuffer.
// If the browser can't run it, getEngine() resolves to null and callers must fall
// back to the server-side engine at /stockfish.php.
//
// The build outputs (sf_18_smallnet*.js/.wasm, the .nnue net) live in public/engine/
// rather than assets/engine/: Emscripten's glue code and locateFile() resolve sibling
// files by their exact original filename, which AssetMapper's content-hashed public
// paths can never match (only this file, statically imported via asset(), gets a
// hashed URL). Keeping them in public/ serves them as plain, unhashed static files.
const ENGINE_DIR = '/engine/';
const NNUE_FILE = 'nn-4ca89e4b3abf.nnue';
// Ordered by preference: relaxed-simd is faster but needs newer CPU/browser support,
// so fall back to the baseline SIMD build before giving up on the client engine.
const BUILDS = ['sf_18_smallnet_relaxed-simd.js', 'sf_18_smallnet.js'];

let enginePromise = null;
let nnuePromise = null;

function envSupportsThreadedWasm() {
    return typeof WebAssembly !== 'undefined'
        && typeof SharedArrayBuffer !== 'undefined'
        && typeof self !== 'undefined'
        && self.crossOriginIsolated === true;
}

function loadNnueBuffer() {
    if (!nnuePromise) {
        nnuePromise = fetch(ENGINE_DIR + NNUE_FILE, { credentials: 'same-origin' })
            .then((r) => {
                if (!r.ok) throw new Error('nnue fetch failed: HTTP ' + r.status);
                return r.arrayBuffer();
            })
            .then((buf) => new Uint8Array(buf));
    }
    return nnuePromise;
}

async function bootBuild(fileName) {
    const mod = await import(ENGINE_DIR + fileName);
    const factory = mod.default;
    const sf = await factory({ locateFile: (path) => ENGINE_DIR + path });

    const nnue = await loadNnueBuffer();
    sf.setNnueBuffer(nnue, 0);
    // Smallnet builds use a single net for both the big/small eval slots — index 1
    // is a no-op on dual-net builds and harmless to skip if it throws.
    try { sf.setNnueBuffer(nnue, 1); } catch (_) { /* single-net build */ }

    return sf;
}

async function bootEngine() {
    if (!envSupportsThreadedWasm()) return null;
    for (const build of BUILDS) {
        try {
            return await bootBuild(build);
        } catch (err) {
            console.warn(`[vrchess-engine] ${build} failed to load, trying next option`, err);
        }
    }
    return null;
}

// Resolves to a raw StockfishWeb instance, or null if the client engine is unavailable.
// Cached — only attempts to boot once per page load.
export function getEngine() {
    if (!enginePromise) enginePromise = bootEngine();
    return enginePromise;
}

function evalBar(cp) {
    return 100 / (1 + Math.exp(-cp / 120));
}

function freshResult() {
    return {
        depth: null, seldepth: null, time: null, nodes: null, nps: null,
        score: null, score_type: null, eval: null,
        pv: [], bestmove: null, ponder: null, multipv: {},
    };
}

// Serializes UCI conversations against one engine instance — mirrors src/Logic/Stockfish.php
// line-by-line so the client and server produce the same result shape.
class EngineSession {
    constructor(sf) {
        this.sf = sf;
        this.waiters = [];
        this.search = null; // { onUpdate, resolve, result }
        // Serializes analyze() calls. UCI only allows one search at a time, and stopping a
        // search is asynchronous (send "stop", wait for the engine to actually emit
        // "bestmove"). Without this queue, calling analyze() again right away — e.g. the user
        // moves to the next position while the engine is still winding down the previous
        // search — would throw "Engine is busy" and look like the client engine failed,
        // triggering an unwanted fallback to the server. Queueing just waits the handful of
        // milliseconds for the stop to land, then proceeds, so the local engine keeps being used.
        this.queue = Promise.resolve();
        sf.listen = (line) => this.onLine(line);
        sf.onError = (msg) => console.error('[vrchess-engine]', msg);
        this.ready = this.init();
    }

    onLine(line) {
        line = String(line).trim();
        if (!line) return;

        for (let i = this.waiters.length - 1; i >= 0; i--) {
            if (line.includes(this.waiters[i].needle)) {
                this.waiters.splice(i, 1)[0].resolve(line);
            }
        }

        if (this.search) this.feedSearchLine(line);
    }

    waitFor(needle) {
        return new Promise((resolve) => this.waiters.push({ needle, resolve }));
    }

    send(cmd) {
        this.sf.uci(cmd);
    }

    async init() {
        const threads = Math.max(1, Math.min(navigator.hardwareConcurrency || 2, 4));
        this.send('uci');
        await this.waitFor('uciok');
        this.send(`setoption name Threads value ${threads}`);
        this.send('setoption name Hash value 32');
        this.send('isready');
        await this.waitFor('readyok');
    }

    feedSearchLine(line) {
        const s = this.search;
        const r = s.result;

        if (line.startsWith('info')) {
            let m;
            if ((m = line.match(/\bdepth\s+(\d+)/))) r.depth = +m[1];
            if ((m = line.match(/\bseldepth\s+(\d+)/))) r.seldepth = +m[1];
            if ((m = line.match(/\btime\s+(\d+)/))) r.time = +m[1];
            if ((m = line.match(/\bnodes\s+(\d+)/))) r.nodes = +m[1];
            if ((m = line.match(/\bnps\s+(\d+)/))) r.nps = +m[1];

            let mpvIndex = 1;
            if ((m = line.match(/\bmultipv\s+(\d+)/))) mpvIndex = +m[1];
            if (!r.multipv[mpvIndex]) r.multipv[mpvIndex] = { score: null, score_type: null, eval: null, pv: [] };

            let hasData = false;
            if ((m = line.match(/\bscore\s+(cp|mate)\s+(-?\d+)/))) {
                const scoreType = m[1];
                const scoreVal = +m[2];
                r.multipv[mpvIndex].score_type = scoreType;
                r.multipv[mpvIndex].score = scoreVal;
                r.multipv[mpvIndex].eval = evalBar(scoreVal);
                if (mpvIndex === 1) {
                    r.score_type = scoreType;
                    r.score = scoreVal;
                    r.eval = evalBar(scoreVal);
                }
                hasData = true;
            }
            if ((m = line.match(/ pv (.+)$/))) {
                const pvArr = m[1].trim().split(/\s+/);
                r.multipv[mpvIndex].pv = pvArr;
                if (mpvIndex === 1) {
                    r.pv = pvArr;
                    if (pvArr[0]) r.bestmove = pvArr[0];
                }
                hasData = true;
            }

            if (hasData && s.onUpdate) s.onUpdate(r);
            return;
        }

        if (line.startsWith('bestmove')) {
            const parts = line.split(/\s+/);
            r.bestmove = parts[1] || r.pv[0] || null;
            r.ponder = parts[3] || null;
            this.search = null;
            s.resolve(r);
        }
    }

    // Runs one search to completion. Resolves with the final result; onUpdate (optional)
    // fires on every info line so callers can render live progress, matching the
    // server's SSE stream behaviour. Queued — see the `queue` comment in the constructor.
    analyze(fen, opts, onUpdate, signal) {
        const run = () => this._runSearch(fen, opts, onUpdate, signal);
        const result = this.queue.then(run);
        // Keep the queue itself always-resolving so one failed/aborted search doesn't jam
        // up whatever's queued behind it.
        this.queue = result.then(() => { }, () => { });
        return result;
    }

    async _runSearch(fen, { depth, movetime, multipv = 1, chess960 = false } = {}, onUpdate, signal) {
        await this.ready;
        // By the time its turn comes up, a queued call may already be stale (the user
        // navigated past it again) — skip engaging the engine entirely for it.
        if (signal?.aborted) throw new DOMException('Aborted', 'AbortError');
        if (this.search) throw new Error('Engine is busy with another search'); // should be unreachable — queue serializes calls

        // Attach the abort listener before the ucinewgame/isready handshake (not just
        // around "go") so a navigation that happens during that handshake still stops
        // this search promptly instead of running it to completion unstopped, which would
        // otherwise delay whatever search is queued behind it.
        let onAbort;
        if (signal) {
            onAbort = () => this.send('stop');
            signal.addEventListener('abort', onAbort, { once: true });
        }

        try {
            this.send('ucinewgame');
            this.send('isready');
            await this.waitFor('readyok');

            if (signal?.aborted) throw new DOMException('Aborted', 'AbortError');

            this.send(`setoption name MultiPV value ${Math.max(1, Math.min(5, multipv))}`);
            this.send(`setoption name UCI_Chess960 value ${chess960 ? 'true' : 'false'}`);
            this.send(`position fen ${fen}`);

            const result = freshResult();
            const donePromise = new Promise((resolve) => {
                this.search = { onUpdate, resolve, result };
            });

            if (movetime != null) this.send(`go movetime ${movetime}`);
            else this.send(`go depth ${Math.max(1, Math.min(99, depth ?? 18))}`);

            // Safety net: if the worker ever wedges (crashed thread, browser throttling a
            // background tab, etc.) donePromise would hang forever and jam the queue for every
            // position after it. A generous timeout — scaled to how long the search was asked
            // to run — turns that into "this position falls back to the server" instead, and
            // discards the whole engine/session so the *next* position gets a fresh worker
            // rather than being queued behind a permanently-stuck one.
            const budgetMs = movetime != null ? movetime : (depth ?? 18) * 1500;
            const timeoutMs = Math.max(15000, budgetMs * 3);
            let timeoutHandle;
            const timeout = new Promise((_, reject) => {
                timeoutHandle = setTimeout(() => reject(new Error('Engine search timed out')), timeoutMs);
            });
            try {
                return await Promise.race([donePromise, timeout]);
            } catch (e) {
                if (/timed out/.test(e.message)) {
                    this.search = null;
                    resetSession(); // next getSession() call boots a fresh worker
                }
                throw e;
            } finally {
                clearTimeout(timeoutHandle);
            }
        } finally {
            if (signal && onAbort) signal.removeEventListener('abort', onAbort);
        }
    }
}

let sessionPromise = null;

// Resolves to an EngineSession, or null if the client engine can't run in this browser.
export async function getSession() {
    if (!sessionPromise) {
        sessionPromise = getEngine().then((sf) => (sf ? new EngineSession(sf) : null));
    }
    return sessionPromise;
}

// Discards the cached engine/session so the next getSession() call boots a fresh worker.
// Used to recover from a wedged search (see the timeout in _runSearch) — never call this for
// an ordinary per-position failure, only for "the engine stopped responding entirely".
export function resetSession() {
    sessionPromise = null;
    enginePromise = null;
}
